<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/mail.php';
require_once '../../config/sms.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Loan.php';
require_once '../../includes/helpers.php';
require_once '../../includes/mail-notify.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

$loan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$loan_id) {
    header('Location: loans.php');
    exit;
}

$loan_model = new Loan($conn);
$admin_id = Auth::getUserId();

// Handle record-repayment POST on this page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repay') {
    $result = $loan_model->recordRepayment(
        $loan_id,
        (float)($_POST['amount'] ?? 0),
        (float)($_POST['interest_paid'] ?? 0),
        (float)($_POST['principal_paid'] ?? 0),
        sanitize($_POST['repayment_date'] ?? date('Y-m-d')),
        null,
        sanitize($_POST['notes'] ?? ''),
        $admin_id
    );
        $mail_note = '';
        if ($result['success']) {
            $loan_row = $loan_model->getLoanById($loan_id); // totals already updated
            if ($loan_row) {
                $mail_note = notifyCustomer('repayment', [
                    'customer'       => ['name' => $loan_row['customer_name'], 'email' => $loan_row['customer_email'] ?? null],
                    'amount'         => (float)($_POST['amount'] ?? 0),
                    'loan_amount'    => (float)($loan_row['loan_amount'] ?? 0),
                    'total_repaid'   => (float)($loan_row['total_repaid'] ?? 0),
                    'reference_code' => $result['reference_code'] ?? '',
                ]);
                $mail_note .= notifyCustomerSms('repayment', [
                    'customer'       => ['name' => $loan_row['customer_name'], 'email' => $loan_row['customer_email'] ?? null, 'phone_number' => $loan_row['customer_phone'] ?? ($loan_row['phone_number'] ?? null)],
                    'amount'         => (float)($_POST['amount'] ?? 0),
                    'loan_amount'    => (float)($loan_row['loan_amount'] ?? 0),
                    'total_repaid'   => (float)($loan_row['total_repaid'] ?? 0),
                    'reference_code' => $result['reference_code'] ?? '',
                ]);
            }
            $_SESSION['success'] = $result['message']
                . (isset($result['reference_code']) ? ' Reference: ' . $result['reference_code'] : '')
                . $mail_note;
    } else {
        $_SESSION['error'] = $result['message'];
    }
    header('Location: loan-detail.php?id=' . $loan_id);
    exit;
}

$loan = $loan_model->getLoanById($loan_id);
if (!$loan) {
    $_SESSION['error'] = 'Loan not found.';
    header('Location: loans.php');
    exit;
}

$repayments = $loan_model->getRepaymentHistory($loan_id) ?: [];

$total_repaid = (float)($loan['total_repaid'] ?? 0);
$loan_amount  = (float)$loan['loan_amount'];
$balance      = max(0, $loan_amount - $total_repaid);
$progress     = $loan_amount > 0 ? min(100, round(($total_repaid / $loan_amount) * 100)) : 0;

$s = $loan['status'];
$badge = match($s) {
    'completed' => 'bg-green-100 text-green-700',
    'overdue'   => 'bg-red-100 text-red-700',
    'active'    => 'bg-blue-100 text-blue-700',
    default     => 'bg-yellow-100 text-yellow-700',
};
$label = match($s) {
    'completed' => '? Completed',
    'overdue'   => '?? Overdue',
    'active'    => '?? Active',
    default     => '? ' . ucfirst($s),
};

$can_repay = ($s === 'active' || $s === 'overdue');
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
    <title>Loan Detail - PayTrack Admin</title>
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
                <a href="loans.php"              class="text-blue-600 font-medium border-b-2 border-blue-600">Loans</a>
                <a href="receipts.php"           class="text-gray-700 hover:text-blue-600 font-medium">Receipts</a>
                <a href="transactions.php"       class="text-gray-700 hover:text-blue-600 font-medium">Transactions</a>
                <a href="messages.php"           class="text-gray-700 hover:text-blue-600 font-medium">Messages</a>
            </div>
        </div>
        <div class="flex items-center space-x-4">
            <span class="text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
        </div>
    </div>
</nav>

