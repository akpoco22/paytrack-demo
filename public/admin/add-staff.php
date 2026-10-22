<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Staff.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $phone_number = sanitize($_POST['phone_number'] ?? '');
    $staff_id = sanitize($_POST['staff_id'] ?? '');
    $role = sanitize($_POST['role'] ?? '');
    $address = sanitize($_POST['address'] ?? '');

    if (!$name || !$phone_number || !$staff_id || !$role) {
        $_SESSION['error'] = 'Please fill in all required fields';
        header('Location: staff.php');
        exit;
    }

    if ($role !== 'loans') {
        $_SESSION['error'] = 'Invalid role selected';
        header('Location: staff.php');
        exit;
    }

    $staff = new Staff($conn);
    $result = $staff->createStaff($name, $phone_number, $staff_id, $role, $address);

    if ($result['success']) {
        $_SESSION['success'] = 'Staff member registered successfully! Staff ID: ' . $result['staff_id'];
    } else {
        $_SESSION['error'] = $result['message'] ?? 'Failed to register staff member';
    }

    header('Location: staff.php');
    exit;
} else {
    header('Location: staff.php');
    exit;
}
?>
