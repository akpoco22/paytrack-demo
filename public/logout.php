<?php
session_start();

require_once '../config/database.php';
require_once '../src/auth/Auth.php';

$auth = new Auth($conn);
$auth->logout();

header('Location: login.php');
exit;
?>
