<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Customer.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();

$customer = new Customer($conn);

$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * ITEMS_PER_PAGE;

if ($search_term) {
    $customers = $customer->searchCustomersByName($search_term, ITEMS_PER_PAGE, $offset);
    $total_customers = $customer->getSearchCustomerCount($search_term);
} else {
    $customers = $customer->getAllCustomers(ITEMS_PER_PAGE, $offset);
    $total_customers = $customer->getTotalCustomerCount();
}
// Record-transaction modal dropdown: capped list, server-side search (1000-user safe)
$modal_customers = $customer->getCustomerOptions('', 20) ?: [];
$total_pages = ceil($total_customers / ITEMS_PER_PAGE);
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
    <title>Customers - PayTrack Admin</title>
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
                    <a href="users.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Customers</a>
                    <a href="staff.php" class="text-gray-700 hover:text-blue-600 font-medium">Staff</a>
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
            <h2 class="text-3xl font-bold text-gray-800">All Customers</h2>
            <div class="flex items-center gap-4">
                <p class="text-gray-600">Total Customers: <span class="font-bold text-blue-600"><?php echo $total_customers; ?></span></p>
                <button
                    onclick="openAddCustomerModal()"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium"
                >
                    + Add Customer
                </button>
                <button
                    onclick="openTransactionModal()"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium"
                >
                    + Record Transaction
                </button>
            </div>
        </div>

        <!-- Search Section -->
        <div class="mb-6">
            <form method="GET" class="flex gap-3">
                <input
                    type="text"
                    name="search"
                    placeholder="Search customers by name..."
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
                    <a href="users.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg font-medium">
                        Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Customers Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Card ID</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Balance</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Joined</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $cust): ?>
                            <tr class="border-b border-gray-200 hover:bg-gray-50 transition">
                                <td class="px-6 py-4 font-mono text-sm font-semibold text-blue-600"><?php echo htmlspecialchars($cust['card_id'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4 font-medium text-gray-800"><?php echo htmlspecialchars($cust['name']); ?></td>
                                <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($cust['email']); ?></td>
                                <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($cust['phone_number'] ?? '-'); ?></td>
                                <td class="px-6 py-4 font-semibold text-gray-800"><?php echo formatCurrency($cust['balance']); ?></td>
                                <td class="px-6 py-4 text-gray-600"><?php echo formatDate($cust['created_at']); ?></td>
                                <td class="px-6 py-4">
                                    <a href="profile.php?id=<?php echo $cust['id']; ?>" class="text-blue-600 hover:text-blue-700 font-semibold">View Profile</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-center space-x-2 bg-gray-50">
                    <?php if ($page > 1): ?>
                        <a href="?page=1" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">First</a>
                        <a href="?page=<?php echo $page - 1; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Previous</a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>"
                           class="px-3 py-1 rounded <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Next</a>
                        <a href="?page=<?php echo $total_pages; ?>" class="px-3 py-1 rounded bg-gray-200 text-gray-800 hover:bg-gray-300">Last</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Transaction Modal -->
    <div id="transactionModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md max-h-[90vh] overflow-y-auto">
            <h3 class="text-2xl font-bold text-gray-800 mb-4">Record Transaction</h3>

            <form id="transactionForm" method="POST" action="take-record.php"><?php echo csrfField(); ?>
                <!-- Customer Search -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Search Customer</label>
                    <input
                        type="text"
                        id="customerSearch"
                        placeholder="Search by name or Card ID..."
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600 mb-2"
                    >
                </div>

                <!-- Customer Selection -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Select Customer</label>
                    <select
                        name="customer_id"
                        id="customerSelect"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                        <option value="">-- Choose a customer --</option>
                        <?php foreach ($modal_customers as $cust): ?>
                            <option value="<?php echo $cust['id']; ?>" data-name="<?php echo htmlspecialchars($cust['name']); ?>" data-card="<?php echo htmlspecialchars($cust['card_id'] ?? ''); ?>">
                                <?php echo htmlspecialchars($cust['name']) . ' (' . htmlspecialchars($cust['card_id'] ?? 'N/A') . ') - ?' . number_format($cust['balance'], 2); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Transaction Type -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Transaction Type</label>
                    <div class="flex gap-4">
                        <label class="flex items-center">
                            <input
                                type="radio"
                                name="type"
                                value="deposit"
                                required
                                class="w-4 h-4 text-blue-600"
                            >
                            <span class="ml-2 text-gray-700">Deposit</span>
                        </label>
                        <label class="flex items-center">
                            <input
                                type="radio"
                                name="type"
                                value="withdrawal"
                                class="w-4 h-4 text-blue-600"
                            >
                            <span class="ml-2 text-gray-700">Withdrawal</span>
                        </label>
                    </div>
                </div>

                <!-- Amount -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Amount</label>
                    <input
                        type="number"
                        name="amount"
                        step="0.01"
                        min="0"
                        placeholder="Enter amount"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Description -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Description (Optional)</label>
                    <input
                        type="text"
                        name="description"
                        placeholder="Enter transaction description"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Backdate Transaction -->
                <div class="mb-4 border-t border-gray-200 pt-4">
                    <label class="flex items-center">
                        <input
                            type="checkbox"
                            name="use_backdate"
                            id="use_backdate"
                            class="w-4 h-4 text-blue-600 rounded"
                        >
                        <span class="ml-2 text-gray-700 font-semibold">Backdate this transaction</span>
                    </label>
                    <p class="text-gray-600 text-sm mt-1">Check if transaction occurred on a different date</p>
                </div>

                <!-- Transaction Date -->
                <div id="backdateSection" class="mb-4 hidden">
                    <label class="block text-gray-700 font-semibold mb-2">Transaction Date & Time</label>
                    <input
                        type="datetime-local"
                        name="transaction_date"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Buttons -->
                <div class="flex gap-3">
                    <button
                        type="submit"
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold"
                    >
                        Record Transaction
                    </button>
                    <button
                        type="button"
                        onclick="closeTransactionModal()"
                        class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 py-2 rounded-lg font-semibold"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Customer Modal -->
    <div id="addCustomerModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-lg p-6 w-96">
            <h3 class="text-2xl font-bold text-gray-800 mb-4">Add New Customer</h3>

            <form id="addCustomerForm" method="POST" action="add-user.php"><?php echo csrfField(); ?>
                <!-- Card ID -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Card ID</label>
                    <input
                        type="text"
                        name="card_id"
                        placeholder="Enter unique card ID"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Name -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Full Name</label>
                    <input
                        type="text"
                        name="name"
                        placeholder="Enter customer's full name"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Email (Optional) -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Email Address (Optional)</label>
                    <input
                        type="email"
                        name="email"
                        placeholder="Enter email address (optional)"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Phone (Required) -->
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
                        Create Customer
                    </button>
                    <button
                        type="button"
                        onclick="closeAddCustomerModal()"
                        class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 py-2 rounded-lg font-semibold"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Customer search: local filter over preloaded 20 + live server search (1000-user safe)
        const customerSearch = document.getElementById('customerSearch');
        const customerSelect = document.getElementById('customerSelect');
        let searchTimer = null;

        function filterOptions() {
            const searchTerm = customerSearch.value.toLowerCase();
            customerSelect.querySelectorAll('option').forEach(option => {
                if (option.value === '') {
                    option.style.display = '';
                    return;
                }
                const name = (option.dataset.name || '').toLowerCase();
                const card = (option.dataset.card || '').toLowerCase();
                option.style.display = (name.includes(searchTerm) || card.includes(searchTerm)) ? '' : 'none';
            });
        }

        customerSearch.addEventListener('keyup', function() {
            filterOptions();
            // Live server search after a short pause — fetches matches beyond the preloaded 20
            clearTimeout(searchTimer);
            const q = customerSearch.value.trim();
            searchTimer = setTimeout(function() {
                if (q.length < 2) return;
                fetch('customer-search.php?q=' + encodeURIComponent(q), {credentials: 'same-origin'})
                    .then(r => r.json())
                    .then(rows => {
                        const keep = customerSelect.value;
                        const seen = new Set();
                        customerSelect.querySelectorAll('option').forEach(o => { if (o.value) seen.add(o.value); });
                        rows.forEach(c => {
                            if (seen.has(String(c.id))) return;
                            const o = document.createElement('option');
                            o.value = c.id;
                            o.dataset.name = c.name || '';
                            o.dataset.card = c.card_id || '';
                            o.textContent = (c.name || '') + ' (' + (c.card_id || 'N/A') + ')';
                            customerSelect.appendChild(o);
                        });
                        customerSelect.value = keep;
                        filterOptions();
                    })
                    .catch(() => { /* keep local options on failure */ });
            }, 300);
        });

        // Backdate checkbox toggle
        const backdateCheckbox = document.getElementById('use_backdate');
        const backdateSection = document.getElementById('backdateSection');
        const backdateContainer = backdateCheckbox.closest('.mb-4');
        const typeRadios = document.querySelectorAll('input[name="type"]');

        // Monitor transaction type changes
        typeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'withdrawal') {
                    // Hide backdate option for withdrawals
                    backdateContainer.classList.add('hidden');
                    backdateCheckbox.checked = false;
                    backdateSection.classList.add('hidden');
                } else {
                    // Show backdate option for deposits
                    backdateContainer.classList.remove('hidden');
                }
            });
        });

        backdateCheckbox.addEventListener('change', function() {
            if (this.checked) {
                backdateSection.classList.remove('hidden');
            } else {
                backdateSection.classList.add('hidden');
            }
        });

        function openAddCustomerModal() {
            document.getElementById('addCustomerModal').classList.remove('hidden');
        }

        function closeAddCustomerModal() {
            document.getElementById('addCustomerModal').classList.add('hidden');
            document.getElementById('addCustomerForm').reset();
        }

        // Close modal when clicking outside
        document.getElementById('addCustomerModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddCustomerModal();
            }
        });

        function openTransactionModal() {
            document.getElementById('transactionModal').classList.remove('hidden');
            customerSearch.value = '';
            filterOptions();
        }

        function closeTransactionModal() {
            document.getElementById('transactionModal').classList.add('hidden');
            document.getElementById('transactionForm').reset();
            backdateSection.classList.add('hidden');
        }

        // Close modal when clicking outside
        document.getElementById('transactionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTransactionModal();
            }
        });
    </script>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
