<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Customer.php';
require_once '../../src/models/Transaction.php';
require_once '../../src/models/KYC.php';
require_once '../../src/models/Loan.php';
require_once '../../includes/helpers.php';

requireUser();
checkSessionTimeout();

$user_id = Auth::getUserId();
$customer = new Customer($conn);
$transaction = new Transaction($conn);
$kyc_model = new KYC($conn);
$loan_model = new Loan($conn);

$user_info = $customer->getUserInfo($user_id);
$summary = $transaction->getTransactionSummary($user_id);
$kyc = $kyc_model->getKYCByCustomer($user_id);
$kyc_status = $kyc['verification_status'] ?? 'not_submitted';

// Get recent transactions
$recent_transactions = $transaction->getCustomerTransactions($user_id, 5);

// Approved loans (created from approved applications).
// Total outstanding is summed over all loans, not just the 10 shown.
$all_loans = $loan_model->getCustomerLoans($user_id, 1000);
$my_loans = array_slice($all_loans, 0, 10);
$total_outstanding = 0;
$open_interest_rate = 0;
foreach ($all_loans as $l) {
    $loan_owed = max(0, (float)$l['loan_amount'] - (float)($l['total_repaid'] ?? 0));
    $total_outstanding += $loan_owed;
    if ($loan_owed > 0) $open_interest_rate = (float)$l['interest_rate'];
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
    <title>Dashboard - PayTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-8">
                <a href="dashboard.php" class="flex items-center shrink-0"><img src="/public/logo.png" alt="PayTrack" class="h-10 w-auto"></a>
                <div class="hidden md:flex space-x-6">
                    <a href="dashboard.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Dashboard</a>
                    <a href="transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Transactions</a>
                    <a href="kyc.php" class="text-gray-700 hover:text-blue-600 font-medium">KYC</a>
                    <a href="apply-loan.php" class="text-gray-700 hover:text-blue-600 font-medium">Apply for Loan</a>
                    <a href="contact.php" class="text-gray-700 hover:text-blue-600 font-medium">Contact Admin</a>
                    <a href="profile.php" class="text-gray-700 hover:text-blue-600 font-medium">Profile</a>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-gray-700"><?php echo htmlspecialchars($user_info['name']); ?></span>
                <a href="../logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Welcome Section -->
        <div class="mb-8">
            <h2 class="text-3xl font-bold text-gray-800">Welcome back, <?php echo htmlspecialchars(explode(' ', $user_info['name'])[0]); ?>!</h2>
            <p class="text-gray-600 mt-2">Here's your account overview</p>
        </div>

        <!-- Balance + My Loans -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Balance Card -->
            <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg shadow-lg p-6 md:p-8 text-white">
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-4 mb-6">
                    <div class="min-w-0">
                        <p class="text-blue-100 text-sm font-semibold mb-2">Current Balance</p>
                        <p class="text-4xl md:text-5xl font-bold break-words"><?php echo formatCurrency($user_info['balance']); ?></p>
                    </div>
                    <div class="sm:text-right">
                        <p class="text-blue-100 text-sm font-semibold mb-2">Your Card ID</p>
                        <p class="text-xl md:text-2xl font-mono font-bold break-all"><?php echo htmlspecialchars($user_info['card_id'] ?? 'N/A'); ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <div class="min-w-0">
                        <p class="text-blue-100 text-xs sm:text-sm">Total Deposits</p>
                        <p class="text-xl md:text-2xl font-bold mt-1 break-words"><?php echo formatCurrency($summary['total_deposits'] ?? 0); ?></p>
                    </div>
                    <div class="min-w-0">
                        <p class="text-blue-100 text-xs sm:text-sm">Total Withdrawals</p>
                        <p class="text-xl md:text-2xl font-bold mt-1 break-words"><?php echo formatCurrency($summary['total_withdrawals'] ?? 0); ?></p>
                    </div>
                    <div class="min-w-0">
                        <p class="text-blue-100 text-xs sm:text-sm">Loan Repayments</p>
                        <p class="text-xl md:text-2xl font-bold mt-1 break-words"><?php echo formatCurrency($summary['total_loan_repayments'] ?? 0); ?></p>
                    </div>
                    <div class="min-w-0">
                        <p class="text-blue-100 text-xs sm:text-sm">Total Outstanding Loan</p>
                        <p class="text-xl md:text-2xl font-bold mt-1 break-words <?php echo $total_outstanding > 0 ? 'text-red-200' : ''; ?>"><?php echo formatCurrency($total_outstanding); ?></p>
                        <?php if ($total_outstanding > 0): ?>
                            <p class="text-blue-100 text-xs mt-1">Interest: <?php echo rtrim(rtrim(number_format($open_interest_rate, 2), '0'), '.'); ?>% per month</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- My Loans (approved applications become loans) -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">My Loans</h3>
                </div>
                <?php if (empty($my_loans)): ?>
                    <div class="px-6 py-8 text-center text-gray-600">No loans yet.</div>
                <?php else: ?>
                    <div class="divide-y divide-gray-100">
                        <?php foreach ($my_loans as $loan):
                            $loan_balance = max(0, (float)$loan['loan_amount'] - (float)($loan['total_repaid'] ?? 0));
                            $loan_badge = match($loan['status']) {
                                'completed' => 'bg-green-100 text-green-700',
                                'overdue'   => 'bg-red-100 text-red-700',
                                'active'    => 'bg-blue-100 text-blue-700',
                                default     => 'bg-yellow-100 text-yellow-700',
                            };
                        ?>
                            <div class="px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-800 break-words">Loan of <?php echo formatCurrency($loan['loan_amount']); ?></p>
                                    <p class="text-gray-500 text-sm">
                                        Given <?php echo formatDate($loan['date_given']); ?> · Due <?php echo formatDate($loan['due_date']); ?>
                                    </p>
                                </div>
                                <div class="flex items-center justify-between sm:justify-end gap-6">
                                    <div class="sm:text-right">
                                        <p class="text-xs text-gray-500">Balance</p>
                                        <p class="font-bold <?php echo $loan_balance > 0 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo formatCurrency($loan_balance); ?></p>
                                    </div>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $loan_badge; ?>"><?php echo ucfirst($loan['status']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // KYC status tile + Apply-for-Loan CTA
        $kyc_tile = match($kyc_status) {
            'approved'   => ['cls' => 'border-l-4 border-green-500',  'label' => 'KYC Approved',  'note' => 'You can apply for a loan now.'],
            'pending'    => ['cls' => 'border-l-4 border-yellow-500', 'label' => 'KYC Pending',   'note' => 'Your verification is under review.'],
            'rejected'   => ['cls' => 'border-l-4 border-red-500',    'label' => 'KYC Rejected',  'note' => 'Contact admin to resolve.'],
            default      => ['cls' => 'border-l-4 border-gray-400',   'label' => 'KYC Not Done',  'note' => 'Complete KYC to unlock loans.'],
        };
        ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- KYC status -->
            <div class="bg-white rounded-lg shadow p-6 <?php echo $kyc_tile['cls']; ?>">
                <h3 class="text-lg font-bold text-gray-800 mb-1"><?php echo $kyc_tile['label']; ?></h3>
                <p class="text-gray-600 text-sm mb-4"><?php echo $kyc_tile['note']; ?></p>
                <a href="kyc.php" class="text-blue-600 hover:text-blue-700 text-sm font-semibold">
                    <?php echo $kyc_status === 'not_submitted' ? 'Complete your KYC &rarr;' : 'View KYC details &rarr;'; ?>
                </a>
            </div>

            <!-- Apply for Loan CTA -->
            <a href="apply-loan.php" class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg shadow p-6 hover:shadow-lg transition cursor-pointer block">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white mb-2">Apply for a Loan</h3>
                        <p class="text-blue-100 text-sm">
                            <?php echo $kyc_status === 'approved' ? 'Your KYC is approved — request a loan today.' : 'Complete KYC first to unlock loans.'; ?>
                        </p>
                    </div>
                    <span class="text-4xl">💸</span>
                </div>
            </a>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <a href="transactions.php" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition cursor-pointer border-l-4 border-blue-600">
                <h3 class="text-lg font-bold text-gray-800 mb-2">View Transactions</h3>
                <p class="text-gray-600">See your complete transaction history</p>
            </a>

            <a href="contact.php" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition cursor-pointer border-l-4 border-blue-600">
                <h3 class="text-lg font-bold text-gray-800 mb-2">Contact Admin</h3>
                <p class="text-gray-600">Send a message to the admin</p>
            </a>

            <a href="profile.php" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition cursor-pointer border-l-4 border-blue-600">
                <h3 class="text-lg font-bold text-gray-800 mb-2">My Profile</h3>
                <p class="text-gray-600">View and update your information</p>
            </a>
        </div>

        <!-- Recent Transactions -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-800">Recent Transactions</h3>
                <a href="transactions.php" class="text-blue-600 hover:text-blue-700 text-sm font-semibold">View all →</a>
            </div>

            <?php if (empty($recent_transactions)): ?>
                <div class="px-6 py-8 text-center text-gray-600">
                    No transactions yet.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Reference Code</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_transactions as $trans): ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                    <td class="px-6 py-4 font-mono text-sm text-gray-800"><?php echo htmlspecialchars($trans['reference_code']); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $trans['type'] === 'deposit' ? 'bg-green-100 text-green-800' : ($trans['type'] === 'loan_repayment' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800'); ?>">
                                            <?php echo htmlspecialchars(transactionTypeLabel($trans['type'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 font-semibold text-gray-800"><?php echo formatCurrency($trans['amount']); ?></td>
                                    <td class="px-6 py-4 text-gray-600"><?php echo formatDateTime($trans['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
