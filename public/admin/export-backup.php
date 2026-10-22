<?php
/**
 * Admin data export page — one-click full company backup as Excel.
 * The download itself streams from export.php?token=... (admin + CSRF).
 */
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();

$token = csrfToken();

// Table row counts for the preview cards
$tables = ['customers','staff','transactions','loans','loan_applications','loan_repayments','kyc_records','receipts','contact_messages'];
$counts = [];
foreach ($tables as $t) {
    try {
        $counts[$t] = (int)$conn->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
    } catch (Exception $e) {
        $counts[$t] = 0;
    }
}
$total_rows = array_sum($counts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <link rel="manifest" href="/public/manifest.json">
    <link rel="icon" type="image/png" href="/public/icons/icon-192.png">
    <title>Export Backup - PayTrack Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
<nav class="bg-white shadow-sm border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
        <div class="flex items-center space-x-8">
            <a href="dashboard.php" class="flex items-center shrink-0"><img src="/public/logo.png" alt="PayTrack" class="h-10 w-auto"></a>
            <div class="hidden md:flex space-x-6">
                <a href="dashboard.php" class="text-gray-700 hover:text-blue-600 font-medium">Dashboard</a>
                <a href="users.php" class="text-gray-700 hover:text-blue-600 font-medium">Customers</a>
                <a href="staff.php" class="text-gray-700 hover:text-blue-600 font-medium">Staff</a>
                <a href="staff-transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Staff Transactions</a>
                <a href="kyc.php" class="text-gray-700 hover:text-blue-600 font-medium">KYC</a>
                <a href="loans.php" class="text-gray-700 hover:text-blue-600 font-medium">Loans</a>
                <a href="receipts.php" class="text-gray-700 hover:text-blue-600 font-medium">Receipts</a>
                <a href="transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Transactions</a>
                <a href="messages.php" class="text-gray-700 hover:text-blue-600 font-medium">Messages</a>
                <a href="export-backup.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Export</a>
            </div>
        </div>
        <div class="flex items-center space-x-4">
            <span class="hidden md:inline text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Logout</a>
        </div>
    </div>
</nav>

<div class="max-w-7xl mx-auto px-4 py-8">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <h2 class="text-3xl font-bold text-gray-800">Company Data Backup</h2>
    <p class="text-gray-500 mt-1 mb-8">One file, every table — opens directly in Excel. Password hashes are never included.</p>

    <div class="bg-white rounded-xl shadow p-6 mb-8 flex flex-col md:flex-row md:items-center gap-4">
        <div class="flex-1">
            <p class="text-lg font-bold text-gray-800">Full backup — <?php echo number_format($total_rows); ?> rows across <?php echo count($tables); ?> tables</p>
            <p class="text-gray-500 text-sm mt-1">Sheets: Customers · Staff · Transactions · Loan Applications · Loans · Loan Repayments · KYC · Receipts · Messages</p>
        </div>
        <a href="export.php?token=<?php echo urlencode($token); ?>"
           class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-semibold text-center">
            ⬇ Download Excel Backup
        </a>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
        <?php
        $labels = ['customers'=>'👥 Customers','staff'=>'🧑‍💼 Staff','transactions'=>'📋 Transactions','loan_applications'=>'📝 Loan Applications','loans'=>'💳 Loans','loan_repayments'=>'💰 Repayments','kyc_records'=>'🪪 KYC Records','receipts'=>'🧾 Receipts','contact_messages'=>'✉️ Messages'];
        foreach ($labels as $t => $label):
        ?>
        <div class="bg-white rounded-xl shadow p-5">
            <p class="text-gray-500 text-xs font-semibold uppercase tracking-wide mb-1"><?php echo $label; ?></p>
            <p class="text-3xl font-bold text-gray-800"><?php echo number_format($counts[$t] ?? 0); ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-8 p-4 bg-yellow-50 border border-yellow-300 rounded-lg text-sm text-yellow-800">
        <strong>Keep backups safe:</strong> this file contains customer names, phones, balances and loan amounts.
        Store it securely, never email it unencrypted, and delete old copies you no longer need.
    </div>
</div>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
