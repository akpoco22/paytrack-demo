<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Staff.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();

$staff_model = new Staff($conn);

$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * ITEMS_PER_PAGE;

// Server-side search + pagination (1000-user safe)
if ($search_term !== '') {
    $total_filtered = $staff_model->getSearchStaffCount($search_term);
    $filtered_staff = $staff_model->searchStaff($search_term, ITEMS_PER_PAGE, $offset);
} else {
    $total_filtered = $staff_model->getTotalStaffCount();
    $filtered_staff = $staff_model->getAllStaff(ITEMS_PER_PAGE, $offset);
}
$total_staff = $staff_model->getTotalStaffCount();

// Apply pagination
$total_pages = max(1, (int)ceil($total_filtered / ITEMS_PER_PAGE));
$paginated_staff = $filtered_staff;
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
    <title>Staff Management - PayTrack Admin</title>
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
                    <a href="staff.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Staff</a>
                    <a href="staff-transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Staff Transactions</a>
                <a href="kyc.php" class="text-gray-700 hover:text-blue-600 font-medium">KYC</a>
                <a href="loans.php" class="text-gray-700 hover:text-blue-600 font-medium">Loans</a>
                <a href="receipts.php"           class="text-gray-700 hover:text-blue-600 font-medium">Receipts</a>
                    <a href="transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Transactions</a>
                    <a href="messages.php" class="text-gray-700 hover:text-blue-600 font-medium">Messages</a>
                <a href="export-backup.php" class="text-gray-700 hover:text-blue-600 font-medium">Export</a>
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
        <!-- Flash Messages -->
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

        <div class="flex justify-between items-center mb-6">
            <h2 class="text-3xl font-bold text-gray-800">Staff Management</h2>
            <div class="flex items-center gap-4">
                <p class="text-gray-600">Total Staff: <span class="font-bold text-blue-600"><?php echo $total_staff; ?></span></p>
                <a href="staff-transactions.php"
                   class="bg-blue-100 hover:bg-blue-200 text-blue-700 px-4 py-2 rounded-lg font-medium text-sm">
                    ?? View Transactions
                </a>
                <button
                    onclick="openAddStaffModal()"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium"
                >
                    + Register Staff
                </button>
            </div>
        </div>

        <!-- Search Section -->
        <div class="mb-6">
            <form method="GET" class="flex gap-3">
                <input
                    type="text"
                    name="search"
                    placeholder="Search staff by name, phone, or staff ID..."
                    value="<?php echo htmlspecialchars($search_term); ?>"
                    class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                >
                <button
                    type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium"
                >
                    Search
                </button>
                <?php if ($search_term): ?>
                    <a href="staff.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg font-medium">
                        Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Staff Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Staff ID</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Role</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Joined</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($paginated_staff)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-gray-600">
                                    No staff found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($paginated_staff as $staff): ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-50 transition">
                                    <td class="px-6 py-4 font-mono text-sm font-semibold text-blue-600"><?php echo htmlspecialchars($staff['staff_id']); ?></td>
                                    <td class="px-6 py-4 font-medium text-gray-800"><?php echo htmlspecialchars($staff['name']); ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($staff['phone_number'] ?? '-'); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-purple-100 text-purple-800">
                                            <?php echo ucfirst($staff['role']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $staff['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo $staff['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-gray-600"><?php echo formatDate($staff['created_at']); ?></td>
                                    <td class="px-6 py-4">
                                        <a href="staff-profile.php?id=<?php echo $staff['id']; ?>" class="text-blue-600 hover:text-blue-700 font-semibold">View Profile</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-center space-x-2 bg-gray-50">
                    <?php if ($page > 1): ?>
                        <a href="?page=1<?php echo $search_term ? '&search=' . urlencode($search_term) : ''; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">First</a>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $search_term ? '&search=' . urlencode($search_term) : ''; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Previous</a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search_term ? '&search=' . urlencode($search_term) : ''; ?>"
                           class="px-3 py-1 rounded <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $search_term ? '&search=' . urlencode($search_term) : ''; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Next</a>
                        <a href="?page=<?php echo $total_pages; ?><?php echo $search_term ? '&search=' . urlencode($search_term) : ''; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Last</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Staff Modal -->
    <div id="addStaffModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
            <h3 class="text-2xl font-bold text-gray-800 mb-4">Register New Staff</h3>

            <form id="addStaffForm" method="POST" action="add-staff.php"><?php echo csrfField(); ?>
                <!-- Full Name -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Full Name *</label>
                    <input
                        type="text"
                        name="name"
                        placeholder="Enter full name"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Phone Number -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Phone Number *</label>
                    <input
                        type="tel"
                        name="phone_number"
                        placeholder="Enter phone number"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Staff ID -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Staff ID *</label>
                    <input
                        type="text"
                        name="staff_id"
                        placeholder="e.g., SV001, LN001"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                    <p class="text-gray-600 text-xs mt-1">Will be used as password</p>
                </div>

                <!-- Role -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Role *</label>
                    <select
                        name="role"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                        <option value="">-- Select Role --</option>
                        <option value="loans">Loans Staff</option>
                    </select>
                </div>

                <!-- Address (Optional) -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Address (Optional)</label>
                    <input
                        type="text"
                        name="address"
                        placeholder="Enter address"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Buttons -->
                <div class="flex gap-3">
                    <button
                        type="submit"
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold"
                    >
                        Register Staff
                    </button>
                    <button
                        type="button"
                        onclick="closeAddStaffModal()"
                        class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 py-2 rounded-lg font-semibold"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddStaffModal() {
            document.getElementById('addStaffModal').classList.remove('hidden');
        }

        function closeAddStaffModal() {
            document.getElementById('addStaffModal').classList.add('hidden');
            document.getElementById('addStaffForm').reset();
        }

        // Close modal when clicking outside
        document.getElementById('addStaffModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddStaffModal();
            }
        });
    </script>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
