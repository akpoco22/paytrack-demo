<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/mail.php';
require_once '../../config/sms.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Customer.php';
require_once '../../src/models/Transaction.php';
require_once '../../includes/helpers.php';
require_once '../../includes/mail-notify.php';

requireAdmin();
checkSessionTimeout();

$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($customer_id === 0) {
    header('Location: users.php');
    exit;
}

$customer = new Customer($conn);
$transaction = new Transaction($conn);

$user = $customer->getCustomerById($customer_id);

if (!$user) {
    header('Location: users.php');
    exit;
}

$trans_error = '';
$trans_success = '';

// Handle transaction creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transaction_type'])) {
    requireCsrf();
    $type = sanitize($_POST['transaction_type']);
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $description = sanitize($_POST['description'] ?? '');
    $use_backdate = isset($_POST['use_backdate']) && $_POST['use_backdate'] === 'on';
    $transaction_date = $use_backdate ? sanitize($_POST['transaction_date']) : null;

    if ($amount > 0 && in_array($type, ['deposit', 'withdrawal'])) {
        $result = $transaction->createTransactionWithDate($customer_id, $type, $amount, $description, $transaction_date, null, Auth::getUserId());
        if ($result['success']) {
            $trans_success = $result['message'] . " (Reference: {$result['reference_code']})";
            // Refresh user data
            $user = $customer->getCustomerById($customer_id);
            $mail_note = notifyCustomer('transaction', [
                'customer'       => $user ?: ['name' => '', 'email' => null],
                'type'           => $type,
                'amount'         => $amount,
                'reference_code' => $result['reference_code'],
            ]);
            $mail_note .= notifyCustomerSms('transaction', [
                'customer'       => $user ?: ['name' => '', 'phone_number' => null],
                'type'           => $type,
                'amount'         => $amount,
                'reference_code' => $result['reference_code'],
            ]);
            $trans_success .= $mail_note;
        } else {
            $trans_error = $result['message'];
        }
    } else {
        $trans_error = 'Invalid amount or transaction type';
    }
}

// Get transaction summary
$summary = $transaction->getTransactionSummary($customer_id);

