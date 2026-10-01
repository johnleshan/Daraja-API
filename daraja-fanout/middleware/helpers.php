<?php
declare(strict_types=1);

function envv(string $k, ?string $d = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $envPath = __DIR__ . '/.env';
        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $parts = explode('=', $line, 2);
                $cache[trim($parts[0])] = trim($parts[1] ?? '');
            }
        }
    }
    if (array_key_exists($k, $cache)) return $cache[$k];
    if (array_key_exists($k, $_ENV)) return $_ENV[$k];
    $v = getenv($k);
    return $v === false ? $d : $v;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function read_body(): string
{
    return file_get_contents('php://input') ?: '';
}

function log_raw(string $body, string $tag = 'in'): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents(
        $dir . '/' . $tag . '_' . gmdate('Y-m-d') . '.json',
        date('c') . "\t" . $body . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function queue_path(): string
{
    return __DIR__ . '/logs/retry_queue.json';
}

function queue_load(): array
{
    if (!is_file(queue_path()) || filesize(queue_path()) === 0) return [];
    $rows = json_decode((string)file_get_contents(queue_path()), true);
    if (!is_array($rows)) {
        log_raw(json_encode(['error' => 'corrupt retry queue, reset', 'at' => date('c')]), 'warn');
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r) || empty($r['url']) || !isset($r['body'])) continue;
        $out[] = $r;
    }
    return $out;
}

