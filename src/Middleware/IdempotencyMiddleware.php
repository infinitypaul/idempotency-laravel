<?php

namespace Infinitypaul\Idempotency\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Infinitypaul\Idempotency\Logging\AlertDispatcher;
use Infinitypaul\Idempotency\Logging\EventType;
use Infinitypaul\Idempotency\Logging\LogFormatter;

class IdempotencyMiddleware
{
    /**
     * @throws LockTimeoutException
     */
    public function handle($request, Closure $next)
    {
        if (!in_array($request->method(), config('idempotency.methods', ['POST', 'PUT', 'PATCH', 'DELETE']))) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return response()->json(['error' => 'Missing Idempotency-Key header'], 400);
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $idempotencyKey)) {
            return response()->json(['error' => 'Invalid Idempotency-Key format. Must be a valid UUID.'], 400);
        }

        $cacheKey = "idempotency:{$idempotencyKey}:response";
        $processingKey = "idempotency:{$idempotencyKey}:processing";
        $metadataKey = "idempotency:{$idempotencyKey}:metadata";

        if (Cache::has($cacheKey)) {
            $metadata = Cache::get($metadataKey, [
                'created_at' => now()->subMinutes(1)->timestamp,
                'hit_count' => 0,
            ]);

            $metadata['hit_count']++;
            $metadata['last_hit_at'] = now()->timestamp;
            Cache::put($metadataKey, $metadata, now()->addMinutes(config('idempotency.ttl', 60)));

            if ($metadata['hit_count'] > config('idempotency.alert_threshold', 5)) {
                (new AlertDispatcher())->dispatch(EventType::RESPONSE_DUPLICATE,
                    ['idempotency_key' => $idempotencyKey,
                    'hit_count' => $metadata['hit_count'],
                    'endpoint' => $request->path(),
                ]);

            }

            $response = Cache::get($cacheKey);
            $response->headers->set('Idempotency-Key', $idempotencyKey);
            $response->headers->set('Idempotency-Status', 'Repeated');

            return $response;
        }

        $lock = Cache::lock("idempotency_lock:{$idempotencyKey}", 30);
        $lockAcquired = false;

        try {
            $lockAcquired = $lock->block(5);

            if (! $lockAcquired) {
                if (Cache::has($cacheKey)) {
                    $response = Cache::get($cacheKey);
                    $response->headers->set('Idempotency-Key', $idempotencyKey);
                    $response->headers->set('Idempotency-Status', 'Repeated');
                    return $response;
                }

                if (Cache::has($processingKey)) {
                    (new AlertDispatcher())->dispatch(EventType::CONCURRENT_CONFLICT,  [
                        'idempotency_key' => $idempotencyKey,
                        'endpoint' => $request->path(),
                    ]);

                    return response()->json([
                        'error' => 'A request with this Idempotency-Key is currently being processed',
                    ], 409);
                }

                (new AlertDispatcher())->dispatch(EventType::LOCK_INCONSISTENCY, [
                    'idempotency_key' => $idempotencyKey,
                    'endpoint' => $request->path(),
                ]);

                return response()->json([
                    'error' => 'Could not process request. Please try again.',
                ], 500);
            }

            Cache::put($processingKey, true, now()->addMinutes(5));
            Cache::put($metadataKey, [
                'created_at' => now()->timestamp,
                'hit_count' => 0,
                'endpoint' => $request->path(),
                'user_id' => auth()->check() ? $request->user()->id : null,
                'client_ip' => $request->ip(),
            ], now()->addMinutes(config('idempotency.ttl', 60)));

            $response = $next($request);
            $response->headers->set('Idempotency-Key', $idempotencyKey);
            $response->headers->set('Idempotency-Status', 'Original');

            if ($response->isSuccessful()) {
                Cache::put($cacheKey, $response, now()->addMinutes(config('idempotency.ttl', 60)));
            }

            return $response;
        } catch (\Exception $e) {
            (new AlertDispatcher())->dispatch(EventType::EXCEPTION_THROWN, [
                'idempotency_key' => $idempotencyKey,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            throw $e;
        } finally {
            Cache::forget($processingKey);
            if ($lockAcquired) {
                $lock->release();
            }
        }
    }

}