// Get transaction count for pagination
$trans_page = isset($_GET['trans_page']) ? max(1, (int)$_GET['trans_page']) : 1;
$trans_offset = ($trans_page - 1) * ITEMS_PER_PAGE;
$transactions = $transaction->getCustomerTransactions($customer_id, ITEMS_PER_PAGE, $trans_offset);
$trans_total = $transaction->getCustomerTransactionCount($customer_id);
$trans_pages = ceil($trans_total / ITEMS_PER_PAGE);
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
    <title>Customer Profile - PayTrack Admin</title>
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
                    <a href="staff.php" class="text-gray-700 hover:text-blue-600 font-medium">Staff</a>
                    <a href="staff-transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Staff Transactions</a>
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
        <!-- Flash Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Back Link -->
        <a href="users.php" class="text-blue-600 hover:text-blue-700 font-semibold mb-6 inline-block">? Back to Users</a>

        <!-- User Info Section -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- User Details -->
            <div class="md:col-span-2 bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">Customer Information</h2>
                    <button
                        onclick="openPasswordModal()"
                        class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg font-medium text-sm"
                    >
                        ?? Reset Password
                    </button>
                </div>

                <div class="space-y-4">
                    <div class="border-b border-gray-200 pb-4 bg-blue-50 p-3 rounded">
                        <p class="text-gray-600 text-sm font-semibold">Customer ID</p>
                        <p class="text-blue-700 text-lg font-mono font-bold"><?php echo $user['id']; ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Full Name</p>
                        <p class="text-gray-800 text-lg"><?php echo htmlspecialchars($user['name']); ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Email Address</p>
                        <p class="text-gray-800 text-lg"><?php echo htmlspecialchars($user['email']); ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Phone Number</p>
                        <p class="text-gray-800 text-lg"><?php echo htmlspecialchars($user['phone_number'] ?? 'N/A'); ?></p>
                    </div>

                    <div class="border-b border-gray-200 pb-4">
                        <p class="text-gray-600 text-sm font-semibold">Address</p>
                        <p class="text-gray-800 text-lg"><?php echo htmlspecialchars($user['address'] ?? 'N/A'); ?></p>
                    </div>

                    <div>
                        <p class="text-gray-600 text-sm font-semibold">Member Since</p>
                        <p class="text-gray-800 text-lg"><?php echo formatDate($user['created_at']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Balance Card -->
            <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg shadow p-6 text-white">
                <p class="text-blue-100 text-sm font-semibold mb-2">Current Balance</p>
                <p class="text-4xl font-bold mb-8"><?php echo formatCurrency($user['balance']); ?></p>

                <div class="space-y-3 pt-6 border-t border-blue-500">
                    <div class="flex justify-between">
                        <span class="text-blue-100">Total Deposits</span>
                        <span class="font-semibold"><?php echo formatCurrency($summary['total_deposits'] ?? 0); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-blue-100">Total Withdrawals</span>
                        <span class="font-semibold"><?php echo formatCurrency($summary['total_withdrawals'] ?? 0); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-blue-100">Loan Repayments</span>
                        <span class="font-semibold"><?php echo formatCurrency($summary['total_loan_repayments'] ?? 0); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-blue-100">Total Transactions</span>
                        <span class="font-semibold"><?php echo $summary['total_transactions'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Transaction Section -->
        <div class="bg-white rounded-lg shadow overflow-hidden mb-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-bold text-gray-800">Add Transaction</h3>
            </div>

            <div class="px-6 py-6">
                <?php if ($trans_error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                        <?php echo htmlspecialchars($trans_error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($trans_success): ?>
                    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">
                        <?php echo htmlspecialchars($trans_success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4"><?php echo csrfField(); ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Transaction Type</label>
                            <select name="transaction_type" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">-- Select Type --</option>
                                <option value="deposit">Deposit</option>
                                <option value="withdrawal">Withdrawal</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">Amount (?)</label>
                            <input type="number" name="amount" step="0.01" min="0.01" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Enter amount">
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Description</label>
                        <input type="text" name="description" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="e.g., Manual deposit, Salary payment">
                    </div>

                    <div class="border-t border-gray-200 pt-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="use_backdate" id="use_backdate" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="ml-2 text-gray-700 font-semibold">Backdate this transaction</span>
                        </label>
                        <p class="text-gray-600 text-sm mt-1">Check this box if the transaction occurred on a different date</p>
                    </div>

                    <div id="backdate_section" class="hidden">
                        <label class="block text-gray-700 font-semibold mb-2">Transaction Date</label>
                        <input type="datetime-local" name="transaction_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>

                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-semibold">
                        Create Transaction
                    </button>
                </form>

                <script>
                    const transactionTypeSelect = document.querySelector('select[name="transaction_type"]');
                    const backdateCheckbox = document.getElementById('use_backdate');
                    const backdateSection = document.getElementById('backdate_section');
                    const backdateContainer = backdateCheckbox.closest('.border-t');

                    // Monitor transaction type changes
                    transactionTypeSelect.addEventListener('change', function() {
                        if (this.value === 'withdrawal') {
                            // Hide backdate option for withdrawals
                            backdateContainer.classList.add('hidden');
                            backdateCheckbox.checked = false;
                            backdateSection.classList.add('hidden');
                        } else if (this.value === 'deposit') {
                            // Show backdate option for deposits
                            backdateContainer.classList.remove('hidden');
                        }
                    });

                    backdateCheckbox.addEventListener('change', function() {
                        backdateSection.classList.toggle('hidden');
                    });
                </script>
            </div>
        </div>

        <!-- Transaction History -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-bold text-gray-800">Transaction History</h3>
            </div>

            <?php if (empty($transactions)): ?>
                <div class="px-6 py-8 text-center text-gray-600">
                    No transactions found for this user.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Reference Code</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $trans): ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                    <td class="px-6 py-4 font-mono text-sm text-gray-800"><?php echo htmlspecialchars($trans['reference_code']); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $trans['type'] === 'deposit' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo ucfirst($trans['type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 font-semibold text-gray-800"><?php echo formatCurrency($trans['amount']); ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($trans['description'] ?? '-'); ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?php echo formatDateTime($trans['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($trans_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-gray-200 flex justify-center space-x-2">
                        <?php for ($i = 1; $i <= $trans_pages; $i++): ?>
                            <a href="?id=<?php echo $customer_id; ?>&trans_page=<?php echo $i; ?>"
                               class="px-3 py-1 rounded <?php echo $i === $trans_page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="passwordModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-lg p-6 w-96">
            <h3 class="text-2xl font-bold text-gray-800 mb-4">Reset Password</h3>

            <form id="passwordForm" method="POST" action="reset-password.php"><?php echo csrfField(); ?>
                <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">

                <!-- Choose Option -->
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Password Option</label>
                    <div class="space-y-2">
                        <label class="flex items-center">
                            <input
                                type="radio"
                                name="password_option"
                                value="generate"
                                checked
                                class="w-4 h-4 text-orange-600"
                            >
                            <span class="ml-2 text-gray-700">Auto-generate new password</span>
                        </label>
                        <label class="flex items-center">
                            <input
                                type="radio"
                                name="password_option"
                                value="custom"
                                class="w-4 h-4 text-orange-600"
                            >
                            <span class="ml-2 text-gray-700">Set custom password</span>
                        </label>
                    </div>
                </div>

                <!-- Custom Password Input -->
                <div id="customPasswordField" class="mb-4 hidden">
                    <label class="block text-gray-700 font-semibold mb-2">New Password</label>
                    <input
                        type="text"
                        name="custom_password"
                        placeholder="Enter custom password"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-600"
                    >
                    <p class="text-gray-600 text-sm mt-1">At least 8 characters recommended</p>
                </div>

                <!-- Buttons -->
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
        const passwordOptionRadios = document.querySelectorAll('input[name="password_option"]');
        const customPasswordField = document.getElementById('customPasswordField');

        passwordOptionRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'custom') {
                    customPasswordField.classList.remove('hidden');
                } else {
                    customPasswordField.classList.add('hidden');
                }
            });
        });

        function openPasswordModal() {
            document.getElementById('passwordModal').classList.remove('hidden');
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').classList.add('hidden');
            document.getElementById('passwordForm').reset();
            customPasswordField.classList.add('hidden');
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
