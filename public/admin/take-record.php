<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Transaction.php';
require_once '../../src/models/Customer.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $type = isset($_POST['type']) ? $_POST['type'] : '';
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $use_backdate = isset($_POST['use_backdate']) && $_POST['use_backdate'] === 'on';
    $transaction_date = $use_backdate ? (isset($_POST['transaction_date']) ? $_POST['transaction_date'] : null) : null;

    // Validate inputs
    if (!$customer_id || !$type || $amount <= 0) {
        $_SESSION['error'] = 'Invalid input. Please check all fields.';
        header('Location: users.php');
        exit;
    }

    if (!in_array($type, ['deposit', 'withdrawal'])) {
        $_SESSION['error'] = 'Invalid transaction type.';
        header('Location: users.php');
        exit;
    }

    // Create transaction with optional backdating
    $transaction = new Transaction($conn);
    $result = $transaction->createTransactionWithDate($customer_id, $type, $amount, $description, $transaction_date, null, Auth::getUserId());

    if ($result['success']) {
        $_SESSION['success'] = 'Transaction recorded successfully! Reference: ' . $result['reference_code'];
    } else {
        $_SESSION['error'] = $result['message'] ?? 'Failed to record transaction.';
    }

    header('Location: users.php');
    exit;
} else {
    header('Location: users.php');
    exit;
}
?>
