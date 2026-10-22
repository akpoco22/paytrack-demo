<?php
/**
 * Mail (SMTP) Configuration
 * Extol Global Service
 *
 * Fill in your SMTP provider details here. For production, move these
 * to real environment variables and read them with getenv().
 */

// SMTP server
define('MAIL_HOST', 'mail.xtramarketcenter.com');
define('MAIL_PORT', 465);

// 'tls' (STARTTLS, usually port 587), 'ssl' (implicit TLS, usually port 465),
// or '' for no encryption (local dev only — never use in production)
define('MAIL_ENCRYPTION', 'ssl');

// SMTP auth (usually your mailbox address + an app password)
define('MAIL_USERNAME', getenv('MAIL_USERNAME') ?: '');
define('MAIL_PASSWORD', getenv('MAIL_PASSWORD') ?: '');

// What customers see in the From field
define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: 'notifications@paytrack.app');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'PayTrack');

// Seconds to wait for the SMTP server before giving up.
// Sends always run non-blocking: a slow server must never break loan,
// repayment, KYC or transaction actions.
define('MAIL_TIMEOUT', 10);
?>
