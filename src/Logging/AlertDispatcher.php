<?php

namespace Infinitypaul\Idempotency\Logging;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository as CacheStore;
use Infinitypaul\Idempotency\Events\IdempotencyAlertFired;

class AlertDispatcher
{
    private CacheManager $cacheManager;

    public function __construct(?CacheManager $cacheManager = null)
    {
        $this->cacheManager = $cacheManager ?? app(CacheManager::class);
    }

    public function dispatch($eventType, $context = []): void
    {
        if (! $this->shouldSendAlert($eventType, $context)) {
            return;
        }

        event(new IdempotencyAlertFired($eventType, $context));
    }

    private function cache(): CacheStore
    {
        return $this->cacheManager->store(config('idempotency.cache_store'));
    }

    /**
     * Determine whether this alert should be sent based on cooldown.
     */
    protected function shouldSendAlert($eventType, $context): bool
    {
        $hashKey = md5($eventType . ':' . json_encode($context));
        $cacheKey = "idempotency:alert_sent:{$hashKey}";

        if ($this->cache()->has($cacheKey)) {
            return false;
        }

        $cooldown = config("idempotency.alerts.threshold", 60);
        $this->cache()->put($cacheKey, true, now()->addMinutes($cooldown));

        return true;
    }
}