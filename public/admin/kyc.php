<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/mail.php';
require_once '../../config/sms.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/KYC.php';
require_once '../../src/models/Customer.php';
require_once '../../includes/helpers.php';
require_once '../../includes/mail-notify.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

$kyc_model = new KYC($conn);
$customer_model = new Customer($conn);

// Handle approve/reject POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kyc_id  = (int)($_POST['kyc_id'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $notes   = sanitize($_POST['notes'] ?? '');
    $admin_id = $_SESSION['user_id'] ?? 0;

    if ($kyc_id && $action === 'approve') {
        $result = $kyc_model->approveKYC($kyc_id, $admin_id, $notes);
        $mail_note = '';
        if ($result['success']) {
            $kyc_row = $conn->prepare("SELECT k.customer_id, c.name, c.email, c.phone_number FROM kyc_records k JOIN customers c ON k.customer_id = c.id WHERE k.id = ?");
            $kyc_row->execute([$kyc_id]);
            if ($customer = $kyc_row->fetch(PDO::FETCH_ASSOC)) {
                $mail_note = notifyCustomer('kyc_approved', ['customer' => $customer, 'notes' => $notes]);
                $mail_note .= notifyCustomerSms('kyc_approved', ['customer' => $customer]);
            }
        }
        $_SESSION[$result['success'] ? 'success' : 'error'] = $result['message'] . $mail_note;
    } elseif ($kyc_id && $action === 'reject') {
        $result = $kyc_model->rejectKYC($kyc_id, $admin_id, $notes);
        $mail_note = '';
        if ($result['success']) {
            $kyc_row = $conn->prepare("SELECT k.customer_id, c.name, c.email, c.phone_number FROM kyc_records k JOIN customers c ON k.customer_id = c.id WHERE k.id = ?");
            $kyc_row->execute([$kyc_id]);
            if ($customer = $kyc_row->fetch(PDO::FETCH_ASSOC)) {
                $mail_note = notifyCustomer('kyc_rejected', ['customer' => $customer, 'notes' => $notes]);
                $mail_note .= notifyCustomerSms('kyc_rejected', ['customer' => $customer]);
            }
        }
        $_SESSION[$result['success'] ? 'success' : 'error'] = $result['message'] . $mail_note;
    }

    header('Location: kyc.php');
    exit;
}

// Tabs: all / pending / approved / rejected â€” server-side pagination (1000-user safe)
$filter = isset($_GET['status']) && in_array($_GET['status'], ['pending','approved','rejected']) ? $_GET['status'] : 'all';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 15;
$offset = ($page - 1) * $limit;

$rows  = $kyc_model->getKYCByStatus($filter, $limit, $offset) ?: [];
$total = $kyc_model->getKYCCountByStatus($filter);
$total_pages   = max(1, (int)ceil($total / $limit));

$pending_count = $kyc_model->getPendingKYCCount();

$status_counts = [
    'all'      => $kyc_model->getKYCCountByStatus('all'),
    'pending'  => $pending_count,
    'approved' => $kyc_model->getKYCCountByStatus('approved'),
    'rejected' => $kyc_model->getKYCCountByStatus('rejected'),
];

