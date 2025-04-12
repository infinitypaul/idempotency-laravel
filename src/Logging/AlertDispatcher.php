<?php

namespace Infinitypaul\Idempotency\Logging;

use Illuminate\Support\Facades\Cache;
use Infinitypaul\Idempotency\Events\IdempotencyAlertFired;

class AlertDispatcher
{
    public function dispatch($eventType, $context = []): void
    {
        if (! $this->shouldSendAlert($eventType, $context)) {
            return;
        }

        event(new IdempotencyAlertFired($eventType, $context));
    }

    /**
     * Determine whether this alert should be sent based on cooldown.
     */
    protected function shouldSendAlert($eventType, $context): bool
    {
        $hashKey = md5($eventType . ':' . json_encode($context));
        $cacheKey = "idempotency:alert_sent:{$hashKey}";

        if (Cache::has($cacheKey)) {
            return false;
        }

        $cooldown = config("idempotency.alerts.threshold", 60);
        Cache::put($cacheKey, true, now()->addMinutes($cooldown));

        return true;
    }
}