# Idempotency for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/infinitypaul/idempotency-laravel.svg?style=flat-square)](https://packagist.org/packages/infinitypaul/idempotency-laravel)

A production-ready Laravel middleware for implementing idempotency in your API requests. Safely retry API calls without worrying about duplicate processing.

## What Is Idempotency?

Idempotency ensures that an API operation produces the same result regardless of how many times it is executed. This is critical for payment processing, order submissions, and other operations where duplicate execution could have unintended consequences.

## Features

- **Robust Cache Mechanism**: Reliably stores and serves cached responses
- **Lock-Based Concurrency Control**: Prevents race conditions with distributed locks
- **Comprehensive Telemetry**: Track and monitor idempotency operations
- **Alert System**: Get notified about suspicious activity
- **Payload Validation**: Detect when the same key is used with different payloads
- **Detailed Logging**: Easily debug idempotency issues

## Installation

You can install the package via composer:

```bash
composer require infinitypaul/idempotency-laravel
```

## Configuration

```bash
php artisan vendor:publish --provider="Infinitypaul\Idempotency\IdempotencyServiceProvider"
```
This will create a config/idempotency.php file with the following options:

```php
return [
    // Enable or disable idempotency functionality
    'enabled' => env('IDEMPOTENCY_ENABLED', true),
    
    // HTTP methods that should be considered for idempotency
    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],
    
    // How long to cache idempotent responses (in minutes)
    'ttl' => env('IDEMPOTENCY_TTL', 1440), // 24 hours
    
    // Validation settings
    'validation' => [
        // Pattern to validate idempotency keys (UUID format by default)
        'pattern' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
        
        // Maximum response size to cache (in bytes)
        'max_length' => env('IDEMPOTENCY_MAX_LENGTH', 10485760), // 10MB
    ],
    
    // Alert settings
    'alert' => [
        // Number of hits before sending an alert
        'threshold' => env('IDEMPOTENCY_ALERT_THRESHOLD', 5),
    ],
    
    // Telemetry settings
    'telemetry' => [
        // Default telemetry driver
        'driver' => env('IDEMPOTENCY_TELEMETRY_DRIVER', 'inspector'),
        
        // Custom driver class if using 'custom' driver
        'custom_driver_class' => null,
    ],
];
```
## Usage
Add the middleware to your routes or route groups in your routes/api.php file:
```php
Route::middleware(['auth:api', \Infinitypaul\Idempotency\Middleware\EnsureIdempotency::class])
    ->group(function () {
        Route::post('/payments', [PaymentController::class, 'store']);
        // Other routes...
    });
```
### Using With Requests
To make an idempotent request, clients should include an Idempotency-Key header with a unique UUID:

```http request
POST /api/payments HTTP/1.1
Content-Type: application/json
Idempotency-Key: 123e4567-e89b-12d3-a456-426614174000

{
  "amount": 1000,
  "currency": "USD",
  "description": "Order #1234"
}
```
If the same idempotency key is used again with the same payload, the original response will be returned without re-executing the operation.

## Response Headers
The middleware adds these headers to responses:

- `Idempotency-Key`: The key used for the request
- `Idempotency-Status`: Either `Original` (first request) or `Repeated` (cached response)

## Telemetry
The package provides built-in telemetry through various service. The telemetry records:

- Request processing time
- Cache hits and misses
- Lock acquisition time
- Response sizes
- Error rates

## Telemetry Drivers
I intend to add more drivers in my free time

- Inspector (https://inspector.dev/)

## Custom Driver
To use a custom telemetry driver, implement the TelemetryDriver interface:

```php
<?php

namespace App\Telemetry;

use Infinitypaul\Idempotency\Telemetry\TelemetryDriver;

class CustomTelemetryDriver implements TelemetryDriver
{
    // Implement the required methods...
}
```

Then update your configuration:

```php
'telemetry' => [
    'driver' => 'custom',
    'custom_driver_class' => \App\Telemetry\CustomTelemetryDriver::class,
],
```

## Advanced Usage
### Custom Events
The package dispatches an events that you can listen for:

```php
\Infinitypaul\Idempotency\Events\IdempotencyAlertFired::class
```