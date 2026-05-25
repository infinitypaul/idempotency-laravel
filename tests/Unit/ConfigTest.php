<?php

namespace Infinitypaul\Idempotency\Tests\Unit;

use Infinitypaul\Idempotency\Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_default_methods_include_state_changing_verbs(): void
    {
        $methods = config('idempotency.methods');

        $this->assertContains('POST', $methods);
        $this->assertContains('PUT', $methods);
        $this->assertContains('PATCH', $methods);
        $this->assertContains('DELETE', $methods);
        $this->assertNotContains('GET', $methods);
    }

    public function test_default_ttl_is_60_minutes(): void
    {
        $this->assertEquals(60, config('idempotency.ttl'));
    }

    public function test_default_lock_timeout_is_30_seconds(): void
    {
        $this->assertEquals(30, config('idempotency.lock_timeout'));
    }

    public function test_default_lock_wait_is_5_seconds(): void
    {
        $this->assertEquals(5, config('idempotency.lock_wait'));
    }

    public function test_default_validation_pattern_matches_uuid(): void
    {
        $pattern = config('idempotency.validation.pattern');

        $this->assertMatchesRegularExpression($pattern, '550e8400-e29b-41d4-a716-446655440000');
        $this->assertMatchesRegularExpression($pattern, 'A550E840-E29B-41D4-A716-446655440000');
        $this->assertDoesNotMatchRegularExpression($pattern, 'not-a-uuid');
        $this->assertDoesNotMatchRegularExpression($pattern, '12345');
    }

    public function test_enabled_defaults_to_false(): void
    {
        $app = $this->createApplication();
        $app['config']->set('idempotency.enabled', env('IDEMPOTENCY_ENABLED', false));

        $this->assertFalse($app['config']->get('idempotency.enabled'));
    }

    public function test_telemetry_defaults_to_null_driver(): void
    {
        $this->assertEquals('null', config('idempotency.telemetry.driver'));
    }

    public function test_cache_store_defaults_to_null(): void
    {
        $this->assertNull(config('idempotency.cache_store'));
    }
}
