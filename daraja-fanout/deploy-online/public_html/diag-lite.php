<?php
// diag-lite: no creds, no source dump. Upload temporarily, open once, delete.
header('Content-Type: text/plain');
echo "PHP " . PHP_VERSION . "\n";
echo "PDO: " . implode(',', PDO::getAvailableDrivers()) . "\n";
echo "config loaded: " . (defined('DB_HOST') ? 'YES (db_host=' . DB_HOST . ')' : 'NO') . "\n";
echo "Authorization passed to PHP: " . (isset($_SERVER['HTTP_AUTHORIZATION']) ? 'yes' : 'no') . "\n";
echo "PHP_AUTH_USER: " . (isset($_SERVER['PHP_AUTH_USER']) ? 'yes' : 'no') . "\n";
