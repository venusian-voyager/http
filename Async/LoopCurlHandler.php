<?php

namespace Voyager\Http\Async;

use CurlMultiHandle;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Promise\Utils;
use Voyager\Contracts\IOPools\Loop;
use Voyager\IOPools\Resources\Sleeper;
use Voyager\Http\Async\Concerns\TracksInFlight;

/**
 * ext-curl's multi interface on the loop. ext-curl never hands out curl's sockets, so this driver
 * is blind: it holds the loop's sleep inside curl_multi_select() while transfers are in flight and
 * drives them with curl_multi_exec() every tick. Other wake sources are looked at once the select
 * returns, so they can wait up to the pace; the pcurl driver waits on curl's sockets with them.
 */
final class LoopCurlHandler extends Sleeper
{
    use TracksInFlight;

    /**
     * @param array<int, mixed> $multi_options CURLMOPT_* => value
     */
    public function __construct(
        private readonly Loop $loop,
        private readonly CurlFactory $factory,
        private readonly array $multi_options = [],
    ) {}

    protected function name(): AsyncResource
    {
        return AsyncResource::CURL;
    }

    public function sleep(int $budget_ns): void
    {
        curl_multi_select($this->multi(), $budget_ns / 1e9);
    }

    public function tick(): void
    {
        do {
            $code = curl_multi_exec($this->multi(), $running);
        } while ($code === CURLM_CALL_MULTI_PERFORM);

        $this->harvest();
        Utils::queue()->run();
    }

    private function multi(): CurlMultiHandle
    {
        if (is_null($this->multi)) {
            $this->multi = curl_multi_init();

            foreach ($this->multi_options as $option => $value) {
                curl_multi_setopt($this->multi, $option, $value);
            }
        }

        return $this->multi;
    }
}
