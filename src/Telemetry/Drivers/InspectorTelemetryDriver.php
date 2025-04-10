<?php

namespace Infinitypaul\Idempotency\Telemetry\Drivers;

use Infinitypaul\Idempotency\Telemetry\TelemetryDriver;
use Inspector\Laravel\Facades\Inspector;

class InspectorTelemetryDriver implements TelemetryDriver
{

    public function startSegment($name, $description = null)
    {
        if(!Inspector::isRecording()){
            return null;
        }
        return Inspector::startSegment($name, $description ?? $name);
    }

    public function addSegmentContext($segment, $key, $value)
    {
        if ($segment) {
            $segment->addContext($key, $value);
        }
    }

    public function endSegment($segment)
    {
        if ($segment) {
            $segment->end();
        }
    }

    public function recordMetric($name, $value = 1)
    {
        if (Inspector::isRecording()) {
            Inspector::startSegment('metric', $name)->addContext('value', $value)->end();
        }
    }

    public function recordTiming($name, $milliseconds)
    {
        if (Inspector::isRecording()) {
            Inspector::startSegment('timing', $name)->addContext('value_ms', $milliseconds)->end();
        }
    }

    public function recordSize($name, $bytes)
    {
        if (Inspector::isRecording()) {
            Inspector::startSegment('size', $name)->addContext('bytes', $bytes)->end();
        }
    }
}