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

if (!Auth::isAuthenticated() || ($_SESSION['user_type'] ?? '') !== 'staff') {
    header('Location: ../../public/login.php');
    exit;
}
if (($_SESSION['staff_role'] ?? '') !== 'loans') {
    http_response_code(403);
    exit('Access denied. Loan staff only.');
}

checkSessionTimeout();
requireCsrf();

$loan_id = max(0, (int)($_GET['id'] ?? $_POST['loan_id'] ?? 0));
if (!$loan_id) {
    header('Location: loans.php');
    exit;
}

$loan_model = new Loan($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    $interest_paid = (float)($_POST['interest_paid'] ?? 0);
    $principal_paid = (float)($_POST['principal_paid'] ?? 0);
    $loan_before = $loan_model->getLoanById($loan_id);

    if (!$loan_before) {
        $_SESSION['error'] = 'Loan not found.';
    } elseif (!in_array($loan_before['status'], ['active', 'overdue'], true)) {
        $_SESSION['error'] = 'Repayments are only accepted for active or overdue loans.';
    } else {
        $result = $loan_model->recordRepayment(
            $loan_id,
            $amount,
            $interest_paid,
            $principal_paid,
            sanitize($_POST['repayment_date'] ?? date('Y-m-d')),
            Auth::getUserId(),
            sanitize($_POST['notes'] ?? '')
        );

        if ($result['success']) {
            $loan_after = $loan_model->getLoanById($loan_id);
            $mail_note = '';
            if ($loan_after) {
                $customer = [
                    'name' => $loan_after['customer_name'],
                    'email' => $loan_after['customer_email'] ?? null,
                    'phone_number' => $loan_after['customer_phone'] ?? ($loan_after['phone_number'] ?? null),
                ];
                $notification_data = [
                    'customer' => $customer,
                    'amount' => $amount,
                    'loan_amount' => (float)$loan_after['loan_amount'],
                    'total_repaid' => (float)$loan_after['total_repaid'],
                    'reference_code' => $result['reference_code'] ?? '',
                ];
                $mail_note = notifyCustomer('repayment', $notification_data);
                $mail_note .= notifyCustomerSms('repayment', $notification_data);
            }
            $_SESSION['success'] = $result['message']
                . (isset($result['reference_code']) ? ' Reference: ' . $result['reference_code'] . '.' : '')
                . $mail_note;
        } else {
            $_SESSION['error'] = $result['message'];
        }
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
$loan_amount = (float)$loan['loan_amount'];
$balance = max(0, $loan_amount - $total_repaid);
$progress = $loan_amount > 0 ? min(100, round(($total_repaid / $loan_amount) * 100)) : 0;
$can_repay = in_array($loan['status'], ['active', 'overdue'], true) && $balance > 0;
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
$status_class = $loan['status'] === 'overdue' ? 'bg-red-100 text-red-800' : ($loan['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800');
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
    <title>Loan Details - PayTrack Staff</title>
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
            <span class="hidden sm:inline text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
        </div>
    </div>
</nav>

<main class="max-w-6xl mx-auto px-4 py-8">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div class="flex flex-wrap items-center gap-3">
            <a href="loans.php" class="text-blue-600 hover:text-blue-700 font-semibold">← Loan collection</a>
            <h1 class="text-3xl font-bold text-gray-800">Loan #<?php echo (int)$loan['id']; ?></h1>
            <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($loan['status'])); ?></span>
        </div>
        <?php if ($can_repay): ?>
            <a href="#record-repayment" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-center font-semibold">Record Payment</a>
        <?php endif; ?>
    </div>

    <?php if ($success): ?><div class="mb-5 p-4 bg-green-100 border border-green-300 text-green-800 rounded-lg"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-5 p-4 bg-red-100 border border-red-300 text-red-800 rounded-lg"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-5"><p class="text-xs font-semibold uppercase text-gray-500 mb-1">Loan Principal</p><p class="text-2xl font-bold text-gray-800"><?php echo formatCurrency($loan_amount); ?></p></div>
        <div class="bg-white rounded-lg shadow p-5"><p class="text-xs font-semibold uppercase text-gray-500 mb-1">Principal Repaid</p><p class="text-2xl font-bold text-blue-600"><?php echo formatCurrency($total_repaid); ?></p></div>
        <div class="bg-white rounded-lg shadow p-5"><p class="text-xs font-semibold uppercase text-gray-500 mb-1">Principal Balance</p><p class="text-2xl font-bold <?php echo $balance > 0 ? 'text-amber-700' : 'text-green-700'; ?>"><?php echo formatCurrency($balance); ?></p></div>
        <div class="bg-white rounded-lg shadow p-5"><p class="text-xs font-semibold uppercase text-gray-500 mb-1">Due Date</p><p class="text-2xl font-bold text-gray-800"><?php echo formatDate($loan['due_date']); ?></p></div>
    </section>

    <section class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="flex justify-between items-center mb-2"><h2 class="font-bold text-gray-800">Repayment progress</h2><span class="text-sm text-gray-600"><?php echo $progress; ?>%</span></div>
        <div class="w-full bg-gray-200 rounded-full h-3"><div class="bg-blue-600 h-3 rounded-full" style="width: <?php echo $progress; ?>%"></div></div>
        <p class="text-sm text-gray-500 mt-2"><?php echo htmlspecialchars($loan['customer_name']); ?> · <?php echo htmlspecialchars($loan['phone_number'] ?? ''); ?> · <?php echo htmlspecialchars(ucfirst($loan['repayment_frequency'] ?? '')); ?> repayment</p>
    </section>

    <?php if ($can_repay): ?>
    <section id="record-repayment" class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-800 mb-1">Record a repayment</h2>
        <p class="text-sm text-gray-500 mb-5">The total collected is calculated from principal and interest. Principal cannot exceed the remaining balance.</p>
        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4"><?php echo csrfField(); ?>
            <input type="hidden" name="loan_id" value="<?php echo (int)$loan['id']; ?>">
            <div>
                <label for="principalPaid" class="block text-sm font-semibold text-gray-700 mb-1">Principal paid (₦)</label>
                <input id="principalPaid" name="principal_paid" type="number" step="0.01" min="0" max="<?php echo number_format($balance, 2, '.', ''); ?>" value="0.00" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label for="interestPaid" class="block text-sm font-semibold text-gray-700 mb-1">Interest paid (₦)</label>
                <input id="interestPaid" name="interest_paid" type="number" step="0.01" min="0" value="0.00" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label for="totalAmount" class="block text-sm font-semibold text-gray-700 mb-1">Total collected (₦)</label>
                <input id="totalAmount" name="amount" type="number" step="0.01" min="0.01" value="0.00" readonly required class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-700">
            </div>
            <div>
                <label for="repaymentDate" class="block text-sm font-semibold text-gray-700 mb-1">Repayment date</label>
                <input id="repaymentDate" name="repayment_date" type="date" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="md:col-span-2">
                <label for="notes" class="block text-sm font-semibold text-gray-700 mb-1">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="2" maxlength="1000" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
            </div>
            <div class="md:col-span-2 flex flex-col sm:flex-row gap-3">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-semibold">Save Repayment</button>
                <a href="loans.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg text-center font-semibold">Cancel</a>
            </div>
        </form>
    </section>
    <?php else: ?>
    <div class="mb-6 p-4 bg-gray-100 border border-gray-200 text-gray-700 rounded-lg">This loan is not eligible for additional collection.</div>
    <?php endif; ?>

    <section class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200"><h2 class="text-lg font-bold text-gray-800">Repayment history <span class="text-gray-500 font-normal">(<?php echo count($repayments); ?>)</span></h2></div>
        <?php if (!$repayments): ?>
            <div class="px-6 py-10 text-center text-gray-500">No repayments recorded yet.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[750px]">
                <thead class="bg-gray-50 border-b border-gray-200"><tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-600">Date</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-600">Total</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-600">Principal</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-600">Interest</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-600">Source</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-600">Reference</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-600">Notes</th>
                </tr></thead>
                <tbody>
                <?php foreach ($repayments as $repayment): ?>
                    <tr class="border-b border-gray-100">
                        <td class="px-5 py-4 text-sm text-gray-600"><?php echo formatDate($repayment['repayment_date']); ?></td>
                        <td class="px-5 py-4 font-semibold text-gray-800"><?php echo formatCurrency($repayment['amount']); ?></td>
                        <td class="px-5 py-4 text-gray-700"><?php echo formatCurrency($repayment['principal_paid']); ?></td>
                        <td class="px-5 py-4 text-gray-700"><?php echo formatCurrency($repayment['interest_paid']); ?></td>
                        <td class="px-5 py-4 text-gray-700"><?php echo ($repayment['source'] ?? 'manual') === 'auto' ? 'Automatic' : 'Manual'; ?></td>
                        <td class="px-5 py-4 font-mono text-xs text-blue-700"><?php echo htmlspecialchars($repayment['reference_code']); ?></td>
                        <td class="px-5 py-4 text-sm text-gray-500"><?php echo htmlspecialchars($repayment['notes'] ?: '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</main>
<script>
(function () {
    const principal = document.getElementById('principalPaid');
    const interest = document.getElementById('interestPaid');
    const total = document.getElementById('totalAmount');
    function updateTotal() {
        const p = Math.max(0, Number(principal.value) || 0);
        const i = Math.max(0, Number(interest.value) || 0);
        total.value = (p + i).toFixed(2);
    }
    principal.addEventListener('input', updateTotal);
    interest.addEventListener('input', updateTotal);
}());
</script>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
