<?php

namespace Voyager\Http\Async;

use CurlMultiHandle;
use GuzzleHttp\Handler\CurlFactory;
use Pcurl\Multi;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\LoopTimer;
use Voyager\Contracts\IOPools\StreamWatchable;
use Voyager\Http\Async\Concerns\TracksInFlight;
use Voyager\Http\Client\HttpClientException;

/** curl's sockets handed to the loop as streams via ext-pcurl. Ticks only when one fires or curl's timer says so. */
final class LoopPcurlHandler implements StreamWatchable
{
    use TracksInFlight { add as private addHandle; }

    /** @var array<int, resource> fd => dup'd stream */
    private array $sockets = [];
    private ?LoopTimer $timer = null;

    public function __construct(
        private readonly Loop $loop,
        private readonly CurlFactory $factory,
    ) {}

    protected function name(): AsyncResource
    {
        return AsyncResource::PCURL;
    }

    public function streams(): array
    {
        return array_values($this->sockets);
    }

    public function tick(): void
    {
        // the loop says "something fired", not which fd; bitmask 0 lets libcurl find out
        foreach (array_keys($this->sockets) as $fd)
        {
            Multi::curlMultiSocketAction($this->multi(), $fd, 0);
        }

        $this->harvest();
    }

    private function add(int $id): void
    {
        $this->addHandle($id);
        Multi::curlMultiSocketAction($this->multi(), PcurlSocket::TIMEOUT->value, 0);
        $this->harvest();
    }

    private function onSocket(int $fd, int $what): void
    {
        if ($what === PcurlPoll::REMOVE->value)
        {
            if (isset($this->sockets[$fd])) { fclose($this->sockets[$fd]); unset($this->sockets[$fd]); }
            return;
        }

        if (! isset($this->sockets[$fd]))
        {
            $stream = fopen('php://fd/'.$fd, 'r+');
            if ($stream === false)
            {
                throw new HttpClientException('php://fd/'.$fd.' failed');
            }

            $this->sockets[$fd] = $stream;
        }

        // The loop selects streams for read only. A socket curl marked writable has to be
        // serviced on the next turn, or the transfer sits until curl's connect timeout.
        if ($what === PcurlPoll::OUT->value || $what === PcurlPoll::INOUT->value)
        {
            $bits = $what === PcurlPoll::INOUT->value
                ? PcurlSelect::IN->value | PcurlSelect::OUT->value
                : PcurlSelect::OUT->value;

            $this->loop->at(0, function () use ($fd, $bits) {
                if (! isset($this->sockets[$fd])) { return; }

                Multi::curlMultiSocketAction($this->multi(), $fd, $bits);
                $this->harvest();
            });
        }
    }

    private function onTimer(int $timeout_ms): void
    {
        $this->timer?->cancel();
        $this->timer = null;

        if ($timeout_ms < 0) { return; }

        $this->timer = $this->loop->at(max(0, $timeout_ms) / 1000, function () {
            $this->timer = null;
            Multi::curlMultiSocketAction($this->multi(), PcurlSocket::TIMEOUT->value, 0);
            $this->harvest();
        });
    }

    private function multi(): CurlMultiHandle
    {
        if (is_null($this->multi))
        {
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
        if ($code !== 0)
        {
            throw new HttpClientException('curl_multi_setopt: '.Multi::curlMultiStrerror($code));
        }
    }
}
