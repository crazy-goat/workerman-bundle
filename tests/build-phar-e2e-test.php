#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Integration test: full Symfony app → build PHAR → start/stop/reload server → HTTP requests.
 */

$exitCode = 0;
$appDir = __DIR__ . '/../e2e';

function pharExec(string $pharPath, string $cmd, bool $quiet = false): array
{
    $c = sprintf('APP_RUNTIME=CrazyGoat\WorkermanBundle\Runtime APP_ENV=test %s -d phar.readonly=0 %s workerman:server %s 2>&1',
        PHP_BINARY, escapeshellarg($pharPath), $cmd);
    exec($c, $out, $ret);
    return [$out, $ret];
}

function httpGet(string $url, int $timeout = 5): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, (string)$body, $err];
}

function assertEq(mixed $expected, mixed $actual, string $msg): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException("{$msg}: expected {$expected}, got " . var_export($actual, true));
    }
}

try {
    echo "=== PHAR E2E Integration Test ===\n\n";

    // ---- 1. Install ----
    echo "1) Installing dependencies...\n";
    exec('composer install --no-interaction --working-dir=' . escapeshellarg($appDir) . ' 2>&1', $out, $ret);
    if ($ret !== 0) throw new \RuntimeException('Composer install failed');

    // ---- 2. Build PHAR ----
    echo "\n2) Building PHAR...\n";
    $pharPath = $appDir . '/build/test-app.phar';
    exec(sprintf('php -d phar.readonly=0 %s/console workerman:build:phar -o %s/build --filename test-app.phar 2>&1',
        escapeshellarg($appDir), escapeshellarg($appDir)), $out, $ret);
    if ($ret !== 0 || !file_exists($pharPath)) throw new \RuntimeException('PHAR build failed');
    echo "   PHAR: {$pharPath} (" . number_format(filesize($pharPath)) . " bytes)\n";

    // ---- 3. Start ----
    echo "\n3) Starting server...\n";
    [$out, $ret] = pharExec($pharPath, 'start -d');
    echo "   " . (trim(implode("\n   ", $out)) ?: 'Started') . "\n";
    sleep(2);

    // ---- 4. HTTP ----
    echo "\n4) HTTP GET /health...\n";
    [$code, $body] = httpGet('http://127.0.0.1:8887/health');
    assertEq(200, $code, 'HTTP status');
    $data = json_decode((string) $body, true);
    assertEq('ok', $data['status'] ?? null, 'Response status');
    echo "   HTTP/{$code}: {$body}\n";

    // ---- 5. Reload ----
    echo "\n5) Reloading server...\n";
    [$out, $ret] = pharExec($pharPath, 'reload');
    echo "   " . trim(implode("\n   ", $out)) . "\n";
    sleep(1);

    // ---- 6. HTTP after reload ----
    echo "\n6) HTTP after reload...\n";
    [$code, $body] = httpGet('http://127.0.0.1:8887/health');
    assertEq(200, $code, 'HTTP status after reload');
    echo "   HTTP/{$code}: {$body}\n";

    // ---- 7. Restart ----
    echo "\n7) Restarting server...\n";
    [$out, $ret] = pharExec($pharPath, 'restart -d');
    echo "   " . (trim(implode("\n   ", $out)) ?: 'Restarted') . "\n";
    sleep(2);

    // ---- 8. HTTP after restart ----
    echo "\n8) HTTP after restart...\n";
    [$code, $body] = httpGet('http://127.0.0.1:8887/health');
    assertEq(200, $code, 'HTTP status after restart');
    echo "   HTTP/{$code}: {$body}\n";

    // ---- 9. Stop ----
    echo "\n9) Stopping server...\n";
    [$out, $ret] = pharExec($pharPath, 'stop');
    echo "   " . trim(implode("\n   ", $out)) . "\n";
    sleep(1);

    // ---- 10. HTTP after stop (should fail) ----
    echo "\n10) Verifying server is stopped...\n";
    [$code, , $err] = httpGet('http://127.0.0.1:8887/health', 2);
    // Connection refused is expected — if we got a response, something's wrong
    if (($err === '' || $code !== 0) && $code !== 0) {
        throw new \RuntimeException('Server still responding after stop (HTTP ' . $code . ')');
    }
    echo "   Server stopped (connection refused) — OK\n";

    echo "\n=== PASSED ===\n";

} catch (\Throwable $e) {
    echo "\n!!! FAILED: {$e->getMessage()}\n";
    $exitCode = 1;
} finally {
    // Ensure server is stopped
    if (isset($pharPath) && file_exists($pharPath)) {
        exec(sprintf('APP_RUNTIME=CrazyGoat\WorkermanBundle\Runtime %s -d phar.readonly=0 %s workerman:server stop 2>&1',
            PHP_BINARY, escapeshellarg($pharPath)));
        sleep(1);
    }
}

exit($exitCode);
