<?php

namespace Infinitypaul\Idempotency\Telemetry;

interface TelemetryDriver
{
    /**
     * Start a new telemetry segment (like a span or trace).
     *
     * @param string $name
     * @param string|null $description
     * @return mixed
     */
    public function startSegment($name,$description = null);

    /**
     * Add context or metadata to a telemetry segment.
     *
     * @param mixed $segment
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function addSegmentContext($segment, $key, $value);

    /**
     * End a segment.
     *
     * @param mixed $segment
     * @return void
     */
    public function endSegment($segment);

    /**
     * Record a numeric metric (e.g., counter).
     *
     * @param string $name
     * @param int $value
     * @return void
     */
    public function recordMetric($name, $value = 1);

    /**
     * Record a timing metric (e.g., duration).
     *
     * @param string $name
     * @param float $milliseconds
     * @return void
     */
    public function recordTiming($name, $milliseconds);

    /**
     * Record a size-based metric (e.g., response size).
     *
     * @param string $name
     * @param int $bytes
     * @return void
     */
    public function recordSize($name, $bytes);
}
