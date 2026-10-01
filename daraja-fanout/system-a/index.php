<?php
declare(strict_types=1);
date_default_timezone_set('Africa/Nairobi');

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool { return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0; }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool { return $needle === '' || substr($haystack, -strlen($needle)) === $needle; }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool { return $needle === '' || strpos($haystack, $needle) !== false; }
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

function data_dir(): string
{
    if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
    return __DIR__ . '/data';
}

function json_path(): string { return data_dir() . '/transactions.json'; }
function csv_path(): string { return data_dir() . '/transactions.csv'; }

function load_rows(): array
{
    if (!is_file(json_path())) return [];
    $rows = json_decode((string)file_get_contents(json_path()), true);
    return is_array($rows) ? $rows : [];
}

function save_rows(array $rows): void
{
    usort($rows, fn($a, $b) => strcmp((string)($b['time'] ?? ''), (string)($a['time'] ?? '')));
    file_put_contents(json_path(), json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $fp = fopen(csv_path(), 'w');
    if ($fp) {
        fputcsv($fp, ['id', 'type', 'receipt', 'time', 'amount', 'phone', 'names', 'ref', 'status', 'desc', 'system']);
        foreach ($rows as $r) {
            fputcsv($fp, [
                (string)($r['id'] ?? ''), (string)($r['type'] ?? ''), (string)($r['receipt'] ?? ''),
                (string)($r['time'] ?? ''), (string)($r['amount'] ?? ''), (string)($r['phone'] ?? ''),
                (string)($r['names'] ?? ''), (string)($r['ref'] ?? ''), (string)($r['status'] ?? ''),
                (string)($r['desc'] ?? ''), (string)($r['system'] ?? ''),
            ]);
        }
        fclose($fp);
    }
}

function log_raw(string $body, string $tag = 'reject'): void
{
    $dir = data_dir();
    file_put_contents(
        $dir . '/' . $tag . '_' . gmdate('Y-m-d') . '.json',
        date('c') . "\t" . $body . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/** Idempotent upsert: SK callback rows update pending STK rows by CheckoutRequestID, C2B rows by TransID. */
function upsert(array $row): array
{
    $rows = load_rows();
    $updated = false;
    if (!empty($row['id'])) {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? null) === $row['id']) {
                $rows[$i] = array_merge($r, $row);
                $updated = true;
                break;
            }
        }
    }
    if (!$updated) $rows[] = $row;
    save_rows($rows);
    return $row;
}

function now_key(): string
{
    return date('YmdHis');
}

/** Normalize any Daraja payload (C2B confirmation JSON or STK Push Result callback) into a record. */
function normalize(array $d): array
{
    $system = (string)envv('SYSTEM_NAME', 'UNNAMED');

    if (isset($d['Body']['stkCallback'])) {
        $cb = $d['Body']['stkCallback'];
        $item = [];
        foreach (($cb['CallbackMetadata']['Item'] ?? []) as $it) {
            $item[(string)$it['Name']] = $it['Value'] ?? null;
        }
        $ok = (int)($cb['ResultCode'] ?? 1) === 0;
        return [
            'id' => (string)($cb['CheckoutRequestID'] ?? ('STK-' . uniqid())),
            'type' => 'STK_PUSH',
            'receipt' => (string)($item['MpesaReceiptNumber'] ?? ''),
            'time' => isset($item['TransactionDate'])
                ? preg_replace('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', '$1-$2-$3 $4:$5:$6', (string)$item['TransactionDate'])
                : date('Y-m-d H:i:s'),
            'amount' => (float)($item['Amount'] ?? 0),
            'phone' => (string)($item['PhoneNumber'] ?? ''),
            'names' => '',
            'ref' => (string)($cb['MerchantRequestID'] ?? ''),
            'status' => $ok ? 'COMPLETED' : 'FAILED',
            'desc' => (string)($cb['ResultDesc'] ?? ''),
            'system' => $system,
            'meta' => ['Balance' => ($item['Balance'] ?? ''), 'TransactionDate' => ($item['TransactionDate'] ?? '')],
        ];
    }

    if (isset($d['TransactionType']) || isset($d['TransactionID'])) {
        $time = preg_replace('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', '$1-$2-$3 $4:$5:$6', (string)($d['TransTime'] ?? ''));
        return [
            'id' => (string)($d['TransactionID'] ?? ('C2B-' . uniqid())),
            'type' => (string)($d['TransactionType'] ?? 'Pay Bill'),
            'receipt' => (string)($d['TransactionID'] ?? ''),
            'time' => $time ?: date('Y-m-d H:i:s'),
            'amount' => (float)($d['TransactionAmount'] ?? 0),
            'phone' => (string)($d['MSISDN'] ?? ''),
            'names' => trim(($d['FirstName'] ?? '') . ' ' . ($d['MiddleName'] ?? '') . ' ' . ($d['LastName'] ?? '')),
            'ref' => (string)($d['BillRefNumber'] ?? ''),
            'status' => 'COMPLETED',
            'desc' => 'Paybill ' . ($d['BusinessShortCode'] ?? '') . (isset($d['OrgAccountBalance']) ? ', bal ' . $d['OrgAccountBalance'] : ''),
            'system' => $system,
        ];
    }

    return [
        'id' => 'UNK-' . uniqid(),
        'type' => 'UNKNOWN',
        'time' => date('Y-m-d H:i:s'),
        'status' => 'UNKNOWN',
        'system' => $system,
        'raw' => $d,
    ];
}

