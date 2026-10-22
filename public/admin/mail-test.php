<?php
/**
 * Mail test page (admin only) — verify SMTP settings end-to-end.
 * PayTrack
 *
 * GET  -> shows a form prefilled with MAIL_FROM_EMAIL + a Send button
 * POST -> Mailer::send() a test message and shows the result.
 * Nothing here touches the database.
 */
session_start();

require_once '../../config/environment.php';
require_once '../../config/mail.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/mail/Mailer.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();
requireCsrf();

$result = null;
$to = MAIL_FROM_EMAIL;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['to'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result = ['success' => false, 'error' => 'Enter a valid recipient email address.'];
    } else {
        $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;">'
              . '<h2>PayTrack — test email ✅</h2>'
              . '<p>If you can read this, SMTP is working. Sent at ' . htmlspecialchars(date('Y-m-d H:i:s')) . '.</p>'
              . '</body></html>';
        $result = Mailer::send($to, 'PayTrack Test', 'PayTrack mail test ✅', $html);
    }
}

$configured = Mailer::isConfigured();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <link rel="manifest" href="/public/manifest.json">
    <link rel="icon" type="image/png" href="/public/icons/icon-192.png">
    <title>Mail Test - PayTrack Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="max-w-xl mx-auto px-4 py-10">
        <a href="dashboard.php" class="text-blue-600 hover:underline text-sm">&larr; Back to dashboard</a>
        <h2 class="text-2xl font-bold text-gray-800 mt-2 mb-1">Email (SMTP) test</h2>
        <p class="text-gray-600 text-sm mb-6">Sends one test message through <code><?= htmlspecialchars(MAIL_HOST . ':' . MAIL_PORT) ?></code>.</p>

        <div class="mb-4 p-3 rounded-lg text-sm <?= $configured ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
            <?= $configured
                ? 'SMTP credentials look configured.'
                : 'SMTP is NOT configured — edit <code>config/mail.php</code> with your provider details first. Sends are skipped until then.' ?>
        </div>

        <?php if ($result !== null): ?>
            <div class="mb-4 p-3 rounded-lg text-sm <?= $result['success'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700' ?>">
                <?= $result['success']
                    ? '✅ Test email sent to ' . htmlspecialchars($to) . '.'
                    : '❌ Failed: ' . htmlspecialchars($result['error']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="bg-white shadow rounded-lg p-6 space-y-4"><?php echo csrfField(); ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Recipient email</label>
                <input type="email" name="to" required value="<?= htmlspecialchars($to) ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-medium">
                Send test email
            </button>
        </form>
    </div>
</body>
</html>
