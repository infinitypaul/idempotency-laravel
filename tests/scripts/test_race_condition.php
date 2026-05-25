#!/usr/bin/env php
<?php

/**
 * Race condition test for the idempotency middleware.
 *
 * Starts a testbench server with file-based cache, fires N concurrent requests
 * with the same Idempotency-Key using curl_multi, and asserts that at most 1
 * request is processed as "Original".
 *
 * Usage:
 *   php tests/scripts/test_race_condition.php [--count=5] [--rounds=3]
 *
 * Exit codes:
 *   0 = all rounds passed
 *   1 = at least one round had duplicate "Original" responses
 */

$options = getopt('', ['count:', 'rounds:', 'port:']);
$concurrentCount = (int) ($options['count'] ?? 5);
$rounds = (int) ($options['rounds'] ?? 3);
$port = (int) ($options['port'] ?? rand(8200, 8999));

$projectRoot = realpath(__DIR__ . '/../../');
$testbench = $projectRoot . '/vendor/bin/testbench';
$host = '127.0.0.1';

function startServer(string $testbench, string $host, int $port, string $projectRoot): array
{
    $cacheDir = $projectRoot . '/vendor/orchestra/testbench-core/laravel/storage/framework/cache/data';
    if (is_dir($cacheDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
    }

    $cmd = sprintf(
        '%s serve --host=%s --port=%d --no-reload',
        escapeshellarg($testbench),
        $host,
        $port
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes, $projectRoot);

    if (!is_resource($process)) {
        fwrite(STDERR, "Failed to start testbench server\n");
        exit(1);
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $ready = false;
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($conn) {
            fclose($conn);
            $ready = true;
            break;
        }
        usleep(200_000);
    }

    if (!$ready) {
        $stderr = stream_get_contents($pipes[2]);
        $stdout = stream_get_contents($pipes[1]);
        fwrite(STDERR, "Server failed to start within 15s\n");
        fwrite(STDERR, "stdout: {$stdout}\nstderr: {$stderr}\n");
        proc_terminate($process);
        exit(1);
    }

    return ['process' => $process, 'pipes' => $pipes];
}

function stopServer(array $server): void
{
    foreach ($server['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($server['process'], 9);
    proc_close($server['process']);
}

function fireConcurrentRequests(string $url, array $payload, string $idempotencyKey, int $count): array
{
    $multiHandle = curl_multi_init();
    $handles = [];

    $jsonPayload = json_encode($payload);

    for ($i = 0; $i < $count; $i++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                "Idempotency-Key: {$idempotencyKey}",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_multi_add_handle($multiHandle, $ch);
        $handles[] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle, 0.1);
    } while ($running > 0);

    $results = [];
    foreach ($handles as $i => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $idempotencyStatus = 'N/A';
        if (preg_match('/Idempotency-Status:\s*(\S+)/i', $headers, $m)) {
            $idempotencyStatus = $m[1];
        }

        $results[] = [
            'request_id' => $i + 1,
            'http_code' => $httpCode,
            'idempotency_status' => $idempotencyStatus,
            'body' => json_decode($body, true) ?? [],
        ];

        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiHandle);

    return $results;
}

function generateUuid(): string
{
    return sprintf(
        '%s-%s-4%s-%s%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        substr(bin2hex(random_bytes(2)), 1),
        dechex(mt_rand(8, 11)),
        substr(bin2hex(random_bytes(2)), 1),
        bin2hex(random_bytes(6))
    );
}

// --- Main ---

echo "\n" . str_repeat('=', 60) . "\n";
echo "Idempotency Race Condition Test\n";
echo str_repeat('=', 60) . "\n";
echo "Concurrent requests per round: {$concurrentCount}\n";
echo "Rounds: {$rounds}\n";
echo str_repeat('=', 60) . "\n\n";

echo "Starting testbench server on port {$port}...\n";
$server = startServer($testbench, $host, $port, $projectRoot);
$baseUrl = "http://{$host}:{$port}/api/test-endpoint";
echo "Server ready at http://{$host}:{$port}\n\n";

$allPassed = true;

for ($round = 1; $round <= $rounds; $round++) {
    $idempotencyKey = generateUuid();
    $payload = ['amount' => 6000, 'currency' => 'NGN'];

    echo "Round {$round}/{$rounds} (Key: {$idempotencyKey})\n";
    echo str_repeat('-', 60) . "\n";

    $results = fireConcurrentRequests($baseUrl, $payload, $idempotencyKey, $concurrentCount);

    foreach ($results as $r) {
        echo sprintf(
            "  Request %d: HTTP %d | Idempotency-Status: %s\n",
            $r['request_id'],
            $r['http_code'],
            $r['idempotency_status']
        );
    }

    $originals = array_filter($results, fn($r) => $r['idempotency_status'] === 'Original');
    $repeated = array_filter($results, fn($r) => $r['idempotency_status'] === 'Repeated');
    $errors = array_filter($results, fn($r) => $r['http_code'] >= 500);

    $originalCount = count($originals);
    $repeatedCount = count($repeated);
    $errorCount = count($errors);

    echo "\n  Original: {$originalCount} | Repeated: {$repeatedCount} | Errors: {$errorCount}\n";

    if ($originalCount > 1) {
        echo "  ** FAIL: {$originalCount} requests processed as Original (expected at most 1) **\n";
        $allPassed = false;
    } elseif ($originalCount === 0 && $repeatedCount === 0) {
        echo "  ** WARN: No Original or Repeated responses — middleware may not be running **\n";
        $allPassed = false;
    } else {
        echo "  PASS\n";
    }

    echo "\n";
}

stopServer($server);

echo str_repeat('=', 60) . "\n";
if ($allPassed) {
    echo "RESULT: PASS — Idempotency middleware correctly prevents duplicate processing\n";
} else {
    echo "RESULT: FAIL — Duplicate processing detected\n";
}
echo str_repeat('=', 60) . "\n\n";

exit($allPassed ? 0 : 1);
