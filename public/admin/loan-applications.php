<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/mail.php';
require_once '../../config/sms.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/LoanApplication.php';
require_once '../../src/models/Customer.php';
require_once '../../includes/helpers.php';
require_once '../../includes/mail-notify.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

$app_model = new LoanApplication($conn);
$customer_model = new Customer($conn);
$admin_id = Auth::getUserId();

// Handle approve / reject POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $app_id  = (int)($_POST['application_id'] ?? 0);
    $notes   = sanitize($_POST['notes'] ?? '');

    if ($action === 'approve') {
        $date_given = sanitize($_POST['date_given'] ?? date('Y-m-d'));
        $due_date   = sanitize($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
        $result = $app_model->approve($app_id, null, $date_given, $due_date, $notes, $admin_id);
        if ($result['success']) {
            $app = $app_model->getById($app_id);
                $mail_note = '';
                if ($app) {
                    $cust = $customer_model->getCustomerById($app['customer_id']);
                    if ($cust) {
                        $mail_note = notifyCustomer('loan_created', [
                            'customer'    => $cust,
                            'loan_amount' => $app['loan_amount'],
                            'due_date'    => $due_date,
                        ]);
                        $mail_note .= notifyCustomerSms('loan_created', [
                            'customer'    => $cust,
                            'loan_amount' => $app['loan_amount'],
                            'due_date'    => $due_date,
                        ]);
                    }
                }
                $_SESSION['success'] = $result['message'] . $mail_note;
        } else {
            $_SESSION['error'] = $result['message'];
        }
    } elseif ($action === 'reject') {
        $result = $app_model->reject($app_id, null, $notes, $admin_id);
        $_SESSION[$result['success'] ? 'success' : 'error'] = $result['message'];
    }

    header('Location: loan-applications.php');
    exit;
}

// Tabs: all / pending / approved / rejected — server-side pagination (1000-user safe)
$filter = isset($_GET['status']) && in_array($_GET['status'], ['pending','approved','rejected']) ? $_GET['status'] : 'pending';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$rows  = $app_model->getApplicationsByStatus($filter, $limit, $offset) ?: [];
$total = $app_model->countByStatus($filter);
$total_pages = max(1, (int)ceil($total / $limit));

$status_counts = [
    'all'      => $app_model->countByStatus('all'),
    'pending'  => $app_model->countByStatus('pending'),
    'approved' => $app_model->countByStatus('approved'),
    'rejected' => $app_model->countByStatus('rejected'),
];

function appTab($status, $label, $count, $current) {
    $active = $current === $status;
    $cls = $active ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100';
    return "<a href=\"loan-applications.php?status={$status}\" class=\"px-4 py-2 rounded-md text-sm font-semibold transition {$cls}\">{$label} <span class=\"ml-1 opacity-75\">({$count})</span></a>";
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
    <title>Loan Applications - PayTrack Admin</title>
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
                <a href="kyc.php"                class="text-gray-700 hover:text-blue-600 font-medium">KYC</a>
                <a href="loan-applications.php"  class="text-blue-600 font-medium border-b-2 border-blue-600">Loan Applications</a>
                <a href="loans.php"              class="text-gray-700 hover:text-blue-600 font-medium">Loans</a>
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
            <h2 class="text-3xl font-bold text-gray-800">Loan Applications</h2>
            <p class="text-gray-500 text-sm mt-1">Customer-submitted loan requests</p>
        </div>
    </div>

    <!-- Status tabs -->
    <div class="flex flex-wrap space-x-1 mb-6 bg-white rounded-lg shadow p-1 w-fit">
        <?php echo appTab('pending',  'Pending',  $status_counts['pending'],  $filter); ?>
        <?php echo appTab('approved', 'Approved', $status_counts['approved'], $filter); ?>
        <?php echo appTab('rejected', 'Rejected', $status_counts['rejected'], $filter); ?>
        <?php echo appTab('all',      'All',      $status_counts['all'],      $filter); ?>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <?php if (empty($rows)): ?>
            <div class="px-6 py-16 text-center">
                <p class="text-4xl mb-3">📭</p>
                <p class="text-gray-500 font-medium">No applications in this view.</p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Rate</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Plan</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Applied</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $app): ?>
                    <?php
                        $badge = match($app['status']) {
                            'approved' => 'bg-green-100 text-green-700',
                            'rejected' => 'bg-red-100 text-red-700',
                            default    => 'bg-yellow-100 text-yellow-700',
                        };
                    ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                        <td class="px-6 py-4">
                            <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($app['customer_name']); ?></p>
                            <p class="text-gray-500 text-xs"><?php echo htmlspecialchars($app['phone_number'] ?? ''); ?></p>
                        </td>
                        <td class="px-6 py-4 font-semibold text-gray-800"><?php echo formatCurrency($app['loan_amount']); ?></td>
                        <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($app['interest_rate']); ?>%</td>
                        <td class="px-6 py-4 text-gray-600"><?php echo ucfirst($app['repayment_frequency'] ?? 'daily'); ?></td>
                        <td class="px-6 py-4 text-gray-500 text-sm"><?php echo formatDate($app['created_at']); ?></td>
                        <td class="px-6 py-4">
                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $badge; ?>"><?php echo ucfirst($app['status']); ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($app['status'] === 'pending'): ?>
                                <button onclick="openReview(<?php echo $app['id']; ?>, 'approve')"
                                        class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-xs font-semibold mr-2">Approve</button>
                                <button onclick="openReview(<?php echo $app['id']; ?>, 'reject')"
                                        class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-xs font-semibold">Reject</button>
                            <?php else: ?>
                                <span class="text-gray-400 text-xs">—</span>
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

<!-- Approve/Reject Modal -->
<div id="reviewModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
        <h3 id="modalTitle" class="text-xl font-bold text-gray-800 mb-4"></h3>
        <form method="POST"><?php echo csrfField(); ?>
            <input type="hidden" name="application_id" id="modalAppId">
            <input type="hidden" name="action" id="modalAction">

            <div id="approveFields" class="space-y-4">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Date Given</label>
                    <input type="date" name="date_given" value="<?php echo date('Y-m-d'); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2 text-sm">Due Date</label>
                    <input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2 text-sm">Notes (optional)</label>
                <textarea name="notes" rows="2" placeholder="Add a note..."
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"></textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit" id="modalSubmitBtn" class="flex-1 py-2 rounded-lg text-white font-semibold"></button>
                <button type="button" onclick="closeReview()" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 rounded-lg font-semibold">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReview(appId, action) {
    document.getElementById('modalAppId').value = appId;
    document.getElementById('modalAction').value = action;
    document.getElementById('modalTitle').textContent = (action === 'approve' ? 'Approve ' : 'Reject ') + 'Loan Application';
    document.getElementById('approveFields').style.display = (action === 'approve') ? 'block' : 'none';

    const btn = document.getElementById('modalSubmitBtn');
    btn.textContent = action === 'approve' ? 'Approve & Create Loan' : 'Reject Application';
    btn.className = 'flex-1 py-2 rounded-lg text-white font-semibold ' + (action === 'approve' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700');

    document.getElementById('reviewModal').classList.remove('hidden');
}
function closeReview() {
    document.getElementById('reviewModal').classList.add('hidden');
}
document.getElementById('reviewModal').addEventListener('click', function(e) {
    if (e.target === this) closeReview();
});
</script>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