<div class="max-w-5xl mx-auto px-4 py-8">

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
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="loans.php" class="text-blue-600 hover:text-blue-700 font-medium text-sm">? Back to Loans</a>
            <h2 class="text-3xl font-bold text-gray-800">Loan #<?php echo $loan['id']; ?></h2>
            <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $badge; ?>"><?php echo $label; ?></span>
        </div>
        <?php if ($can_repay): ?>
            <button onclick="openRepay()"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium">
                ?? Record Payment
            </button>
        <?php endif; ?>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-gray-500 text-xs font-semibold uppercase mb-1">Loan Amount</p>
            <p class="text-2xl font-bold text-gray-800"><?php echo formatCurrency($loan_amount); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-gray-500 text-xs font-semibold uppercase mb-1">Total Repaid</p>
            <p class="text-2xl font-bold text-blue-600"><?php echo formatCurrency($total_repaid); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-gray-500 text-xs font-semibold uppercase mb-1">Balance Left</p>
            <p class="text-2xl font-bold <?php echo $balance > 0 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo formatCurrency($balance); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-gray-500 text-xs font-semibold uppercase mb-1">Interest Charged</p>
            <p class="text-2xl font-bold text-gray-800"><?php echo formatCurrency($loan['interest_charged'] ?? 0); ?></p>
        </div>
    </div>

    <!-- Progress -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="flex justify-between items-center mb-2">
            <h3 class="text-lg font-bold text-gray-800">?? Repayment Progress</h3>
            <span class="text-sm font-semibold text-gray-600"><?php echo $progress; ?>%</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-3">
            <div class="bg-blue-600 h-3 rounded-full transition-all" style="width: <?php echo $progress; ?>%"></div>
        </div>
        <p class="text-xs text-gray-500 mt-2">
            <?php echo formatCurrency($total_repaid); ?> of <?php echo formatCurrency($loan_amount); ?> principal repaid
            (<?php echo htmlspecialchars($loan['interest_rate']); ?>% interest rate)
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        <!-- Loan details -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">?? Loan Details</h3>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Customer</dt>
                    <dd class="text-gray-800 font-semibold"><?php echo htmlspecialchars($loan['customer_name']); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Phone</dt>
                    <dd class="text-gray-800"><?php echo htmlspecialchars($loan['phone_number'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Frequency</dt>
                    <dd class="text-gray-800"><?php echo htmlspecialchars(ucfirst($loan['repayment_frequency'] ?? '�')); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Purpose</dt>
                    <dd class="text-gray-800 text-right max-w-xs"><?php echo htmlspecialchars($loan['purpose'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Date Given</dt>
                    <dd class="text-gray-800"><?php echo formatDate($loan['date_given']); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Due Date</dt>
                    <dd class="text-gray-800"><?php echo formatDate($loan['due_date']); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Collateral</dt>
                    <dd class="text-gray-800 text-right max-w-xs"><?php echo htmlspecialchars($loan['collateral'] ?: '�'); ?></dd>
                </div>
            </dl>
        </div>

        <!-- Guarantor -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">?? Guarantor</h3>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Name</dt>
                    <dd class="text-gray-800 font-semibold"><?php echo htmlspecialchars($loan['guarantor_name'] ?: '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Phone</dt>
                    <dd class="text-gray-800"><?php echo htmlspecialchars($loan['guarantor_phone'] ?: '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Address</dt>
                    <dd class="text-gray-800 text-right max-w-xs"><?php echo htmlspecialchars($loan['guarantor_address'] ?: '�'); ?></dd>
                </div>
            </dl>
        </div>

    </div>

    <!-- Repayment history -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-bold text-gray-800">?? Repayment History (<?php echo count($repayments); ?>)</h3>
        </div>
        <?php if (empty($repayments)): ?>
            <div class="px-6 py-12 text-center">
                <p class="text-4xl mb-3">??</p>
                <p class="text-gray-500 font-medium">No repayments recorded yet.</p>
                <?php if ($can_repay): ?>
                    <button onclick="openRepay()" class="mt-4 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium text-sm">Record First Payment</button>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Principal</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Interest</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Source</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Reference</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($repayments as $r): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                        <td class="px-6 py-4 text-gray-500 text-sm"><?php echo formatDate($r['repayment_date']); ?></td>
                        <td class="px-6 py-4 font-semibold text-gray-800"><?php echo formatCurrency($r['amount']); ?></td>
                        <td class="px-6 py-4 text-gray-600"><?php echo formatCurrency($r['principal_paid']); ?></td>
                        <td class="px-6 py-4 text-gray-600"><?php echo formatCurrency($r['interest_paid']); ?></td>
                        <td class="px-6 py-4 text-gray-600"><?php echo ($r['source'] ?? 'manual') === 'auto' ? 'Automatic' : 'Manual'; ?></td>
                        <td class="px-6 py-4 font-mono text-xs text-blue-600"><?php echo htmlspecialchars($r['reference_code']); ?></td>
                        <td class="px-6 py-4 text-gray-500 text-sm max-w-xs truncate"><?php echo htmlspecialchars($r['notes'] ?: '�'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php if ($can_repay): ?>
<!-- Record Repayment Modal -->
<div id="repayModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
        <h3 class="text-xl font-bold text-gray-800 mb-4">?? Record Repayment</h3>
        <form method="POST"><?php echo csrfField(); ?>
            <input type="hidden" name="action" value="repay">
            <p class="text-xs text-gray-500 mb-3">Total collected is calculated from principal and interest.</p>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Principal Paid (₦)</label>
                    <input type="number" name="principal_paid" id="detailRepayPrincipal" step="0.01" min="0" max="<?php echo number_format($balance, 2, '.', ''); ?>" value="0" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Interest Paid (₦)</label>
                    <input type="number" name="interest_paid" id="detailRepayInterest" step="0.01" min="0" value="0" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2 text-sm">Total Amount (₦)</label>
                <input type="number" name="amount" id="detailRepayAmount" step="0.01" min="0.01" value="0.00" readonly required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-700 text-sm">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2 text-sm">Repayment Date</label>
                <input type="date" name="repayment_date" value="<?php echo date('Y-m-d'); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2 text-sm">Notes (optional)</label>
                <textarea name="notes" rows="2" placeholder="Add a note..."
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold">Record</button>
                <button type="button" onclick="closeRepay()" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 rounded-lg font-semibold">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRepay() {
    document.getElementById('repayModal').classList.remove('hidden');
}
(function() {
    var principal = document.getElementById('detailRepayPrincipal');
    var interest = document.getElementById('detailRepayInterest');
    var amount = document.getElementById('detailRepayAmount');
    function updateRepaymentAmount() {
        amount.value = ((Number(principal.value) || 0) + (Number(interest.value) || 0)).toFixed(2);
    }
    principal.addEventListener('input', updateRepaymentAmount);
    interest.addEventListener('input', updateRepaymentAmount);
})();
function closeRepay() {
    document.getElementById('repayModal').classList.add('hidden');
}
document.getElementById('repayModal').addEventListener('click', function(e) {
    if (e.target === this) closeRepay();
});
</script>
<?php endif; ?>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
