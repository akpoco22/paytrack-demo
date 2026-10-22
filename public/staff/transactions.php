<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/mail.php';
require_once '../../config/sms.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Transaction.php';
require_once '../../src/models/Customer.php';
require_once '../../includes/helpers.php';
require_once '../../includes/mail-notify.php';

$is_loan_staff = ($_SESSION['staff_role'] ?? '') === 'loans';

// Check if staff is logged in
if (!Auth::isAuthenticated() || $_SESSION['user_type'] !== 'staff') {
    header('Location: ../../public/login.php');
    exit;
}

checkSessionTimeout();

$staff_id = Auth::getUserId();
$transaction = new Transaction($conn);
$customer = new Customer($conn);

$error = '';
$success = '';

// Handle transaction creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $customer_id = isset($_POST['customer_id'])  ? (int)$_POST['customer_id'] : 0;
    $type = isset($_POST['type']) ? sanitize($_POST['type']) : '';
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $description = isset($_POST['description']) ? sanitize($_POST['description']) : '';
    $use_backdate = isset($_POST['use_backdate']) && $_POST['use_backdate'] === 'on';
    $transaction_date = $use_backdate ? (isset($_POST['transaction_date']) ? sanitize($_POST['transaction_date']) : null) : null;

    if (!$customer_id || !$type || $amount <= 0) {
        $error = 'Please fill in all required fields';
    } elseif (!in_array($type, ['deposit', 'withdrawal'])) {
        $error = 'Invalid transaction type';
    } else {
        $result = $transaction->createTransactionWithDate($customer_id, $type, $amount, $description, $transaction_date, $staff_id, null);
        if ($result['success']) {
            $success = 'Transaction recorded successfully! Reference: ' . $result['reference_code'];
            $customer_row = $customer->getCustomerById($customer_id);
            if ($customer_row) {
                $success .= notifyCustomer('transaction', [
                    'customer'       => $customer_row,
                    'type'           => $type,
                    'amount'         => $amount,
                    'reference_code' => $result['reference_code'],
                ]);
                $success .= notifyCustomerSms('transaction', [
                    'customer'       => $customer_row,
                    'type'           => $type,
                    'amount'         => $amount,
                    'reference_code' => $result['reference_code'],
                ]);
            }
        } else {
            $error = $result['message'];
        }
    }
}

// Get all customers
$customers = $customer->getAllCustomers(1000, 0); // Get all for dropdown
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
    <title>Record Transaction - PayTrack Staff</title>
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
                    <a href="activity.php" class="text-gray-700 hover:text-blue-600 font-medium">My Activity</a>
                    <a href="transactions.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Record Transaction</a>
                    <?php if ($is_loan_staff): ?>
                        <a href="loans.php" class="text-gray-700 hover:text-blue-600 font-medium">Loan Collection</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-2xl mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-3xl font-bold text-gray-800">Record Transaction</h2>
            <a href="dashboard.php" class="text-blue-600 hover:text-blue-700 font-semibold">← Back to Dashboard</a>
        </div>

        <!-- Messages -->
        <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <div class="bg-white rounded-lg shadow p-6">
            <form method="POST" class="space-y-4"><?php echo csrfField(); ?>
                <!-- Customer Selection -->
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Search Customer</label>
                    <input
                        type="text"
                        id="customerSearch"
                        placeholder="Search by name, phone, or card ID..."
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600 mb-2"
                    >
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Select Customer *</label>
                    <select
                        name="customer_id"
                        id="customerSelect"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                        <option value="">-- Choose a customer --</option>
                        <?php foreach ($customers as $cust): ?>
                            <option value="<?php echo $cust['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($cust['name']); ?>"
                                    data-phone="<?php echo htmlspecialchars($cust['phone_number'] ?? ''); ?>"
                                    data-card="<?php echo htmlspecialchars($cust['card_id'] ?? ''); ?>">
                                <?php echo htmlspecialchars($cust['name']) . ' (' . htmlspecialchars($cust['phone_number'] ?? '') . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Transaction Type -->
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Transaction Type *</label>
                    <div class="flex gap-6">
                        <label class="flex items-center">
                            <input type="radio" name="type" value="deposit" required class="w-4 h-4 text-blue-600">
                            <span class="ml-2 text-gray-700">Deposit</span>
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="type" value="withdrawal" class="w-4 h-4 text-blue-600">
                            <span class="ml-2 text-gray-700">Withdrawal</span>
                        </label>
                    </div>
                </div>

                <!-- Amount -->
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Amount (₦) *</label>
                    <input
                        type="number"
                        name="amount"
                        step="0.01"
                        min="0.01"
                        placeholder="Enter amount"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Description</label>
                    <input
                        type="text"
                        name="description"
                        placeholder="e.g., Daily savings, Loan payment"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Backdate (only for deposits) -->
                <div class="border-t border-gray-200 pt-4" id="backdateContainer">
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
                <div id="backdateSection" class="hidden">
                    <label class="block text-gray-700 font-semibold mb-2">Transaction Date & Time</label>
                    <input
                        type="datetime-local"
                        name="transaction_date"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600"
                    >
                </div>

                <!-- Buttons -->
                <div class="flex gap-3 pt-4">
                    <button
                        type="submit"
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold"
                    >
                        Record Transaction
                    </button>
                    <button
                        type="reset"
                        class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 py-2 rounded-lg font-semibold"
                    >
                        Clear
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Customer search
        const customerSearch = document.getElementById('customerSearch');
        const customerSelect = document.getElementById('customerSelect');
        const options = customerSelect.querySelectorAll('option');

        customerSearch.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            options.forEach(option => {
                if (option.value === '') {
                    option.style.display = '';
                    return;
                }
                const name = option.dataset.name.toLowerCase();
                const phone = option.dataset.phone.toLowerCase();
                const card = option.dataset.card.toLowerCase();

                if (name.includes(searchTerm) || phone.includes(searchTerm) || card.includes(searchTerm)) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
        });

        // Backdate visibility based on transaction type
        const typeRadios = document.querySelectorAll('input[name="type"]');
        const backdateContainer = document.getElementById('backdateContainer');
        const backdateCheckbox = document.getElementById('use_backdate');
        const backdateSection = document.getElementById('backdateSection');

        typeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'withdrawal') {
                    backdateContainer.classList.add('hidden');
                    backdateCheckbox.checked = false;
                    backdateSection.classList.add('hidden');
                } else {
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
    </script>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
