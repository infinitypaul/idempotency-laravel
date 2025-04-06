<?php

namespace Infinitypaul\Idempotency\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Cache;

class IdempotencyMiddleware
{
    public function handle($request, Closure $next)
    {
        if (!in_array($request->method(), config('idempotency.methods', ['POST', 'PUT', 'PATCH', 'DELETE']))) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return response()->json([
                'error' => 'Missing Idempotency-Key header',
            ], 400);
        }
        $uuidPattern = config('idempotency.key_pattern', '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
        if (!preg_match($uuidPattern, $idempotencyKey)) {
            return response()->json([
                'error' => 'Invalid Idempotency-Key format. Must be a valid UUID.',
            ], 400);
        }

        $cacheKey = "idempotency:{$idempotencyKey}:response";
        $processingKey = "idempotency:{$idempotencyKey}:processing";
        $metadataKey = "idempotency:{$idempotencyKey}:metadata";

        if (Cache::has($cacheKey)) {
            // Get metadata if available
            $metadata = Cache::get($metadataKey, [
                'created_at' => (new \Carbon\Carbon)->subMinutes(1)->timestamp,
                'hit_count' => 0,
            ]);

            $metadata['hit_count']++;
            $metadata['last_hit_at'] = now()->timestamp;
            Cache::put($metadataKey, $metadata, now()->addMinutes(config('idempotency.ttl', 60)));

            if ($metadata['hit_count'] > config('idempotency.alert_threshold', 5)) {
                //dispatch event
            }

            $response = Cache::get($cacheKey);
            $response->headers->set('Idempotency-Key', $idempotencyKey);
            $response->headers->set('Idempotency-Status', 'Repeated');

            return $response;

        }

        $lock = Cache::lock("idempotency_lock:{$idempotencyKey}", config('idempotency.lock_ttl', 30));

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
                    return response()->json([
                        'error' => 'A request with this Idempotency-Key is currently being processed',
                    ], 409);
                }

                return response()->json([
                    'error' => 'Could not process request. Please try again.',
                ], 500);
            }
            Cache::put($processingKey, true, now()->addMinutes(5));

            $metadata = [
                'created_at' => now()->timestamp,
                'hit_count' => 0,
                'endpoint' => $request->path(),
                'user_id' => $request->user() ? $request->user()->id : null,
                'client_ip' => $request->ip(),
            ];

            Cache::put($metadataKey, $metadata, now()->addMinutes(config('idempotency.ttl', 60)));
            $response = $next($request);
            $response->headers->set('Idempotency-Key', $idempotencyKey);
            $response->headers->set('Idempotency-Status', 'Original');
            if ($response->isSuccessful()) {
                $ttl = config('idempotency.ttl', 60);
                Cache::put($cacheKey, $response, now()->addMinutes($ttl));


            }
            return $response;
        } catch (\Exception $exception){
            throw $exception;
        } finally {
            Cache::forget($processingKey);

            if ($lockAcquired) {
                $lock->release();
            }
        }
    }
}