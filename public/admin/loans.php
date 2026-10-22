<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/mail.php';
require_once '../../config/sms.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Loan.php';
require_once '../../src/models/LoanApplication.php';
require_once '../../src/models/Customer.php';
require_once '../../includes/helpers.php';
require_once '../../includes/mail-notify.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

$loan_model     = new Loan($conn);
$app_model      = new LoanApplication($conn);
$customer_model = new Customer($conn);
$admin_id       = Auth::getUserId();
$pending_apps_count = $app_model->countByStatus('pending');

// Handle create-loan / record-repayment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $result = $loan_model->createLoan(
                (int)($_POST['customer_id'] ?? 0),
                (float)($_POST['loan_amount'] ?? 0),
                (float)($_POST['interest_rate'] ?? 0),
                sanitize($_POST['repayment_frequency'] ?? ''),
                sanitize($_POST['purpose'] ?? ''),
                sanitize($_POST['guarantor_name'] ?? ''),
                sanitize($_POST['guarantor_phone'] ?? ''),
                sanitize($_POST['guarantor_address'] ?? ''),
                sanitize($_POST['collateral'] ?? ''),
                sanitize($_POST['date_given'] ?? ''),
                sanitize($_POST['due_date'] ?? ''),
                null,
                $admin_id
        );
            $mail_note = '';
            if ($result['success']) {
                $customer = $customer_model->getCustomerById((int)($_POST['customer_id'] ?? 0));
                if ($customer) {
                    $mail_note = notifyCustomer('loan_created', [
                        'customer'    => $customer,
                        'loan_amount' => (float)($_POST['loan_amount'] ?? 0),
                        'due_date'    => sanitize($_POST['due_date'] ?? ''),
                    ]);
                    $mail_note .= notifyCustomerSms('loan_created', [
                        'customer'    => $customer,
                        'loan_amount' => (float)($_POST['loan_amount'] ?? 0),
                        'due_date'    => sanitize($_POST['due_date'] ?? ''),
                    ]);
                }
            }
            $_SESSION[$result['success'] ? 'success' : 'error'] = $result['message'] . $mail_note;
    } elseif ($action === 'repay') {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        if (!$loan_id) {
            $_SESSION['error'] = 'Invalid loan selected.';
        } else {
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
                $loan = $loan_model->getLoanById($loan_id);
                $base = $result['message'] . (isset($result['reference_code']) ? ' Reference: ' . $result['reference_code'] : '');
                if ($loan) {
                    $after = $loan_model->getLoanById($loan_id); // totals already updated
                    $mail_note = notifyCustomer('repayment', [
                        'customer'       => ['name' => $loan['customer_name'], 'email' => $loan['customer_email'] ?? null],
                        'amount'         => (float)($_POST['amount'] ?? 0),
                        'loan_amount'    => (float)($after['loan_amount'] ?? $loan['loan_amount']),
                        'total_repaid'   => (float)($after['total_repaid'] ?? 0),
                        'reference_code' => $result['reference_code'] ?? '',
                    ]);
                    $mail_note .= notifyCustomerSms('repayment', [
                        'customer'       => ['name' => $loan['customer_name'], 'email' => $loan['customer_email'] ?? null, 'phone_number' => $loan['customer_phone'] ?? ($loan['phone_number'] ?? null)],
                        'amount'         => (float)($_POST['amount'] ?? 0),
                        'loan_amount'    => (float)($after['loan_amount'] ?? $loan['loan_amount']),
                        'total_repaid'   => (float)($after['total_repaid'] ?? 0),
                        'reference_code' => $result['reference_code'] ?? '',
                    ]);
                }
                $_SESSION['success'] = $base . $mail_note;
            } else {
                $_SESSION['error'] = $result['message'];
            }
        }
    }

    header('Location: loans.php');
    exit;
}

// Tabs: all / active / completed / overdue â€” server-side pagination (1000-user safe)
$filter = isset($_GET['status']) && in_array($_GET['status'], ['active','completed','overdue']) ? $_GET['status'] : 'all';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$rows  = $loan_model->getLoansByStatus($filter, $limit, $offset) ?: [];
$total = $loan_model->getLoanCountByStatus($filter);
$total_pages = max(1, (int)ceil($total / $limit));

$status_counts = [
    'all'       => $loan_model->getLoanCountByStatus('all'),
    'active'    => $loan_model->getLoanCountByStatus('active'),
    'completed' => $loan_model->getLoanCountByStatus('completed'),
    'overdue'   => $loan_model->getLoanCountByStatus('overdue'),
];

// Customer dropdown: capped list + AJAX search (never loads all 1000)
$customer_search = trim($_GET['customer_search'] ?? '');
$customers = $customer_model->getCustomerOptions($customer_search, 20) ?: [];

