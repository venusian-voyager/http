<?php

namespace Voyager\Http\Async;

use CurlMultiHandle;
use GuzzleHttp\Handler\CurlFactory;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Sleepable;
use Voyager\Contracts\IOPools\Tickable;
use Voyager\Http\Async\Concerns\TracksInFlight;

/** ext-curl multi on the loop. Owns the sleep while requests are in flight; polls otherwise opaque. */
final class LoopCurlHandler implements Tickable, Sleepable
{
    use TracksInFlight;

    public function __construct(
        private readonly Loop $loop,
        private readonly CurlFactory $factory,
        private readonly array $multi_options = [],
    ) {}

    protected function name(): AsyncResource
    {
        return AsyncResource::CURL;
    }

    public function tick(): void
    {
        do
        {
            $code = curl_multi_exec($this->multi(), $running);
        } while ($code === CURLM_CALL_MULTI_PERFORM);

        $this->harvest();
    }

    public function sleep(int $budget_ms = 0): array
    {
        curl_multi_select($this->multi(), $budget_ms / 1000);

        return [];
    }

    private function multi(): CurlMultiHandle
    {
        if (is_null($this->multi))
        {
            $this->multi = curl_multi_init();
            foreach ($this->multi_options as $opt => $value) { curl_multi_setopt($this->multi, $opt, $value); }
        }

        return $this->multi;
    }
}
