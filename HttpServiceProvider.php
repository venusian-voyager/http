<?php

namespace Voyager\Http;

use Voyager\Contracts\Core\FrameworkCore;
use Voyager\Contracts\NutsAndBolts\DeferrableProvider;
use Voyager\Http\Async\HttpAsyncManager;
use Voyager\Http\Client\Factory;
use Voyager\NutsAndBolts\ServiceProvider;

final class HttpServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/http.php', 'http');

        $this->app->registerSingleton('http.async', fn (FrameworkCore $app) => new HttpAsyncManager($app));
        $this->app->registerSingleton('http', fn (FrameworkCore $app) => new Factory(null, $app['http.async']));
    }

    public function provides(): array
    {
        return ['http', 'http.async'];
    }
}
