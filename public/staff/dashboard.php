<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Staff.php';
require_once '../../src/models/Transaction.php';
require_once '../../src/models/Loan.php';
require_once '../../includes/helpers.php';

// Check if staff is logged in
if (!Auth::isAuthenticated() || $_SESSION['user_type'] !== 'staff') {
    header('Location: ../../public/login.php');
    exit;
}

checkSessionTimeout();

$staff_id = Auth::getUserId();
$is_loan_staff = ($_SESSION['staff_role'] ?? '') === 'loans';
$staff = new Staff($conn);
$transaction = new Transaction($conn);
$loan_counts = $is_loan_staff ? (new Loan($conn))->getStaffLoanCounts() : null;

// Get staff info
$staff_info = $staff->getStaffById($staff_id);

// Get today's transactions recorded by THIS STAFF MEMBER ONLY
$today = date('Y-m-d');
$today_start = $today . ' 00:00:00';
$today_end = $today . ' 23:59:59';

// Get all transactions for today
$all_today_transactions = $transaction->getTransactionsByDate($today);

// Filter for THIS staff's transactions today
$my_today_transactions = [];
$today_deposits = 0;
$today_withdrawals = 0;

if ($all_today_transactions) {
    foreach ($all_today_transactions as $txn) {
        // Check if this transaction was recorded by this staff member
        if (isset($txn['staff_id']) && $txn['staff_id'] == $staff_id) {
            $my_today_transactions[] = $txn;
            // Calculate totals for this staff's transactions today
            if ($txn['type'] === 'deposit') {
                $today_deposits += $txn['amount'];
            } else {
                $today_withdrawals += $txn['amount'];
            }
        }
    }
}

// Get all activity for this staff (pagination)
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * ITEMS_PER_PAGE;
$all_activity = $staff->getStaffActivity($staff_id, ITEMS_PER_PAGE, $offset);
$activity_count = $staff->getStaffActivityCount($staff_id);
$total_pages = ceil($activity_count / ITEMS_PER_PAGE);
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
    <title>Staff Dashboard - PayTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-8">
                <a href="dashboard.php" class="flex items-center shrink-0"><img src="/public/logo.png" alt="PayTrack" class="h-10 w-auto"></a>
                <div class="hidden md:flex space-x-6">
                    <a href="dashboard.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Dashboard</a>
                    <a href="activity.php" class="text-gray-700 hover:text-blue-600 font-medium">My Activity</a>
                    <a href="transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Record Transaction</a>
                    <?php if ($is_loan_staff): ?>
                        <a href="loans.php" class="text-gray-700 hover:text-blue-600 font-medium">Loan Collection</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <div class="text-right">
                    <p class="text-gray-800 font-semibold"><?php echo htmlspecialchars($_SESSION['name']); ?></p>
                    <p class="text-gray-600 text-sm"><?php echo ucfirst($_SESSION['staff_role']); ?> Staff</p>
                </div>
                <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- Staff Info Card -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">Staff Information</h3>
                <div class="space-y-3">
                    <div>
                        <p class="text-gray-600 text-sm">Name</p>
                        <p class="text-gray-800 font-semibold"><?php echo htmlspecialchars($staff_info['name']); ?></p>
                    </div>
                    <div>
                        <p class="text-gray-600 text-sm">Phone</p>
                        <p class="text-gray-800 font-semibold"><?php echo htmlspecialchars($staff_info['phone_number']); ?></p>
                    </div>
                    <div>
                        <p class="text-gray-600 text-sm">Role</p>
                        <p class="text-gray-800 font-semibold"><?php echo ucfirst($staff_info['role']); ?></p>
                    </div>
                    <div>
                        <p class="text-gray-600 text-sm">Member Since</p>
                        <p class="text-gray-800 font-semibold"><?php echo formatDate($staff_info['created_at']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Today's Stats -->
            <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg shadow p-6 text-white">
                <h3 class="text-lg font-bold mb-4">Today's Activity (24hrs)</h3>
                <div class="space-y-3">
                    <div>
                        <p class="text-blue-100 text-sm">Transactions Recorded</p>
                        <p class="text-3xl font-bold"><?php echo count($my_today_transactions); ?></p>
                    </div>
                    <div>
                        <p class="text-blue-100 text-sm">Total Deposits</p>
                        <p class="text-xl font-bold">₦<?php echo number_format($today_deposits, 2); ?></p>
                    </div>
                    <div>
                        <p class="text-blue-100 text-sm">Total Withdrawals</p>
                        <p class="text-xl font-bold">₦<?php echo number_format($today_withdrawals, 2); ?></p>
                    </div>
                    <div class="pt-3 border-t border-blue-500">
                        <p class="text-blue-100 text-xs">Resets at midnight (24-hour cycle)</p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Actions</h3>
                <div class="space-y-3">
                    <a href="transactions.php" class="block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-center font-semibold">
                        + Record Transaction
                    </a>
                    <a href="activity.php" class="block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-center font-semibold">
                        View My Activity
                    </a>
                    <?php if ($is_loan_staff): ?>
                        <a href="loans.php" class="block bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-center font-semibold">
                            Manage Loan Collections
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($is_loan_staff && $loan_counts): ?>
        <section class="mb-8">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Loan Portfolio</h2>
                    <p class="text-sm text-gray-500">Current means not past due; outstanding includes active and overdue loans.</p>
                </div>
                <a href="loans.php" class="text-blue-600 hover:text-blue-700 font-semibold">Open collection list →</a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-lg shadow p-5">
                    <p class="text-gray-500 text-sm">Customers with outstanding loans</p>
                    <p class="text-3xl font-bold text-gray-800"><?php echo $loan_counts['customers_with_outstanding']; ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-5">
                    <p class="text-gray-500 text-sm">Outstanding loans</p>
                    <p class="text-3xl font-bold text-amber-600"><?php echo $loan_counts['outstanding_loans']; ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-5">
                    <p class="text-gray-500 text-sm">Current loans</p>
                    <p class="text-3xl font-bold text-blue-600"><?php echo $loan_counts['current_loans']; ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-5">
                    <p class="text-gray-500 text-sm">Paid loans</p>
                    <p class="text-3xl font-bold text-green-600"><?php echo $loan_counts['paid_loans']; ?></p>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Today's Transactions -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-bold text-gray-800">Today's Transactions</h3>
            </div>

            <?php if (empty($my_today_transactions)): ?>
                <div class="px-6 py-8 text-center text-gray-600">
                    No transactions recorded yet today.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Reference</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Customer</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($my_today_transactions as $txn): ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                    <td class="px-6 py-4 font-mono text-sm text-gray-800"><?php echo htmlspecialchars($txn['reference_code']); ?></td>
                                    <td class="px-6 py-4 text-gray-800"><?php echo htmlspecialchars($txn['customer_name']); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $txn['type'] === 'deposit' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo ucfirst($txn['type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 font-semibold text-gray-800">₦<?php echo number_format($txn['amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($txn['description'] ?? '-'); ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?php echo date('H:i:s', strtotime($txn['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
