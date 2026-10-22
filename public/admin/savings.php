<?php
session_start();
require_once '../../src/auth/Auth.php';
if (Auth::isAuthenticated() && Auth::isAdmin()) {
    header('Location: dashboard.php');
} else {
    header('Location: ../../public/login.php');
}
exit;
