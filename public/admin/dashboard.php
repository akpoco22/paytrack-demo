<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Customer.php';
require_once '../../src/models/Transaction.php';
require_once '../../src/models/Staff.php';
require_once '../../src/models/KYC.php';
require_once '../../src/models/Loan.php';
require_once '../../src/models/LoanApplication.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();

$customer    = new Customer($conn);
$transaction = new Transaction($conn);
$staff_model = new Staff($conn);
$kyc_model   = new KYC($conn);
$loan_model  = new Loan($conn);
$app_model   = new LoanApplication($conn);

$stats            = $transaction->getSystemStats();
$total_customers  = $customer->getTotalCustomerCount();
$system_balance   = $customer->getTotalSystemBalance();
$total_staff      = $staff_model->getTotalStaffCount();
$pending_kyc      = $kyc_model->getPendingKYCCount();
$active_loans     = $conn->query("SELECT COUNT(*) as count FROM loans WHERE status = 'active'")->fetch(PDO::FETCH_ASSOC)['count'];
$pending_loan_apps = $app_model->countByStatus('pending');
$recent_txns      = $transaction->getAllTransactions(5, 0);
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
    <title>Dashboard - PayTrack Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">

<!-- Navigation -->
<nav class="bg-white shadow-sm border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
        <div class="flex items-center space-x-8">
            <a href="dashboard.php" class="flex items-center shrink-0"><img src="/public/logo.png" alt="PayTrack" class="h-10 w-auto"></a>
            <div class="hidden md:flex space-x-6">
                <a href="dashboard.php"          class="text-blue-600 font-medium border-b-2 border-blue-600">Dashboard</a>
                <a href="users.php"              class="text-gray-700 hover:text-blue-600 font-medium">Customers</a>
                <a href="staff.php"              class="text-gray-700 hover:text-blue-600 font-medium">Staff</a>
                <a href="staff-transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Staff Transactions</a>
                <a href="kyc.php"                class="text-gray-700 hover:text-blue-600 font-medium">KYC</a>
                <a href="loans.php"              class="text-gray-700 hover:text-blue-600 font-medium">Loans</a>
                <a href="loan-applications.php"  class="text-gray-700 hover:text-blue-600 font-medium relative flex items-center">
                    Loan Requests
                    <?php if ($pending_loan_apps > 0): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-amber-500 text-white ml-1"><?php echo $pending_loan_apps; ?></span>
                    <?php endif; ?>
                </a>
                <a href="receipts.php"           class="text-gray-700 hover:text-blue-600 font-medium">Receipts</a>
                <a href="transactions.php"       class="text-gray-700 hover:text-blue-600 font-medium">Transactions</a>
                <a href="messages.php"           class="text-gray-700 hover:text-blue-600 font-medium">Messages</a>
                <a href="export-backup.php" class="text-gray-700 hover:text-blue-600 font-medium">Export</a>
            </div>
        </div>
        <div class="flex items-center space-x-4">
            <span class="hidden md:inline text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Logout</a>
            <!-- Mobile menu button -->
            <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg text-gray-600 hover:bg-gray-100">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
        </div>
    </div>
    <!-- Mobile menu -->
    <div id="mobileMenu" class="hidden md:hidden border-t border-gray-200 px-4 py-3 space-y-2 bg-white">
        <a href="dashboard.php"          class="block px-3 py-2 rounded-lg text-blue-600 bg-blue-50 font-medium">Dashboard</a>
        <a href="users.php"              class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 font-medium">Customers</a>
        <a href="staff.php"              class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 font-medium">Staff</a>
        <a href="staff-transactions.php" class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 font-medium">Staff Transactions</a>
        <a href="kyc.php"                class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 font-medium">KYC</a>
        <a href="loans.php"              class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 font-medium">Loans</a>
        <a href="loan-applications.php"  class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 font-medium">
            Loan Requests <?php if ($pending_loan_apps > 0): ?><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-amber-500 text-white ml-1"><?php echo $pending_loan_apps; ?></span><?php endif; ?>
        </a>
        <a href="receipts.php"           class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 font-medium">Receipts</a>
        <a href="transactions.php"       class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 font-medium">Transactions</a>
        <a href="messages.php"           class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 font-medium">Messages</a>
                <a href="export-backup.php" class="text-gray-700 hover:text-blue-600 font-medium">Export</a>
        <div class="pt-2 border-t border-gray-200">
            <span class="block px-3 py-1 text-sm text-gray-500"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <a href="../../public/logout.php" class="block px-3 py-2 rounded-lg text-red-600 hover:bg-red-50 font-medium">Logout</a>
        </div>
    </div>
