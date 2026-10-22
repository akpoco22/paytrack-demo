<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Transaction.php';
require_once '../../includes/helpers.php';

requireUser();
checkSessionTimeout();

$user_id = Auth::getUserId();
$transaction = new Transaction($conn);

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * ITEMS_PER_PAGE;
$transactions = $transaction->getCustomerTransactions($user_id, ITEMS_PER_PAGE, $offset);
$total_count = $transaction->getCustomerTransactionCount($user_id);
$total_pages = ceil($total_count / ITEMS_PER_PAGE);
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
    <title>Transactions - PayTrack</title>
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
                    <a href="transactions.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Transactions</a>
                    <a href="contact.php" class="text-gray-700 hover:text-blue-600 font-medium">Contact Admin</a>
                    <a href="profile.php" class="text-gray-700 hover:text-blue-600 font-medium">Profile</a>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 py-8">
        <h2 class="text-3xl font-bold text-gray-800 mb-6">Transaction History</h2>

        <!-- Transactions Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <?php if (empty($transactions)): ?>
                <div class="px-6 py-8 text-center text-gray-600">
                    No transactions found.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Reference Code</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $trans): ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-50 transition">
                                    <td class="px-6 py-4 font-mono text-sm text-gray-800"><?php echo htmlspecialchars($trans['reference_code']); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $trans['type'] === 'deposit' ? 'bg-green-100 text-green-800' : ($trans['type'] === 'loan_repayment' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800'); ?>">
                                            <?php echo htmlspecialchars(transactionTypeLabel($trans['type'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 font-semibold text-gray-800"><?php echo formatCurrency($trans['amount']); ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($trans['description'] ?? '-'); ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?php echo formatDateTime($trans['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-gray-200 flex justify-center space-x-2 bg-gray-50">
                        <?php if ($page > 1): ?>
                            <a href="?page=1" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">First</a>
                            <a href="?page=<?php echo $page - 1; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Previous</a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>"
                               class="px-3 py-1 rounded <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Next</a>
                            <a href="?page=<?php echo $total_pages; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Last</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
