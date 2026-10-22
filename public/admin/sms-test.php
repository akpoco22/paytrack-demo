<?php
/**
 * SMS test page (admin only) — verify Termii settings end-to-end.
 * PayTrack
 *
 * GET  -> shows config status + a form (recipient phone + message)
 * POST -> Sms::sendSms() and shows the result.
 * Nothing here touches the database.
 */
session_start();

require_once '../../config/environment.php';
require_once '../../config/sms.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/sms/Sms.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

$result = null;
$to = '';
$message = 'Hello from PayTrack — SMS test.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['to'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if (Sms::toIntl($to) === '') {
        $result = ['success' => false, 'error' => 'Enter a valid Nigerian phone number (e.g. 0803...).'];
    } elseif ($message === '') {
        $result = ['success' => false, 'error' => 'Message cannot be empty.'];
    } else {
        $result = Sms::sendSms($to, $message);
    }
}

$configured = Sms::isConfigured();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <link rel="manifest" href="/public/manifest.json">
    <link rel="icon" type="image/png" href="/public/icons/icon-192.png">
    <title>SMS Test - PayTrack Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="max-w-xl mx-auto px-4 py-10">
        <a href="dashboard.php" class="text-blue-600 hover:underline text-sm">&larr; Back to dashboard</a>
        <h2 class="text-2xl font-bold text-gray-800 mt-2 mb-1">SMS (Termii) test</h2>
        <p class="text-gray-600 text-sm mb-6">Sends one test SMS via Termii as <code><?= htmlspecialchars(defined('TERMII_SENDER_ID') ? TERMII_SENDER_ID : '') ?></code>.</p>

        <div class="mb-4 p-3 rounded-lg text-sm <?= $configured ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
            <?= $configured
                ? 'Termii API key looks configured.'
                : 'SMS is NOT configured — add your Termii API key + Sender ID in <code>config/sms.php</code> first. Sends are skipped until then.' ?>
        </div>

        <?php if ($result !== null): ?>
            <div class="mb-4 p-3 rounded-lg text-sm <?= $result['success'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700' ?>">
                <?= $result['success']
                    ? '✅ Test SMS sent to ' . htmlspecialchars($to) . '.'
                    : '❌ Failed: ' . htmlspecialchars($result['error']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="bg-white shadow rounded-lg p-6 space-y-4"><?php echo csrfField(); ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Recipient phone (e.g. 08031234567)</label>
                <input type="tel" name="to" required value="<?= htmlspecialchars($to) ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                <textarea name="message" rows="3" required
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"><?= htmlspecialchars($message) ?></textarea>
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-medium">
                Send test SMS
            </button>
        </form>
    </div>
</body>
</html>
