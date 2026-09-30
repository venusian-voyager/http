<?php

namespace Voyager\Http\Async;

use GuzzleHttp\Handler\CurlFactory;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Http\Client\HttpClientException;
use Voyager\NutsAndBolts\Manager;

final class HttpAsyncManager extends Manager
{
    public function getDefaultDriver(): ?string
    {
        return $this->config->get('http.async.default', 'auto');
    }

    public function loop(): ?Loop
    {
        return $this->vessel->has('event-loop') ? $this->vessel['event-loop'] : null;
    }

    /** The bottom Guzzle handler for async sends, or null when no loop is bound. */
    public function handler(): ?callable
    {
        return is_null($this->loop()) ? null : $this->driver();
    }

    /** pcurl when the extension is loaded: it waits on curl's sockets. ext-curl's blind driver otherwise. */
    public function createAutoDriver(): LoopCurlHandler|LoopPcurlHandler
    {
        return extension_loaded('pcurl') ? $this->createPcurlDriver() : $this->createCurlDriver();
    }

    public function createCurlDriver(): LoopCurlHandler
    {
        $c = $this->config->get('http.async.drivers.curl', []);

        return new LoopCurlHandler($this->loop(), new CurlFactory($c['max_handles'] ?? 50), $c['multi_options'] ?? []);
    }

    public function createPcurlDriver(): LoopPcurlHandler
    {
        if (! extension_loaded('pcurl'))
        {
            throw new HttpClientException('The pcurl extension is not loaded.');
        }

        $c = $this->config->get('http.async.drivers.pcurl', []);

        return new LoopPcurlHandler($this->loop(), new CurlFactory($c['max_handles'] ?? 50));
    }
}
