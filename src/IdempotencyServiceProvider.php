<?php
namespace Infinitypaul\Idempotency;

use Illuminate\Support\ServiceProvider;
class IdempotencyServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ .'/config/idempotency.php', 'idempotency');
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config/idempotency.php' => config_path('idempotency.php')
        ], 'config');
    }
}