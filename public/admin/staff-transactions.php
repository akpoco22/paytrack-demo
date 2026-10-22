<?php
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Staff.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();

$staff_model = new Staff($conn);

// --- Filters ---
$active_tab  = isset($_GET['tab']) ? $_GET['tab'] : 'today';
$filter_staff = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : null;
$filter_from  = isset($_GET['date_from']) && $_GET['date_from'] ? $_GET['date_from'] : null;
$filter_to    = isset($_GET['date_to'])   && $_GET['date_to']   ? $_GET['date_to']   : null;
$filter_type  = isset($_GET['type']) && in_array($_GET['type'], ['deposit','withdrawal']) ? $_GET['type'] : null;
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page     = 20;
$offset       = ($page - 1) * $per_page;

// Staff filter dropdown: capped list (1000-user safe)
$all_staff          = $staff_model->getAllStaff(50, 0);
$today_summary      = $staff_model->getAllStaffTodaySummary();
$today_total_dep    = array_sum(array_column($today_summary, 'today_deposits'));
$today_total_with   = array_sum(array_column($today_summary, 'today_withdrawals'));
$today_total_count  = array_sum(array_column($today_summary, 'today_count'));

// History tab data
$history_rows  = $staff_model->getAllStaffTransactions($per_page, $offset, $filter_staff, $filter_from, $filter_to, $filter_type);
$history_total = $staff_model->countAllStaffTransactions($filter_staff, $filter_from, $filter_to, $filter_type);
$total_pages   = ceil($history_total / $per_page);

