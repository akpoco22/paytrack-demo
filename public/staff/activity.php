<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Staff.php';
require_once '../../includes/helpers.php';

$is_loan_staff = ($_SESSION['staff_role'] ?? '') === 'loans';

// Check if staff is logged in
if (!Auth::isAuthenticated() || $_SESSION['user_type'] !== 'staff') {
    header('Location: ../../public/login.php');
    exit;
}

checkSessionTimeout();

$staff_id = Auth::getUserId();
$staff = new Staff($conn);

// Get staff info
$staff_info = $staff->getStaffById($staff_id);

// Get pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * ITEMS_PER_PAGE;

// Get date filter
$date_from = isset($_GET['from']) ? sanitize($_GET['from']) : null;
$date_to = isset($_GET['to']) ? sanitize($_GET['to']) : null;

// Get activity with optional date filtering
$activity = $staff->getStaffActivity($staff_id, ITEMS_PER_PAGE, $offset, $date_from, $date_to);
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
    <title>My Activity - PayTrack Staff</title>
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
                    <a href="activity.php" class="text-blue-600 font-medium border-b-2 border-blue-600">My Activity</a>
                    <a href="transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Record Transaction</a>
                    <?php if ($is_loan_staff): ?>
                        <a href="loans.php" class="text-gray-700 hover:text-blue-600 font-medium">Loan Collection</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-3xl font-bold text-gray-800">My Activity</h2>
            <a href="dashboard.php" class="text-blue-600 hover:text-blue-700 font-semibold">← Back to Dashboard</a>
        </div>

        <!-- Date Filter -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <form method="GET" class="flex gap-4 items-end">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">From Date</label>
                    <input type="date" name="from" value="<?php echo htmlspecialchars($date_from ?? ''); ?>" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">To Date</label>
                    <input type="date" name="to" value="<?php echo htmlspecialchars($date_to ?? ''); ?>" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-semibold">Filter</button>
                <?php if ($date_from || $date_to): ?>
                    <a href="activity.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg font-semibold">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Activity Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Reference Code</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activity)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-600">
                                    No transactions recorded.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($activity as $txn): ?>
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
                                    <td class="px-6 py-4 text-gray-600"><?php echo formatDateTime($txn['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-center space-x-2 bg-gray-50">
                    <?php if ($page > 1): ?>
                        <a href="?page=1<?php echo ($date_from ? '&from=' . urlencode($date_from) : '') . ($date_to ? '&to=' . urlencode($date_to) : ''); ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">First</a>
                        <a href="?page=<?php echo $page - 1; ?><?php echo ($date_from ? '&from=' . urlencode($date_from) : '') . ($date_to ? '&to=' . urlencode($date_to) : ''); ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Previous</a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo ($date_from ? '&from=' . urlencode($date_from) : '') . ($date_to ? '&to=' . urlencode($date_to) : ''); ?>"
                           class="px-3 py-1 rounded <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo ($date_from ? '&from=' . urlencode($date_from) : '') . ($date_to ? '&to=' . urlencode($date_to) : ''); ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Next</a>
                        <a href="?page=<?php echo $total_pages; ?><?php echo ($date_from ? '&from=' . urlencode($date_from) : '') . ($date_to ? '&to=' . urlencode($date_to) : ''); ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Last</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
