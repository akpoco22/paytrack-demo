<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Receipt.php';
require_once '../../src/models/Transaction.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

$receipt_model = new Receipt($conn);
$admin_id = $_SESSION['user_id'] ?? 0;

// Handle receipt generation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['transaction_id']) || isset($_POST['repayment_id']))) {
    if (isset($_POST['transaction_id'])) {
        $transaction_id = (int)$_POST['transaction_id'];
        $result = $transaction_id > 0
            ? $receipt_model->createTransactionReceipt($transaction_id, null, $admin_id)
            : ['success' => false, 'message' => 'Please enter a valid transaction ID.'];
    } else {
        $repayment_id = (int)$_POST['repayment_id'];
        $result = $repayment_id > 0
            ? $receipt_model->createRepaymentReceipt($repayment_id, null, $admin_id)
            : ['success' => false, 'message' => 'Please enter a valid repayment ID.'];
    }
    $_SESSION[$result['success'] ? 'success' : 'error'] = $result['success']
        ? 'Receipt generated: ' . $result['receipt_number']
        : $result['message'];
    header('Location: receipts.php');
    exit;
}

// Pagination
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 15;
$offset = ($page - 1) * $limit;

$count_row = $conn->query("SELECT COUNT(*) as c FROM receipts")->fetch(PDO::FETCH_ASSOC);
$total       = (int)($count_row['c'] ?? 0);
$total_pages = max(1, ceil($total / $limit));

