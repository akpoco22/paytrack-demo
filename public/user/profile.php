<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Customer.php';
require_once '../../includes/helpers.php';

requireUser();
checkSessionTimeout();

$user_id = Auth::getUserId();
$customer = new Customer($conn);
$user_info = $customer->getUserInfo($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="PayTrack">
    <link rel="manifest" href="/public/manifest.json">
    <link rel="icon" type="image/png" href="/public/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/public/icons/icon-192.png">
    <title>Profile - PayTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-8">
                <a href="dashboard.php" class="flex items-center shrink-0"><img src="/public/logo.png" alt="PayTrack" class="h-10 w-auto"></a>
                <div class="hidden md:flex space-x-6">
                    <a href="dashboard.php" class="text-gray-700 hover:text-blue-600 font-medium">Dashboard</a>
                    <a href="transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Transactions</a>
                    <a href="contact.php" class="text-gray-700 hover:text-blue-600 font-medium">Contact Admin</a>
                    <a href="profile.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Profile</a>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-4xl mx-auto px-4 py-8">
        <h2 class="text-3xl font-bold text-gray-800 mb-8">My Profile</h2>

        <!-- Profile Information -->
        <div class="bg-white rounded-lg shadow p-8">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Left Column -->
                <div class="space-y-6">
                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Full Name</p>
                        <p class="text-gray-800 text-lg mt-1"><?php echo htmlspecialchars($user_info['name']); ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Email Address</p>
                        <p class="text-gray-800 text-lg mt-1"><?php echo htmlspecialchars($user_info['email']); ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Phone Number</p>
                        <p class="text-gray-800 text-lg mt-1"><?php echo htmlspecialchars($user_info['phone_number'] ?? 'Not provided'); ?></p>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-6">
                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Address</p>
                        <p class="text-gray-800 text-lg mt-1"><?php echo htmlspecialchars($user_info['address'] ?? 'Not provided'); ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Current Balance</p>
                        <p class="text-gray-800 text-lg mt-1 font-bold text-blue-600"><?php echo formatCurrency($user_info['balance']); ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Member Since</p>
                        <p class="text-gray-800 text-lg mt-1"><?php echo formatDate($user_info['created_at']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Info Message -->
            <div class="mt-8 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-blue-800 text-sm">
                    <span class="font-semibold">Note:</span> To update your profile information, please contact the admin using the Contact Admin page.
                </p>
            </div>
        </div>
    </div>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
