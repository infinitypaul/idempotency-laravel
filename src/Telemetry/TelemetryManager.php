<?php

namespace Infinitypaul\Idempotency\Telemetry;

use Illuminate\Support\Manager;
use Infinitypaul\Idempotency\Telemetry\Drivers\InspectorTelemetryDriver;
use InvalidArgumentException;

class TelemetryManager extends Manager
{
    protected $telemetryEnabled;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->telemetryEnabled = config('idempotency.enabled');
    }

    public function getDefaultDriver()
    {
        return config('idempotency.telemetry.driver');
    }

    public function createInspectorDriver()
    {
        return new InspectorTelemetryDriver();
    }

    public function createCustomDriver(){
        $class = config('idempotency.telemetry.custom_driver_class');

        if(!$class || !class_exists($class)){
            throw new InvalidArgumentException("Custom telemetry driver class [$class] not found.");
        }

        $driver = app($class);

        if(!$driver instanceof TelemetryDriver){
            throw new InvalidArgumentException("Custom telemetry driver must implement TelemetryDriver interface.");
        }

        return $driver;
    }

    public function driver($driver = null)
    {
        if (! $this->telemetryEnabled) {
            return new class {
                public function __call($method, $args) { return null; }
            };
        }

        return parent::driver($driver);
    }

    public function __call($method, $parameters)
    {
        if(!$this->telemetryEnabled){
            return null;
        }

        return $this->driver()->$method(...$parameters);
    }
}