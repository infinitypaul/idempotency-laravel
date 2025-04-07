<?php
namespace Infinitypaul\Idempotency;

use Illuminate\Support\ServiceProvider;
use Infinitypaul\Idempotency\Telemetry\TelemetryManager;

class IdempotencyServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ .'/config/idempotency.php', 'idempotency');

        $this->app->singleton(TelemetryManager::class, function ($app) {
            return new TelemetryManager($app);
        });
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config/idempotency.php' => config_path('idempotency.php')
        ], 'config');
    }
}