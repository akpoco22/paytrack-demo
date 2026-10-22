<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Customer.php';
require_once '../../src/models/KYC.php';
require_once '../../includes/helpers.php';

requireUser();
checkSessionTimeout();
requireCsrf();

$user_id = Auth::getUserId();
$kyc_model = new KYC($conn);
$existing_kyc = $kyc_model->getKYCByCustomer($user_id);

// Handle submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing_kyc) {
    $result = $kyc_model->submitKYC(
        $user_id,
        sanitize($_POST['id_type'] ?? ''),
        sanitize($_POST['id_number'] ?? ''),
        isset($_POST['id_expiry']) ? sanitize($_POST['id_expiry']) : null,
        sanitize($_POST['address'] ?? ''),
        sanitize($_POST['employment_status'] ?? ''),
        sanitize($_POST['income_source'] ?? ''),
        sanitize($_POST['bank_account'] ?? ''),
        sanitize($_POST['bank_name'] ?? ''),
        sanitize($_POST['reference_contact_name'] ?? ''),
        sanitize($_POST['reference_contact_phone'] ?? ''),
        sanitize($_POST['bvn'] ?? ''),
        sanitize($_POST['nin'] ?? ''),
        sanitize($_POST['guarantor_name'] ?? ''),
        sanitize($_POST['guarantor_phone'] ?? ''),
        sanitize($_POST['guarantor_address'] ?? ''),
        sanitize($_POST['collateral'] ?? '')
    );

    if ($result['success']) {
        $_SESSION['success'] = $result['message'];
        header('Location: kyc.php');
        exit;
    }
    $error = $result['message'];
    $existing_kyc = $kyc_model->getKYCByCustomer($user_id);
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
    <title>KYC - PayTrack</title>
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
                    <a href="kyc.php" class="text-blue-600 font-medium border-b-2 border-blue-600">KYC</a>
                    <a href="apply-loan.php" class="text-gray-700 hover:text-blue-600 font-medium">Apply for Loan</a>
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
        <h2 class="text-3xl font-bold text-gray-800 mb-2">Know Your Customer (KYC)</h2>
        <p class="text-gray-600 mb-8">Complete this form to verify your identity. Once approved, you can apply for loans.</p>

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

        <?php if ($existing_kyc): ?>
            <!-- KYC already submitted: show status -->
            <?php
            $kyc_status = $existing_kyc['verification_status'];
            $badge = match($kyc_status) {
                'approved' => 'bg-green-100 text-green-700',
                'rejected' => 'bg-red-100 text-red-700',
                default    => 'bg-yellow-100 text-yellow-700',
            };
            $label = ucfirst($kyc_status);
            ?>
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-bold text-gray-800">Your KYC Status</h3>
                    <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $badge; ?>"><?php echo $label; ?></span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div><p class="text-gray-500">ID Type</p><p class="text-gray-800 font-semibold"><?php echo htmlspecialchars($existing_kyc['id_type'] ?? '-'); ?></p></div>
                    <div><p class="text-gray-500">BVN</p><p class="text-gray-800 font-mono"><?php echo htmlspecialchars($existing_kyc['bvn'] ?? '-'); ?></p></div>
                    <div><p class="text-gray-500">NIN</p><p class="text-gray-800 font-mono"><?php echo htmlspecialchars($existing_kyc['nin'] ?? '-'); ?></p></div>
                    <div><p class="text-gray-500">Bank</p><p class="text-gray-800 font-semibold"><?php echo htmlspecialchars($existing_kyc['bank_name'] ?? '-'); ?></p></div>
                </div>
                <?php if ($kyc_status === 'pending'): ?>
                    <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-800">
                        Your application is awaiting review. You'll be notified when it's done.
                        <?php if ($kyc_status === 'pending'): ?>
                            <a href="apply-loan.php" class="block mt-3 text-blue-600 hover:text-blue-700 font-semibold text-sm">Meanwhile, view loan terms &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- Submission form -->
            <div class="bg-white rounded-lg shadow p-6">
                <form method="POST" class="space-y-6"><?php echo csrfField(); ?>
                    <!-- Identity -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Identity</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">ID Type *</label>
                                <select name="id_type" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                    <option value="">Select…</option>
                                    <option value="national_id">National ID (NIN card)</option>
                                    <option value="drivers_license">Driver's License</option>
                                    <option value="passport">Passport</option>
                                    <option value="voter_card">Voter Card</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">ID Number *</label>
                                <input type="text" name="id_number" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">ID Expiry Date</label>
                                <input type="date" name="id_expiry" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">BVN *</label>
                                <input type="text" name="bvn" placeholder="11-digit Bank Verification Number" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">NIN</label>
                                <input type="text" name="nin" placeholder="11-digit National Identification Number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">Residential Address *</label>
                                <input type="text" name="address" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                        </div>
                    </div>

                    <!-- Employment -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Employment & Income</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">Employment Status *</label>
                                <select name="employment_status" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                    <option value="">Select…</option>
                                    <option value="employed">Employed</option>
                                    <option value="self_employed">Self-employed</option>
                                    <option value="business_owner">Business Owner</option>
                                    <option value="student">Student</option>
                                    <option value="retired">Retired</option>
                                    <option value="unemployed">Unemployed</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">Income Source</label>
                                <input type="text" name="income_source" placeholder="e.g. Monthly salary, shop sales" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                        </div>
                    </div>

                    <!-- Bank -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Bank Details</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">Bank Account Number *</label>
                                <input type="text" name="bank_account" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">Bank Name *</label>
                                <input type="text" name="bank_name" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                        </div>
                    </div>

                    <!-- Reference / Guarantor -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Reference & Guarantor <span class="text-gray-400 font-normal text-sm">(optional)</span></h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">Reference Contact Name</label>
                                <input type="text" name="reference_contact_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">Reference Phone</label>
                                <input type="tel" name="reference_contact_phone" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">Guarantor Name</label>
                                <input type="text" name="guarantor_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">Guarantor Phone</label>
                                <input type="tel" name="guarantor_phone" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">Guarantor Address</label>
                                <input type="text" name="guarantor_address" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                        </div>
                    </div>

                    <!-- Collateral -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Collateral <span class="text-gray-400 font-normal text-sm">(optional)</span></h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label class="block text-gray-700 font-semibold mb-2 text-sm">Collateral / Asset (if any)</label>
                                <input type="text" name="collateral" placeholder="e.g. Vehicle, property, equipment" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 pt-4">
                        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-lg font-semibold">
                            Submit KYC for Verification
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