function loanTab($status, $label, $count, $current) {
    $active = $current === $status;
    $cls    = $active
        ? 'bg-blue-600 text-white'
        : 'text-gray-600 hover:bg-gray-100';
    return "<a href=\"loans.php?status={$status}\" class=\"px-4 py-2 rounded-md text-sm font-semibold transition {$cls}\">{$label} <span class=\"ml-1 opacity-75\">({$count})</span></a>";
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
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="PayTrack">
    <link rel="manifest" href="/public/manifest.json">
    <link rel="icon" type="image/png" href="/public/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/public/icons/icon-192.png">
    <title>Loans Management - PayTrack Admin</title>
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
                <a href="loan-applications.php"  class="text-gray-700 hover:text-blue-600 font-medium flex items-center">
                    Loan Requests
                    <?php if ($pending_apps_count > 0): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-amber-500 text-white ml-1"><?php echo $pending_apps_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="receipts.php"           class="text-gray-700 hover:text-blue-600 font-medium">Receipts</a>
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
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-3xl font-bold text-gray-800">Loans Management</h2>
            <p class="text-gray-500 text-sm mt-1">Create loans and record repayments</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="loan-applications.php?status=pending"
               class="bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 rounded-lg font-medium text-sm flex items-center">
                📋 Loan Requests
                <?php if ($pending_apps_count > 0): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-white text-amber-800 ml-2"><?php echo $pending_apps_count; ?> pending</span>
                <?php endif; ?>
            </a>
            <button onclick="openCreateLoan()"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium text-sm">
                + Create Loan
            </button>
        </div>
    </div>

    <?php if ($pending_apps_count > 0): ?>
    <div class="mb-6 p-4 bg-amber-50 border border-amber-300 rounded-lg flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <span class="text-2xl">⏳</span>
            <div>
                <p class="font-semibold text-amber-900">
                    <?php echo $pending_apps_count; ?> customer loan application<?php echo $pending_apps_count > 1 ? 's' : ''; ?> awaiting review
                </p>
                <p class="text-xs text-amber-700">Customers have submitted loan requests that need your approval.</p>
            </div>
        </div>
        <a href="loan-applications.php?status=pending" class="bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap">Review Requests →</a>
    </div>
    <?php endif; ?>

    <!-- Status tabs -->
    <div class="flex space-x-1 mb-6 bg-white rounded-lg shadow p-1 w-fit">
        <?php echo loanTab('all',       'All',         $status_counts['all'],       $filter); ?>
        <?php echo loanTab('active',    '?? Active',     $status_counts['active'],    $filter); ?>
        <?php echo loanTab('completed', '? Completed', $status_counts['completed'], $filter); ?>
        <?php echo loanTab('overdue',   '?? Overdue',    $status_counts['overdue'],   $filter); ?>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <?php if (empty($rows)): ?>
            <div class="px-6 py-16 text-center">
                <p class="text-4xl mb-3">??</p>
                <p class="text-gray-500 font-medium">No loans found.</p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Interest</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Given</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Due</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Repaid</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $loan): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                        <td class="px-6 py-4 font-semibold text-gray-800"><?php echo htmlspecialchars($loan['customer_name']); ?></td>
                        <td class="px-6 py-4 font-semibold text-gray-800"><?php echo formatCurrency($loan['loan_amount']); ?></td>
                        <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($loan['interest_rate']); ?>%</td>
                        <td class="px-6 py-4 text-gray-500 text-sm"><?php echo formatDate($loan['date_given']); ?></td>
                        <td class="px-6 py-4 text-gray-500 text-sm"><?php echo formatDate($loan['due_date']); ?></td>
                        <td class="px-6 py-4">
                            <?php
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
                            ?>
                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $badge; ?>"><?php echo $label; ?></span>
                        </td>
                        <td class="px-6 py-4 font-semibold text-gray-800"><?php echo formatCurrency($loan['total_repaid'] ?? 0); ?></td>
                        <td class="px-6 py-4 flex items-center gap-2">
                            <a href="loan-detail.php?id=<?php echo $loan['id']; ?>"
                               class="text-blue-600 hover:text-blue-700 font-semibold text-sm">View</a>
                            <?php if ($loan['status'] === 'active' || $loan['status'] === 'overdue'): ?>
                                <button onclick="openRepay(<?php echo $loan['id']; ?>)"
                                        class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-xs font-semibold">Record Payment</button>
                            <?php endif; ?>
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
                <a href="?status=<?php echo $filter; ?>&page=<?php echo $i; ?>"
                   class="px-3 py-1 rounded text-sm <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Create Loan Modal -->
