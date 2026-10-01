<?php
declare(strict_types=1);
date_default_timezone_set('Africa/Nairobi');

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return $n === '' || strncmp($h, $n, strlen($n)) === 0; }
}

function envv(string $k, ?string $d = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $envPath = __DIR__ . '/.env';
        if (is_file($envPath)) {
            $raw = (string)file_get_contents($envPath);
            if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
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

function require_auth(): bool
{
    $user = (string)envv('API_USER', '');
    $pass = (string)envv('API_PASS', '');
    if ($user === '' || $pass === '') return true;
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) && $_SERVER['PHP_AUTH_USER'] === $user && hash_equals($pass, (string)$_SERVER['PHP_AUTH_PW'])) return true;
    header('WWW-Authenticate: Basic realm="Daraja Ledger"');
    http_response_code(401);
    return false;
}

function db(): ?PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $host = (string)envv('DB_HOST', '');
    $name = (string)envv('DB_NAME', '');
    $user = (string)envv('DB_USER', '');
    $pass = (string)envv('DB_PASS', '');
    if ($host === '' || $name === '' || $user === '') return null;
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        json_out(['error' => 'db connect failed', 'detail' => $e->getMessage()]);
        exit;
    }
    return $pdo;
}

function data_dir(): string
{
    if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0775, true);
    return __DIR__ . '/data';
}

function log_raw(string $body, string $tag = 'in'): void
{
    file_put_contents(data_dir() . '/' . $tag . '_' . gmdate('Y-m-d') . '.json', date('c') . "\t" . $body . "\n", FILE_APPEND | LOCK_EX);
}

function normalize(array $d): ?array
{
    if (isset($d['Body']['stkCallback'])) {
        $cb = $d['Body']['stkCallback'];
        $item = [];
        foreach (($cb['CallbackMetadata']['Item'] ?? []) as $it) {
            $item[(string)$it['Name']] = $it['Value'] ?? null;
        }
        $ok = (int)($cb['ResultCode'] ?? 1) === 0;
        return [
            'id' => (string)($cb['CheckoutRequestID'] ?? uniqid('STK-')),
            'type' => 'stk_push',
            'receipt' => (string)($item['MpesaReceiptNumber'] ?? ''),
            'trans_time' => isset($item['TransactionDate'])
                ? preg_replace('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', '$1-$2-$3 $4:$5:$6', (string)$item['TransactionDate'])
                : date('Y-m-d H:i:s'),
            'amount' => (float)($item['Amount'] ?? 0),
            'phone' => (string)($item['PhoneNumber'] ?? ''),
            'payer_name' => '',
            'account_ref' => (string)($cb['MerchantRequestID'] ?? ''),
            'status' => $ok ? 'completed' : 'failed',
            'result_desc' => (string)($cb['ResultDesc'] ?? ''),
            'raw' => $d,
        ];
    }
    if (isset($d['TransactionType']) || isset($d['TransactionID'])) {
        $time = preg_replace('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', '$1-$2-$3 $4:$5:$6', (string)($d['TransTime'] ?? ''));
        return [
            'id' => (string)($d['TransactionID'] ?? uniqid('C2B-')),
            'type' => 'c2b',
            'receipt' => (string)($d['TransactionID'] ?? ''),
            'trans_time' => $time ?: date('Y-m-d H:i:s'),
            'amount' => (float)($d['TransactionAmount'] ?? 0),
            'phone' => (string)($d['MSISDN'] ?? ''),
            'payer_name' => trim(($d['FirstName'] ?? '') . ' ' . ($d['MiddleName'] ?? '') . ' ' . ($d['LastName'] ?? '')),
            'account_ref' => (string)($d['BillRefNumber'] ?? ''),
            'status' => 'completed',
            'result_desc' => 'Paybill ' . ($d['BusinessShortCode'] ?? '') . (isset($d['OrgAccountBalance']) ? ', bal ' . $d['OrgAccountBalance'] : ''),
            'raw' => $d,
        ];
    }
    return null;
}

