<?php
/**
 * callback.php ??? Safaricom write endpoint (hardened)
 * - credentials live OUTSIDE the webroot (../mpesa_private/config.php)
 * - optional Safaricom IP allowlist (config SAFARICOM_IPS, empty = allow all)
 * - logs raw payloads, savi ng to (json | csv | mysql)
 * - responds to Safaricom instantly
 */

declare(strict_types=1);
date_default_timezone_set('Africa/Nairobi');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
header('Content-Type: application/json');

require __DIR__ . '/mpesa_private/config.php';

/* ---- 1. IP allowlist (config SAFARICOM_IPS; empty = allow all) ---- */
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!empty(SAFARICOM_IPS) && !in_array($ip, SAFARICOM_IPS, true)) {
    audit("DENIED ip=$ip\n", 'denied_' . gmdate('Y-m-d') . '.log');
    http_response_code(403);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'not allowed']);
    exit;
}

/* ---- 2. POST body ---- */
$raw = file_get_contents('php://input') ?: '';
if ($raw === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}
audit($raw, 'raw_' . gmdate('Y-m-d') . '.log');

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted (unparseable payload logged)']);
    exit;
}

$log = normalize($payload);
if ($log === null) {
    audit($raw, 'unsupported_' . gmdate('Y-m-d') . '.log');
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted (unsupported shape logged)']);
    exit;
}

$stored = persist($log);
$stk = ($log['type'] ?? '') === 'stk_push';

echo json_encode($stk
    ? ['ResponseCode' => 0, 'ResponseDesc' => 'success', 'stored' => $stored]
    : ['ResultCode' => 0, 'ResultDesc' => 'Accepted', 'stored' => $stored]);
exit;

/* ================= helpers ================= */

function audit(string $raw, string $name): void
{
    if (!SAVE_LOCAL_FILES) return;
    $dir = LOG_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/' . $name, date('c') . "\t" . $raw . "\n", FILE_APPEND | LOCK_EX);
}

/** Shape any Safaricom payload into one transaction record. */
function normalize(array $d): ?array
{
    // 1) HIT: STK Push result callback
    if (isset($d['Body']['stkCallback'])) {
        $cb = $d['Body']['stkCallback'];
        $item = [];
        foreach (($cb['CallbackMetadata']['Item'] ?? []) as $it) {
            $item[(string)$it['Name']] = $it['Value'] ?? null;
        }
        return [
            'id'          => (string)($cb['CheckoutRequestID'] ?? uniqid('STK-')),
            'type'        => 'stk_push',
            'receipt'     => (string)($item['MpesaReceiptNumber'] ?? ''),
            'trans_time'  => isset($item['TransactionDate'])
                ? preg_replace('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', '$1-$2-$3 $4:$5:$6', (string)$item['TransactionDate'])
                : date('Y-m-d H:i:s'),
            'amount'      => (float)($item['Amount'] ?? 0),
            'phone'       => (string)($item['PhoneNumber'] ?? ''),
            'payer_name'  => '',
            'account_ref' => (string)($cb['MerchantRequestID'] ?? ''),
            'status'      => ((int)($cb['ResultCode'] ?? 1) === 0) ? 'completed' : 'failed',
            'result_desc' => (string)($cb['ResultDesc'] ?? ''),
            'raw'         => $d,
        ];
    }

    // 2) C2B confirmation (paybill-to-paybill: customer typed 324364 in M-Pesa menu)
    if (isset($d['TransactionType']) || isset($d['TransactionID'])) {
        $time = preg_replace('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', '$1-$2-$3 $4:$5:$6', (string)($d['TransTime'] ?? ''));
        return [
            'id'          => (string)($d['TransactionID'] ?? uniqid('C2B-')),
            'type'        => 'c2b',
            'receipt'     => (string)($d['TransactionID'] ?? ''),
            'trans_time'  => $time ?: date('Y-m-d H:i:s'),
            'amount'      => (float)($d['TransactionAmount'] ?? 0),
            'phone'       => (string)($d['MSISDN'] ?? ''),
            'payer_name'  => trim(($d['FirstName'] ?? '') . ' ' . ($d['MiddleName'] ?? '') . ' ' . ($d['LastName'] ?? '')),
            'account_ref' => (string)($d['BillRefNumber'] ?? ''),
            'status'      => 'completed',
            'result_desc' => 'Paybill ' . ($d['BusinessShortCode'] ?? '') .
                             (isset($d['OrgAccountBalance']) ? ' (bal ' . $d['OrgAccountBalance'] . ')' : ''),
            'raw'         => $d,
        ];
    }

    // 3) B2C result (payout): stored but not triggered by this integration
    if (isset($d['Result']['ResultType'])) {
        $r = $d['Result'];
        $params = [];
        foreach (($r['ResultParameters']['ResultParameter'] ?? []) as $p) {
            $params[(string)$p['Key']] = $p['Value'] ?? null;
        }
        return [
            'id'          => (string)($r['TransactionID'] ?? uniqid('B2C-')),
            'type'        => 'b2c',
            'receipt'     => (string)($params['Receipt'] ?? $r['TransactionID'] ?? ''),
            'trans_time'  => (string)($r['TransactionCompletedTimeStamp'] ?? ''),
            'amount'      => 0.0,
            'phone'       => (string)($params['ReceiverPartyPublicName'] ?? ''),
            'payer_name'  => '',
            'account_ref' => (string)($r['OriginatorConversationID'] ?? ''),
            'status'      => ((int)($r['ResultCode'] ?? 1) === 0) ? 'completed' : 'failed',
            'result_desc' => (string)($r['ResultDesc'] ?? ''),
            'raw'         => $d,
        ];
    }
    return null;
}

