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

$kyc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$kyc_id) {
    header('Location: kyc.php');
    exit;
}

$kyc_model = new KYC($conn);
$admin_id  = $_SESSION['user_id'] ?? 0;

// Handle approve/reject POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $notes  = sanitize($_POST['notes'] ?? '');

    if ($action === 'approve') {
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
        $flash = $result['message'] . $mail_note;
    } elseif ($action === 'reject') {
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
        $flash = $result['message'] . $mail_note;
    }
    if (isset($result)) {
        $_SESSION[$result['success'] ? 'success' : 'error'] = $flash ?? $result['message'];
    }
    header('Location: kyc-detail.php?id=' . $kyc_id);
    exit;
}

// Fetch KYC record with customer info
$stmt = $conn->prepare(
    "SELECT k.*, c.name, c.phone_number, c.email, c.card_id, c.balance,
            c.address as customer_address
     FROM kyc_records k
     JOIN customers c ON k.customer_id = c.id
     WHERE k.id = ?"
);
$stmt->execute([$kyc_id]);
$kyc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$kyc) {
    $_SESSION['error'] = 'KYC record not found.';
    header('Location: kyc.php');
    exit;
}

// Fetch verifier name if verified
$verifier_name = null;
if ($kyc['verified_by']) {
    $vStmt = $conn->prepare("SELECT name FROM customers WHERE id = ?");
    $vStmt->execute([$kyc['verified_by']]);
    $vRow = $vStmt->fetch(PDO::FETCH_ASSOC);
    $verifier_name = $vRow['name'] ?? 'Admin #' . $kyc['verified_by'];
}

