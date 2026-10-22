<?php
/**
 * SMS (Termii) Configuration
 * Extol Global Service
 *
 * Sign up at https://termii.com → get an API key + Sender ID, then fill in
 * below. While TERMII_API_KEY is empty/placeholder, all SMS sends are
 * skipped silently — the app works exactly as before.
 */

// Termii API key (starts with TL...). Get it from termii.com dashboard.
define('TERMII_API_KEY', '');

// Sender ID approved on your Termii account (e.g. 'PayTrack' — max 11 chars,
// must be registered/approved in Termii before live sends work).
define('TERMII_SENDER_ID', 'PayTrack');

// Seconds to wait for Termii before giving up. Like mail, SMS must never
// block or break loan/repayment/KYC/transaction actions.
define('TERMII_TIMEOUT', 10);
?>