/** Save to MySQL if configured, otherwise JSON + CSV next to this file. */
function persist(array $t): array
{
    if (defined('DB_HOST') && DB_HOST !== '') {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $sql = 'INSERT INTO transactions
                      (id, type, receipt, trans_time, amount, phone, payer_name, account_ref, status, result_desc, raw)
                    VALUES (:id, :type, :receipt, :trans_time, :amount, :phone, :payer_name, :account_ref, :status, :result_desc, :raw)
                    ON DUPLICATE KEY UPDATE
                      receipt = VALUES(receipt), status = VALUES(status), result_desc = VALUES(result_desc),
                      trans_time = VALUES(trans_time), amount = VALUES(amount), phone = VALUES(phone),
                      payer_name = VALUES(payer_name), account_ref = VALUES(account_ref), raw = VALUES(raw)';
            $st = $pdo->prepare($sql);
            $st->execute([
                ':id'          => $t['id'],
                ':type'        => $t['type'],
                ':receipt'     => $t['receipt'],
                ':trans_time'  => $t['trans_time'],
                ':amount'      => $t['amount'],
                ':phone'       => $t['phone'],
                ':payer_name'  => $t['payer_name'],
                ':account_ref' => $t['account_ref'],
                ':status'      => $t['status'],
                ':result_desc' => $t['result_desc'],
                ':raw'         => json_encode($t['raw'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
            return ['db' => 'mysql', 'action' => 'ok'];
        } catch (Throwable $e) {
            audit("DB ERROR: " . $e->getMessage() . "\n" . json_encode($t), 'dbfail_' . gmdate('Y-m-d') . '.log');
            if (DEBUG) {
                return ['db' => 'error', 'detail' => $e->getMessage()];
            }
        }
    }

    if (!SAVE_LOCAL_FILES) return ['db' => 'none'];

    $dir = LOG_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $jsonPath = $dir . '/transactions.json';
    $rows = json_decode((string)@file_get_contents($jsonPath), true);
    if (!is_array($rows)) $rows = [];
    $found = false;
    foreach ($rows as $i => $row) {
        if (($row['id'] ?? null) === $t['id']) { $rows[$i] = array_merge($row, $t); $found = true; break; }
    }
    if (!$found) $rows[] = $t;
    usort($rows, fn($a, $b) => strcmp((string)($b['trans_time'] ?? ''), (string)($a['trans_time'] ?? '')));
    file_put_contents($jsonPath, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    $csv = $dir . '/transactions.csv';
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
    return ['db' => 'json+csv', 'count' => count($rows)];
}
