<?php

namespace Voyager\Http\Async;

use CurlMultiHandle;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Promise\Utils;
use Pcurl\Multi;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\LoopResources\Deadlined;
use Voyager\IOPools\Resources\WakeSource;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\Writable;
use Voyager\Http\Async\Concerns\TracksInFlight;
use Voyager\Http\Client\HttpClientException;

/**
 * curl's multi interface driven by its socket and timer callbacks, which pcurl binds. Every socket
 * curl opens joins the loop's wait for exactly the direction curl asked for, and curl's timer is
 * this resource's own deadline, so a transfer wakes the loop the moment it can move, like any other
 * wake source.
 */
final class LoopPcurlHandler extends WakeSource implements Deadlined
{
    use TracksInFlight { add as private addHandle; forgetWhenIdle as private forgetHandler; }

    /**
     * @var array<int, array{stream: resource, what: int}> fd => the stream the loop waits on, and the CURL_POLL_* curl asked for
     */
    private array $sockets = [];

    /**
     * Streams curl let go of since the last wakes() call. Still open: the waiter drops a stream from
     * its set only while the stream is open, and curl may keep the socket for its next request.
     *
     * @var list<resource>
     */
    private array $retired = [];

    /**
     * Streams the last sync no longer declared, so the waiter has dropped them: safe to close.
     *
     * @var list<resource>
     */
    private array $closing = [];

    /**
     * @var array<int, int> stream id => curl's fd
     */
    private array $fds = [];

    /** Absolute hrtime(true) curl wants its timeout action at, or null when it wants none. */
    private ?int $due_at = null;

    public function __construct(
        private readonly Loop $loop,
        private readonly CurlFactory $factory,
    ) {}

    protected function name(): AsyncResource
    {
        return AsyncResource::PCURL;
    }

    public function wakes(): array
    {
        // The waiter syncs right after this call: what the last sync dropped can close now, and what
        // curl released since then is dropped by this one.
        $this->close($this->closing);
        [$this->closing, $this->retired] = [$this->retired, []];

        $wakes = [];

        foreach ($this->sockets as ['stream' => $stream, 'what' => $what]) {
            if ($what & PcurlPoll::IN->value) {
                $wakes[] = new Readable($stream);
            }

            if ($what & PcurlPoll::OUT->value) {
                $wakes[] = new Writable($stream);
            }
        }

        return $wakes;
    }

    public function woke(array $fired): void
    {
        $bits = [];

        foreach ($fired as $wake) {
            if (! is_null($fd = $this->fds[(int) $wake->stream] ?? null)) {
                $bits[$fd] = ($bits[$fd] ?? 0) | ($wake instanceof Writable ? PcurlSelect::OUT->value : PcurlSelect::IN->value);
            }
        }

        foreach ($bits as $fd => $mask) {
            // An earlier action this turn may have had curl let go of the socket.
            if (isset($this->sockets[$fd])) {
                Multi::curlMultiSocketAction($this->multi(), $fd, $mask);
            }
        }

        $this->harvest();
        Utils::queue()->run();
    }

    public function dueAt(): ?int
    {
        // Idle yet still here: streams are left to close. Already due, so each turn's sync drops or
        // closes them and fire() checks again whether the handler can leave.
        if ($this->registered && $this->in_flight === []) {
            return 0;
        }

        return $this->due_at;
    }

    public function fire(): void
    {
        // Cleared first: the action runs curl's timer callback, which sets the next one.
        $this->due_at = null;
        Multi::curlMultiSocketAction($this->multi(), PcurlSocket::TIMEOUT->value, 0);

        $this->harvest();
        Utils::queue()->run();
    }

    private function add(int $id): void
    {
        $this->addHandle($id);
        Multi::curlMultiSocketAction($this->multi(), PcurlSocket::TIMEOUT->value, 0);
        $this->harvest();
    }

    private function onSocket(int $fd, int $what): void
    {
        if ($what === PcurlPoll::REMOVE->value) {
            if (isset($this->sockets[$fd])) {
                // Not closed yet: under epoll, a closed duplicate of a socket curl keeps stays in the
                // set, and the next duplicate of that socket fails to join it.
                $this->retired[] = $this->sockets[$fd]['stream'];
                unset($this->fds[(int) $this->sockets[$fd]['stream']]);
                unset($this->sockets[$fd]);
            }

            return;
        }

        if (! isset($this->sockets[$fd])) {
            // A stream of its own on curl's descriptor, for the loop to wait on. pcurl dups the
            // descriptor the way php://fd does, in every SAPI; php://fd is CLI-only.
            $stream = Multi::curlSocketStream($fd);

            $this->sockets[$fd] = ['stream' => $stream, 'what' => $what];
            $this->fds[(int) $stream] = $fd;

            return;
        }

        $this->sockets[$fd]['what'] = $what;
    }

    /**
     * Idle, the handler leaves the loop only once wakes() has closed every stream curl let go of:
     * until then it stays, due at once (see dueAt()), so the next syncs drop and close them.
     */
    private function forgetWhenIdle(): void
    {
        if ($this->retired === [] && $this->closing === []) {
            $this->forgetHandler();
        }
    }

    /**
     * @param list<resource> $streams
     */
    private function close(array $streams): void
    {
        foreach ($streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function onTimer(int $timeout_ms): void
    {
        $this->due_at = $timeout_ms < 0 ? null : hrtime(true) + $timeout_ms * 1_000_000;
    }

    private function multi(): CurlMultiHandle
    {
        if (is_null($this->multi)) {
            $this->multi = curl_multi_init();

            $this->setopt(PcurlOption::SOCKET_FUNCTION->value, function ($easy, int $fd, int $what, $clientp, $socketp): int {
                $this->onSocket($fd, $what);
                return 0;
            });

            $this->setopt(PcurlOption::TIMER_FUNCTION->value, function ($multi, int $timeout_ms, $clientp): int {
                $this->onTimer($timeout_ms);
                return 0;
            });
        }

        return $this->multi;
    }

    private function setopt(int $option, callable $callback): void
    {
        $code = Multi::curlMultiSetopt($this->multi, $option, $callback);

        if ($code !== 0) {
            throw new HttpClientException('curl_multi_setopt: '.Multi::curlMultiStrerror($code));
        }
    }
}
