<?php

namespace Infinitypaul\Idempotency\Middleware;

use Closure;
use Exception;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Infinitypaul\Idempotency\Logging\AlertDispatcher;
use Infinitypaul\Idempotency\Logging\EventType;
use Infinitypaul\Idempotency\Telemetry\TelemetryManager;

class IdempotencyMiddleware
{
    private const CACHE_TTL_MINUTES = 60;
    private const LOCK_TIMEOUT_SECONDS = 30;
    private const LOCK_WAIT_SECONDS = 5;
    private const PROCESSING_TTL_MINUTES = 5;

    private TelemetryManager $telemetryManager;
    private mixed $segment;
    private float $startTime;

    public function __construct(TelemetryManager $telemetryManager)
    {
        $this->telemetryManager = $telemetryManager;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     * @throws LockTimeoutException|Exception
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $this->startTime = microtime(true);
        $this->initializeTelemetry($request);

        if (!$this->isMethodApplicable($request)) {
            return $this->handleSkippedMethod($next, $request);
        }


        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return $this->handleMissingKey();
        }


        if (!$this->isValidUuid($idempotencyKey)) {
            return $this->handleInvalidKey();
        }


        $keys = $this->generateCacheKeys($idempotencyKey);

        // Check for cached response
        if (Cache::has($keys['response'])) {
            return $this->handleCachedResponse($keys, $idempotencyKey, $request);
        }

