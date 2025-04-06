<?php
namespace Infinitypaul\Idempotency\Logging;
class EventType
{
    const LOCK_INCONSISTENCY = 'lock.inconsistency';
    const CONCURRENT_CONFLICT = 'lock.concurrent_conflict';
    const CACHE_HIT = 'cache.hit';
    const CACHE_LATE_HIT = 'cache.late_hit';
    const RESPONSE_DUPLICATE = 'response.duplicate';
    const RESPONSE_ORIGINAL = 'response.original';
    const RESPONSE_ERROR = 'response.error';
    const EXCEPTION_THROWN = 'exception.thrown';
    const MISSING_KEY = 'header.missing_key';
    const INVALID_KEY_FORMAT = 'header.invalid_key';
}