/** Basic Auth for dashboard + admin endpoints (optional: only if DASH_USER set). */
function require_auth(): bool
{
    $user = (string)envv('DASH_USER', '');
    $pass = (string)envv('DASH_PASS', '');
    if ($user === '' || $pass === '') return true;
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) && $_SERVER['PHP_AUTH_USER'] === $user && $_SERVER['PHP_AUTH_PW'] === $pass) {
        return true;
    }
    header('WWW-Authenticate: Basic realm="Daraja System"');
    http_response_code(401);
    return false;
}

function oauth_token(): ?string
{
    $base = envv('MPESA_ENV', 'sandbox') === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    $cacheFile = data_dir() . '/.tok';
    if (is_file($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c) && ($c['exp'] ?? 0) > time() + 60 && ($c['base'] ?? '') === $base) {
            return (string)$c['token'];
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
    ]);
    $resp = json_decode((string)curl_exec($ch), true);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || empty($resp['access_token'])) return null;
    $token = (string)$resp['access_token'];
    file_put_contents($cacheFile, json_encode(['token' => $token, 'exp' => time() + 3500, 'base' => $base]), LOCK_EX);
    return $token;
}

function stk_push(string $phone, float $amount, string $ref): array
{
    $shortcode = (string)envv('PAYBILL', '');
    $passkey = (string)envv('PASSKEY', '');
    $callback = (string)envv('STK_CALLBACK_URL', '');
    if ($shortcode === '' || $passkey === '' || $callback === '') {
        return ['ok' => false, 'error' => 'PAYBILL / PASSKEY / STK_CALLBACK_URL missing in .env'];
    }
    $token = oauth_token();
    if (!$token) return ['ok' => false, 'error' => 'oauth failed'];

    $phone = (string)preg_replace('/\D/', '', $phone);
    if (str_starts_with((string)$phone, '0')) $phone = '254' . substr((string)$phone, 1);
    if (str_starts_with((string)$phone, '7') || str_starts_with((string)$phone, '1')) $phone = '254' . (string)$phone;

    $base = envv('MPESA_ENV', 'sandbox') === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    $ts = date('YmdHis');
    $payload = [
        'BusinessShortCode' => $shortcode,
        'Password' => base64_encode($shortcode . $passkey . $ts),
        'Timestamp' => $ts,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => $amount,
        'PartyA' => $phone,
        'PartyB' => $shortcode,
        'PhoneNumber' => $phone,
        'CallBackURL' => $callback,
        'AccountReference' => $ref,
        'TransactionDesc' => 'Payment to ' . (string)envv('SYSTEM_NAME', ''),
    ];

    $ch = curl_init($base . '/mpesa/stkpush/v1/processrequest');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 20000,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    ]);
    $resp = json_decode((string)curl_exec($ch), true);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 200 && isset($resp['CheckoutRequestID'])) {
        upsert([
            'id' => (string)$resp['CheckoutRequestID'],
            'type' => 'STK_PUSH',
            'receipt' => '',
            'time' => date('Y-m-d H:i:s'),
            'amount' => $amount,
            'phone' => $phone,
            'names' => '',
            'ref' => $ref,
            'status' => 'PENDING',
            'desc' => (string)($resp['ResponseDescription'] ?? 'push sent'),
            'system' => (string)envv('SYSTEM_NAME', ''),
        ]);
        return ['ok' => true, 'response' => $resp];
    }
    return ['ok' => false, 'http' => $http, 'error' => is_array($resp) ? (string)($resp['errorMessage'] ?? 'stkpush failed') : 'stkpush failed'];
}

