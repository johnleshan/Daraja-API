<?php
/**
 * mpesa_private/config.php  —  MAIN CONFIG (one file, shared: callback.php + transactions.php)
 *
 * UPLOAD LOCATION: NOT in public_html. Put this file one level ABOVE public_html,
 * i.e. /home/<cpanel-user>/mpesa_private/config.php
 * Web visitors can never fetch a file outside public_html, so the values below are
 * unreachable by URL even if the site is misconfigured.
 *
 * callback.php and transactions.php both do:
 *     require __DIR__ . '/../mpesa_private/config.php';
 * so keep this exact filename/folder.
 */

/* ---------- MySQL (same DB as the transactions table) ---------- */
const DB_HOST = 'localhost';
const DB_NAME = 'kenenia_daraja';
const DB_USER = 'kenenia_daraja_api';
const DB_PASS = 'CHANGE_ME_db_password';

/* ---------- transactions.php API auth ---------- */
const API_USER = 'askene';
const API_PASS = 'CHANGE_ME_strong';
const API_TOKEN = '';   // optional; X-API-Token header auth fallback

/* ---------- Safaricom IP allowlist (callback.php POSTs only) ----------
 * Leave as empty array => allow all (fine while testing).
 * For go-live fill with Daraja production callback IPs, e.g.:
 *   ['196.201.214.200','196.201.214.206', ...] - verify current list on
 *   https://developer.safaricom.co.ke/APIs (C2B page, "IPs" section)
 */
const SAFARICOM_IPS = [];

/* ---------- Housekeeping ---------- */
const DEBUG = false;              // true = logs real errors in responses; never dumps creds
const SAVE_LOCAL_FILES = true;    // write mpesa_logs/ raw audit + JSON + CSV

// dirname() is not valid in a const expression on PHP 7, so LOG_DIR is defined()
define('LOG_DIR', dirname(__DIR__) . '/mpesa_logs');
