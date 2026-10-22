<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/ContactMessage.php';
require_once '../../includes/helpers.php';

requireUser();
checkSessionTimeout();

$user_id = Auth::getUserId();
$contactMsg = new ContactMessage($conn);
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $subject = sanitize($_POST['subject'] ?? '');
    $message = sanitize($_POST['message'] ?? '');

    if (empty($subject) || empty($message)) {
        $error = 'Please fill in all fields';
    } else {
        $result = $contactMsg->sendMessage($user_id, $subject, $message);
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

// Get user's messages
$messages = $contactMsg->getUserMessages($user_id);
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
    <title>Contact Admin - PayTrack</title>
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
                    <a href="contact.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Contact Admin</a>
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
    <div class="max-w-4xl mx-auto px-4 py-8">
        <h2 class="text-3xl font-bold text-gray-800 mb-8">Contact the Admin</h2>

        <!-- New Message Form -->
        <div class="bg-white rounded-lg shadow p-6 mb-8">
            <h3 class="text-xl font-bold text-gray-800 mb-4">Send a Message</h3>

            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4"><?php echo csrfField(); ?>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Subject</label>
                    <input type="text" name="subject" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Message subject...">
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Message</label>
                    <textarea name="message" required rows="6" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Type your message here..."></textarea>
                </div>

                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-semibold">
                    Send Message
                </button>
            </form>
        </div>

        <!-- Message History -->
        <div>
            <h3 class="text-xl font-bold text-gray-800 mb-4">Message History</h3>

            <?php if (empty($messages)): ?>
                <div class="bg-white rounded-lg shadow p-8 text-center text-gray-600">
                    No messages yet.
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($messages as $msg): ?>
                        <div class="bg-white rounded-lg shadow overflow-hidden">
                            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-start">
                                <div>
                                    <h4 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($msg['subject']); ?></h4>
                                    <p class="text-gray-600 text-sm"><?php echo formatDateTime($msg['created_at']); ?></p>
                                </div>
                                <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $msg['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'; ?>">
                                    <?php echo ucfirst($msg['status']); ?>
                                </span>
                            </div>

                            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                                <p class="text-gray-800 whitespace-pre-wrap"><?php echo htmlspecialchars($msg['message']); ?></p>
                            </div>

                            <?php if ($msg['status'] === 'responded' && $msg['response']): ?>
                                <div class="px-6 py-4 bg-green-50 border-t border-green-200">
                                    <p class="text-sm font-semibold text-green-800 mb-2">Admin Response:</p>
                                    <p class="text-gray-800 whitespace-pre-wrap"><?php echo htmlspecialchars($msg['response']); ?></p>
                                    <p class="text-gray-600 text-sm mt-2">Responded: <?php echo formatDateTime($msg['responded_at']); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