function navLink($status, $label, $count, $current) {
    $active = $current === $status;
    $cls    = $active
        ? 'bg-blue-600 text-white'
        : 'text-gray-600 hover:bg-gray-100';
    return "<a href=\"kyc.php?status={$status}\" class=\"px-4 py-2 rounded-md text-sm font-semibold transition {$cls}\">{$label} <span class=\"ml-1 opacity-75\">({$count})</span></a>";
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
    <title>KYC Management - PayTrack Admin</title>
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
                <a href="kyc.php"                class="text-blue-600 font-medium border-b-2 border-blue-600">KYC</a>
                <a href="loans.php"              class="text-gray-700 hover:text-blue-600 font-medium">Loans</a>
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
            <h2 class="text-3xl font-bold text-gray-800">KYC Management</h2>
            <p class="text-gray-500 text-sm mt-1">Verify customer identity records</p>
        </div>
        <?php if ($pending_count > 0): ?>
            <div class="bg-yellow-100 border border-yellow-300 text-yellow-800 px-4 py-2 rounded-lg text-sm font-semibold">
                ? <?php echo $pending_count; ?> pending review<?php echo $pending_count > 1 ? 's' : ''; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Status tabs -->
    <div class="flex space-x-1 mb-6 bg-white rounded-lg shadow p-1 w-fit">
        <?php echo navLink('all',      'All',      $status_counts['all'],      $filter); ?>
        <?php echo navLink('pending',  '? Pending',  $status_counts['pending'],  $filter); ?>
        <?php echo navLink('approved', '? Approved', $status_counts['approved'], $filter); ?>
        <?php echo navLink('rejected', '? Rejected', $status_counts['rejected'], $filter); ?>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <?php if (empty($rows)): ?>
            <div class="px-6 py-16 text-center">
                <p class="text-4xl mb-3">??</p>
                <p class="text-gray-500 font-medium">No KYC records found.</p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Phone</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Submitted</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $kyc): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                        <td class="px-6 py-4 font-semibold text-gray-800"><?php echo htmlspecialchars($kyc['name']); ?></td>
                        <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($kyc['phone_number'] ?? 'ï¿½'); ?></td>
                        <td class="px-6 py-4">
                            <?php
                            $s = $kyc['verification_status'];
                            $badge = match($s) {
                                'approved' => 'bg-green-100 text-green-700',
                                'rejected' => 'bg-red-100 text-red-700',
                                default    => 'bg-yellow-100 text-yellow-700',
                            };
                            $label = match($s) {
                                'approved' => '? Approved',
                                'rejected' => '? Rejected',
                                default    => '? Pending',
                            };
                            ?>
                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $badge; ?>"><?php echo $label; ?></span>
                        </td>
                        <td class="px-6 py-4 text-gray-500 text-sm"><?php echo formatDate($kyc['created_at']); ?></td>
                        <td class="px-6 py-4 flex items-center gap-2">
                            <a href="kyc-detail.php?id=<?php echo $kyc['id']; ?>"
                               class="text-blue-600 hover:text-blue-700 font-semibold text-sm">View</a>
                            <?php if ($kyc['verification_status'] === 'pending'): ?>
                                <button onclick="openAction(<?php echo $kyc['id']; ?>, 'approve')"
                                        class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-xs font-semibold">Approve</button>
                                <button onclick="openAction(<?php echo $kyc['id']; ?>, 'reject')"
                                        class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-xs font-semibold">Reject</button>
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
<div id="actionModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-96">
        <h3 id="modalTitle" class="text-xl font-bold text-gray-800 mb-4"></h3>
        <form method="POST"><?php echo csrfField(); ?>
            <input type="hidden" name="kyc_id" id="modalKycId">
            <input type="hidden" name="action" id="modalAction">
            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2">Notes (optional)</label>
                <textarea name="notes" rows="3" placeholder="Add a note..."
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="submit" id="modalSubmitBtn" class="flex-1 py-2 rounded-lg text-white font-semibold"></button>
                <button type="button" onclick="closeAction()" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 rounded-lg font-semibold">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAction(kycId, action) {
    document.getElementById('modalKycId').value  = kycId;
    document.getElementById('modalAction').value = action;
    const btn   = document.getElementById('modalSubmitBtn');
    const title = document.getElementById('modalTitle');
    if (action === 'approve') {
        title.textContent  = '? Approve KYC Record';
        btn.textContent    = 'Approve';
        btn.className      = 'flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold';
    } else {
        title.textContent  = '? Reject KYC Record';
        btn.textContent    = 'Reject';
        btn.className      = 'flex-1 bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg font-semibold';
    }
    document.getElementById('actionModal').classList.remove('hidden');
}
function closeAction() {
    document.getElementById('actionModal').classList.add('hidden');
}
document.getElementById('actionModal').addEventListener('click', function(e) {
    if (e.target === this) closeAction();
});
</script>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
