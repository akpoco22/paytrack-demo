<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/ContactMessage.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

$contactMsg = new ContactMessage($conn);

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * ITEMS_PER_PAGE;
$messages = $contactMsg->getAllMessages(ITEMS_PER_PAGE, $offset);

// Get total count
$result = $conn->query("SELECT COUNT(*) as count FROM contact_messages");
$row = $result->fetch(PDO::FETCH_ASSOC);
$total_messages = $row['count'];
$total_pages = ceil($total_messages / ITEMS_PER_PAGE);

// Handle response
$response_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_id']) && isset($_POST['response'])) {
    $message_id = (int)$_POST['message_id'];
    $response = sanitize($_POST['response']);

    if ($contactMsg->respondToMessage($message_id, $response)) {
        $response_result = ['success' => true, 'message' => 'Response sent successfully'];
        // Reload messages
        $messages = $contactMsg->getAllMessages(ITEMS_PER_PAGE, $offset);
    } else {
        $response_result = ['success' => false, 'message' => 'Failed to send response'];
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
    <title>Messages - PayTrack Admin</title>
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
                    <a href="messages.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Messages</a>
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
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-3xl font-bold text-gray-800">Customer Messages</h2>
            <p class="text-gray-600">Total: <span class="font-bold text-blue-600"><?php echo $total_messages; ?></span></p>
        </div>

        <!-- Messages -->
        <div class="space-y-4">
            <?php if (empty($messages)): ?>
                <div class="bg-white rounded-lg shadow p-8 text-center text-gray-600">
                    No messages found.
                </div>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($msg['subject']); ?></h3>
                                <p class="text-gray-600 text-sm">From: <span class="font-semibold"><?php echo htmlspecialchars($msg['name']); ?></span> (<?php echo htmlspecialchars($msg['email']); ?>)</p>
                                <p class="text-gray-500 text-sm"><?php echo formatDateTime($msg['created_at']); ?></p>
                            </div>
                            <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $msg['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'; ?>">
                                <?php echo ucfirst($msg['status']); ?>
                            </span>
                        </div>

                        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                            <p class="text-gray-800 whitespace-pre-wrap"><?php echo htmlspecialchars($msg['message']); ?></p>
                        </div>

                        <?php if ($msg['status'] === 'responded'): ?>
                            <div class="px-6 py-4 bg-green-50 border-b border-green-200">
                                <p class="text-sm font-semibold text-green-800 mb-2">Admin Response:</p>
                                <p class="text-gray-800 whitespace-pre-wrap"><?php echo htmlspecialchars($msg['response']); ?></p>
                                <p class="text-gray-600 text-sm mt-2">Responded: <?php echo formatDateTime($msg['responded_at']); ?></p>
                            </div>
                        <?php else: ?>
                            <div class="px-6 py-4">
                                <form method="POST" class="space-y-4"><?php echo csrfField(); ?>
                                    <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">

                                    <div>
                                        <label class="block text-gray-700 font-semibold mb-2">Your Response</label>
                                        <textarea name="response" required rows="4" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Type your response here..."></textarea>
                                    </div>

                                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-semibold">
                                        Send Response
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="flex justify-center space-x-2 mt-8">
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
<script src="/assets/js/pwa.js"></script>
</body>
</html>