function upsert(array $r, string $system = 'ledger'): array
{
    if ($pdo = db()) {
        $st = $pdo->prepare('INSERT INTO transactions
            (id, system, type, receipt, trans_time, amount, phone, payer_name, account_ref, status, result_desc, raw)
            VALUES (:id, :system, :type, :receipt, :trans_time, :amount, :phone, :payer_name, :account_ref, :status, :result_desc, :raw)
            ON DUPLICATE KEY UPDATE
              receipt = VALUES(receipt), status = VALUES(status), result_desc = VALUES(result_desc),
              trans_time = VALUES(trans_time), amount = VALUES(amount), phone = VALUES(phone),
              payer_name = VALUES(payer_name), account_ref = VALUES(account_ref), raw = VALUES(raw)');
        $st->execute([
            ':id' => $r['id'], ':system' => $system, ':type' => $r['type'], ':receipt' => $r['receipt'],
            ':trans_time' => $r['trans_time'], ':amount' => $r['amount'], ':phone' => $r['phone'],
            ':payer_name' => $r['payer_name'], ':account_ref' => $r['account_ref'],
            ':status' => $r['status'], ':result_desc' => $r['result_desc'],
            ':raw' => json_encode($r['raw'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
        return ['stored' => $pdo->query('SELECT COUNT(*) c FROM transactions')->fetchColumn()];
    }

    $path = data_dir() . '/transactions.json';
    $raw = is_file($path) ? (string)file_get_contents($path) : '[]';
    $rows = json_decode($raw, true);
    if (!is_array($rows)) {
        log_raw($raw, 'corrupt');
        $rows = [];
    }
    $found = false;
    foreach ($rows as $i => $row) {
        if (($row['id'] ?? null) === $r['id']) {
            $rows[$i] = array_merge($row, $r);
            $found = true;
            break;
        }
    }
    if (!$found) $rows[] = $r;
    usort($rows, fn($a, $b) => strcmp((string)($b['trans_time'] ?? ''), (string)($a['trans_time'] ?? '')));
    file_put_contents($path, json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $csv = data_dir() . '/transactions.csv';
    $fp = fopen($csv, 'w');
    if ($fp) {
        fputcsv($fp, ['id', 'type', 'receipt', 'time', 'amount', 'phone', 'name', 'ref', 'status', 'desc']);
        foreach ($rows as $row) {
            fputcsv($fp, [
                (string)($row['id'] ?? ''), (string)($row['type'] ?? ''), (string)($row['receipt'] ?? ''),
                (string)($row['trans_time'] ?? ''), (string)($row['amount'] ?? ''), (string)($row['phone'] ?? ''),
                (string)($row['payer_name'] ?? ''), (string)($row['account_ref'] ?? ''),
                (string)($row['status'] ?? ''), (string)($row['result_desc'] ?? ''),
            ]);
        }
        fclose($fp);
    }
    return ['stored' => 'json', 'count' => count($rows)];
}

function list_tx(string $since = '', int $limit = 500): array
{
    if ($pdo = db()) {
        if ($since !== '') {
            $st = $pdo->prepare('SELECT id, type, receipt, trans_time, amount, phone, payer_name, account_ref, status, result_desc FROM transactions WHERE trans_time > :s ORDER BY trans_time ASC LIMIT :l');
            $st->execute([':s' => $since, ':l' => $limit]);
        } else {
            $st = $pdo->prepare('SELECT id, type, receipt, trans_time, amount, phone, payer_name, account_ref, status, result_desc FROM transactions ORDER BY trans_time ASC LIMIT :l');
            $st->execute([':l' => $limit]);
        }
        return $st->fetchAll();
    }
    $rows = json_decode((string)@file_get_contents(data_dir() . '/transactions.json'), true) ?: [];
    usort($rows, fn($a, $b) => strcmp((string)($a['trans_time'] ?? ''), (string)($b['trans_time'] ?? '')));
    if ($since !== '') $rows = array_values(array_filter($rows, fn($r) => strcmp((string)($r['trans_time'] ?? ''), $since) > 0));
    return array_slice($rows, 0, $limit);
}

function oauth_token(): ?string
{
    $base = envv('MPESA_ENV', 'production') === 'sandbox' ? 'https://sandbox.safaricom.co.ke' : 'https://api.safaricom.co.ke';
    $cacheFile = data_dir() . '/.tok';
    if (is_file($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c) && ($c['exp'] ?? 0) > time() + 60 && ($c['base'] ?? '') === $base) return (string)$c['token'];
    }
    $ch = curl_init($base . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => envv('CONSUMER_KEY', '') . ':' . envv('CONSUMER_SECRET', ''), CURLOPT_TIMEOUT_MS => 10000]);
    $resp = json_decode((string)curl_exec($ch), true);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || empty($resp['access_token'])) return null;
    file_put_contents($cacheFile, json_encode(['token' => (string)$resp['access_token'], 'exp' => time() + 3500, 'base' => $base]), LOCK_EX);
    return (string)$resp['access_token'];
}

function register_urls(string $shortcode): array
{
    $token = oauth_token();
    if (!$token) return ['ok' => false, 'error' => 'oauth failed'];
    $base = envv('MPESA_ENV', 'production') === 'sandbox' ? 'https://sandbox.safaricom.co.ke' : 'https://api.safaricom.co.ke';
    $pub = (string)envv('PUBLIC_URL', '');
    $payload = [
        'ShortCode' => $shortcode,
        'ResponseType' => 'Completed',
        'ConfirmationURL' => $pub . '?route=callback',
        'ValidationURL' => $pub . '?route=callback',
    ];
    $ch = curl_init($base . '/mpesa/c2b/v1/registerurl');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    ]);
    $resp = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => $http === 200, 'http' => $http, 'sent' => $payload, 'response' => json_decode($resp, true) ?: $resp];
}

