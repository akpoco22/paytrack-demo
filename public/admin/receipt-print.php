<?php
/**
 * Print-friendly receipt page — PayTrack.
 * Opens with ?id=<receipt_id>, renders a clean A4-ish receipt with a
 * Print / Save as PDF button (uses the browser print-to-PDF, no deps).
 */
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Receipt.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();

$receipt_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$receipt_id) {
    $_SESSION['error'] = 'No receipt selected.';
    header('Location: receipts.php');
    exit;
}

$receipt_model = new Receipt($conn);
$r = $receipt_model->getReceiptById($receipt_id);
if (!$r) {
    $_SESSION['error'] = 'Receipt not found.';
    header('Location: receipts.php');
    exit;
}

$is_repayment = $r['receipt_type'] === 'loan_repayment';
$source_ref   = $is_repayment ? ($r['repay_ref'] ?? '—') : ($r['txn_ref'] ?? '—');
$type_label   = $is_repayment ? 'Loan Repayment' : ucfirst($r['txn_type'] ?? 'Transaction');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <link rel="icon" type="image/png" href="/public/icons/icon-192.png">
    <title>Receipt <?php echo htmlspecialchars($r['receipt_number']); ?> - PayTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .receipt-sheet { box-shadow: none !important; border: 1px solid #000 !important; margin: 0 !important; max-width: 100% !important; }
        }
    </style>
</head>
<body class="bg-gray-100">

<!-- Top bar (hidden when printing) -->
<div class="no-print bg-white shadow-sm border-b border-gray-200">
    <div class="max-w-3xl mx-auto px-4 py-3 flex items-center justify-between">
        <a href="receipts.php" class="text-blue-600 hover:text-blue-700 font-medium text-sm">← Back to Receipts</a>
        <div class="flex gap-2">
            <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-semibold text-sm">🖨️ Print / Save as PDF</button>
        </div>
    </div>
</div>

<div class="max-w-3xl mx-auto px-4 py-8">
    <div class="receipt-sheet bg-white rounded-lg shadow p-8">

        <!-- Header -->
        <div class="text-center border-b-2 border-blue-600 pb-6 mb-6">
            <div class="flex items-center justify-center gap-3 mb-1">
                <img src="/public/logo.png" alt="PayTrack" class="h-12 w-auto">
            </div>
            <p class="text-gray-500 text-sm tracking-wide uppercase">Official Receipt</p>
        </div>

        <!-- Meta -->
        <div class="grid grid-cols-2 gap-4 mb-6 text-sm">
            <div>
                <p class="text-gray-500 font-medium">Receipt Number</p>
                <p class="font-mono font-bold text-blue-700"><?php echo htmlspecialchars($r['receipt_number']); ?></p>
            </div>
            <div class="text-right">
                <p class="text-gray-500 font-medium">Date Issued</p>
                <p class="font-semibold text-gray-800"><?php echo formatDateTime($r['generated_at']); ?></p>
            </div>
            <div>
                <p class="text-gray-500 font-medium">Customer</p>
                <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($r['customer_name'] ?? '—'); ?></p>
                <?php if (!empty($r['phone_number'])): ?>
                    <p class="text-gray-500 text-xs"><?php echo htmlspecialchars($r['phone_number']); ?></p>
                <?php endif; ?>
            </div>
            <div class="text-right">
                <p class="text-gray-500 font-medium">Type</p>
                <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($type_label); ?></p>
                <p class="text-gray-400 text-xs font-mono">Ref: <?php echo htmlspecialchars($source_ref); ?></p>
            </div>
        </div>

        <!-- Amount -->
        <div class="border-t border-b border-gray-200 py-6 mb-6 text-center">
            <p class="text-gray-500 text-sm font-medium uppercase tracking-wide">Amount Received</p>
            <p class="text-5xl font-bold text-gray-900 mt-1"><?php echo formatCurrency($r['amount']); ?></p>
        </div>

        <!-- Footer -->
        <div class="text-center text-gray-400 text-xs">
            <p>Thank you for your transaction.</p>
            <p>This is an official receipt from PayTrack.</p>
        </div>

    </div>
    <p class="no-print text-center text-gray-400 text-xs mt-4">Tip: choose “Save as PDF” in the print dialog to download this receipt.</p>
</div>

</body>
</html>
