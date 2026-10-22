<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/mail.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Customer.php';
require_once '../../includes/helpers.php';
require_once '../../includes/mail-notify.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $card_id = isset($_POST['card_id']) ? trim($_POST['card_id']) : '';
    $phone_number = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';

    // Validate inputs
    if (empty($name) || empty($card_id) || empty($phone_number)) {
        $_SESSION['error'] = 'Name, Card ID, and phone number are required.';
        header('Location: users.php');
        exit;
    }

    // Email is optional but validate if provided
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email format.';
        header('Location: users.php');
        exit;
    }

    // Create the customer
    $customer = new Customer($conn);
    $result = $customer->createCustomer($name, $email ?: null, $card_id, $phone_number, $address);

    if ($result['success']) {
        $mail_note = '';
        if (!empty($email)) {
            $mail_note = notifyCustomer('welcome', [
                'customer' => ['name' => $name, 'email' => $email, 'phone_number' => $phone_number],
                'password' => $result['password'],
                'card_id'  => $result['card_id'],
            ]);
        }
        $_SESSION['success'] = "User '{$name}' created successfully! Card ID: " . htmlspecialchars($result['card_id']) . " | Default Password: " . htmlspecialchars($result['password']) . htmlspecialchars($mail_note);
    } else {
        $_SESSION['error'] = $result['message'] ?? 'Failed to create user. Email or Card ID may already exist.';
    }

    header('Location: users.php');
    exit;
} else {
    header('Location: users.php');
    exit;
}
?>
