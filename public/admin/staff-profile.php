<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Staff.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();

$staff_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($staff_id === 0) {
    header('Location: staff.php');
    exit;
}

$staff_model = new Staff($conn);
$staff = $staff_model->getStaffById($staff_id);

if (!$staff) {
    header('Location: staff.php');
    exit;
}

// Get staff activity
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * ITEMS_PER_PAGE;
$activity = $staff_model->getStaffActivity($staff_id, ITEMS_PER_PAGE, $offset);
$activity_count = $staff_model->getStaffActivityCount($staff_id);
$activity_pages = ceil($activity_count / ITEMS_PER_PAGE);

// Handle password reset
$password_error = '';
$password_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    requireCsrf();
    $new_password = $_POST['new_password'] ?? '';

    if (strlen($new_password) < 8) {
        $password_error = 'Password must be at least 8 characters';
    } else {
        $result = $staff_model->updatePassword($staff_id, $new_password);
        if ($result['success']) {
            $password_success = 'Password updated successfully!';
        } else {
            $password_error = $result['message'];
        }
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
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="PayTrack">
    <link rel="manifest" href="/public/manifest.json">
    <link rel="icon" type="image/png" href="/public/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/public/icons/icon-192.png">
    <title>Staff Profile - PayTrack Admin</title>
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
                    <a href="users.php" class="text-gray-700 hover:text-blue-600 font-medium">Customers</a>
                    <a href="staff.php" class="text-blue-600 font-medium">Staff</a>
                <a href="kyc.php" class="text-gray-700 hover:text-blue-600 font-medium">KYC</a>
                <a href="loans.php" class="text-gray-700 hover:text-blue-600 font-medium">Loans</a>
                <a href="receipts.php"           class="text-gray-700 hover:text-blue-600 font-medium">Receipts</a>
                    <a href="transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Transactions</a>
                    <a href="messages.php" class="text-gray-700 hover:text-blue-600 font-medium">Messages</a>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Back Link -->
        <a href="staff.php" class="text-blue-600 hover:text-blue-700 font-semibold mb-6 inline-block">Back to Staff</a>

        <!-- Staff Info Section -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- Staff Details -->
            <div class="md:col-span-2 bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">Staff Information</h2>
                    <button
                        onclick="openPasswordModal()"
                        class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg font-medium text-sm"
                    >
                        ?? Reset Password
                    </button>
                </div>

                <div class="space-y-4">
                    <div class="border-b border-gray-200 pb-4 bg-blue-50 p-3 rounded">
                        <p class="text-gray-600 text-sm font-semibold">Staff ID</p>
                        <p class="text-blue-700 text-lg font-mono font-bold"><?php echo htmlspecialchars($staff['staff_id']); ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Full Name</p>
                        <p class="text-gray-800 text-lg"><?php echo htmlspecialchars($staff['name']); ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Phone Number</p>
                        <p class="text-gray-800 text-lg"><?php echo htmlspecialchars($staff['phone_number'] ?? 'N/A'); ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Role</p>
                        <p class="text-gray-800 text-lg">
                            <span class="px-3 py-1 rounded-full text-sm font-semibold bg-purple-100 text-purple-800">
                                <?php echo ucfirst($staff['role']); ?>
                            </span>
                        </p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Address</p>
                        <p class="text-gray-800 text-lg"><?php echo htmlspecialchars($staff['address'] ?? 'N/A'); ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Status</p>
                        <p class="text-gray-800 text-lg">
                            <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $staff['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo $staff['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </p>
                    </div>

                    <div>
                        <p class="text-gray-600 text-sm font-semibold">Member Since</p>
                        <p class="text-gray-800 text-lg"><?php echo formatDate($staff['created_at']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Activity Stats Card -->
            <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg shadow p-6 text-white">
                <p class="text-blue-100 text-sm font-semibold mb-2">Total Transactions Recorded</p>
                <p class="text-4xl font-bold mb-8"><?php echo $activity_count; ?></p>

                <div class="space-y-3 pt-6 border-t border-blue-500">
                    <div class="flex justify-between">
                        <span class="text-blue-100">Joined</span>
                        <span class="font-semibold"><?php echo formatDate($staff['created_at']); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-blue-100">Role</span>
                        <span class="font-semibold"><?php echo ucfirst($staff['role']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activity History -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-bold text-gray-800">Transaction Activity</h3>
            </div>

            <?php if (empty($activity)): ?>
                <div class="px-6 py-8 text-center text-gray-600">
                    No transactions recorded by this staff member.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Reference Code</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Customer</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activity as $txn): ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                    <td class="px-6 py-4 font-mono text-sm text-gray-800"><?php echo htmlspecialchars($txn['reference_code']); ?></td>
                                    <td class="px-6 py-4 text-gray-800"><?php echo htmlspecialchars($txn['customer_name']); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $txn['type'] === 'deposit' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo ucfirst($txn['type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 font-semibold text-gray-800">?<?php echo number_format($txn['amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($txn['description'] ?? '-'); ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?php echo formatDateTime($txn['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($activity_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-gray-200 flex justify-center space-x-2 bg-gray-50">
                        <?php if ($page > 1): ?>
                            <a href="?id=<?php echo $staff_id; ?>&page=1" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">First</a>
                            <a href="?id=<?php echo $staff_id; ?>&page=<?php echo $page - 1; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Previous</a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($activity_pages, $page + 2); $i++): ?>
                            <a href="?id=<?php echo $staff_id; ?>&page=<?php echo $i; ?>"
                               class="px-3 py-1 rounded <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $activity_pages): ?>
                            <a href="?id=<?php echo $staff_id; ?>&page=<?php echo $page + 1; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Next</a>
                            <a href="?id=<?php echo $staff_id; ?>&page=<?php echo $activity_pages; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Last</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="passwordModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-lg p-6 w-96">
            <h3 class="text-2xl font-bold text-gray-800 mb-4">Reset Password</h3>

            <?php if ($password_error): ?>
                <div class="mb-4 p-3 bg-red-100 border border-red-400 text-red-700 rounded-lg">
                    <?php echo htmlspecialchars($password_error); ?>
                </div>
            <?php endif; ?>

            <?php if ($password_success): ?>
                <div class="mb-4 p-3 bg-green-100 border border-green-400 text-green-700 rounded-lg">
                    <?php echo htmlspecialchars($password_success); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4"><?php echo csrfField(); ?>
                <input type="hidden" name="action" value="reset_password">

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">New Password</label>
                    <input
                        type="text"
                        name="new_password"
                        placeholder="Enter new password"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-600"
                    >
                    <p class="text-gray-600 text-sm mt-1">Staff ID is often used as password</p>
                </div>

                <div class="flex gap-3">
                    <button
                        type="submit"
                        class="flex-1 bg-orange-600 hover:bg-orange-700 text-white py-2 rounded-lg font-semibold"
                    >
                        Reset Password
                    </button>
                    <button
                        type="button"
                        onclick="closePasswordModal()"
                        class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 py-2 rounded-lg font-semibold"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openPasswordModal() {
            document.getElementById('passwordModal').classList.remove('hidden');
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').classList.add('hidden');
        }

        // Close modal when clicking outside
        document.getElementById('passwordModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePasswordModal();
            }
        });
    </script>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