<div id="createLoanModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <h3 class="text-xl font-bold text-gray-800 mb-4">?? Create Loan</h3>
        <p class="text-xs text-gray-500 mb-4">Customer must have approved KYC ï¿½ otherwise creation will be rejected.</p>
        <form method="POST"><?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create">
            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2 text-sm">Customer *</label>
                <input type="text" id="loanCustomerSearch" placeholder="Type name, phone or card ID..." autocomplete="off" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm mb-2">
                <select name="customer_id" id="loanCustomerSelect" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    <option value="">-- Select customer --</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name'] . ' (' . ($c['phone_number'] ?? 'no phone') . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 mt-1">Showing up to 20 — keep typing to search all customers.</p>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Loan Amount (₦) *</label>
                    <input type="number" name="loan_amount" step="0.01" min="1" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Interest Rate (%/month) *</label>
                    <input type="number" name="interest_rate" step="0.01" min="0" value="5.00" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Repayment Frequency</label>
                    <select name="repayment_frequency" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                        <option value="daily" selected>Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Purpose</label>
                    <input type="text" name="purpose" placeholder="e.g. Business capital" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Guarantor Name</label>
                    <input type="text" name="guarantor_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Guarantor Phone</label>
                    <input type="tel" name="guarantor_phone" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2 text-sm">Guarantor Address</label>
                <input type="text" name="guarantor_address" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2 text-sm">Collateral</label>
                <input type="text" name="collateral" placeholder="Optional" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Date Given *</label>
                    <input type="date" name="date_given" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Due Date *</label>
                    <input type="date" name="due_date" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold">Create Loan</button>
                <button type="button" onclick="closeCreateLoan()" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 rounded-lg font-semibold">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Record Repayment Modal -->
<div id="repayModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
        <h3 class="text-xl font-bold text-gray-800 mb-4">?? Record Repayment</h3>
        <form method="POST"><?php echo csrfField(); ?>
            <input type="hidden" name="action" value="repay">
            <input type="hidden" name="loan_id" id="repayLoanId">
            <p class="text-xs text-gray-500 mb-3">Total collected is calculated from principal and interest.</p>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Principal Paid (₦)</label>
                    <input type="number" name="principal_paid" id="repayPrincipal" step="0.01" min="0" value="0" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Interest Paid (₦)</label>
                    <input type="number" name="interest_paid" id="repayInterest" step="0.01" min="0" value="0" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2 text-sm">Total Amount (₦)</label>
                <input type="number" name="amount" id="repayAmount" step="0.01" min="0.01" value="0.00" readonly required class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-700 text-sm">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2 text-sm">Repayment Date</label>
                <input type="date" name="repayment_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2 text-sm">Notes (optional)</label>
                <textarea name="notes" rows="2" placeholder="Add a note..." class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold">Record</button>
                <button type="button" onclick="closeRepay()" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 rounded-lg font-semibold">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var principal = document.getElementById('repayPrincipal');
    var interest = document.getElementById('repayInterest');
    var amount = document.getElementById('repayAmount');
    function updateRepaymentAmount() {
        amount.value = ((Number(principal.value) || 0) + (Number(interest.value) || 0)).toFixed(2);
    }
    principal.addEventListener('input', updateRepaymentAmount);
    interest.addEventListener('input', updateRepaymentAmount);
})();
function openCreateLoan() {
    document.getElementById('createLoanModal').classList.remove('hidden');
}
function closeCreateLoan() {
    document.getElementById('createLoanModal').classList.add('hidden');
}
function openRepay(loanId) {
    document.getElementById('repayLoanId').value = loanId;
    document.getElementById('repayModal').classList.remove('hidden');
}
function closeRepay() {
    document.getElementById('repayModal').classList.add('hidden');
}
document.getElementById('createLoanModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreateLoan();
});
document.getElementById('repayModal').addEventListener('click', function(e) {
    if (e.target === this) closeRepay();
});
// Live customer search (1000-user safe): queries customer-search.php, capped at 20 rows
(function() {
    var box = document.getElementById('loanCustomerSearch');
    var sel = document.getElementById('loanCustomerSelect');
    if (!box || !sel) return;
    var t = null;
    box.addEventListener('input', function() {
        clearTimeout(t);
        var q = box.value.trim();
        t = setTimeout(function() {
            fetch('customer-search.php?q=' + encodeURIComponent(q), {credentials: 'same-origin'})
                .then(function(r) { return r.json(); })
                .then(function(rows) {
                    var keep = sel.value;
                    sel.innerHTML = '<option value="">-- Select customer --</option>';
                    rows.forEach(function(c) {
                        var o = document.createElement('option');
                        o.value = c.id;
                        o.textContent = c.name + ' (' + (c.phone_number || 'no phone') + ')';
                        sel.appendChild(o);
                    });
                    sel.value = keep;
                })
                .catch(function() { /* keep existing options on failure */ });
        }, 250);
    });
})();
</script>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
