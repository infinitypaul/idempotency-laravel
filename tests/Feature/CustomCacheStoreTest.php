<?php

namespace Infinitypaul\Idempotency\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Infinitypaul\Idempotency\Middleware\EnsureIdempotency;
use Infinitypaul\Idempotency\Tests\TestCase;

class CustomCacheStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(EnsureIdempotency::class)->post('/test-endpoint', function (Request $request) {
            return response()->json(['message' => 'processed', 'amount' => $request->input('amount')]);
        });
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.stores.idempotency', [
            'driver' => 'array',
        ]);

        $app['config']->set('idempotency.cache_store', 'idempotency');
    }

    public function test_idempotency_uses_configured_cache_store(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 500];

        $first = $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);
        $first->assertStatus(200);
        $first->assertHeader('Idempotency-Status', 'Original');

        $second = $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);
        $second->assertStatus(200);
        $second->assertHeader('Idempotency-Status', 'Repeated');
    }

    public function test_data_is_stored_in_configured_store_not_default(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 500];

        $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);

        $responseKey = "idempotency:{$key}:response";

        $this->assertTrue(Cache::store('idempotency')->has($responseKey));
        $this->assertFalse(Cache::store('array')->has($responseKey));
    }

    public function test_clearing_default_store_does_not_affect_idempotency(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 750];

        $first = $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);
        $first->assertStatus(200);
        $first->assertHeader('Idempotency-Status', 'Original');

        Cache::store('array')->flush();

        $second = $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);
        $second->assertStatus(200);
        $second->assertHeader('Idempotency-Status', 'Repeated');
    }

    public function test_null_cache_store_uses_default(): void
    {
        config(['idempotency.cache_store' => null]);

        $key = (string) Str::uuid();
        $payload = ['amount' => 100];

        $first = $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);
        $first->assertStatus(200);
        $first->assertHeader('Idempotency-Status', 'Original');

        $second = $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);
        $second->assertStatus(200);
        $second->assertHeader('Idempotency-Status', 'Repeated');
    }

    public function test_payload_mismatch_detected_with_custom_store(): void
    {
        $key = (string) Str::uuid();

        $first = $this->postJson('/test-endpoint', ['amount' => 100], ['Idempotency-Key' => $key]);
        $first->assertStatus(200);

        $second = $this->postJson('/test-endpoint', ['amount' => 999], ['Idempotency-Key' => $key]);
        $second->assertStatus(422);
        $second->assertJson(['error' => 'Idempotency-Key reused with different request payload']);
    }

    public function test_metadata_stored_in_configured_store(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 200];

        $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);

        $metadataKey = "idempotency:{$key}:metadata";
        $metadata = Cache::store('idempotency')->get($metadataKey);

        $this->assertNotNull($metadata);
        $this->assertArrayHasKey('created_at', $metadata);
        $this->assertArrayHasKey('hit_count', $metadata);
        $this->assertEquals(0, $metadata['hit_count']);
    }
}