function htmlesc(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

function render_dashboard(): void
{
    $rows = load_rows();
    $today = substr((string)date('Y-m-d'), 0, 10);
    $weekAgo = date('Y-m-d', strtotime('-6 days'));
    $sumToday = 0.0;
    $cntToday = 0;
    $sumWeek = 0.0;
    $cntWeek = 0.0;
    $sumTotal = 0.0;
    $cntTotal = 0;
    $cntPending = 0;
    $cntFailed = 0;
    $sys = (string)envv('SYSTEM_NAME', 'UNNAMED');
    foreach ($rows as $r) {
        if (($r['status'] ?? '') !== 'COMPLETED') {
            if (($r['status'] ?? '') === 'PENDING') $cntPending++;
            elseif (($r['status'] ?? '') === 'FAILED') $cntFailed++;
        }
        if (($r['type'] ?? '') === 'STK_PUSH' && ($r['status'] ?? '') !== 'COMPLETED') {
            $cntTotal++;
            continue;
        }
        $amt = (float)($r['amount'] ?? 0);
        $day = substr((string)($r['time'] ?? ''), 0, 10);
        if ($day === $today) { $sumToday += $amt; $cntToday++; }
        if ($day >= $weekAgo && $day <= $today) { $sumWeek += $amt; $cntWeek++; }
        $sumTotal += $amt;
        $cntTotal++;
    }
    $url = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $filter = $_GET['filter'] ?? '';
    $q = $_GET['q'] ?? '';
    $list = $rows;
    if ($filter !== '') {
        $list = array_values(array_filter($list, fn($r) => strcasecmp((string)($r['status'] ?? ''), $filter) === 0));
    }
    if ($q !== '') {
        $qq = strtolower(sender_normalize($q));
        $list = array_values(array_filter($list, function ($r) use ($qq, $q) {
            $hay = strtolower(json_encode($r, JSON_UNESCAPED_UNICODE) . sender_normalize((string)($r['phone'] ?? '')));
            return str_contains($hay, $qq) || str_contains(strtolower((string)($r['phone'] ?? '')), strtolower($q));
        }));
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlesc($sys) ?> - Transactions</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; color: #0f172a; padding: 20px; }
            .wrap { max-width: 1100px; margin: 0 auto; }
            .badge { display: inline-block; padding: 4px 12px; border-radius: 6px; font-weight: 700; font-size: 13px; color: #fff; background: #0ea5e9; letter-spacing: .5px; }
            header h1 { font-size: 22px; margin-top: 10px; }
            header p { color: #475569; font-size: 13px; margin-top: 4px; margin-bottom: 18px; }
            .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px; }
            .card { background: #fff; border-radius: 10px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
            .card .t { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
            .card .v { font-size: 22px; font-weight: 700; margin-top: 6px; color: #0f172a; }
            .card .s { font-size: 12px; color: #94a3b8; margin-top: 2px; }
            table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.06); font-size: 13px; }
            th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
            th { background: #f8fafc; font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
            tr:hover td { background: #f8fafc; }
            .st { padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; display: inline-block; }
            .st-COMPLETED { background: #dcfce7; color: #166534; }
            .st-PENDING { background: #fef9c3; color: #854d0e; }
            .st-FAILED { background: #fee2e2; color: #991b1b; }
            .st-UNKNOWN { background: #e2e8f0; color: #334155; }
            form.f { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
            input, select, button { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; }
            button { background: #0ea5e9; color: #fff; border-color: #0ea5e9; cursor: pointer; font-weight: 600; }
            .empty { text-align: center; color: #64748b; padding: 30px; }
            footer { margin-top: 16px; font-size: 12px; color: #94a3b8; }
        </style>
    </head>
    <body>
    <div class="wrap">
        <header>
            <span class="badge">SYSTEM <?= htmlesc($sys) ?></span>
            <h1>M-Pesa Transactions</h1>
            <p>Paybill <?= htmlesc((string)envv('PAYBILL', '')) ?> &middot; All data served from local JSON/CSV &middot; API: <code>/transactions</code> &middot; Webhook: <code>POST /webhook/mpesa</code> &middot; STK Push: <code>POST /stkpush</code></p>
        </header>

        <div class="cards">
            <div class="card"><div class="t">Received today</div><div class="v">KES <?= number_format($sumToday, 2) ?></div><div class="s"><?= $cntToday ?> txns</div></div>
            <div class="card"><div class="t">Last 7 days</div><div class="v">KES <?= number_format($sumWeek, 2) ?></div><div class="s"><?= (int)$cntWeek ?> txns</div></div>
            <div class="card"><div class="t">All time</div><div class="v">KES <?= number_format($sumTotal, 2) ?></div><div class="s"><?= $cntTotal ?> txns</div></div>
            <div class="card"><div class="t">Pending / Failed</div><div class="v"><?= $cntPending ?> / <?= $cntFailed ?></div><div class="s">STK pushes</div></div>
        </div>

        <?php if (($_GET['r'] ?? '') === 'stkpush' && ($_GET['msg'] ?? '') !== ''): ?>
            <div style="background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px">
                <b>STK Push:</b> <?= htmlesc((string)$_GET['msg']) ?>
            </div>
        <?php endif; ?>

        <form class="f" method="post" action="?route=stkpush" style="background:#fff;padding:16px;border-radius:10px;margin-bottom:18px;align-items:flex-start">
            <input type="hidden" name="redirect" value="1">
            <div style="width:100%">
                <div style="font-weight:700;font-size:14px;margin-bottom:10px">Trigger M-Pesa prompt (STK Push) to any number</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <input type="text" name="phone" placeholder="07XXXXXXXX / 2541XXXXXXXX" required style="flex:1;min-width:160px">
                    <input type="number" name="amount" placeholder="Amount KES" min="1" required style="flex:0 0 140px">
                    <input type="text" name="ref" placeholder="Account ref (optional)" style="flex:0 0 180px">
                    <button type="submit">Send prompt</button>
                </div>
                <p style="font-size:12px;color:#94a3b8;margin-top:8px">Customer enters PIN &rarr; Safaricom calls the middleware callback &rarr; this system <b>and the other clone</b> both log the payment, proving both systems run on one paybill.</p>
            </div>
        </form>

        <form class="f" method="get">
            <input type="text" name="q" placeholder="Search receipt / phone / ref" value="<?= htmlesc((string)$q) ?>">
            <select name="filter">
                <option value="">All statuses</option>
                <?php foreach (['COMPLETED', 'PENDING', 'FAILED', 'UNKNOWN'] as $s): ?>
                    <option value="<?= $s ?>" <?= strcasecmp($filter, $s) === 0 ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Filter</button>
            <a href="/" style="align-self:center;font-size:13px">Reset</a>
        </form>

        <table>
            <thead>
            <tr><th>#</th><th>Receipt</th><th>Type</th><th>Time</th><th>Amount (KES)</th><th>Phone</th><th>Name</th><th>Ref</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php if (!$list): ?>
                <tr><td colspan="9" class="empty">No transactions yet.</td></tr>
            <?php else: $n = 0; foreach ($list as $r): $n++; ?>
                <tr>
                    <td><?= $n ?></td>
                    <td><?= htmlesc((string)($r['receipt'] ?? '-')) ?></td>
                    <td><?= htmlesc((string)($r['type'] ?? '')) ?></td>
                    <td><?= htmlesc((string)($r['time'] ?? '')) ?></td>
                    <td><b><?= number_format((float)($r['amount'] ?? 0), 2) ?></b></td>
                    <td><?= htmlesc((string)($r['phone'] ?? '')) ?></td>
                    <td><?= htmlesc((string)($r['names'] ?? '')) ?></td>
                    <td><?= htmlesc((string)($r['ref'] ?? '')) ?></td>
                    <td><span class="st st-<?= htmlesc((string)($r['status'] ?? 'UNKNOWN')) ?>"><?= htmlesc((string)($r['status'] ?? '')) ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <footer>JSON API: <a href="?route=transactions">/transactions</a> &middot; CSV: data/transactions.csv &middot; <?= htmlesc(date('c')) ?></footer>
    </div>
    </body>
    </html>
    <?php
}

function sender_normalize(string $q): string
{
    $d = (string)preg_replace('/\D/', '', $q);
    if ($d === '' ) return '';
    if (str_starts_with($d, '0')) return '254' . substr($d, 1);
    if (str_starts_with($d, '7') || str_starts_with($d, '1')) return '254' . $d;
    return $d;
}

$ROUTE_MAP = [
    'webhook' => '/webhook/mpesa',
    'callback' => '/callback',
    'stkpush' => '/stkpush',
    'transactions' => '/transactions',
    'csv' => '/csv',
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

if ($method === 'GET' && str_starts_with($path, '/health')) {
    json_out(['service' => envv('SYSTEM_NAME', 'system'), 'status' => 'ok', 'txn_count' => count(load_rows()), 'time' => date('c')]);
    exit;
}

if ($method === 'GET' && str_starts_with($path, '/transactions')) {
    if (!require_auth()) exit;
    $rows = load_rows();
    $f = $_GET['q'] ?? null;
    if ($f) $rows = array_values(array_filter($rows, fn($r) => str_contains(strtolower(json_encode($r)), strtolower((string)$f))));
    $limit = min((int)($_GET['limit'] ?? 2000), 5000);
    json_out(['system' => envv('SYSTEM_NAME', ''), 'count' => count($rows), 'transactions' => array_slice($rows, 0, $limit)]);
    exit;
}

if ($method === 'GET' && ($path === '/csv') ) {
    if (!require_auth()) exit;
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="transactions.csv"');
    readfile(csv_path());
    exit;
}

if ($method === 'POST' && str_starts_with($path, '/stkpush')) {
    if (!require_auth()) exit;
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_starts_with($ctype, 'application/x-www-form-urlencoded') || str_starts_with($ctype, 'multipart/form-data')) {
        $in = $_POST;
    } else {
        $in = json_decode(read_body(), true) ?: [];
    }
    $phone = (string)($in['phone'] ?? '');
    $amount = (float)($in['amount'] ?? 0);
    $ref = (string)($in['ref'] ?? envv('PAYBILL', ''));
    if (!preg_match('/^(?:\+?254|0)?(1|7)\d{8}$/', $phone) || $amount <= 0) {
        if (!empty($in['redirect'])) {
            header('Location: ?r=stkpush&msg=' . urlencode('Invalid phone or amount'));
        } else {
            json_out(['ok' => false, 'error' => 'send {phone: "07XXXXXXXX", amount: 10, ref?: "..."}'], 400);
        }
        exit;
    }
    $r = stk_push($phone, $amount, $ref);
    if (!empty($in['redirect'])) {
        $redirect = implode('&', array_filter([
            '?r=stkpush',
            $r['ok'] === true ? 'msg=' . urlencode('Push sent to ' . $phone . '. Enter PIN within 60 seconds.') : 'msg=' . urlencode('Push failed: ' . (is_array($r['error'] ?? null) ? json_encode($r['error']) : (string)($r['error'] ?? 'unknown error'))),
        ]));
        header("Location: $redirect");
    } else {
        json_out($r, $r['ok'] ? 200 : 502);
    }
    exit;
}

if ($method === 'POST' && str_starts_with($path, '/webhook/mpesa')) {
    $body = read_body();
    $secret = (string)envv('HMAC_SECRET', '');
    if ($secret !== '') {
        $hdr = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
        if (!hash_equals($expected, (string)$hdr)) {
            log_raw(json_encode(['error' => 'bad signature', 'body' => json_decode($body, true)]), 'reject');
            json_out(['error' => 'unauthorized'], 401);
            exit;
        }
    }
    $d = json_decode($body, true) ?: [];
    if (!$d) {
        json_out(['ResultCode' => 1, 'ResultDesc' => 'invalid JSON'], 400);
        exit;
    }
    $row = upsert(normalize($d));
    json_out(['ResultCode' => 0, 'ResultDesc' => 'Accepted', 'system' => envv('SYSTEM_NAME', ''), 'record' => $row]);
    exit;
}

if ($method === 'GET' && $path === '/') {
    if (!require_auth()) exit;
    render_dashboard();
    exit;
}

http_response_code(404);
json_out(['error' => 'not found', 'path' => $path]);