        return $this->handleNewRequest($keys, $idempotencyKey, $next, $request);
    }

    /**
     * Initialize telemetry for the request.
     *
     * @param Request $request
     * @return void
     */
    private function initializeTelemetry(Request $request): void
    {
        $context = [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
        ];

        $telemetry = $this->telemetryManager->driver();
        $this->segment = $telemetry->startSegment('idempotency', 'Idempotency Middleware');
        $telemetry->recordMetric('requests.total');
        $telemetry->addSegmentContext($this->segment, 'request_info', $context);
    }

    /**
     * Check if the request method is applicable for idempotency.
     *
     * @param Request $request
     * @return bool
     */
    private function isMethodApplicable(Request $request): bool
    {
        return in_array(
            $request->method(),
            config('idempotency.methods', ['POST', 'PUT', 'PATCH', 'DELETE'])
        );
    }

    /**
     * Handle request with a method that doesn't require idempotency.
     *
     * @param Closure $next
     * @param Request $request
     * @return mixed
     */
    private function handleSkippedMethod(Closure $next, Request $request): mixed
    {
        $telemetry = $this->telemetryManager->driver();
        $telemetry->addSegmentContext($this->segment, 'skipped', true);
        $telemetry->addSegmentContext($this->segment, 'reason', 'method_not_applicable');
        $telemetry->endSegment($this->segment);
        $telemetry->recordMetric('requests.skipped');

        return $next($request);
    }

    /**
     * Handle request with missing idempotency key.
     *
     * @return JsonResponse
     */
    private function handleMissingKey(): JsonResponse
    {
        $telemetry = $this->telemetryManager->driver();
        $telemetry->addSegmentContext($this->segment, 'error', 'missing_key');
        $telemetry->endSegment($this->segment);
        $telemetry->recordMetric('errors.missing_key');
        return response()->json(['error' => 'Missing Idempotency-Key header'], 400);
    }

    /**
     * Check if the idempotency key is a valid UUID.
     *
     * @param string $key
     * @return bool
     */
    private function isValidUuid(string $key): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key);
    }

    /**
     * Handle request with invalid idempotency key format.
     *
     * @return JsonResponse
     */
    private function handleInvalidKey(): JsonResponse
    {
        $telemetry = $this->telemetryManager->driver();
        $telemetry->addSegmentContext($this->segment, 'error', 'invalid_key_format');
        $telemetry->endSegment($this->segment);
        $telemetry->recordMetric('errors.invalid_key');

        return response()->json(['error' => 'Invalid Idempotency-Key format. Must be a valid UUID.'], 400);
    }

    /**
     * Generate cache keys for the idempotency key.
     *
     * @param string $idempotencyKey
     * @return array
     */
    private function generateCacheKeys(string $idempotencyKey): array
    {
        return [
            'response' => "idempotency:{$idempotencyKey}:response",
            'processing' => "idempotency:{$idempotencyKey}:processing",
            'metadata' => "idempotency:{$idempotencyKey}:metadata",
            'lock' => "idempotency_lock:{$idempotencyKey}",
        ];
    }

    /**
     * Handle request with a cached response.
     *
     * @param array $keys
     * @param string $idempotencyKey
     * @param Request $request
     * @return mixed
     */
    private function handleCachedResponse(array $keys, string $idempotencyKey, Request $request): mixed
    {
        $telemetry = $this->telemetryManager->driver();
        $telemetry->recordMetric('cache.hit');

        $duration = microtime(true) - $this->startTime;
        $telemetry->recordTiming('duplicate_handling_time', $duration * 1000);

        $metadata = $this->updateMetadata($keys['metadata']);

        $telemetry->addSegmentContext($this->segment, 'status', 'duplicate');
        $telemetry->addSegmentContext($this->segment, 'hit_count', $metadata['hit_count']);
        $telemetry->addSegmentContext($this->segment, 'original_request_age', now()->timestamp - $metadata['created_at']);
        $telemetry->addSegmentContext($this->segment, 'handling_time_ms', $duration * 1000);
        $telemetry->endSegment($this->segment);

        $this->checkAlertThreshold($metadata, $idempotencyKey, $request);

        $response = Cache::get($keys['response']);
        $this->addIdempotencyHeaders($response, $idempotencyKey, 'Repeated');

        return $response;
    }

    /**
     * Update metadata for a cached response.
     *
     * @param string $metadataKey
     * @return array
     */
    private function updateMetadata(string $metadataKey): array
    {
        $metadata = Cache::get($metadataKey, [
            'created_at' => now()->subMinutes(1)->timestamp,
            'hit_count' => 0,
        ]);

        $metadata['hit_count']++;
        $metadata['last_hit_at'] = now()->timestamp;

        Cache::put(
            $metadataKey,
            $metadata,
            now()->addMinutes(config('idempotency.ttl', self::CACHE_TTL_MINUTES))
        );

        return $metadata;
    }

    /**
     * Check if the hit count exceeds the alert threshold.
     *
     * @param array $metadata
     * @param string $idempotencyKey
     * @param Request $request
     * @return void
     */
    private function checkAlertThreshold(array $metadata, string $idempotencyKey, Request $request): void
    {
        if ($metadata['hit_count'] >= config('idempotency.alert_threshold', 5)) {
            (new AlertDispatcher())->dispatch(
                EventType::RESPONSE_DUPLICATE,
                [
                    'idempotency_key' => $idempotencyKey,
                    'meta_data' => $metadata,
                    'context' => [
                        'url' => $request->fullUrl(),
                        'method' => $request->method(),
                    ],
                ]
            );
        }
    }

    /**
     * Add idempotency headers to the response.
     *
     * @param $response
     * @param string $idempotencyKey
     * @param string $status
     * @return void
     */
    private function addIdempotencyHeaders($response, string $idempotencyKey, string $status): void
    {
        $response->headers->set('Idempotency-Key', $idempotencyKey);
        $response->headers->set('Idempotency-Status', $status);
    }

    /**
     * Handle a new request that needs to be processed.
     *
     * @param array $keys
     * @param string $idempotencyKey
     * @param Closure $next
     * @param Request $request
     * @return mixed
     * @throws LockTimeoutException
     */
    private function handleNewRequest(array $keys, string $idempotencyKey, Closure $next, Request $request): mixed
    {
        $lock = Cache::lock($keys['lock'], self::LOCK_TIMEOUT_SECONDS);
        $lockAcquired = false;
        $lockAcquisitionStart = microtime(true);

        try {
            $lockAcquired = $lock->block(self::LOCK_WAIT_SECONDS);
            $lockAcquisitionTime = microtime(true) - $lockAcquisitionStart;

            $telemetry = $this->telemetryManager->driver();
            $telemetry->recordTiming('lock_acquisition_time', $lockAcquisitionTime * 1000);

            if (!$lockAcquired) {
                return $this->handleLockAcquisitionFailure(
                    $keys,
                    $idempotencyKey,
                    $request,
                    $lockAcquisitionTime
                );
            }


            $telemetry->recordMetric('lock.successful_acquisition', 1);

            return $this->processRequest($keys, $idempotencyKey, $next, $request);

        } catch (Exception $e) {
            $this->logException($idempotencyKey, $e);
            throw $e;
        } finally {
            Cache::forget($keys['processing']);
            if ($lockAcquired) {
                $lock->release();
            }
        }
    }

    /**
     * Handle failure to acquire a lock.
     *
     * @param array $keys
     * @param string $idempotencyKey
     * @param Request $request
     * @param float $lockAcquisitionTime
     * @return mixed
     */
    private function handleLockAcquisitionFailure(
        array $keys,
        string $idempotencyKey,
        Request $request,
        float $lockAcquisitionTime
    ) {
        $telemetry = $this->telemetryManager->driver();
        $telemetry->recordMetric('lock.failed_acquisition', 1);
        $telemetry->addSegmentContext($this->segment, 'lock_wait_time_ms', $lockAcquisitionTime * 1000);

        // Check if a response was cached while waiting for the lock
        if (Cache::has($keys['response'])) {
            return $this->handleLateCachedResponse($keys, $idempotencyKey);
        }

        // Check if another request is currently processing this key
        if (Cache::has($keys['processing'])) {
            return $this->handleConcurrentConflict($idempotencyKey, $request);
        }

        // Lock inconsistency (failed to acquire, but no cache or processing flag)
        return $this->handleLockInconsistency($idempotencyKey, $request);
    }

    /**
     * Handle a late cache hit after failing to acquire a lock.
     *
     * @param array $keys
     * @param string $idempotencyKey
     * @return Response
     */
    private function handleLateCachedResponse(array $keys, string $idempotencyKey): Response
    {
        $telemetry = $this->telemetryManager->driver();
        $telemetry->recordMetric('cache.late_hit', 1);
        $telemetry->addSegmentContext($this->segment, 'status', 'late_duplicate');
        $telemetry->endSegment($this->segment);

        $response = Cache::get($keys['response']);
        $this->addIdempotencyHeaders($response, $idempotencyKey, 'Repeated');

        return $response;
    }

    /**
     * Handle concurrent processing of the same idempotency key.
     *
     * @param string $idempotencyKey
     * @param Request $request
     * @return JsonResponse
     */
    private function handleConcurrentConflict(string $idempotencyKey, Request $request): JsonResponse
    {
        $telemetry = $this->telemetryManager->driver();
        $telemetry->recordMetric('responses.concurrent_conflict', 1);
        $telemetry->addSegmentContext($this->segment, 'status', 'concurrent_conflict');
        $telemetry->endSegment($this->segment);

        (new AlertDispatcher())->dispatch(
            EventType::CONCURRENT_CONFLICT,
            [
                'idempotency_key' => $idempotencyKey,
                'endpoint' => $request->path(),
            ]
        );

        return response()->json([
            'error' => 'A request with this Idempotency-Key is currently being processed',
        ], 409);
    }

    /**
     * Handle a lock inconsistency.
     *
     * @param string $idempotencyKey
     * @param Request $request
     * @return JsonResponse
     */
    private function handleLockInconsistency(string $idempotencyKey, Request $request): JsonResponse
    {
        $telemetry = $this->telemetryManager->driver();
        $telemetry->recordMetric('errors.lock_inconsistency', 1);
        $telemetry->addSegmentContext($this->segment, 'status', 'lock_inconsistency');
        $telemetry->endSegment($this->segment);

        (new AlertDispatcher())->dispatch(
            EventType::LOCK_INCONSISTENCY,
            [
                'idempotency_key' => $idempotencyKey,
                'endpoint' => $request->path(),
            ]
        );

        return response()->json([
            'error' => 'Could not process request. Please try again.',
        ], 500);
    }

    /**
     * Process a new request.
     *
     * @param array $keys
     * @param string $idempotencyKey
     * @param Closure $next
     * @param Request $request
     * @return mixed
     */
    private function processRequest(array $keys, string $idempotencyKey, Closure $next, Request $request): mixed
    {
        Cache::put($keys['processing'], true, now()->addMinutes(self::PROCESSING_TTL_MINUTES));

        $this->setRequestMetadata($keys['metadata'], $request);

        $telemetry = $this->telemetryManager->driver();
        $telemetry->recordMetric('requests.original', 1);

        $processingStart = microtime(true);
        $response = $next($request);
        $processingTime = microtime(true) - $processingStart;

        $telemetry->recordTiming('request_processing_time', $processingTime * 1000);
        $this->addIdempotencyHeaders($response, $idempotencyKey, 'Original');

        if ($response->isSuccessful()) {
            $this->cacheSuccessfulResponse($keys['response'], $response, $request);
        } else {
            $telemetry->recordMetric('responses.error', 1);
        }

        $telemetry->addSegmentContext($this->segment, 'status', 'original');
        $telemetry->addSegmentContext($this->segment, 'processing_time_ms', $processingTime * 1000);
        $telemetry->endSegment($this->segment);

        return $response;
    }

    /**
     * Set metadata for a new request.
     *
     * @param string $metadataKey
     * @param Request $request
     * @return void
     */
    private function setRequestMetadata(string $metadataKey, Request $request): void
    {
        Cache::put(
            $metadataKey,
            [
                'created_at' => now()->timestamp,
                'hit_count' => 0,
                'endpoint' => $request->path(),
                'user_id' => auth()->check() ? $request->user()->id : null,
                'client_ip' => $request->ip(),
            ],
            now()->addMinutes(config('idempotency.ttl', self::CACHE_TTL_MINUTES))
        );
    }

    /**
     * Cache a successful response.
     *
     * @param string $cacheKey
     * @param Response $response
     * @param Request $request
     * @return void
     */
    private function cacheSuccessfulResponse(string $cacheKey, $response, Request $request): void
    {
        Cache::put(
            $cacheKey,
            $response,
            now()->addMinutes(config('idempotency.ttl', self::CACHE_TTL_MINUTES))
        );

        $responseSize = strlen($response->getContent());
        $telemetry = $this->telemetryManager->driver();
        $telemetry->recordSize('response_size', $responseSize);

        $this->checkResponseSizeWarning($responseSize, $request);
    }

    /**
     * Check if response size exceeds the warning threshold.
     *
     * @param int $responseSize
     * @param Request $request
     * @return void
     */
    private function checkResponseSizeWarning(int $responseSize, Request $request): void
    {
        if ($responseSize > config('idempotency.size_warning', 1024 * 100)) {
            (new AlertDispatcher())->dispatch(
                EventType::SIZE_WARNING,
                [
                    'size_bytes' => $responseSize,
                    'size_kb' => round($responseSize / 1024, 2).' KB',
                    'context' => [
                        'url' => $request->fullUrl(),
                        'method' => $request->method(),
                    ],
                ]
            );
        }
    }

    /**
     * Log an exception.
     *
     * @param string $idempotencyKey
     * @param Exception $exception
     * @return void
     */
    private function logException(string $idempotencyKey, Exception $exception): void
    {
        (new AlertDispatcher())->dispatch(
            EventType::EXCEPTION_THROWN,
            [
                'idempotency_key' => $idempotencyKey,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]
        );
    }
}