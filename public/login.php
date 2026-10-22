<?php
session_start();

require_once '../config/environment.php';
require_once '../config/database.php';
require_once '../src/auth/Auth.php';
require_once '../includes/helpers.php';

// If already logged in, redirect to appropriate dashboard
if (Auth::isAuthenticated()) {
    if (Auth::isAdmin()) {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: user/dashboard.php');
    }
    exit;
}

$error = '';
$success = '';
$is_register = isset($_GET['register']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = new Auth($conn);

    if ($is_register) {
        $name             = sanitize($_POST['name'] ?? '');
        $email            = sanitize($_POST['email'] ?? '');
        $password         = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $address          = sanitize($_POST['address'] ?? '');
        $phone            = sanitize($_POST['phone'] ?? '');

        if ($password !== $confirm_password) {
            $error = 'Passwords do not match';
        } elseif ($email && !validateEmail($email)) {
            $error = 'Invalid email address';
        } else {
            $result = $auth->register($name, $email, $password, $address, $phone);
            if ($result['success']) {
                $success     = 'Registration successful! Please log in.';
                $is_register = false;
            } else {
                $error = $result['message'];
            }
        }
    } else {
        $identifier = sanitize($_POST['identifier'] ?? '');
        $password   = $_POST['password'] ?? '';

        $result = $auth->universalLogin($identifier, $password);
        if ($result['success']) {
            if (($result['user_type'] ?? '') === 'staff') {
                header('Location: staff/dashboard.php');
            } elseif ($result['is_admin'] ?? false) {
                header('Location: admin/dashboard.php');
            } else {
                header('Location: user/dashboard.php');
            }
            exit;
        } else {
            $error = $result['message'];
        }
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
    <title><?php echo $is_register ? 'Register' : 'Login'; ?> - PayTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-blue-100 min-h-screen flex items-center justify-center p-4">

    <!-- PWA Install Banner -->
    <div id="installBanner" class="hidden fixed top-0 left-0 right-0 bg-blue-600 text-white px-4 py-3 flex items-center justify-between z-50 shadow-lg">
        <div class="flex items-center gap-3">
            <span class="text-xl">📱</span>
            <div>
                <p class="font-semibold text-sm">Install PayTrack</p>
                <p class="text-xs text-blue-200">Add to your home screen for quick access</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="installApp()" class="bg-white text-blue-600 px-3 py-1 rounded-lg text-sm font-bold hover:bg-blue-50">Install</button>
            <button onclick="document.getElementById('installBanner').classList.add('hidden')" class="text-blue-200 hover:text-white text-lg leading-none">✕</button>
        </div>
    </div>

    <div class="w-full max-w-md">
        <div class="bg-white rounded-lg shadow-xl p-8">
            <img src="/public/logo.png" alt="PayTrack" class="h-16 w-auto mx-auto mb-2">
            <p class="text-center text-gray-600 mb-8">Savings &amp; Loans Management System</p>

            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <?php if ($is_register): ?>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Full Name</label>
                        <input type="text" name="name" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Phone Number *</label>
                        <input type="tel" name="phone" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Email (Optional)</label>
                        <input type="email" name="email" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Address</label>
                        <input type="text" name="address" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                <?php else: ?>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Phone Number or Email</label>
                        <input type="text" name="identifier" required placeholder="Enter phone number or email"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <p class="text-gray-500 text-xs mt-1">Use phone number for faster login</p>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Password</label>
                    <input type="password" name="password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <?php if ($is_register): ?>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Confirm Password</label>
                        <input type="password" name="confirm_password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                <?php endif; ?>

                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition duration-200">
                    <?php echo $is_register ? 'Register' : 'Login'; ?>
                </button>
            </form>

            <p class="text-center text-gray-600 mt-6">
                <?php if ($is_register): ?>
                    Already have an account? <a href="login.php" class="text-blue-600 hover:text-blue-700 font-semibold">Login</a>
                <?php else: ?>
                    Don't have an account? <a href="?register=1" class="text-blue-600 hover:text-blue-700 font-semibold">Register</a>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <script>
        // Register service worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/public/sw.js')
                    .then(() => console.log('PayTrack SW registered'))
                    .catch(err => console.log('SW error:', err));
            });
        }

        // PWA install prompt
        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            document.getElementById('installBanner').classList.remove('hidden');
        });

        function installApp() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(() => {
                    deferredPrompt = null;
                    document.getElementById('installBanner').classList.add('hidden');
                });
            }
        }
    </script>
</body>
</html>
