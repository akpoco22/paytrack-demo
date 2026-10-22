<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Customer.php';
require_once '../../src/models/KYC.php';
require_once '../../src/models/LoanApplication.php';
require_once '../../includes/helpers.php';

requireUser();
checkSessionTimeout();
requireCsrf();

$user_id = Auth::getUserId();
$kyc_model  = new KYC($conn);
$app_model  = new LoanApplication($conn);

$kyc = $kyc_model->getKYCByCustomer($user_id);
$kyc_approved = $kyc_model->isKYCApproved($user_id);
$my_apps = $app_model->getCustomerApplications($user_id, 10);
$pending = null;
foreach ($my_apps as $a) {
    if ($a['status'] === 'pending') { $pending = $a; break; }
}

// Handle new application
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$kyc_approved) {
        $error = 'Your KYC must be approved before applying for a loan.';
    } else {
        $result = $app_model->apply(
            $user_id,
            (float)($_POST['loan_amount'] ?? 0),
            (float)($_POST['interest_rate'] ?? 5.0),
            sanitize($_POST['repayment_frequency'] ?? 'daily'),
            sanitize($_POST['purpose'] ?? '')
        );
        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
            header('Location: apply-loan.php');
            exit;
        }
        $error = $result['message'];
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
    <title>Apply for Loan - PayTrack</title>
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
                    <a href="transactions.php" class="text-gray-700 hover:text-blue-600 font-medium">Transactions</a>
                    <a href="kyc.php" class="text-gray-700 hover:text-blue-600 font-medium">KYC</a>
                    <a href="apply-loan.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Apply for Loan</a>
                    <a href="contact.php" class="text-gray-700 hover:text-blue-600 font-medium">Contact Admin</a>
                    <a href="profile.php" class="text-gray-700 hover:text-blue-600 font-medium">Profile</a>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-3xl mx-auto px-4 py-8">
        <h2 class="text-3xl font-bold text-gray-800 mb-2">Apply for a Loan</h2>
        <p class="text-gray-600 mb-8">Standard rate: 5% flat monthly interest. You can repay daily, weekly or monthly.</p>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!$kyc_approved): ?>
            <!-- KYC gate: no approved KYC yet -->
            <div class="bg-white rounded-lg shadow p-6 text-center">
                <div class="text-4xl mb-4">🔒</div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">
                    <?php echo $kyc ? 'KYC ' . ucfirst($kyc['verification_status']) : 'KYC not submitted yet'; ?>
                </h3>
                <p class="text-gray-600 mb-6">
                    <?php if ($kyc && $kyc['verification_status'] === 'pending'): ?>
                        Your KYC is awaiting approval. You can apply for a loan once it's approved.
                    <?php elseif ($kyc && $kyc['verification_status'] === 'rejected'): ?>
                        Your KYC was rejected. Please contact admin to resolve it.
                    <?php else: ?>
                        You need to complete KYC verification before you can apply for a loan.
                    <?php endif; ?>
                </p>
                <a href="kyc.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold">
                    <?php echo $kyc ? 'View KYC Status' : 'Complete Your KYC'; ?>
                </a>
            </div>

        <?php elseif ($app_model->hasOpenLoan($user_id)): ?>
            <!-- One loan at a time: block new applications until the open loan is repaid -->
            <div class="bg-white rounded-lg shadow p-6 text-center">
                <div class="text-4xl mb-4">🔒</div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">You have an unpaid loan</h3>
                <p class="text-gray-600 mb-6">You can apply for another loan once your current loan is fully repaid.</p>
                <a href="dashboard.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold">View My Loans</a>
            </div>

        <?php elseif ($pending): ?>
            <!-- Application already pending -->
            <div class="bg-white rounded-lg shadow p-6 text-center">
                <div class="text-4xl mb-4">⏳</div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">Application in Review</h3>
                <p class="text-gray-600">
                    Your application for <span class="font-bold text-blue-600"><?php echo formatCurrency($pending['loan_amount']); ?></span>
                    is with the admin team. You can submit another once it's decided.
                </p>
            </div>

        <?php else: ?>
            <!-- Apply form -->
            <div class="bg-white rounded-lg shadow p-6">
                <form method="POST" class="space-y-6"><?php echo csrfField(); ?>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Loan Amount (₦) *</label>
                        <input type="number" name="loan_amount" step="0.01" min="1" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-lg font-semibold">
                        <p class="text-gray-500 text-sm mt-1">e.g. 50000 for ₦50,000</p>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Interest Rate (% per month)</label>
                        <input type="number" name="interest_rate" step="0.01" min="0" value="5.00"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-lg">
                        <p class="text-gray-500 text-sm mt-1">Standard rate is 5% flat per month. Contact admin for custom rates.</p>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Repayment Plan *</label>
                        <select name="repayment_frequency" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="daily">Daily — pay small amounts every day</option>
                            <option value="weekly">Weekly — pay once a week</option>
                            <option value="monthly">Monthly — pay once a month</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Purpose</label>
                        <textarea name="purpose" rows="3" placeholder="e.g. Business capital, school fees, emergency"
                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"></textarea>
                    </div>

                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-lg font-semibold">
                        Submit Application
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Application history (only if any) -->
        <?php if (!empty($my_apps)): ?>
            <div class="mt-8 bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">Your Applications</h3>
                </div>
                <div class="divide-y divide-gray-100">
                    <?php foreach ($my_apps as $a): ?>
                        <?php
                        $badge = match($a['status']) {
                            'approved' => 'bg-green-100 text-green-700',
                            'rejected' => 'bg-red-100 text-red-700',
                            default    => 'bg-yellow-100 text-yellow-700',
                        };
                        ?>
                        <div class="px-6 py-4 flex items-center justify-between">
                            <div>
                                <p class="font-semibold text-gray-800"><?php echo formatCurrency($a['loan_amount']); ?></p>
                                <p class="text-gray-500 text-sm"><?php echo ucfirst($a['repayment_frequency'] ?? 'daily'); ?> · <?php echo date('M d, Y', strtotime($a['created_at'])); ?></p>
                                <?php if (!empty($a['notes']) && $a['status'] !== 'pending'): ?>
                                    <p class="text-gray-500 text-xs mt-1"><?php echo htmlspecialchars($a['notes']); ?></p>
                                <?php endif; ?>
                            </div>
                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $badge; ?>"><?php echo ucfirst($a['status']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