</nav>

<!-- Main Content -->
<div class="max-w-7xl mx-auto px-4 py-8">

    <!-- Header -->
    <div class="mb-8">
        <h2 class="text-3xl font-bold text-gray-800">Dashboard</h2>
        <p class="text-gray-500 mt-1">System overview — <?php echo date('l, F j, Y'); ?></p>
    </div>

    <?php if ($pending_kyc > 0): ?>
    <div class="mb-6 p-4 bg-yellow-50 border border-yellow-300 rounded-lg flex items-center gap-3">
        <span class="text-2xl">⏳</span>
        <div>
            <p class="font-semibold text-yellow-800">
                <?php echo $pending_kyc; ?> KYC record<?php echo $pending_kyc > 1 ? 's' : ''; ?> awaiting review
            </p>
            <a href="kyc.php?status=pending" class="text-yellow-700 underline text-sm">Review now →</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($pending_loan_apps > 0): ?>
    <div class="mb-6 p-4 bg-amber-50 border border-amber-300 rounded-lg flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <span class="text-2xl">📋</span>
            <div>
                <p class="font-semibold text-amber-900">
                    <?php echo $pending_loan_apps; ?> customer loan application<?php echo $pending_loan_apps > 1 ? 's' : ''; ?> awaiting review
                </p>
                <p class="text-xs text-amber-700">Customers have submitted loan requests that need your approval.</p>
            </div>
        </div>
        <a href="loan-applications.php?status=pending" class="bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap">Review now →</a>
    </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10">

        <!-- System Balance -->
        <div class="col-span-2 bg-gradient-to-br from-blue-600 to-blue-700 rounded-xl shadow p-6 text-white">
            <p class="text-blue-200 text-sm font-semibold uppercase tracking-wide mb-1">Total System Balance</p>
            <p class="text-4xl font-bold"><?php echo formatCurrency($system_balance ?? 0); ?></p>
            <p class="text-blue-200 text-xs mt-2">Across all customer accounts</p>
        </div>

        <!-- Total Deposits -->
        <div class="bg-white rounded-xl shadow p-5 border-l-4 border-blue-500">
            <p class="text-gray-500 text-xs font-semibold uppercase tracking-wide mb-1">Total Deposits</p>
            <p class="text-2xl font-bold text-gray-800"><?php echo formatCurrency($stats['total_deposits'] ?? 0); ?></p>
            <p class="text-blue-600 text-xs mt-1">↑ All time</p>
        </div>

        <!-- Total Withdrawals -->
        <div class="bg-white rounded-xl shadow p-5 border-l-4 border-red-400">
            <p class="text-gray-500 text-xs font-semibold uppercase tracking-wide mb-1">Total Withdrawals</p>
            <p class="text-2xl font-bold text-gray-800"><?php echo formatCurrency($stats['total_withdrawals'] ?? 0); ?></p>
            <p class="text-red-500 text-xs mt-1">↓ All time</p>
        </div>

        <!-- Loan Repayments -->
        <div class="bg-white rounded-xl shadow p-5 border-l-4 border-indigo-400">
            <div class="flex items-center justify-between mb-2">
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wide">Loan Repayments</p>
                <span class="text-2xl">💰</span>
            </div>
            <p class="text-2xl font-bold text-gray-800"><?php echo formatCurrency($stats['total_loan_repayments'] ?? 0); ?></p>
            <a href="transactions.php?type=loan_repayment" class="text-blue-600 text-xs hover:underline">View repayments →</a>
        </div>

        <!-- Customers -->
        <div class="bg-white rounded-xl shadow p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wide">Customers</p>
                <span class="text-2xl">👥</span>
            </div>
            <p class="text-3xl font-bold text-gray-800"><?php echo number_format($total_customers); ?></p>
            <a href="users.php" class="text-blue-600 text-xs hover:underline">View all →</a>
        </div>

        <!-- Total Transactions -->
        <div class="bg-white rounded-xl shadow p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wide">Transactions</p>
                <span class="text-2xl">📋</span>
            </div>
            <p class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['total_transactions'] ?? 0); ?></p>
            <a href="transactions.php" class="text-blue-600 text-xs hover:underline">View all →</a>
        </div>

        <!-- Staff -->
        <div class="bg-white rounded-xl shadow p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wide">Staff</p>
                <span class="text-2xl">🧑‍💼</span>
            </div>
            <p class="text-3xl font-bold text-gray-800"><?php echo number_format($total_staff); ?></p>
            <a href="staff.php" class="text-blue-600 text-xs hover:underline">Manage →</a>
        </div>

        <!-- Active Loans -->
        <div class="bg-white rounded-xl shadow p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wide">Active Loans</p>
                <span class="text-2xl">💳</span>
            </div>
            <p class="text-3xl font-bold text-gray-800"><?php echo number_format($active_loans); ?></p>
            <a href="loans.php" class="text-blue-600 text-xs hover:underline">View loans →</a>
        </div>

    </div>

    <!-- Quick Actions -->
    <div class="mb-8">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Actions</h3>
        <div class="flex flex-wrap gap-3">
            <a href="users.php"         class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-medium text-sm transition">+ Add Customer</a>
            <a href="staff.php"         class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-medium text-sm transition">+ Register Staff</a>
            <a href="kyc.php?status=pending" class="bg-yellow-500 hover:bg-yellow-600 text-white px-5 py-2 rounded-lg font-medium text-sm transition">Review KYC <?php if ($pending_kyc > 0): ?>(<?php echo $pending_kyc; ?>)<?php endif; ?></a>
            <a href="loan-applications.php?status=pending" class="bg-amber-600 hover:bg-amber-700 text-white px-5 py-2 rounded-lg font-medium text-sm transition">Loan Requests <?php if ($pending_loan_apps > 0): ?>(<?php echo $pending_loan_apps; ?>)<?php endif; ?></a>
            <a href="loans.php"         class="bg-purple-600 hover:bg-purple-700 text-white px-5 py-2 rounded-lg font-medium text-sm transition">Manage Loans</a>
            <a href="staff-transactions.php" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-medium text-sm transition">Staff Activity</a>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-bold text-gray-800">Recent Transactions</h3>
            <a href="transactions.php" class="text-blue-600 hover:text-blue-700 text-sm font-medium">View all →</a>
        </div>

        <?php if (empty($recent_txns)): ?>
        <div class="px-6 py-12 text-center">
            <p class="text-4xl mb-3">📭</p>
            <p class="text-gray-500">No transactions yet.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Reference</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_txns as $txn): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                        <td class="px-6 py-4 font-medium text-gray-800">
                            <a href="profile.php?id=<?php echo $txn['customer_id']; ?>" class="text-blue-600 hover:underline">
                                <?php echo htmlspecialchars($txn['name']); ?>
                            </a>
                        </td>
                        <td class="px-6 py-4 font-mono text-xs text-gray-500"><?php echo htmlspecialchars($txn['reference_code']); ?></td>
                        <td class="px-6 py-4">
                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $txn['type'] === 'deposit' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                <?php echo ucfirst($txn['type']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 font-semibold text-gray-800"><?php echo formatCurrency($txn['amount']); ?></td>
                        <td class="px-6 py-4 text-gray-500 text-sm"><?php echo formatDateTime($txn['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<script src="/assets/js/pwa.js"></script>
<script>
    const btn  = document.getElementById('mobileMenuBtn');
    const menu = document.getElementById('mobileMenu');
    btn.addEventListener('click', () => menu.classList.toggle('hidden'));
</script>
</body>
</html>
