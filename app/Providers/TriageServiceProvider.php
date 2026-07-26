<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Triage\RoutingPolicy;
use App\Services\Triage\TriageClient;
use Illuminate\Support\ServiceProvider;

final class TriageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // RoutingPolicy takes the config array explicitly rather than calling
        // config() internally, so tests can hand it a fixture policy. That
        // makes it un-autowirable, hence the binding.
        $this->app->bind(RoutingPolicy::class, fn ($app) => new RoutingPolicy($app['config']->get('triage')));

        // One client per process: the SDK holds a PSR-18 transport we would
        // rather not rebuild on every resolve.
        $this->app->singleton(TriageClient::class);
    }
}
