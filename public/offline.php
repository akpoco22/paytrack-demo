<?php
// Offline fallback page (served by service worker when network is unavailable)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <title>Offline - PayTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl p-8 max-w-sm w-full text-center">
        <p class="text-5xl mb-4">📶</p>
        <h1 class="text-2xl font-bold text-gray-800 mb-2">You're offline</h1>
        <p class="text-gray-600 mb-6">PayTrack needs an internet connection to load your data. Please reconnect and try again.</p>
        <a href="/public/login.php" class="block w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition">Retry</a>
    </div>
</body>
</html>