function fan_out_local(string $body, int $waitMs = 2500): void
{
    $urls = array_values(array_filter(array_map('trim', explode(',', (string)envv('TARGET_URLS', '')))));
    if (!$urls) return;
    $headers = [];
    $s = (string)envv('HMAC_SECRET', '');
    if ($s !== '') $headers[] = 'X-Signature: ' . base64_encode(hash_hmac('sha256', $body, $s, true));
    $mh = curl_multi_init();
    foreach ($urls as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT_MS => 5000,
        ]);
        curl_multi_add_handle($mh, $ch);
    }
    $running = 0;
    $deadline = microtime(true) + $waitMs / 1000;
    do {
        curl_multi_exec($mh, $running);
        if (curl_multi_select($mh, 0.1) === -1) usleep(5000);
        if (!$running) break;
    } while (microtime(true) < $deadline);
    foreach (range(0, count($urls) - 1) as $i) {
        curl_multi_remove_handle($mh, $i);
    }
    curl_multi_close($mh);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_ends_with((string)$path, '/index.php')) $path = '/';
if (isset($_GET['route']) && $_GET['route'] !== '') {
    $path = '/' . ltrim((string)$_GET['route'], '/');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $path === '/health') {
    $pdo = db();
    $count = null;
    if ($pdo) {
        try { $count = (int)$pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn(); } catch (Throwable $e) { $count = null; }
    }
    json_out([
        'service' => 'daraja-ledger',
        'status' => $pdo ? 'ok(services db)' : 'ok(json)',
        'db' => $pdo ? 'mysql' : 'json',
        'txn_count' => $count,
        'registered_as' => (string)envv('PUBLIC_URL', ''),
        'time' => date('c'),
    ]);
    exit;
}

if ($method === 'POST' && $path === '/register-urls') {
    if (!require_auth()) exit;
    $in = json_decode(read_body(), true) ?: [];
    $shortcode = (string)($in['ShortCode'] ?? envv('PAYBILL', ''));
    json_out(register_urls($shortcode));
    exit;
}

if ($method === 'POST' && $path === '/callback') {
    $body = read_body();
    log_raw($body);
    try {
        $d = json_decode($body, true) ?: [];
        $r = normalize($d);
        if ($r === null) {
            log_raw(json_encode(['error' => 'unknown payload shape']), 'drop');
            json_out(['ResultCode' => 0, 'ResultDesc' => 'Acceptados (unknown shape stored in log)']);
            exit;
        }
        $res = upsert($r);
        fan_out_local($body);
        $isStk = $r['type'] === 'stk_push';
        json_out($isStk
            ? ['ResponseCode' => 0, 'ResponseDesc' => 'success', 'stored' => $res]
            : ['ResultCode' => 0, 'ResultDesc' => 'Accepted', 'stored' => $res]);
    } catch (Throwable $e) {
        log_raw(json_encode(['error' => $e->getMessage(), 'body' => json_decode($body, true)]), 'error');
        json_out(['ResultCode' => 0, 'ResultDesc' => 'Accepted (processed async, error logged)']);
    }
    exit;
}

if ($method === 'GET' && $path === '/transactions' || $path === '/pull') {
    if (!require_auth()) exit;
    $since = $_GET['since'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 500), 2000);
    $rows = list_tx($since, $limit);
    $max = '';
    foreach ($rows as $r) {
        if (strcmp((string)($r['trans_time'] ?? ''), $max) > 0) $max = (string)$r['trans_time'];
    }
    json_out(['count' => count($rows), 'latest_time' => $max ?: $since, 'transactions' => $rows]);
    exit;
}

http_response_code(404);
json_out(['error' => 'not found', 'path' => $path]);
