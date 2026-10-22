<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Customer.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $password_option = isset($_POST['password_option']) ? $_POST['password_option'] : 'generate';
    $custom_password = isset($_POST['custom_password']) ? trim($_POST['custom_password']) : '';

    // Validate customer ID
    if ($customer_id === 0) {
        $_SESSION['error'] = 'Invalid user ID.';
        header('Location: users.php');
        exit;
    }

    // Determine the new password
    if ($password_option === 'custom') {
        if (empty($custom_password)) {
            $_SESSION['error'] = 'Custom password cannot be empty.';
            header('Location: profile.php?id=' . $customer_id);
            exit;
        }
        if (strlen($custom_password) < 8) {
            $_SESSION['error'] = 'Password must be at least 8 characters.';
            header('Location: profile.php?id=' . $customer_id);
            exit;
        }
        $new_password = $custom_password;
    } else {
        // Generate a random password
        $new_password = generateRandomPassword();
    }

    // Update password
    $customer = new Customer($conn);
    $result = $customer->updatePassword($customer_id, $new_password);

    if ($result['success']) {
        $_SESSION['success'] = "Password reset successfully! New password: " . htmlspecialchars($new_password);
    } else {
        $_SESSION['error'] = $result['message'] ?? 'Failed to reset password.';
    }

    header('Location: profile.php?id=' . $customer_id);
    exit;
} else {
    header('Location: users.php');
    exit;
}

/**
 * Generate a random password
 */
function generateRandomPassword($length = 12) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
}
?>
