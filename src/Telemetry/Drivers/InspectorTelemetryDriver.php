<?php

namespace Infinitypaul\Idempotency\Telemetry\Drivers;

use Infinitypaul\Idempotency\Telemetry\TelemetryDriver;

class InspectorTelemetryDriver implements TelemetryDriver
{

    public function startSegment($name, $description = null)
    {
        // TODO: Implement startSegment() method.
    }

    public function addSegmentContext($segment, $key, $value)
    {
        // TODO: Implement addSegmentContext() method.
    }

    public function endSegment($segment)
    {
        // TODO: Implement endSegment() method.
    }

    public function recordMetric($name, $value = 1)
    {
        // TODO: Implement recordMetric() method.
    }

    public function recordTiming($name, $milliseconds)
    {
        // TODO: Implement recordTiming() method.
    }

    public function recordSize($name, $bytes)
    {
        // TODO: Implement recordSize() method.
    }
}