// Build query string helper (preserves filters across pages)
function buildQuery($overrides = []) {
    $base = array_merge([
        'tab'       => $_GET['tab']       ?? 'today',
        'staff_id'  => $_GET['staff_id']  ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to'   => $_GET['date_to']   ?? '',
        'type'      => $_GET['type']      ?? '',
        'page'      => $_GET['page']      ?? 1,
    ], $overrides);
    return '?' . http_build_query(array_filter($base, fn($v) => $v !== ''));
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
    <title>Staff Transactions - PayTrack Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">

    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-8">
                <a href="dashboard.php" class="flex items-center shrink-0"><img src="/public/logo.png" alt="PayTrack" class="h-10 w-auto"></a>
                <div class="hidden md:flex space-x-6">
                    <a href="dashboard.php"          class="text-gray-700 hover:text-blue-600 font-medium">Dashboard</a>
                    <a href="users.php"              class="text-gray-700 hover:text-blue-600 font-medium">Customers</a>
                    <a href="staff.php"              class="text-gray-700 hover:text-blue-600 font-medium">Staff</a>
                    <a href="staff-transactions.php" class="text-blue-600 font-medium border-b-2 border-blue-600">Staff Transactions</a>
                <a href="kyc.php" class="text-gray-700 hover:text-blue-600 font-medium">KYC</a>
                <a href="loans.php" class="text-gray-700 hover:text-blue-600 font-medium">Loans</a>
                <a href="receipts.php"           class="text-gray-700 hover:text-blue-600 font-medium">Receipts</a>
                    <a href="transactions.php"       class="text-gray-700 hover:text-blue-600 font-medium">Transactions</a>
                    <a href="messages.php"           class="text-gray-700 hover:text-blue-600 font-medium">Messages</a>
                <a href="export-backup.php" class="text-gray-700 hover:text-blue-600 font-medium">Export</a>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-gray-700"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../../public/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Logout</a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">

        <!-- Page Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <a href="staff.php" class="text-blue-600 hover:text-blue-700 text-sm font-semibold">← Back to Staff</a>
                <h2 class="text-3xl font-bold text-gray-800 mt-1">Staff Transactions</h2>
            </div>
            <p class="text-gray-500 text-sm">Today: <span class="font-semibold text-gray-700"><?php echo date('l, F j, Y'); ?></span></p>
        </div>

        <!-- TODAY SUMMARY BANNER -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-5 border-l-4 border-blue-500">
                <p class="text-gray-500 text-sm font-semibold">Total Transactions Today</p>
                <p class="text-3xl font-bold text-blue-600 mt-1"><?php echo $today_total_count; ?></p>
                <p class="text-gray-400 text-xs mt-1">Across all active staff</p>
            </div>
            <div class="bg-white rounded-lg shadow p-5 border-l-4 border-blue-500">
                <p class="text-gray-500 text-sm font-semibold">Total Deposits Today</p>
                <p class="text-3xl font-bold text-blue-600 mt-1"><?php echo formatCurrency($today_total_dep); ?></p>
            </div>
            <div class="bg-white rounded-lg shadow p-5 border-l-4 border-red-500">
                <p class="text-gray-500 text-sm font-semibold">Total Withdrawals Today</p>
                <p class="text-3xl font-bold text-red-600 mt-1"><?php echo formatCurrency($today_total_with); ?></p>
            </div>
        </div>

        <!-- TABS -->
        <div class="flex space-x-1 mb-6 bg-white rounded-lg shadow p-1 w-fit">
            <a href="<?php echo buildQuery(['tab' => 'today', 'page' => 1]); ?>"
               class="px-5 py-2 rounded-md text-sm font-semibold transition <?php echo $active_tab === 'today' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>">
                📅 Today's Activity
            </a>
            <a href="<?php echo buildQuery(['tab' => 'history', 'page' => 1]); ?>"
               class="px-5 py-2 rounded-md text-sm font-semibold transition <?php echo $active_tab === 'history' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>">
                🗂 Transaction History
            </a>
        </div>

        <!-- ============================================================ -->
        <!-- TAB: TODAY'S ACTIVITY                                        -->
        <!-- ============================================================ -->
        <?php if ($active_tab === 'today'): ?>

            <!-- Per-Staff Breakdown Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
                <?php foreach ($today_summary as $s): ?>
                    <div class="bg-white rounded-lg shadow p-5">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <p class="font-bold text-gray-800"><?php echo htmlspecialchars($s['name']); ?></p>
                                <p class="text-xs text-gray-500 font-mono"><?php echo htmlspecialchars($s['staff_id']); ?></p>
                            </div>
                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-700">
                                <?php echo ucfirst($s['role']); ?>
                            </span>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-center border-t border-gray-100 pt-3">
                            <div>
                                <p class="text-xs text-gray-500">Transactions</p>
                                <p class="text-lg font-bold text-blue-600"><?php echo $s['today_count']; ?></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Deposits</p>
                                <p class="text-sm font-bold text-blue-600"><?php echo formatCurrency($s['today_deposits']); ?></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Withdrawals</p>
                                <p class="text-sm font-bold text-red-600"><?php echo formatCurrency($s['today_withdrawals']); ?></p>
                            </div>
                        </div>
                        <?php if ($s['today_count'] > 0): ?>
                            <a href="staff-profile.php?id=<?php echo $s['id']; ?>" class="mt-3 block text-center text-xs text-blue-600 hover:text-blue-700 font-semibold">
                                View full profile →
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Today's Full Transaction Feed -->
            <?php
            // Fetch all of today's staff transactions (no pagination on today view — it's one day)
            $today_all = $staff_model->getAllStaffTransactions(500, 0, null, date('Y-m-d'), date('Y-m-d'), null);
            ?>
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-800">All Transactions Today</h3>
                    <span class="text-sm text-gray-500"><?php echo count($today_all); ?> transaction<?php echo count($today_all) !== 1 ? 's' : ''; ?></span>
                </div>

                <?php if (empty($today_all)): ?>
                    <div class="px-6 py-12 text-center">
                        <p class="text-4xl mb-3">📭</p>
                        <p class="text-gray-500 font-medium">No staff transactions recorded today yet.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Staff</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Customer</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Reference</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($today_all as $txn): ?>
                                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                        <td class="px-6 py-3 text-gray-500 text-sm"><?php echo date('H:i A', strtotime($txn['created_at'])); ?></td>
                                        <td class="px-6 py-3">
                                            <p class="font-semibold text-gray-800 text-sm"><?php echo htmlspecialchars($txn['staff_name'] ?? '—'); ?></p>
                                            <p class="text-xs text-gray-400 font-mono"><?php echo htmlspecialchars($txn['staff_code'] ?? ''); ?></p>
                                        </td>
                                        <td class="px-6 py-3 text-gray-700 font-medium"><?php echo htmlspecialchars($txn['customer_name']); ?></td>
                                        <td class="px-6 py-3">
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $txn['type'] === 'deposit' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                                <?php echo ucfirst($txn['type']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-3 font-bold <?php echo $txn['type'] === 'deposit' ? 'text-green-700' : 'text-red-700'; ?>">
                                            <?php echo formatCurrency($txn['amount']); ?>
                                        </td>
                                        <td class="px-6 py-3 text-gray-500 font-mono text-xs"><?php echo htmlspecialchars($txn['reference_code']); ?></td>
                                        <td class="px-6 py-3 text-gray-500 text-sm"><?php echo htmlspecialchars($txn['description'] ?? '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- TAB: TRANSACTION HISTORY                                     -->
        <!-- ============================================================ -->
        <?php if ($active_tab === 'history'): ?>

            <!-- Filters -->
            <form method="GET" class="bg-white rounded-lg shadow p-5 mb-6">
                <input type="hidden" name="tab" value="history">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <!-- Staff Filter -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1">Staff Member</label>
                        <select name="staff_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Staff</option>
                            <?php foreach ($all_staff as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo $filter_staff == $s['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['name']); ?> (<?php echo htmlspecialchars($s['staff_id']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Date From -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1">From Date</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_from ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Date To -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1">To Date</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_to ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Type Filter -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1">Type</label>
                        <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Types</option>
                            <option value="deposit"    <?php echo $filter_type === 'deposit'    ? 'selected' : ''; ?>>Deposits Only</option>
                            <option value="withdrawal" <?php echo $filter_type === 'withdrawal' ? 'selected' : ''; ?>>Withdrawals Only</option>
                        </select>
                    </div>
                </div>

                <!-- Quick date presets + action buttons -->
                <div class="flex flex-wrap items-center gap-3 mt-4">
                    <span class="text-sm text-gray-500 font-semibold">Quick:</span>
                    <button type="button" onclick="setDates('today')"      class="text-xs px-3 py-1 bg-gray-100 hover:bg-blue-100 text-gray-700 rounded-full font-medium">Today</button>
                    <button type="button" onclick="setDates('yesterday')"  class="text-xs px-3 py-1 bg-gray-100 hover:bg-blue-100 text-gray-700 rounded-full font-medium">Yesterday</button>
                    <button type="button" onclick="setDates('this_week')"  class="text-xs px-3 py-1 bg-gray-100 hover:bg-blue-100 text-gray-700 rounded-full font-medium">This Week</button>
                    <button type="button" onclick="setDates('this_month')" class="text-xs px-3 py-1 bg-gray-100 hover:bg-blue-100 text-gray-700 rounded-full font-medium">This Month</button>
                    <div class="ml-auto flex gap-2">
                        <a href="?tab=history" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm font-semibold">Clear</a>
                        <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold">Apply Filters</button>
                    </div>
                </div>
            </form>

            <!-- Results summary -->
            <div class="flex justify-between items-center mb-3">
                <p class="text-sm text-gray-600">
                    Showing <span class="font-semibold text-gray-800"><?php echo count($history_rows); ?></span> of
                    <span class="font-semibold text-gray-800"><?php echo $history_total; ?></span> transactions
                    <?php if ($filter_staff || $filter_from || $filter_to || $filter_type): ?>
                        <span class="text-blue-600">(filtered)</span>
                    <?php endif; ?>
                </p>
            </div>

            <!-- History Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <?php if (empty($history_rows)): ?>
                    <div class="px-6 py-12 text-center">
                        <p class="text-4xl mb-3">🔍</p>
                        <p class="text-gray-500 font-medium">No transactions found for the selected filters.</p>
                        <a href="?tab=history" class="mt-3 inline-block text-blue-600 hover:underline text-sm">Clear filters</a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Date & Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Staff</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Customer</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Reference</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history_rows as $txn): ?>
                                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                        <td class="px-6 py-3 text-gray-500 text-sm whitespace-nowrap"><?php echo formatDateTime($txn['created_at']); ?></td>
                                        <td class="px-6 py-3">
                                            <p class="font-semibold text-gray-800 text-sm"><?php echo htmlspecialchars($txn['staff_name'] ?? '—'); ?></p>
                                            <p class="text-xs text-gray-400 font-mono"><?php echo htmlspecialchars($txn['staff_code'] ?? ''); ?></p>
                                        </td>
                                        <td class="px-6 py-3 text-gray-700 font-medium"><?php echo htmlspecialchars($txn['customer_name']); ?></td>
                                        <td class="px-6 py-3">
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $txn['type'] === 'deposit' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                                <?php echo ucfirst($txn['type']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-3 font-bold <?php echo $txn['type'] === 'deposit' ? 'text-green-700' : 'text-red-700'; ?>">
                                            <?php echo formatCurrency($txn['amount']); ?>
                                        </td>
                                        <td class="px-6 py-3 text-gray-500 font-mono text-xs"><?php echo htmlspecialchars($txn['reference_code']); ?></td>
                                        <td class="px-6 py-3 text-gray-500 text-sm"><?php echo htmlspecialchars($txn['description'] ?? '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-center items-center space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo buildQuery(['page' => 1]); ?>"          class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm">First</a>
                                <a href="<?php echo buildQuery(['page' => $page - 1]); ?>"  class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm">Prev</a>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="<?php echo buildQuery(['page' => $i]); ?>"
                                   class="px-3 py-1 rounded text-sm <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-700'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo buildQuery(['page' => $page + 1]); ?>"    class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm">Next</a>
                                <a href="<?php echo buildQuery(['page' => $total_pages]); ?>" class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm">Last</a>
                            <?php endif; ?>

                            <span class="text-sm text-gray-500 ml-4">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>

    <script>
        function setDates(preset) {
            const from = document.querySelector('[name="date_from"]');
            const to   = document.querySelector('[name="date_to"]');
            const now  = new Date();

            const fmt = d => d.toISOString().split('T')[0];

            if (preset === 'today') {
                from.value = to.value = fmt(now);
            } else if (preset === 'yesterday') {
                const y = new Date(now); y.setDate(y.getDate() - 1);
                from.value = to.value = fmt(y);
            } else if (preset === 'this_week') {
                const start = new Date(now);
                start.setDate(now.getDate() - now.getDay());
                from.value = fmt(start);
                to.value   = fmt(now);
            } else if (preset === 'this_month') {
                from.value = fmt(new Date(now.getFullYear(), now.getMonth(), 1));
                to.value   = fmt(now);
            }
        }
    </script>
<script src="/assets/js/pwa.js"></script>
</body>
</html>
