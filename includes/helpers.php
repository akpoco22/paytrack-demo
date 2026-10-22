<?php
/**
 * Route Protection Helpers
 * Extol Global Service
 */

/**
 * Ensure the session cookie has HttpOnly, Secure, SameSite=Lax flags,
 * even when ini settings are not enforced by the web server.
 * Safe to call whether session has started or not.
 */
function hardenSessionCookie() {
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        $params = session_get_cookie_params();
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        setcookie(
            session_name(),
            session_id(),
            [
                'expires'  => $params['lifetime'] > 0 ? time() + $params['lifetime'] : 0,
                'path'     => $params['path'] ?: '/',
                'domain'   => $params['domain'] ?: '',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }
}

// Auto-run when helpers.php is included on an active session
hardenSessionCookie();

/**
 * Require authentication
 */
function requireAuth() {
    if (!Auth::isAuthenticated()) {
        header('Location: /public/login.php');
        exit;
    }
}

/**
 * Require admin access
 */
function requireAdmin() {
    requireAuth();
    if (!Auth::isAdmin()) {
        header('Location: /public/user/dashboard.php');
        exit;
    }
}

/**
 * Require user access (not admin)
 */
function requireUser() {
    requireAuth();
    if (Auth::isAdmin()) {
        header('Location: /public/admin/dashboard.php');
        exit;
    }
}

/**
 * Check session timeout
 */
function checkSessionTimeout() {
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
        session_destroy();
        header('Location: /public/login.php?expired=1');
        exit;
    }
}

/**
 * CSRF protection — per-session token, timing-safe verification.
 */
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrf() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true; // only state-changing requests need a token
    }
    $sent = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || !hash_equals($expected, (string)$sent)) {
        return false;
    }
    return true;
}

/**
 * Reject a POST without a valid CSRF token. Call at the top of every
 * POST handler after requireAdmin()/requireUser()/staff check.
 */
function requireCsrf() {
    if (!verifyCsrf()) {
        http_response_code(419);
        $_SESSION['error'] = 'Session expired — please try again.';
        $back = $_SERVER['HTTP_REFERER'] ?? '';
        $path = parse_url($back, PHP_URL_PATH);
        $host = parse_url($back, PHP_URL_HOST);
        // Only redirect back on the same host (no open-redirect); else login.
        if (!$path || $path[0] !== '/' || str_starts_with($path, '//')
            || ($host && isset($_SERVER['HTTP_HOST']) && $host !== $_SERVER['HTTP_HOST'])) {
            $path = '/public/login.php';
        }
        header('Location: ' . $path);
        exit;
    }
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return '₦' . number_format($amount, 2);
}

/**
 * Format date
 */
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

/**
 * Format datetime
 */
function formatDateTime($datetime) {
    return date('M d, Y H:i A', strtotime($datetime));
}

/** Human-readable transaction type, including automated loan repayments. */
function transactionTypeLabel($type) {
    return match ($type) {
        'loan_repayment' => 'Loan Repayment',
        'deposit' => 'Deposit',
        'withdrawal' => 'Withdrawal',
        default => ucfirst(str_replace('_', ' ', (string)$type)),
    };
}

/**
 * Sanitize input
 */
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}
?>
