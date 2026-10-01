<?php
declare(strict_types=1);

$env = parse_ini_file('C:\Users\JOHN LESHAN\Documents\PROJECTS\Daraja-API\daraja-fanout\middleware\.env');
$env['PUBLIC_URL'] = 'https://YOUR-CALLBACK-DOMAIN/callback_on.php';
$base = ($env['MPESA_ENV'] ?? 'production') === 'sandbox' ? 'https://sandbox.safaricom.co.ke' : 'https://api.safaricom.co.ke';

$ch = curl_init($base . '/oauth/v1/generate?grant_type=client_credentials');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $env['CONSUMER_KEY'] . ':' . $env['CONSUMER_SECRET'], CURLOPT_TIMEOUT_MS => 15000]);
$tok = json_decode((string)curl_exec($ch), true);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($http !== 200 || empty($tok['access_token'])) { echo "OAUTH FAILED http=$http\n"; exit; }

$payload = [
    'ShortCode' => $env['PAYBILL'] ?? '324364',
    'ResponseType' => 'Completed',
    'ConfirmationURL' => 'https://YOUR-CALLBACK-DOMAIN/callback_on.php',
    'ValidationURL' => 'https://YOUR-CALLBACK-DOMAIN/callback_on.php',
];
$ch = curl_init($base . '/mpesa/c2b/v1/registerurl');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok['access_token'], 'Content-Type: application/json'],
    CURLOPT_TIMEOUT_MS => 20000,
]);
$resp = (string)curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "REGISTER HTTP $http\nSENT: " . json_encode($payload) . "\nRESP: $resp\n";
