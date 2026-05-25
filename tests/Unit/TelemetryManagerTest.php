<?php

namespace Infinitypaul\Idempotency\Tests\Unit;

use Infinitypaul\Idempotency\Telemetry\TelemetryManager;
use Infinitypaul\Idempotency\Tests\TestCase;

class TelemetryManagerTest extends TestCase
{
    public function test_returns_noop_driver_when_telemetry_disabled(): void
    {
        config(['idempotency.telemetry.enabled' => false]);

        $manager = app(TelemetryManager::class);
        $driver = $manager->driver();

        $this->assertNull($driver->startSegment('test'));
        $this->assertNull($driver->recordMetric('test'));
        $this->assertNull($driver->recordTiming('test', 100));
        $this->assertNull($driver->recordSize('test', 100));
    }

    public function test_noop_driver_handles_arbitrary_method_calls(): void
    {
        config(['idempotency.telemetry.enabled' => false]);

        $manager = app(TelemetryManager::class);
        $driver = $manager->driver();

        $this->assertNull($driver->someRandomMethod('arg1', 'arg2'));
    }

    public function test_manager_call_forwarding_returns_null_when_disabled(): void
    {
        config(['idempotency.telemetry.enabled' => false]);

        $manager = app(TelemetryManager::class);

        $this->assertNull($manager->recordMetric('test'));
    }
}
