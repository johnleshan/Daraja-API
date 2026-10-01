<?php
/**
 * Callback handler for Safaricom Daraja API
 */

// Receive the JSON data sent by Safaricom
$callbackJSONData = file_get_contents('php://input');
$logFile = "stk_callback_log.txt";

// Log the raw response for debugging
$log = fopen($logFile, "a");
fwrite($log, "--- Callback Received at " . date('Y-m-d H:i:s') . " ---\n");
fwrite($log, $callbackJSONData . "\n\n");
fclose($log);

// Decode the JSON data
$data = json_decode($callbackJSONData);

if ($data) {
    $resultCode = $data->Body->stkCallback->ResultCode;
    $resultDesc = $data->Body->stkCallback->ResultDesc;
    $merchantRequestID = $data->Body->stkCallback->MerchantRequestID;
    $checkoutRequestID = $data->Body->stkCallback->CheckoutRequestID;

    // Further processing based on ResultCode
    // 0 means Success
    if ($resultCode == 0) {
        $callbackMetadata = $data->Body->stkCallback->CallbackMetadata->Item;
        $amount = "";
        $mpesaReceiptNumber = "";
        $phoneNumber = "";
        $transactionDate = "";

        foreach ($callbackMetadata as $item) {
            if ($item->Name == "Amount") {
                $amount = $item->Value;
            } elseif ($item->Name == "MpesaReceiptNumber") {
                $mpesaReceiptNumber = $item->Value;
            } elseif ($item->Name == "PhoneNumber") {
                $phoneNumber = $item->Value;
            } elseif ($item->Name == "TransactionDate") {
                $transactionDate = $item->Value;
            }
        }

        // Logic to update your database would go here
        // Example: updateTransaction($checkoutRequestID, 'Success', $mpesaReceiptNumber);
    } else {
        // Transaction failed
        // Example: updateTransaction($checkoutRequestID, 'Failed', $resultDesc);
    }
}

// Respond to Safaricom to acknowledge receipt
header("Content-Type: application/json");
echo json_encode(["ResultCode" => 0, "ResultDesc" => "Success"]);
