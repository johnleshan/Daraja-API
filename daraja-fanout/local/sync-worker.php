<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(0);

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
                $p = explode('=', $line, 2);
                $cache[trim($p[0])] = trim($p[1] ?? '');
            }
        }
    }
    if (array_key_exists($k, $cache)) return $cache[$k];
    if (array_key_exists($k, $_ENV)) return $_ENV[$k];
    $v = getenv($k);
    return $v === false ? $d : $v;
}

function say(string $m): void
{
    $line = date('c') . "\t" . $m . "\n";
    echo $line;
    @file_put_contents(__DIR__ . '/data/worker.log', $line, FILE_APPEND | LOCK_EX);
}

function url_get(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 20000,
        CURLOPT_USERPWD => (string)envv('ONLINE_USER', '') . ':' . (string)envv('ONLINE_PASS', ''),
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $http === 200 && is_string($body) ? $body : null;
}

function sync(): bool
{
    $base = (string)envv('ONLINE_URL', '');
    if ($base === '') {
        say('ONLINE_URL missing in local/.env — write e.g. ONLINE_URL=https://yourdomain.co.ke/api');
        return false;
    }
    $since = '';
    $stateP = __DIR__ . '/data/since.state';
    if (is_file($stateP)) $since = trim((string)file_get_contents($stateP));
    $json = url_get($base . (str_contains($base, '?') ? '&' : '?') . 'route=pull&since=' . rawurlencode($since) . '&limit=1000');
    if ($json === null) {
        say('online API not reachable, will retry');
        return false;
    }
    $d = json_decode($json, true) ?: [];
    $rows = $d['transactions'] ?? [];
    if (!is_array($rows)) {
        say('bad payload from ledger');
        return false;
    }
    $applied = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        if (apply_local($r)) $applied++;
    }
    $max = (string)($d['latest_time'] ?? '');
    if ($max !== '' && strcmp($max, $since) > 0) file_put_contents($stateP, $max, LOCK_EX);
    say("pulled " . count($rows) . " txns since " . ($since === '' ? 'start' : $since) . "; applied=$applied; cursor=$max");
    return count($rows) > 0;
}

function apply_local(array $r): bool
{
    $ref = (string)($r['id'] ?? '');
    if ($ref === '') return false;

    // MySQL mode if LOCAL_DB_HOST found
    if ((string)envv('LOCAL_DB_HOST', '') !== '') {
        try {
            $pdo = new PDO(
                'mysql:host=' . envv('LOCAL_DB_HOST', '') . ';port=' . envv('LOCAL_DB_PORT', '3306') .
                ';dbname=' . envv('LOCAL_DB_NAME', 'daraja_ledger') . ';charset=utf8mb4',
                (string)envv('LOCAL_DB_USER', ''), (string)envv('LOCAL_DB_PASS', ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $st = $pdo->prepare('INSERT INTO transactions
                (id, system, type, receipt, trans_time, amount, phone, payer_name, account_ref, status, result_desc)
                VALUES (:id, :system, :type, :receipt, :trans_time, :amount, :phone, :payer_name, :account_ref, :status, :desc)
                ON DUPLICATE KEY UPDATE receipt=VALUES(receipt), status=VALUES(status), result_desc=VALUES(result_desc),
                    trans_time=VALUES(trans_time), amount=VALUES(amount), phone=VALUES(phone),
                    payer_name=VALUES(payer_name), account_ref=VALUES(account_ref)');
            $st->execute([
                ':id' => $r['id'], ':system' => (string)($r['system'] ?? 'cloud'), ':type' => (string)($r['type'] ?? 'c2b'),
                ':receipt' => (string)($r['receipt'] ?? ''), ':trans_time' => (string)($r['trans_time'] ?? date('Y-m-d H:i:s')),
                ':amount' => (float)($r['amount'] ?? 0), ':phone' => (string)($r['phone'] ?? ''),
                ':payer_name' => (string)($r['payer_name'] ?? ''), ':account_ref' => (string)($r['account_ref'] ?? ''),
                ':status' => (string)($r['status'] ?? 'unknown'), ':desc' => (string)($r['result_desc'] ?? ''),
            ]);
            return true;
        } catch (Throwable $e) {
            say('local mysql failed: ' . $e->getMessage() . ' — file fallback');
            $status = (string)($r['status'] ?? '');
        }
    }

    // JSON+CSV file fallback (same folders the local dashboard reads)
    $dirA = dirname(__DIR__) . '/system-a/data';
    $dirB = dirname(__DIR__) . '/system-b/data';
    foreach ([$dirA, $dirB] as $dir) {
        if (!is_dir($dir)) continue;
        $jsonPath = $dir . '/transactions.json';
        $rows = json_decode((string)@file_get_contents($jsonPath), true);
        if (!is_array($rows)) $rows = [];
        $rowId = $r['id'] ?? '';
        $found = false;
        foreach ($rows as $i => $row) {
            if (($row['id'] ?? '') === $rowId) {
                $rows[$i] = array_merge($row, [
                    'id' => $rowId,
                    'type' => ($r['type'] ?? 'c2b') === 'stk_push' ? 'STK_PUSH' : 'Pay Bill',
                    'receipt' => (string)($r['receipt'] ?? ''),
                    'time' => (string)($r['trans_time'] ?? ''),
                    'amount' => (float)($r['amount'] ?? 0),
                    'phone' => (string)($r['phone'] ?? ''),
                    'names' => (string)($r['payer_name'] ?? ''),
                    'ref' => (string)($r['account_ref'] ?? ''),
                    'status' => strtoupper((string)($r['status'] ?? 'UNKNOWN')),
                    'desc' => (string)($r['result_desc'] ?? ''),
                ]);
                $found = true;
                break;
            }
        }
        if (!$found) $rows[] = $r;
        usort($rows, fn($a, $b) => strcmp((string)($b['time'] ?? ''), (string)($a['time'] ?? '')));
        file_put_contents($jsonPath, json_encode(array_values($rows), JSON_PRETTY_PRINT), LOCK_EX);
    }
    return true;
}

@mkdir(__DIR__ . '/data', 0775, true);
say('worker started (interval ' . (string)envv('SYNC_INTERVAL_SEC', '60') . 's)');
$interval = max(10, (int)envv('SYNC_INTERVAL_SEC', '60'));
while (true) {
    sync();
    sleep($interval);
}
