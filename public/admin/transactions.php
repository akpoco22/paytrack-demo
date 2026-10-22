<?php
session_start();
require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Transaction.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();

$transaction = new Transaction($conn);
$type = isset($_GET['type']) && in_array($_GET['type'], ['deposit','withdrawal','loan_repayment'], true) ? $_GET['type'] : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
foreach (['date_from' => &$date_from, 'date_to' => &$date_to] as &$value) {
    if ($value !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) $value = '';
    }
}
unset($value);
if ($date_from && $date_to && $date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];
$page = max(1, (int)($_GET['page'] ?? 1));
$stats = $transaction->getFilteredTransactionStats($type, $date_from ?: null, $date_to ?: null);
$total_transactions = (int)$stats['total_count'];
$total_pages = max(1, (int)ceil($total_transactions / ITEMS_PER_PAGE));
$page = min($page, $total_pages);
$transactions = $transaction->getAllTransactions(ITEMS_PER_PAGE, ($page - 1) * ITEMS_PER_PAGE, $type ?: null, $date_from ?: null, $date_to ?: null);
$filters = array_filter(['type' => $type, 'date_from' => $date_from, 'date_to' => $date_to], static fn($v) => $v !== '');
$query_string = static function ($overrides = []) use ($filters) {
    $query = array_merge($filters, $overrides);
    return '?' . http_build_query(array_filter($query, static fn($v) => $v !== ''));
};
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
    <title>Transactions - PayTrack Admin</title>
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
                <a href="transactions.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Transactions</a>
                <a href="messages.php" class="text-gray-700 hover:text-blue-600 font-medium">Messages</a>
                <a href="export-backup.php" class="text-gray-700 hover:text-blue-600 font-medium">Export</a>
            </div>
        </div>
        <div class="flex items-center space-x-4">
            <span class="text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
        </div>
    </div>
</nav>
<main class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-3 mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Deposit &amp; Withdrawal Transactions</h1>
            <p class="text-gray-500 mt-1">This ledger shows deposits, withdrawals, and loan repayments.</p>
            <p class="text-gray-500 text-sm mt-1">Record a transaction through the customer list or open an individual customer profile.</p>
        </div>
        <a href="users.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-semibold text-center">Record transaction → Select customer</a>
    </div>

    <section class="bg-white rounded-lg shadow p-5 mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
            <div>
                <label for="type" class="block text-sm font-semibold text-gray-700 mb-1">Type</label>
                <select id="type" name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">All types</option>
                    <option value="deposit" <?php echo $type === 'deposit' ? 'selected' : ''; ?>>Deposits</option>
                    <option value="withdrawal" <?php echo $type === 'withdrawal' ? 'selected' : ''; ?>>Withdrawals</option>
                    <option value="loan_repayment" <?php echo $type === 'loan_repayment' ? 'selected' : ''; ?>>Loan repayments</option>
                </select>
            </div>
            <div><label for="date_from" class="block text-sm font-semibold text-gray-700 mb-1">From</label><input id="date_from" type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
            <div><label for="date_to" class="block text-sm font-semibold text-gray-700 mb-1">To</label><input id="date_to" type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
            <div class="flex gap-2"><button class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-semibold">Filter</button><a href="transactions.php" class="px-4 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold">Clear</a></div>
        </form>
    </section>

    <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-5"><p class="text-sm text-gray-500">Matching transactions</p><p class="text-2xl font-bold text-gray-800"><?php echo number_format($total_transactions); ?></p></div>
        <div class="bg-white rounded-lg shadow p-5"><p class="text-sm text-gray-500">Deposit total</p><p class="text-2xl font-bold text-green-700"><?php echo formatCurrency($stats['total_deposits']); ?></p></div>
        <div class="bg-white rounded-lg shadow p-5"><p class="text-sm text-gray-500">Withdrawal total</p><p class="text-2xl font-bold text-red-700"><?php echo formatCurrency($stats['total_withdrawals']); ?></p></div>
        <div class="bg-white rounded-lg shadow p-5"><p class="text-sm text-gray-500">Loan repayment total</p><p class="text-2xl font-bold text-blue-700"><?php echo formatCurrency($stats['total_loan_repayments'] ?? 0); ?></p></div>
    </section>

    <section class="bg-white rounded-lg shadow overflow-hidden">
        <?php if (!$transactions): ?>
            <div class="px-6 py-12 text-center text-gray-500">No transactions match these filters.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px]">
                <thead class="bg-gray-50 border-b border-gray-200"><tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Customer</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Reference</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Type</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Amount</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Recorded by</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                </tr></thead>
                <tbody>
                <?php foreach ($transactions as $row):
                    if ($row['staff_id'] !== null) $recorder = 'Staff: ' . ($row['staff_name'] ?: 'ID ' . $row['staff_id']);
                    elseif ($row['admin_id'] !== null) $recorder = 'Admin: ' . ($row['admin_name'] ?: 'ID ' . $row['admin_id']);
                    else $recorder = 'Legacy / unattributed';
                ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                        <td class="px-5 py-4"><a href="profile.php?id=<?php echo (int)$row['customer_id']; ?>" class="font-semibold text-blue-700 hover:underline"><?php echo htmlspecialchars($row['name']); ?></a></td>
                        <td class="px-5 py-4 font-mono text-xs text-gray-700"><?php echo htmlspecialchars($row['reference_code']); ?></td>
                        <td class="px-5 py-4"><span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $row['type'] === 'deposit' ? 'bg-green-100 text-green-800' : ($row['type'] === 'loan_repayment' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800'); ?>"><?php echo htmlspecialchars(transactionTypeLabel($row['type'])); ?></span></td>
                        <td class="px-5 py-4 font-semibold text-gray-800"><?php echo formatCurrency($row['amount']); ?></td>
                        <td class="px-5 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($recorder); ?></td>
                        <td class="px-5 py-4 text-sm text-gray-600"><?php echo formatDateTime($row['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php if ($total_pages > 1): ?>
        <div class="px-6 py-4 border-t border-gray-200 flex justify-center items-center gap-2 bg-gray-50">
            <?php if ($page > 1): ?><a href="<?php echo htmlspecialchars($query_string(['page' => 1])); ?>" class="px-3 py-2 rounded bg-gray-200 hover:bg-gray-300">First</a><a href="<?php echo htmlspecialchars($query_string(['page' => $page - 1])); ?>" class="px-3 py-2 rounded bg-gray-200 hover:bg-gray-300">Previous</a><?php endif; ?>
            <span class="text-sm text-gray-600 px-2">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
            <?php if ($page < $total_pages): ?><a href="<?php echo htmlspecialchars($query_string(['page' => $page + 1])); ?>" class="px-3 py-2 rounded bg-gray-200 hover:bg-gray-300">Next</a><a href="<?php echo htmlspecialchars($query_string(['page' => $total_pages])); ?>" class="px-3 py-2 rounded bg-gray-200 hover:bg-gray-300">Last</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
</main>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
