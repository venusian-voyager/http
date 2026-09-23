<?php

namespace Voyager\Http\Async\Concerns;

use CurlMultiHandle;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\Promise as GuzzlePromise;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Http\Async\AsyncResource;
use Voyager\Http\Client\HttpClientException;

/** @property Loop $loop @property CurlFactory $factory */
trait TracksInFlight
{
    /** @var array<int, array{easy: EasyHandle, deferred: GuzzlePromise}> */
    private array $in_flight = [];
    private ?CurlMultiHandle $multi = null;
    private bool $registered = false;

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $easy = $this->factory->create($request, $options);
        $id = spl_object_id($easy->handle);

        $deferred = new GuzzlePromise(
            fn () => $this->loop->until(fn () => ! isset($this->in_flight[$id])),
            fn () => $this->cancel($id),
        );
        $this->in_flight[$id] = ['easy' => $easy, 'deferred' => $deferred];

        isset($options['delay'])
            ? $this->loop->at($options['delay'] / 1000, fn () => $this->add($id))
            : $this->add($id);

        $this->register();

        return $deferred;
    }

    public function inFlight(): int
    {
        return count($this->in_flight);
    }

    private function add(int $id): void
    {
        if (! isset($this->in_flight[$id])) { return; }

        $code = curl_multi_add_handle($this->multi(), $this->in_flight[$id]['easy']->handle);
        if ($code !== CURLM_OK)
        {
            throw new HttpClientException('curl_multi_add_handle: '.curl_multi_strerror($code));
        }
    }

    private function harvest(): void
    {
        while (($info = curl_multi_info_read($this->multi())) !== false)
        {
            // not done yet, or a cancelled transfer PHP handed back without its handle
            if ($info['msg'] !== CURLMSG_DONE || ! isset($info['handle'])) { continue; }

            $id = spl_object_id($info['handle']);
            curl_multi_remove_handle($this->multi(), $info['handle']);

            if (! $entry = $this->in_flight[$id] ?? null) { continue; }
            unset($this->in_flight[$id]);

            $entry['easy']->errno = $info['result'];

            // finish() may retry through __invoke, or run callbacks that cancel the promise
            try
            {
                $result = CurlFactory::finish($this, $entry['easy'], $this->factory);
            }
            catch (Throwable $e)
            {
                if (P\Is::pending($entry['deferred'])) { $entry['deferred']->reject($e); }
                continue;
            }

            if (P\Is::pending($entry['deferred'])) { $entry['deferred']->resolve($result); }
        }

        $this->forgetWhenIdle();
    }

    private function cancel(int $id): void
    {
        if (! $entry = $this->in_flight[$id] ?? null) { return; }
        unset($this->in_flight[$id]);
        curl_multi_remove_handle($this->multi(), $entry['easy']->handle);
        $this->factory->release($entry['easy']);
        $this->forgetWhenIdle();
    }

    private function register(): void
    {
        if ($this->registered) { return; }
        $this->loop->resource($this->name()->value, $this);
        $this->registered = true;
    }

    private function forgetWhenIdle(): void
    {
        if ($this->in_flight !== [] || ! $this->registered) { return; }
        $this->loop->forget($this->name()->value);
        $this->registered = false;
    }

    abstract private function multi(): CurlMultiHandle;

    abstract protected function name(): AsyncResource;
}
