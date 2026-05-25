<?php

namespace Infinitypaul\Idempotency\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Infinitypaul\Idempotency\Middleware\EnsureIdempotency;
use Infinitypaul\Idempotency\Tests\TestCase;

class EnsureIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(EnsureIdempotency::class)->post('/test-endpoint', function (Request $request) {
            return response()->json(['message' => 'processed', 'amount' => $request->input('amount')]);
        });

        Route::middleware(EnsureIdempotency::class)->put('/test-endpoint/{id}', function (Request $request, $id) {
            return response()->json(['message' => 'updated', 'id' => $id, 'amount' => $request->input('amount')]);
        });

        Route::middleware(EnsureIdempotency::class)->patch('/test-endpoint/{id}', function (Request $request, $id) {
            return response()->json(['message' => 'patched', 'id' => $id]);
        });

        Route::middleware(EnsureIdempotency::class)->delete('/test-endpoint/{id}', function (Request $request, $id) {
            return response()->json(['message' => 'deleted', 'id' => $id]);
        });

        Route::middleware(EnsureIdempotency::class)->get('/test-endpoint', function () {
            return response()->json(['message' => 'get response']);
        });

        Route::middleware(EnsureIdempotency::class)->post('/test-error-500', function () {
            return response()->json(['error' => 'server error'], 500);
        });

        Route::middleware(EnsureIdempotency::class)->post('/test-error-422', function () {
            return response()->json(['error' => 'validation failed'], 422);
        });

        Route::middleware(EnsureIdempotency::class)->post('/test-error-429', function () {
            return response()->json(['error' => 'too many requests'], 429);
        });

        Route::middleware(EnsureIdempotency::class)->post('/test-exception', function () {
            throw new \RuntimeException('Something broke');
        });

        Route::middleware(EnsureIdempotency::class)->post('/test-empty-response', function () {
            return response()->json([]);
        });
    }

    // ---------------------------------------------------------------
    // Basic validation
    // ---------------------------------------------------------------

    public function test_request_without_idempotency_key_returns_400(): void
    {
        $response = $this->postJson('/test-endpoint', ['amount' => 100]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Missing Idempotency-Key header']);
    }

    public function test_request_with_invalid_idempotency_key_returns_400(): void
    {
        $response = $this->postJson('/test-endpoint', ['amount' => 100], [
            'Idempotency-Key' => 'not-a-valid-uuid',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Invalid Idempotency-Key format. Must be a valid UUID.']);
    }

    public function test_uppercase_uuid_is_accepted(): void
    {
        $key = strtoupper((string) Str::uuid());

        $response = $this->postJson('/test-endpoint', ['amount' => 100], [
            'Idempotency-Key' => $key,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Idempotency-Status', 'Original');
    }

    // ---------------------------------------------------------------
    // HTTP method coverage
    // ---------------------------------------------------------------

    public function test_post_request_enforces_idempotency(): void
    {
        $key = (string) Str::uuid();

        $response = $this->postJson('/test-endpoint', ['amount' => 100], [
            'Idempotency-Key' => $key,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Idempotency-Status', 'Original');
        $response->assertHeader('Idempotency-Key', $key);
    }

    public function test_put_request_enforces_idempotency(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 300];

        $first = $this->putJson('/test-endpoint/42', $payload, ['Idempotency-Key' => $key]);
        $first->assertStatus(200);
        $first->assertHeader('Idempotency-Status', 'Original');
        $first->assertJson(['message' => 'updated', 'id' => '42']);

        $second = $this->putJson('/test-endpoint/42', $payload, ['Idempotency-Key' => $key]);
        $second->assertStatus(200);
        $second->assertHeader('Idempotency-Status', 'Repeated');
    }

    public function test_patch_request_enforces_idempotency(): void
    {
        $key = (string) Str::uuid();

        $first = $this->patchJson('/test-endpoint/7', [], ['Idempotency-Key' => $key]);
        $first->assertStatus(200);
        $first->assertHeader('Idempotency-Status', 'Original');

        $second = $this->patchJson('/test-endpoint/7', [], ['Idempotency-Key' => $key]);
        $second->assertStatus(200);
        $second->assertHeader('Idempotency-Status', 'Repeated');
    }

    public function test_delete_request_enforces_idempotency(): void
    {
        $key = (string) Str::uuid();

        $first = $this->deleteJson('/test-endpoint/99', [], ['Idempotency-Key' => $key]);
        $first->assertStatus(200);
        $first->assertHeader('Idempotency-Status', 'Original');

        $second = $this->deleteJson('/test-endpoint/99', [], ['Idempotency-Key' => $key]);
        $second->assertStatus(200);
        $second->assertHeader('Idempotency-Status', 'Repeated');
    }

    public function test_get_request_skips_idempotency(): void
    {
        $response = $this->getJson('/test-endpoint');

        $response->assertStatus(200);
        $response->assertJson(['message' => 'get response']);
        $this->assertFalse($response->headers->has('Idempotency-Key'));
    }

    // ---------------------------------------------------------------
    // Core idempotency behavior
    // ---------------------------------------------------------------

    public function test_first_request_returns_original_status(): void
    {
        $key = (string) Str::uuid();

        $response = $this->postJson('/test-endpoint', ['amount' => 100], [
            'Idempotency-Key' => $key,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'processed', 'amount' => 100]);
        $response->assertHeader('Idempotency-Key', $key);
        $response->assertHeader('Idempotency-Status', 'Original');
    }

    public function test_duplicate_request_returns_cached_response_with_repeated_status(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 200];

        $first = $this->postJson('/test-endpoint', $payload, [
            'Idempotency-Key' => $key,
        ]);
        $first->assertStatus(200);
        $first->assertHeader('Idempotency-Status', 'Original');

        $second = $this->postJson('/test-endpoint', $payload, [
            'Idempotency-Key' => $key,
        ]);
        $second->assertStatus(200);
        $second->assertHeader('Idempotency-Status', 'Repeated');
        $second->assertJson(['message' => 'processed', 'amount' => 200]);
    }

    public function test_response_body_is_identical_on_cache_hit(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 5000, 'currency' => 'NGN'];

        $first = $this->postJson('/test-endpoint', $payload, [
            'Idempotency-Key' => $key,
        ]);

        $second = $this->postJson('/test-endpoint', $payload, [
            'Idempotency-Key' => $key,
        ]);

        $this->assertEquals($first->json(), $second->json());
    }

    public function test_same_key_with_different_payload_returns_422(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/test-endpoint', ['amount' => 100], [
            'Idempotency-Key' => $key,
        ])->assertStatus(200);

        $response = $this->postJson('/test-endpoint', ['amount' => 999], [
            'Idempotency-Key' => $key,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Idempotency-Key reused with different request payload']);
    }

    public function test_different_keys_process_independently(): void
    {
        $key1 = (string) Str::uuid();
        $key2 = (string) Str::uuid();

        $first = $this->postJson('/test-endpoint', ['amount' => 100], [
            'Idempotency-Key' => $key1,
        ]);
        $first->assertStatus(200);
        $first->assertJson(['amount' => 100]);

        $second = $this->postJson('/test-endpoint', ['amount' => 200], [
            'Idempotency-Key' => $key2,
        ]);
        $second->assertStatus(200);
        $second->assertJson(['amount' => 200]);
        $second->assertHeader('Idempotency-Status', 'Original');
    }

    public function test_empty_payload_works(): void
    {
        $key = (string) Str::uuid();

        $first = $this->postJson('/test-empty-response', [], [
            'Idempotency-Key' => $key,
        ]);
        $first->assertStatus(200);
        $first->assertHeader('Idempotency-Status', 'Original');

        $second = $this->postJson('/test-empty-response', [], [
            'Idempotency-Key' => $key,
        ]);
        $second->assertStatus(200);
        $second->assertHeader('Idempotency-Status', 'Repeated');
    }

    // ---------------------------------------------------------------
    // Response status code caching rules
    // ---------------------------------------------------------------

    public function test_5xx_responses_are_not_cached(): void
    {
        $key = (string) Str::uuid();

        $first = $this->postJson('/test-error-500', [], [
            'Idempotency-Key' => $key,
        ]);
        $first->assertStatus(500);
        $first->assertHeader('Idempotency-Status', 'Original');

        $cacheKey = "idempotency:{$key}:response";
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_429_responses_are_not_cached(): void
    {
        $key = (string) Str::uuid();

        $first = $this->postJson('/test-error-429', [], [
            'Idempotency-Key' => $key,
        ]);
        $first->assertStatus(429);
        $first->assertHeader('Idempotency-Status', 'Original');

        $cacheKey = "idempotency:{$key}:response";
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_4xx_client_errors_are_cached(): void
    {
        $key = (string) Str::uuid();

        $first = $this->postJson('/test-error-422', [], [
            'Idempotency-Key' => $key,
        ]);
        $first->assertStatus(422);
        $first->assertHeader('Idempotency-Status', 'Original');

        $cacheKey = "idempotency:{$key}:response";
        $this->assertTrue(Cache::has($cacheKey));

        $second = $this->postJson('/test-error-422', [], [
            'Idempotency-Key' => $key,
        ]);
        $second->assertStatus(422);
        $second->assertHeader('Idempotency-Status', 'Repeated');
    }

    // ---------------------------------------------------------------
    // Enabled/disabled toggle
    // ---------------------------------------------------------------

    public function test_disabled_idempotency_passes_through(): void
    {
        config(['idempotency.enabled' => false]);

        $response = $this->postJson('/test-endpoint', ['amount' => 100]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'processed']);
        $this->assertFalse($response->headers->has('Idempotency-Key'));
    }

    // ---------------------------------------------------------------
    // Metadata & cache internals
    // ---------------------------------------------------------------

    public function test_metadata_tracks_hit_count(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 100];
        $metadataKey = "idempotency:{$key}:metadata";

        $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);
        $this->assertEquals(0, Cache::get($metadataKey)['hit_count']);

        $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);
        $this->assertEquals(1, Cache::get($metadataKey)['hit_count']);

        $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);
        $this->assertEquals(2, Cache::get($metadataKey)['hit_count']);
    }

    public function test_payload_hash_is_stored_on_first_request(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 100];

        $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);

        $hashKey = "idempotency:{$key}:payload_hash";
        $this->assertTrue(Cache::has($hashKey));
        $this->assertEquals(md5(json_encode($payload)), Cache::get($hashKey));
    }

    public function test_processing_flag_is_cleaned_up_after_request(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/test-endpoint', ['amount' => 100], ['Idempotency-Key' => $key]);

        $this->assertFalse(Cache::has("idempotency:{$key}:processing"));
    }

    public function test_metadata_stores_endpoint_and_ip(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/test-endpoint', ['amount' => 100], ['Idempotency-Key' => $key]);

        $metadata = Cache::get("idempotency:{$key}:metadata");
        $this->assertEquals('test-endpoint', $metadata['endpoint']);
        $this->assertArrayHasKey('client_ip', $metadata);
        $this->assertArrayHasKey('created_at', $metadata);
    }

    public function test_idempotency_key_header_is_returned_on_response(): void
    {
        $key = (string) Str::uuid();

        $response = $this->postJson('/test-endpoint', ['amount' => 100], [
            'Idempotency-Key' => $key,
        ]);

        $this->assertEquals($key, $response->headers->get('Idempotency-Key'));
    }

    // ---------------------------------------------------------------
    // Exception handling
    // ---------------------------------------------------------------

    public function test_exception_in_handler_propagates_and_cleans_up_processing_flag(): void
    {
        $key = (string) Str::uuid();

        try {
            $this->withoutExceptionHandling()
                ->postJson('/test-exception', [], ['Idempotency-Key' => $key]);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Something broke', $e->getMessage());
        }

        $this->assertFalse(Cache::has("idempotency:{$key}:processing"));
    }

    public function test_exception_in_handler_does_not_cache_response(): void
    {
        $key = (string) Str::uuid();

        try {
            $this->withoutExceptionHandling()
                ->postJson('/test-exception', [], ['Idempotency-Key' => $key]);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(Cache::has("idempotency:{$key}:response"));
    }

    public function test_exception_releases_lock_allowing_retry(): void
    {
        $key = (string) Str::uuid();

        try {
            $this->withoutExceptionHandling()
                ->postJson('/test-exception', [], ['Idempotency-Key' => $key]);
        } catch (\RuntimeException) {
            // expected
        }

        $lockKey = "idempotency_lock:{$key}";
        $lock = Cache::lock($lockKey, 5);
        $acquired = $lock->get();
        $this->assertTrue($acquired, 'Lock should be released after exception so retry is possible');
        if ($acquired) {
            $lock->release();
        }
    }

    // ---------------------------------------------------------------
    // Race condition / concurrency scenarios
    // ---------------------------------------------------------------

    public function test_second_request_gets_cached_response_after_lock_released(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 6000];
        $lockKey = "idempotency_lock:{$key}";
        $responseKey = "idempotency:{$key}:response";
        $payloadHashKey = "idempotency:{$key}:payload_hash";

        $first = $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);
        $first->assertStatus(200);
        $first->assertHeader('Idempotency-Status', 'Original');

        $this->assertTrue(Cache::has($responseKey), 'Response should be cached after first request');

        $second = $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key]);
        $second->assertStatus(200);
        $second->assertHeader('Idempotency-Status', 'Repeated');
        $this->assertEquals($first->json(), $second->json());
    }

    public function test_lock_timeout_throws_when_lock_held_by_another_process(): void
    {
        $key = (string) Str::uuid();
        $lockKey = "idempotency_lock:{$key}";

        $lock = Cache::lock($lockKey, 30);
        $lock->get();

        config(['idempotency.lock_wait' => 1]);

        $this->expectException(\Illuminate\Contracts\Cache\LockTimeoutException::class);

        try {
            $this->withoutExceptionHandling()
                ->postJson('/test-endpoint', ['amount' => 100], [
                    'Idempotency-Key' => $key,
                ]);
        } finally {
            $lock->release();
        }
    }

    public function test_lock_timeout_returns_error_response_with_exception_handling(): void
    {
        $key = (string) Str::uuid();
        $lockKey = "idempotency_lock:{$key}";

        $lock = Cache::lock($lockKey, 30);
        $lock->get();

        config(['idempotency.lock_wait' => 1]);

        try {
            $response = $this->postJson('/test-endpoint', ['amount' => 100], [
                'Idempotency-Key' => $key,
            ]);

            $response->assertStatus(500);
        } finally {
            $lock->release();
        }
    }

    public function test_late_cache_hit_returns_repeated_when_response_cached_during_lock_wait(): void
    {
        $key = (string) Str::uuid();
        $lockKey = "idempotency_lock:{$key}";
        $responseKey = "idempotency:{$key}:response";
        $payloadHashKey = "idempotency:{$key}:payload_hash";

        $cachedResponse = response()->json(['message' => 'processed', 'amount' => 100]);
        Cache::put($responseKey, $cachedResponse, now()->addMinutes(60));
        Cache::put($payloadHashKey, md5(json_encode(['amount' => 100])), now()->addMinutes(60));

        $response = $this->postJson('/test-endpoint', ['amount' => 100], [
            'Idempotency-Key' => $key,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Idempotency-Status', 'Repeated');
    }

    public function test_response_cached_before_lock_acquisition_returns_repeated(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 100];
        $responseKey = "idempotency:{$key}:response";
        $payloadHashKey = "idempotency:{$key}:payload_hash";
        $metadataKey = "idempotency:{$key}:metadata";

        $cachedResponse = response()->json(['message' => 'processed', 'amount' => 100]);
        Cache::put($responseKey, $cachedResponse, now()->addMinutes(60));
        Cache::put($payloadHashKey, md5(json_encode($payload)), now()->addMinutes(60));
        Cache::put($metadataKey, [
            'created_at' => now()->timestamp,
            'hit_count' => 0,
        ], now()->addMinutes(60));

        $response = $this->postJson('/test-endpoint', $payload, [
            'Idempotency-Key' => $key,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Idempotency-Status', 'Repeated');
    }

    public function test_multiple_rapid_duplicates_all_return_repeated(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => 500];

        $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key])
            ->assertHeader('Idempotency-Status', 'Original');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/test-endpoint', $payload, ['Idempotency-Key' => $key])
                ->assertStatus(200)
                ->assertHeader('Idempotency-Status', 'Repeated');
        }

        $metadata = Cache::get("idempotency:{$key}:metadata");
        $this->assertEquals(5, $metadata['hit_count']);
    }

    // ---------------------------------------------------------------
    // prepareCacheableResponse behavior
    // ---------------------------------------------------------------

    public function test_exception_data_is_stripped_from_cached_response(): void
    {
        Route::middleware(EnsureIdempotency::class)->post('/test-with-exception-data', function () {
            return response()->json([
                'message' => 'error occurred',
                'exception' => 'App\\Exceptions\\SomeException',
                'trace' => ['line1', 'line2'],
            ], 400);
        });

        $key = (string) Str::uuid();

        $this->postJson('/test-with-exception-data', [], ['Idempotency-Key' => $key]);

        $responseKey = "idempotency:{$key}:response";
        $cached = Cache::get($responseKey);
        $content = json_decode($cached->getContent(), true);

        $this->assertArrayNotHasKey('exception', $content);
        $this->assertEquals('error occurred', $content['message']);
    }

    public function test_nested_exception_data_is_filtered_in_cached_response(): void
    {
        Route::middleware(EnsureIdempotency::class)->post('/test-nested-exception', function () {
            return response()->json([
                'error' => [
                    'message' => 'Something went wrong',
                    'exception' => 'App\\Exceptions\\NestedOne',
                ],
            ], 400);
        });

        $key = (string) Str::uuid();

        $this->postJson('/test-nested-exception', [], ['Idempotency-Key' => $key]);

        $responseKey = "idempotency:{$key}:response";
        $cached = Cache::get($responseKey);
        $content = json_decode($cached->getContent(), true);

        $this->assertEquals('[Filtered Exception]', $content['error']['exception']);
    }

    // ---------------------------------------------------------------
    // Custom config behavior
    // ---------------------------------------------------------------

    public function test_custom_methods_config_limits_idempotency_to_specified_verbs(): void
    {
        config(['idempotency.methods' => ['POST']]);

        $key = (string) Str::uuid();

        $put = $this->putJson('/test-endpoint/1', ['amount' => 100], ['Idempotency-Key' => $key]);
        $put->assertStatus(200);
        $this->assertFalse($put->headers->has('Idempotency-Status'));
    }
}
