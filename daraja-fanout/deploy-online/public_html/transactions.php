<?php
/**
 * transactions.php ??? read API for the local machines (hardened)
 * - credentials OUTSIDE the webroot via ../mpesa_private/config.php
 * - Basic auth + optional X-API-Token fallback
 * - PHP 7.1+ compatible, errors returned as JSON (no blank 500s)
 */

declare(strict_types=1);
date_default_timezone_set('Africa/Nairobi');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
header('Content-Type: application/json');

require __DIR__ . '/mpesa_private/config.php';

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        @file_put_contents(__DIR__ . '/../mpesa_private/php_errors.log',
            date('c') . " FATAL: {$e['message']} @ {$e['file']}:{$e['line']}\n", FILE_APPEND);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'fatal', 'message' => DEBUG ? $e['message'] : 'internal', 'line' => $e['line']]);
        }
    }
});

if (DEBUG) {
    @ini_set('display_errors', '1');
}

function basic_auth_pair(): array
{
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        return [(string)$_SERVER['PHP_AUTH_USER'], (string)$_SERVER['PHP_AUTH_PW']];
    }
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Basic\s+(.*)$/i', $h, $m)) {
        $dec = base64_decode((string)$m[1], true);
        if ($dec !== false) {
            $pos = strpos($dec, ':');
            if ($pos !== false) {
                return [substr($dec, 0, $pos), (string)substr($dec, $pos + 1)];
            }
        }
    }
    return ['', ''];
}

/* ---- auth: Basic or X-API-Token ---- */
[$u, $p] = basic_auth_pair();
$token = (string)($_SERVER['HTTP_X_API_TOKEN'] ?? '');
$tokenOk = API_TOKEN !== '' && hash_equals(API_TOKEN, $token);
$basicOk = $u !== '' && hash_equals((string)API_USER, $u) && hash_equals((string)API_PASS, $p);

if (!$basicOk && !$tokenOk) {
    header('WWW-Authenticate: Basic realm="Daraja Ledger"');
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized', 'hint' => 'Basic or X-API-Token']);
    exit;
}

/* ---- DB ---- */
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(DEBUG ? ['error' => 'db', 'detail' => $e->getMessage()] : ['error' => 'db']);
    exit;
}

$since  = trim((string)($_GET['since'] ?? ''));
$limit  = max(1, min((int)($_GET['limit'] ?? 500), 2000));

try {
    $sql = 'SELECT id, system, type, receipt, trans_time, amount, phone, payer_name, account_ref, status, result_desc
            FROM transactions';
    if ($since !== '') {
        $sql .= ' WHERE trans_time > :s';
    }
    $sql .= ' ORDER BY trans_time ASC LIMIT :l';
    $st = $pdo->prepare($sql);
    if ($since !== '') $st->bindValue(':s', $since);
    $st->bindValue(':l', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(DEBUG ? ['error' => 'query', 'detail' => $e->getMessage()] : ['error' => 'query']);
    exit;
}

$max = '';
foreach ($rows as $r) {
    if (strcmp((string)$r['trans_time'], $max) > 0) $max = (string)$r['trans_time'];
}

echo json_encode([
    'count' => count($rows),
    'latest_time' => $max ?: $since,
    'transactions' => $rows,
], JSON_UNESCAPED_UNICODE);
