<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set('idempotency.enabled', true);
        $this->app['config']->set('idempotency.telemetry.enabled', false);
        $this->app['config']->set('cache.default', 'file');
    }

    public function boot(): void
    {
        Route::prefix('api')->group(__DIR__ . '/../../routes/api.php');
    }
}
