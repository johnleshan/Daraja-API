<?php
require_once('config.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$phone = $_POST['phone'] ?? '';
$amount = $_POST['amount'] ?? '';

// Basic validation
if (empty($phone) || empty($amount)) {
    echo json_encode(['error' => 'Phone and amount are required']);
    exit;
}

// Clean phone number (remove +, ensures it starts with 254)
$phone = preg_replace('/[^0-9]/', '', $phone);
if (strlen($phone) == 10 && substr($phone, 0, 1) == "0") {
    $phone = "254" . substr($phone, 1);
} elseif (strlen($phone) == 9) {
    $phone = "254" . $phone;
}

/**
 * Step 1: Generate Access Token
 */
function getAccessToken() {
    $credentials = base64_encode(CONSUMER_KEY . ':' . CONSUMER_SECRET);
    $curl = curl_init(TOKEN_URL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($status === 200) {
        $result = json_decode($response);
        return $result->access_token;
    }
    return null;
}

$accessToken = getAccessToken();

if (!$accessToken) {
    echo json_encode(['error' => 'Failed to generate access token. Check your Consumer Key and Secret.']);
    exit;
}

/**
 * Step 2: Generate Password
 */
$password = base64_encode(BUSINESS_SHORTCODE . LIPA_NA_MPESA_PASSKEY . TIMESTAMP);

/**
 * Step 3: Initiate STK Push
 */
$stkHeader = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $accessToken
];

$curlPostData = [
    'BusinessShortCode' => BUSINESS_SHORTCODE,
    'Password' => $password,
    'Timestamp' => TIMESTAMP,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => $amount,
    'PartyA' => $phone,
    'PartyB' => BUSINESS_SHORTCODE,
    'PhoneNumber' => $phone,
    'CallBackURL' => CALLBACK_URL,
    'AccountReference' => 'TestPayment',
    'TransactionDesc' => 'Payment for service'
];

$curl = curl_init(STK_PROCESS_URL);
curl_setopt($curl, CURLOPT_HTTPHEADER, $stkHeader);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($curlPostData));
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

$curl_response = curl_exec($curl);
$error = curl_error($curl);
curl_close($curl);

if ($error) {
    echo json_encode(['error' => 'CURL Error: ' . $error]);
} else {
    echo $curl_response;
}
