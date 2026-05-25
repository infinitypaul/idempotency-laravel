<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Infinitypaul\Idempotency\Middleware\EnsureIdempotency;

Route::middleware(EnsureIdempotency::class)->post('/test-endpoint', function (Request $request) {
    usleep(200_000); // 200ms simulated processing to widen the race window
    return response()->json([
        'message' => 'processed',
        'amount' => $request->input('amount'),
        'pid' => getmypid(),
    ]);
});
