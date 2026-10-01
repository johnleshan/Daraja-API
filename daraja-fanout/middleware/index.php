<?php
declare(strict_types=1);
date_default_timezone_set('Africa/Nairobi');
require __DIR__ . '/helpers.php';

$ROUTE_MAP = [
    'callback' => '/callback',
    'retry' => '/retry',
    'registerurls' => '/register-urls',
    'health' => '/health',
];
$routeParam = isset($_GET['route']) ? strtolower((string)$_GET['route']) : null;
$path = ($routeParam !== null && isset($ROUTE_MAP[$routeParam]))
    ? $ROUTE_MAP[$routeParam]
    : (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_ends_with($path, '/index.php') || $path === '/index.php') {
    $path = '/';
}
if (isset($_GET['route']) && !isset($ROUTE_MAP[$routeParam])) {
    $path = (string)$_GET['route'] === '' ? '/' : '/' . ltrim((string)$_GET['route'], '/');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && ($path === '/health' || $path === '/')) {
    json_out([
        'service' => 'daraja-middleware',
        'status' => 'ok',
        'time' => date('c'),
        'targets' => array_filter(array_map('trim', explode(',', (string)envv('TARGET_URLS', '')))),
        'retry_queue' => count(queue_load()),
    ]);
    exit;
}

if ($method === 'GET' && str_starts_with($path, '/retry')) {
    json_out(['retried' => retry_once()]);
    exit;
}

if ($method === 'POST' && str_starts_with($path, '/register-urls')) {
    $in = json_decode(read_body(), true) ?: [];
    $shortcode = (string)($in['ShortCode'] ?? envv('PAYBILL', ''));
    if ($shortcode === '') {
        json_out(['ok' => false, 'error' => 'shortcode required (body ShortCode or env PAYBILL)'], 400);
        exit;
    }
    $result = register_urls($shortcode);
    json_out($result, $result['ok'] ? 200 : 502);
    exit;
}

if ($method === 'POST' && str_starts_with($path, '/callback')) {
    $body = read_body();
    log_raw($body);

    $sig = (string)envv('HMAC_SECRET', '') !== ''
        ? base64_encode(hash_hmac('sha256', $body, (string)envv('HMAC_SECRET', ''), true))
        : '';

    $report = fan_out($body, $sig);

    $decoded = json_decode($body, true) ?: [];
    if (isset($decoded['Body']['stkCallback'])) {
        $ok = $report['ok'];
        json_out([
            'ResponseCode' => $ok ? 0 : 1,
            'ResponseDesc' => $ok ? 'success' : 'broadcast had failures (queued for retry)',
            'report' => $report['report'],
        ]);
        exit;
    }
    json_out([
        'ResultCode' => 0,
        'ResultDesc' => 'Accepted',
        'report' => $report['report'],
    ]);
    exit;
}

http_response_code(404);
json_out(['error' => 'not found', 'path' => $path]);