$status = $kyc['verification_status'];
$status_badge = match($status) {
    'approved' => ['label' => '? Approved', 'cls' => 'bg-green-100 text-green-700'],
    'rejected' => ['label' => '? Rejected', 'cls' => 'bg-red-100 text-red-700'],
    default    => ['label' => '? Pending',  'cls' => 'bg-yellow-100 text-yellow-700'],
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
    <meta name="apple-mobile-web-app-title" content="PayTrack">
    <link rel="manifest" href="/public/manifest.json">
    <link rel="icon" type="image/png" href="/public/icons/icon-192.png">
    <title>KYC Detail - PayTrack Admin</title>
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
            <a href="kyc.php" class="text-blue-600 hover:text-blue-700 font-medium text-sm">? Back to KYC</a>
            <h2 class="text-3xl font-bold text-gray-800">KYC Detail</h2>
            <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $status_badge['cls']; ?>">
                <?php echo $status_badge['label']; ?>
            </span>
        </div>
        <p class="text-gray-500 text-sm">Submitted: <?php echo formatDate($kyc['created_at']); ?></p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Customer Info -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">?? Customer Information</h3>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Full Name</dt>
                    <dd class="text-gray-800 font-semibold"><?php echo htmlspecialchars($kyc['name']); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Phone</dt>
                    <dd class="text-gray-800"><?php echo htmlspecialchars($kyc['phone_number'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Email</dt>
                    <dd class="text-gray-800"><?php echo htmlspecialchars($kyc['email'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Card ID</dt>
                    <dd class="font-mono text-blue-600 font-semibold"><?php echo htmlspecialchars($kyc['card_id'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Balance</dt>
                    <dd class="text-gray-800 font-semibold"><?php echo formatCurrency($kyc['balance'] ?? 0); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Address</dt>
                    <dd class="text-gray-800 text-right max-w-xs"><?php echo htmlspecialchars($kyc['customer_address'] ?? '�'); ?></dd>
                </div>
                <div class="pt-2 border-t">
                    <a href="profile.php?id=<?php echo $kyc['customer_id']; ?>"
                       class="text-blue-600 hover:text-blue-700 text-sm font-semibold">
                        View Full Profile ?
                    </a>
                </div>
            </dl>
        </div>

        <!-- KYC Details -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">?? KYC Information</h3>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">ID Type</dt>
                    <dd class="text-gray-800"><?php echo htmlspecialchars($kyc['id_type'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">ID Number</dt>
                    <dd class="font-mono text-gray-800"><?php echo htmlspecialchars($kyc['id_number'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">ID Expiry</dt>
                    <dd class="text-gray-800"><?php echo $kyc['id_expiry'] ? formatDate($kyc['id_expiry']) : '�'; ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">KYC Address</dt>
                    <dd class="text-gray-800 text-right max-w-xs"><?php echo htmlspecialchars($kyc['address'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Employment</dt>
                    <dd class="text-gray-800"><?php echo htmlspecialchars($kyc['employment_status'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Income Source</dt>
                    <dd class="text-gray-800 text-right max-w-xs"><?php echo htmlspecialchars($kyc['income_source'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Bank Account</dt>
                    <dd class="font-mono text-gray-800"><?php echo htmlspecialchars($kyc['bank_account'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Bank Name</dt>
                    <dd class="text-gray-800"><?php echo htmlspecialchars($kyc['bank_name'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Reference Contact</dt>
                    <dd class="text-gray-800"><?php echo htmlspecialchars($kyc['reference_contact_name'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Reference Phone</dt>
                    <dd class="text-gray-800"><?php echo htmlspecialchars($kyc['reference_contact_phone'] ?? '�'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">BVN</dt>
                    <dd class="font-mono text-gray-800"><?php echo htmlspecialchars($kyc['bvn'] ?? '-'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">NIN</dt>
                    <dd class="font-mono text-gray-800"><?php echo htmlspecialchars($kyc['nin'] ?? '-'); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Guarantor</dt>
                    <dd class="text-gray-800 text-right max-w-xs">
                        <?php echo htmlspecialchars($kyc['guarantor_name'] ?? '-'); ?>
                        <?php if (!empty($kyc['guarantor_phone'])): ?>
                            <div class="text-xs text-gray-500">
                                <?php echo htmlspecialchars($kyc['guarantor_phone']); ?>
                                <?php if (!empty($kyc['guarantor_address'])): ?> · <?php echo htmlspecialchars($kyc['guarantor_address']); ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 text-sm font-medium">Collateral</dt>
                    <dd class="text-gray-800 text-right max-w-xs"><?php echo htmlspecialchars($kyc['collateral'] ?? '-'); ?></dd>
                </div>
            </dl>
        </div>

    </div>

    <!-- Verification Section -->
    <?php if ($status !== 'pending'): ?>
    <div class="mt-6 bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">
            <?php echo $status === 'approved' ? '? Verification Details' : '? Rejection Details'; ?>
        </h3>
        <dl class="space-y-3">
            <div class="flex justify-between">
                <dt class="text-gray-500 text-sm font-medium">Verified By</dt>
                <dd class="text-gray-800"><?php echo htmlspecialchars($verifier_name ?? '�'); ?></dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500 text-sm font-medium">Verified At</dt>
                <dd class="text-gray-800"><?php echo $kyc['verified_at'] ? formatDateTime($kyc['verified_at']) : '�'; ?></dd>
            </div>
            <?php if ($kyc['notes']): ?>
            <div class="pt-2 border-t">
                <dt class="text-gray-500 text-sm font-medium mb-1">Notes</dt>
                <dd class="text-gray-800 bg-gray-50 rounded p-3 text-sm"><?php echo nl2br(htmlspecialchars($kyc['notes'])); ?></dd>
            </div>
            <?php endif; ?>
        </dl>
    </div>
    <?php endif; ?>

    <!-- Action Buttons (Pending only) -->
    <?php if ($status === 'pending'): ?>
    <div class="mt-6 bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">? Actions</h3>
        <div class="flex gap-4">
            <button onclick="openAction('approve')"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-semibold">
                ? Approve KYC
            </button>
            <button onclick="openAction('reject')"
                    class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg font-semibold">
                ? Reject KYC
            </button>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Approve/Reject Modal -->
<div id="actionModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-96">
        <h3 id="modalTitle" class="text-xl font-bold text-gray-800 mb-4"></h3>
        <form method="POST"><?php echo csrfField(); ?>
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
function openAction(action) {
    document.getElementById('modalAction').value = action;
    const btn   = document.getElementById('modalSubmitBtn');
    const title = document.getElementById('modalTitle');
    if (action === 'approve') {
        title.textContent = '? Approve KYC Record';
        btn.textContent   = 'Approve';
        btn.className     = 'flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold';
    } else {
        title.textContent = '? Reject KYC Record';
        btn.textContent   = 'Reject';
        btn.className     = 'flex-1 bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg font-semibold';
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
