<?php
/**
 * Daraja API Configuration
 */
// Daraja App Credentials
// These should ideally be set as Environment Variables on your hosting platform (Render/Vercel)
define('CONSUMER_KEY', getenv('MPESA_CONSUMER_KEY') ?: '');
define('CONSUMER_SECRET', getenv('MPESA_CONSUMER_SECRET') ?: '');

// M-Pesa Shortcode and Passkey
define('BUSINESS_SHORTCODE', getenv('MPESA_SHORTCODE') ?: '174379');
define('LIPA_NA_MPESA_PASSKEY', getenv('MPESA_PASSKEY') ?: '');

// Environment Setup
define('ENVIRONMENT', getenv('MPESA_ENV') ?: 'sandbox'); // change to 'production' for live apps

// API URLs
$baseUrl = (ENVIRONMENT === 'production')
    ? 'https://api.safaricom.co.ke'
    : 'https://sandbox.safaricom.co.ke';

define('TOKEN_URL', $baseUrl . '/oauth/v1/generate?grant_type=client_credentials');
define('STK_PROCESS_URL', $baseUrl . '/mpesa/stkpush/v1/processrequest');

// Callback URL - MUST be publicly accessible
// On Render, this will be https://your-app-name.onrender.com/callback.php
define('CALLBACK_URL', getenv('MPESA_CALLBACK_URL') ?: '');
// Timestamp format used by Daraja
define('TIMESTAMP', date('YmdHis'));
