<?php
namespace Infinitypaul\Idempotency\Events;


class IdempotencyAlertFired
{
    public $eventType;
    public $context;

    public function __construct($eventType, $context = [])
    {
        $this->eventType = $eventType;
        $this->context = $context;
    }
}