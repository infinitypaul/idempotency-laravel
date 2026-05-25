<?php

namespace Infinitypaul\Idempotency\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Infinitypaul\Idempotency\Events\IdempotencyAlertFired;
use Infinitypaul\Idempotency\Logging\AlertDispatcher;
use Infinitypaul\Idempotency\Logging\EventType;
use Infinitypaul\Idempotency\Tests\TestCase;

class AlertDispatcherTest extends TestCase
{
    public function test_dispatches_alert_event(): void
    {
        Event::fake();

        $dispatcher = new AlertDispatcher();
        $dispatcher->dispatch(EventType::RESPONSE_DUPLICATE, ['key' => 'test']);

        Event::assertDispatched(IdempotencyAlertFired::class, function ($event) {
            return $event->eventType === EventType::RESPONSE_DUPLICATE
                && $event->context['key'] === 'test';
        });
    }

    public function test_respects_cooldown_period(): void
    {
        Event::fake();

        $dispatcher = new AlertDispatcher();
        $context = ['key' => 'cooldown-test'];

        $dispatcher->dispatch(EventType::SIZE_WARNING, $context);
        $dispatcher->dispatch(EventType::SIZE_WARNING, $context);

        Event::assertDispatchedTimes(IdempotencyAlertFired::class, 1);
    }

    public function test_different_contexts_dispatch_independently(): void
    {
        Event::fake();

        $dispatcher = new AlertDispatcher();

        $dispatcher->dispatch(EventType::SIZE_WARNING, ['key' => 'a']);
        $dispatcher->dispatch(EventType::SIZE_WARNING, ['key' => 'b']);

        Event::assertDispatchedTimes(IdempotencyAlertFired::class, 2);
    }

    public function test_different_event_types_dispatch_independently(): void
    {
        Event::fake();

        $dispatcher = new AlertDispatcher();
        $context = ['key' => 'same'];

        $dispatcher->dispatch(EventType::SIZE_WARNING, $context);
        $dispatcher->dispatch(EventType::LOCK_INCONSISTENCY, $context);

        Event::assertDispatchedTimes(IdempotencyAlertFired::class, 2);
    }
}