function queue_save(array $rows): void
{
    file_put_contents(queue_path(), json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Fan out a payload to all configured system URLs.
 * Waits up to $waitMs for responses; anything unfinished or failed is queued for retry.
 * Returns true only if every target reported HTTP 200.
 */
function fan_out(string $body, string $signature, int $waitMs = 2500): array
{
    $urlsRaw = (string)envv('TARGET_URLS', '');
    $targets = array_values(array_filter(array_map('trim', explode(',', $urlsRaw))));
    $report = [];

    if (!$targets) {
        log_raw(json_encode(['body' => json_decode($body, true), 'error' => 'no TARGET_URLS configured']), 'drop');
        return ['ok' => false, 'report' => [['url' => '?', 'delivered' => false, 'note' => 'no TARGET_URLS configured']]];
    }

    $headers = [
        'Content-Type: application/json',
        'X-Signature: ' . $signature,
        'X-Forwarded-By: daraja-middleware',
        'X-Queue: true',
    ];

    $mh = curl_multi_init();
    $handles = [];
    foreach ($targets as $i => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 5000,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = ['url' => $url, 'ch' => $ch, 'done' => false, 'fail' => null];
    }

    $running = 0;
    $deadline = microtime(true) + $waitMs / 1000;
    do {
        curl_multi_exec($mh, $running);
        if (curl_multi_select($mh, 0.1) === -1) usleep(5000);
        if (!$running) break;
    } while (microtime(true) < $deadline);

    foreach ($handles as $i => $h) {
        $info = curl_getinfo($h['ch']);
        $http = (int)($info['http_code'] ?? 0);
        if ($http === 200) {
            $report[] = ['url' => $h['url'], 'delivered' => true, 'http' => 200];
        } elseif ($http > 0) {
            $report[] = ['url' => $h['url'], 'delivered' => false, 'http' => $http];
            queue_failed($body, $signature, $h['url']);
        } else {
            $report[] = ['url' => $h['url'], 'delivered' => false, 'err' => curl_error($h['ch'])];
            queue_failed($body, $signature, $h['url']);
        }
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
    }
    curl_multi_close($mh);

    $allOk = count($report) === count(array_filter($report, fn($r) => !empty($r['delivered'])));
    return ['ok' => $allOk, 'report' => $report];
}

function queue_failed(string $body, string $signature, string $url): void
{
    $rows = queue_load();
    $rows[] = [
        'url' => $url,
        'body' => $body,
        'signature' => $signature,
        'attempts' => 0,
        'queued_at' => date('c'),
    ];
    queue_save($rows);
}

/** Retry queue with exponential backoff by attempts count (in minutes). */
function retry_once(): array
{
    $rows = queue_load();
    $kept = [];
    $report = [];
    $now = time();

    foreach ($rows as $r) {
        $r['attempts'] = (int)($r['attempts'] ?? 0);
        if ($r['attempts'] >= 20) {
            log_raw(json_encode($r), 'dead');
            $report[] = ['url' => $r['url'], 'action' => 'dead-letter', 'attempts' => $r['attempts']];
            continue;
        }
        $minBackoff = [0, 1, 2, 5, 10, 20, 30, 60, 60, 60, 120, 120, 240, 240, 360][$r['attempts']] ?? 720;
        $ref = (string)($r['last_try'] ?? $r['queued_at'] ?? '1970-01-01T00:00:00Z');
        if ($now - strtotime($ref) < $minBackoff * 60) {
            $kept[] = $r;
            continue;
        }
        $ch = curl_init($r['url']);
        $sig = (string)envv('HMAC_SECRET', '') !== ''
            ? base64_encode(hash_hmac('sha256', (string)$r['body'], (string)envv('HMAC_SECRET', ''), true))
            : (string)($r['signature'] ?? '');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $r['body'],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Signature: ' . $sig,
                'X-Forwarded-By: daraja-middleware',
                'X-Retry: ' . $r['attempts'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 8000,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($http === 200) {
            $report[] = ['url' => $r['url'], 'action' => 'delivered', 'attempts' => $r['attempts'] + 1];
        } else {
            $r['attempts']++;
            $r['last_try'] = date('c');
            log_raw(json_encode(['url' => $r['url'], 'http' => $http, 'err' => $err, 'response' => (string)$resp]), 'retryfail');
            $kept[] = $r;
            $report[] = ['url' => $r['url'], 'action' => 're-queued', 'attempts' => $r['attempts'], 'http' => $http];
        }
    }
    queue_save($kept);
    return $report;
}

/** Get Daraja OAuth token (cached in data/.tok). */
function oauth_token(): ?string
{
    $base = envv('MPESA_ENV', 'sandbox') === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    $cacheFile = __DIR__ . '/data/.tok';
    if (is_file($cacheFile)) {
        $d = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($d) && ($d['exp'] ?? 0) > time() + 60 && ($d['base'] ?? '') === $base) {
            return (string)$d['token'];
        }
    }
    $ck = (string)envv('CONSUMER_KEY', '');
    $cs = (string)envv('CONSUMER_SECRET', '');
    if ($ck === '' || $cs === '') return null;
    $ch = curl_init($base . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $ck . ':' . $cs,
        CURLOPT_TIMEOUT_MS => 10000,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = json_decode((string)curl_exec($ch), true);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || empty($resp['access_token'])) return null;
    $token = (string)$resp['access_token'];
    if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
    file_put_contents($cacheFile, json_encode(['token' => $token, 'exp' => time() + 3500, 'base' => $base]), LOCK_EX);
    return $token;
}

/** Register this middleware's URLs on Daraja (Confirmation + Validation) for the paybill shortcode. */
function register_urls(string $shortcode): array
{
    $token = oauth_token();
    if (!$token) return ['ok' => false, 'error' => 'oauth failed'];
    $base = envv('MPESA_ENV', 'sandbox') === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    $payload = [
        'ShortCode' => $shortcode,
        'ResponseType' => 'Completed',
        'ConfirmationURL' => envv('PUBLIC_URL', '') . '/callback',
        'ValidationURL' => envv('PUBLIC_URL', '') . '/callback',
    ];
    $ch = curl_init($base . '/mpesa/c2b/v1/registerurl');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 15000,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    ]);
    $resp = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => $http === 200, 'http' => $http, 'sent' => $payload, 'response' => json_decode($resp, true) ?: $resp];
}
