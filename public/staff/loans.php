<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Loan.php';
require_once '../../includes/helpers.php';

if (!Auth::isAuthenticated() || ($_SESSION['user_type'] ?? '') !== 'staff') {
    header('Location: ../../public/login.php');
    exit;
}
if (($_SESSION['staff_role'] ?? '') !== 'loans') {
    http_response_code(403);
    exit('Access denied. Loan staff only.');
}

checkSessionTimeout();

$loan_model = new Loan($conn);
$bucket = isset($_GET['bucket']) && in_array($_GET['bucket'], ['outstanding', 'current', 'paid'], true)
    ? $_GET['bucket']
    : 'outstanding';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$count_buckets = [
    'outstanding' => $loan_model->countLoansByBucket('outstanding'),
    'current' => $loan_model->countLoansByBucket('current'),
    'paid' => $loan_model->countLoansByBucket('paid'),
];
$total = $count_buckets[$bucket];
$total_pages = max(1, (int)ceil($total / $limit));
$page = min($page, $total_pages);
$loans = $loan_model->getLoansByBucket($bucket, $limit, ($page - 1) * $limit) ?: [];
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$bucket_labels = [
    'outstanding' => 'Outstanding',
    'current' => 'Current',
    'paid' => 'Paid',
];
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
    <title>Loan Collection - PayTrack Staff</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
<nav class="bg-white shadow-sm border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
        <div class="flex items-center space-x-8">
            <a href="dashboard.php" class="flex items-center shrink-0"><img src="/public/logo.png" alt="PayTrack" class="h-10 w-auto"></a>
            <div class="hidden md:flex space-x-6">
                <a href="dashboard.php" class="text-gray-700 hover:text-blue-600 font-medium">Dashboard</a>
                <a href="activity.php" class="text-gray-700 hover:text-blue-600 font-medium">My Activity</a>
                <a href="transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Record Transaction</a>
                <a href="loans.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Loan Collection</a>
            </div>
        </div>
        <div class="flex items-center space-x-4">
            <div class="text-right hidden sm:block">
                <p class="text-gray-800 font-semibold"><?php echo htmlspecialchars($_SESSION['name']); ?></p>
                <p class="text-gray-600 text-sm">Loans Staff</p>
            </div>
            <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Loan Collection</h1>
            <p class="text-gray-500 mt-1">Review balances and record customer repayments.</p>
        </div>
        <a href="dashboard.php" class="text-blue-600 hover:text-blue-700 font-semibold">← Back to Dashboard</a>
    </div>

    <?php if ($success): ?>
        <div class="mb-5 p-4 bg-green-100 border border-green-300 text-green-800 rounded-lg"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="mb-5 p-4 bg-red-100 border border-red-300 text-red-800 rounded-lg"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <?php foreach ($bucket_labels as $key => $label): ?>
            <a href="?bucket=<?php echo urlencode($key); ?>" class="bg-white rounded-lg shadow p-5 border-l-4 <?php echo $bucket === $key ? 'border-blue-600 ring-2 ring-blue-100' : 'border-gray-200 hover:border-blue-300'; ?>">
                <p class="text-sm text-gray-500"><?php echo htmlspecialchars($label); ?> loans</p>
                <p class="text-3xl font-bold text-gray-800"><?php echo $count_buckets[$key]; ?></p>
            </a>
        <?php endforeach; ?>
    </div>

    <section class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
            <div>
                <h2 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($bucket_labels[$bucket]); ?> loans</h2>
                <?php if ($bucket === 'outstanding'): ?><p class="text-sm text-gray-500">Includes active and overdue loans. Current loans are a subset of this list.</p><?php endif; ?>
            </div>
            <span class="text-sm text-gray-500"><?php echo $total; ?> record<?php echo $total === 1 ? '' : 's'; ?></span>
        </div>
        <?php if (!$loans): ?>
            <div class="px-6 py-12 text-center text-gray-500">No <?php echo strtolower($bucket_labels[$bucket]); ?> loans found.</div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[850px]">
                    <thead class="bg-white border-b border-gray-200">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Customer</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Loan</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Repaid</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Balance</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Due date</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($loans as $loan):
                        $balance = max(0, (float)$loan['loan_amount'] - (float)$loan['total_repaid']);
                        $status = (string)$loan['status'];
                        $badge = $status === 'completed' ? 'bg-green-100 text-green-800' : ($status === 'overdue' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800');
                    ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-5 py-4">
                                <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($loan['customer_name']); ?></p>
                                <p class="text-sm text-gray-500"><?php echo htmlspecialchars($loan['phone_number'] ?? ''); ?></p>
                            </td>
                            <td class="px-5 py-4 font-semibold text-gray-800"><?php echo formatCurrency($loan['loan_amount']); ?></td>
                            <td class="px-5 py-4 text-gray-700"><?php echo formatCurrency($loan['total_repaid']); ?></td>
                            <td class="px-5 py-4 font-semibold <?php echo $balance > 0 ? 'text-amber-700' : 'text-green-700'; ?>"><?php echo formatCurrency($balance); ?></td>
                            <td class="px-5 py-4 text-gray-600"><?php echo formatDate($loan['due_date']); ?></td>
                            <td class="px-5 py-4"><span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                            <td class="px-5 py-4 text-right"><a href="loan-detail.php?id=<?php echo (int)$loan['id']; ?>" class="text-blue-600 hover:text-blue-800 font-semibold whitespace-nowrap">View / Collect →</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-center items-center gap-2">
                <?php if ($page > 1): ?><a class="px-3 py-2 rounded bg-gray-100 hover:bg-gray-200" href="?bucket=<?php echo urlencode($bucket); ?>&page=<?php echo $page - 1; ?>">Previous</a><?php endif; ?>
                <span class="px-3 py-2 text-sm text-gray-600">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                <?php if ($page < $total_pages): ?><a class="px-3 py-2 rounded bg-gray-100 hover:bg-gray-200" href="?bucket=<?php echo urlencode($bucket); ?>&page=<?php echo $page + 1; ?>">Next</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
