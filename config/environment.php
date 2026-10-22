<?php
/**
 * Environment Configuration
 * PayTrack - Savings & Loans Management System
 */

// Application settings (override with real env vars in production)
define('APP_NAME', getenv('PAYTRACK_APP_NAME') ?: (getenv('EXTOL_APP_NAME') ?: 'PayTrack'));
define('APP_URL', getenv('PAYTRACK_APP_URL') ?: (getenv('EXTOL_APP_URL') ?: ''));
define('APP_ENV', getenv('PAYTRACK_APP_ENV') ?: (getenv('EXTOL_APP_ENV') ?: 'development')); // development or production

// Session settings
define('SESSION_TIMEOUT', 3600); // 1 hour
define('REMEMBER_ME_DURATION', 604800); // 7 days

// Security settings
define('PASSWORD_MIN_LENGTH', 8);
define('HASH_ALGORITHM', 'bcrypt');

// Pagination
define('ITEMS_PER_PAGE', 15);

// File paths
define('PROJECT_ROOT', dirname(dirname(__FILE__)));
define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
define('SRC_PATH', PROJECT_ROOT . '/src');
?>
