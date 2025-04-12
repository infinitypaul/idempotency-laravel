<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Idempotency Settings
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration settings for the idempotency middleware.
    | You can customize the behavior of idempotency handling by modifying
    | these values according to your application's needs.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | HTTP Methods
    |--------------------------------------------------------------------------
    |
    | Define which HTTP methods should be considered for idempotency handling.
    | By default, only state-changing methods (POST, PUT, PATCH, DELETE) are included.
    |
    */
    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    /*
    |--------------------------------------------------------------------------
    | Cache Time-to-Live (TTL)
    |--------------------------------------------------------------------------
    |
    | The number of minutes to store idempotency responses in the cache.
    | This determines how long a client can use the same idempotency key
    | to receive the cached response.
    |
    */
    'ttl' => env('IDEMPOTENCY_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Alert Threshold
    |--------------------------------------------------------------------------
    |
    | The number of times a single idempotency key can be reused before
    | triggering an alert. This helps detect potential replay attacks
    | or integration issues with clients.
    |
    */
    'alert_threshold' => env('IDEMPOTENCY_ALERT_THRESHOLD', 5),

    /*
    |--------------------------------------------------------------------------
    | Response Size Warning
    |--------------------------------------------------------------------------
    |
    | The maximum size (in bytes) of a response before triggering a warning.
    | Large cached responses can consume significant memory.
    | Default is 100KB (102,400 bytes).
    |
    */
    'size_warning' => env('IDEMPOTENCY_SIZE_WARNING', 1024 * 100),


    /*
    |--------------------------------------------------------------------------
    | Lock Timeout
    |--------------------------------------------------------------------------
    |
    | The maximum time (in seconds) to hold a lock while processing a request.
    | This prevents deadlocks if processing unexpectedly hangs.
    |
    */
    'lock_timeout' => env('IDEMPOTENCY_LOCK_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Lock Wait Time
    |--------------------------------------------------------------------------
    |
    | The maximum time (in seconds) to wait to acquire a lock.
    | This determines how long to wait for concurrent requests with the
    | same idempotency key to finish processing.
    |
    */
    'lock_wait' => env('IDEMPOTENCY_LOCK_WAIT', 5),

    /*
    |--------------------------------------------------------------------------
    | Processing TTL
    |--------------------------------------------------------------------------
    |
    | The number of minutes to keep the processing flag for an idempotency key.
    | This helps detect and resolve orphaned processing states.
    |
    */
    'processing_ttl' => env('IDEMPOTENCY_PROCESSING_TTL', 5),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Global switch to enable/disable idempotency functionality.
    | Useful for bypassing idempotency in certain environments like testing.
    |
    */
    'enabled' => env('IDEMPOTENCY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Header Name
    |--------------------------------------------------------------------------
    |
    | The HTTP header name to use for idempotency keys.
    | Default follows the standard practice of using 'Idempotency-Key'.
    |
    */
    'header_name' => env('IDEMPOTENCY_HEADER_NAME', 'Idempotency-Key'),


    /*
    |--------------------------------------------------------------------------
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | Configuration for telemetry features.
    |
    */
    'telemetry' => [
        // Enable or disable telemetry for idempotency operations
        'enabled' => env('IDEMPOTENCY_TELEMETRY_ENABLED', true),

        // Default driver to use for telemetry
        'driver' => env('IDEMPOTENCY_TELEMETRY_DRIVER', 'null'),

        // Available telemetry drivers and their configurations
        'custom_driver_class' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | Configuration for alerting features.
    |
    */
    'alerts' => [
        // Alert threshold
        'threshold' => env('IDEMPOTENCY_ALERTS_THRESHOLD', 60),

    ],

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | Configuration for idempotency key validation.
    |
    */
    'validation' => [
        // The pattern to validate idempotency keys against
        // Default is a UUID pattern
        'pattern' => env('IDEMPOTENCY_KEY_PATTERN', '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'),

        // Maximum length for idempotency keys
        'max_length' => env('IDEMPOTENCY_KEY_MAX_LENGTH', 255),
    ],
];