$stmt = $conn->prepare(
    "SELECT r.*, t.type as txn_type, t.reference_code as txn_ref,
            c.name as customer_name
     FROM receipts r
     LEFT JOIN transactions t ON r.transaction_id = t.id
     LEFT JOIN loan_repayments lr ON r.loan_repayment_id = lr.id
     LEFT JOIN loans l ON lr.loan_id = l.id
     LEFT JOIN customers c ON (t.customer_id = c.id OR l.customer_id = c.id)
     ORDER BY r.generated_at DESC LIMIT ? OFFSET ?"
);
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build printable HTML for a single receipt (for modal preview)
$preview_html  = '';
$preview_error = '';
if (isset($_GET['view'])) {
    $receipt_number = trim($_GET['view']);
    $receipt = $receipt_model->getReceiptByNumber($receipt_number);
    if (!$receipt) {
        $preview_error = 'Receipt not found.';
    } elseif ($receipt['transaction_id']) {
        $preview_html = $receipt_model->generateTransactionHTML((int)$receipt['transaction_id']);
    } elseif ($receipt['loan_repayment_id']) {
        $preview_html = $receipt_model->generateRepaymentHTML((int)$receipt['loan_repayment_id']);
    }
    if (!$preview_html && !$preview_error) {
        $preview_error = 'Could not generate receipt preview.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="PayTrack">
    <link rel="manifest" href="/public/manifest.json">
    <link rel="icon" type="image/png" href="/public/icons/icon-192.png">
    <title>Receipts - PayTrack Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">

<nav class="bg-white shadow-sm border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
        <div class="flex items-center space-x-8">
            <a href="dashboard.php" class="flex items-center shrink-0"><img src="/public/logo.png" alt="PayTrack" class="h-10 w-auto"></a>
            <div class="hidden md:flex space-x-6">
                <a href="dashboard.php"          class="text-gray-700 hover:text-blue-600 font-medium">Dashboard</a>
                <a href="users.php"              class="text-gray-700 hover:text-blue-600 font-medium">Customers</a>
                <a href="staff.php"              class="text-gray-700 hover:text-blue-600 font-medium">Staff</a>
                <a href="staff-transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Staff Transactions</a>
                <a href="kyc.php"                class="text-gray-700 hover:text-blue-600 font-medium">KYC</a>
                <a href="loans.php"              class="text-gray-700 hover:text-blue-600 font-medium">Loans</a>
                <a href="receipts.php"           class="text-blue-600 font-medium border-b-2 border-blue-600">Receipts</a>
                <a href="transactions.php"       class="text-gray-700 hover:text-blue-600 font-medium">Transactions</a>
                <a href="messages.php"           class="text-gray-700 hover:text-blue-600 font-medium">Messages</a>
                <a href="export-backup.php" class="text-gray-700 hover:text-blue-600 font-medium">Export</a>
            </div>
        </div>
        <div class="flex items-center space-x-4">
            <span class="text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
        </div>
    </div>
</nav>

<div class="max-w-7xl mx-auto px-4 py-8">

    <!-- Flash messages -->
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

    <!-- Header -->
    <div class="mb-6">
        <h2 class="text-3xl font-bold text-gray-800">Receipts</h2>
        <p class="text-gray-500 text-sm mt-1">
            Total: <span class="font-bold text-blue-600"><?php echo $total; ?></span>
        </p>
    </div>

    <!-- Generate receipt -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="text-lg font-bold text-gray-800 mb-2">?? Generate Receipt</h3>
        <p class="text-gray-500 text-sm mb-4">Enter a transaction ID (from the Transactions page) to create its receipt.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-3xl">
            <form method="POST" class="flex gap-3 items-end"><?php echo csrfField(); ?>
                <div class="flex-1"><label class="block text-sm font-semibold text-gray-700 mb-1">Transaction ID</label><input type="number" name="transaction_id" min="1" required placeholder="e.g. 12" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-semibold">Generate</button>
            </form>
            <form method="POST" class="flex gap-3 items-end"><?php echo csrfField(); ?>
                <div class="flex-1"><label class="block text-sm font-semibold text-gray-700 mb-1">Loan repayment ID</label><input type="number" name="repayment_id" min="1" required placeholder="e.g. 12" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></div>
                <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-5 py-2 rounded-lg font-semibold">Generate</button>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <?php if (empty($rows)): ?>
            <div class="px-6 py-16 text-center">
                <p class="text-4xl mb-3">??</p>
                <p class="text-gray-500 font-medium">No receipts yet. Generate one above.</p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Receipt #</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Generated</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                        <td class="px-6 py-4 font-mono text-sm font-semibold text-blue-600">
                            <?php echo htmlspecialchars($r['receipt_number']); ?>
                        </td>
                        <td class="px-6 py-4 font-medium text-gray-800">
                            <?php echo htmlspecialchars($r['customer_name'] ?? '—'); ?>
                        </td>
                        <td class="px-6 py-4">
                            <?php
                            $t = $r['txn_type'] ?? $r['receipt_type'];
                            $badge = $r['receipt_type'] === 'loan_repayment'
                                ? 'bg-purple-100 text-purple-700'
                                : ($t === 'withdrawal' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700');
                            $label = $r['receipt_type'] === 'loan_repayment'
                                ? 'Loan Repayment'
                                : ucfirst($t ?? 'Transaction');
                            ?>
                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $badge; ?>"><?php echo $label; ?></span>
                            <?php if ($r['txn_ref']): ?>
                                <span class="block text-xs text-gray-400 font-mono mt-1"><?php echo htmlspecialchars($r['txn_ref']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 font-semibold text-gray-800"><?php echo formatCurrency($r['amount']); ?></td>
                        <td class="px-6 py-4 text-gray-500 text-sm"><?php echo formatDateTime($r['generated_at']); ?></td>
                        <td class="px-6 py-4 flex items-center gap-3">
                            <a href="receipts.php?view=<?php echo urlencode($r['receipt_number']); ?>&page=<?php echo $page; ?>"
                               class="text-blue-600 hover:text-blue-700 font-semibold text-sm">View</a>
                            <a href="receipt-print.php?id=<?php echo (int)$r['id']; ?>" target="_blank"
                               class="text-gray-500 hover:text-gray-700 font-semibold text-sm">??? Print</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-center space-x-2">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>"
                   class="px-3 py-1 rounded text-sm <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Receipt Preview Modal -->
<?php if ($preview_html || $preview_error): ?>
<div id="receiptModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <?php if ($preview_error): ?>
            <p class="text-red-600 font-medium mb-4"><?php echo htmlspecialchars($preview_error); ?></p>
        <?php else: ?>
            <div id="printArea"><?php echo $preview_html; ?></div>
        <?php endif; ?>
        <div class="flex gap-3 mt-4">
            <?php if ($preview_html): ?>
            <button onclick="window.print()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold">?? Print</button>
            <?php endif; ?>
            <a href="receipts.php?page=<?php echo $page; ?>" class="flex-1 text-center bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 rounded-lg font-semibold">Close</a>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="/assets/js/pwa.js"></script>
</body>
</html>
