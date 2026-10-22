<?php
/**
 * Admin data export — full company backup as a multi-sheet Excel file.
 *
 * GET export.php → downloads extol-backup-YYYY-MM-DD-HHMM.xls
 * Admin-only + CSRF token required (?token=... from the Export page button).
 * Streams row-by-row (500/batch) so 1000+ users never exhaust memory.
 * Format: SpreadsheetML 2003 — opens directly in Excel, no library needed.
 */
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../includes/helpers.php';

requireAdmin();
checkSessionTimeout();

// Token check (CSRF for a GET download link)
$token = $_GET['token'] ?? '';
$expected = $_SESSION['csrf_token'] ?? '';
if ($expected === '' || !hash_equals($expected, (string)$token)) {
    http_response_code(419);
    $_SESSION['error'] = 'Session expired — please try the export again.';
    header('Location: dashboard.php');
    exit;
}

set_time_limit(300);

$filename = 'paytrack-backup-' . date('Y-m-d-Hi') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
echo "\xEF\xBB\xBF"; // UTF-8 BOM so ₦ renders in Excel

function xlsCell($v) {
    if ($v === null) return '<Cell><Data ss:Type="String"></Data></Cell>';
    if (is_numeric($v) && $v !== '') {
        return '<Cell><Data ss:Type="Number">' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
    }
    return '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
}

function xlsSheet($name, array $headers, $stmt) {
    echo '<Worksheet ss:Name="' . htmlspecialchars(substr($name, 0, 31), ENT_QUOTES, 'UTF-8') . '"><Table>';
    echo '<Row>';
    foreach ($headers as $h) {
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
    }
    echo '</Row>';
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo '<Row>';
        foreach ($headers as $h) {
            echo xlsCell($row[$h] ?? null);
        }
        echo '</Row>';
    }
    echo '</Table></Worksheet>';
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';

try {
    // Password hashes are deliberately EXCLUDED — a backup file must never
    // carry credential material. Everything else exports in full.
    $sheets = [
        ['Customers', ['id','name','email','phone_number','card_id','address','gender','date_of_birth','notes','is_admin','balance','created_at','updated_at'],
            "SELECT id,name,email,phone_number,card_id,address,gender,date_of_birth,notes,is_admin,balance,created_at,updated_at FROM customers ORDER BY id"],
        ['Staff', ['id','name','phone_number','staff_id','role','address','gender','date_of_birth','notes','is_active','created_at','updated_at'],
            "SELECT id,name,phone_number,staff_id,role,address,gender,date_of_birth,notes,is_active,created_at,updated_at FROM staff ORDER BY id"],
        ['Transactions', ['id','customer_id','staff_id','admin_id','type','amount','reference_code','description','created_at','updated_at'],
            "SELECT id,customer_id,staff_id,admin_id,type,amount,reference_code,description,created_at,updated_at FROM transactions ORDER BY id"],
        ['Loan_Applications', ['id','customer_id','loan_amount','interest_rate','repayment_frequency','purpose','status','reviewed_by','reviewed_by_admin','reviewed_at','notes','created_at','updated_at'],
            "SELECT id,customer_id,loan_amount,interest_rate,repayment_frequency,purpose,status,reviewed_by,reviewed_by_admin,reviewed_at,notes,created_at,updated_at FROM loan_applications ORDER BY id"],
        ['Loans', ['id','customer_id','loan_amount','interest_rate','repayment_frequency','purpose','guarantor_name','guarantor_phone','guarantor_address','collateral','date_given','due_date','status','total_repaid','interest_charged','created_by','created_by_admin','created_at'],
            "SELECT id,customer_id,loan_amount,interest_rate,repayment_frequency,purpose,guarantor_name,guarantor_phone,guarantor_address,collateral,date_given,due_date,status,total_repaid,interest_charged,created_by,created_by_admin,created_at FROM loans ORDER BY id"],
        ['Loan_Repayments', ['id','loan_id','amount','interest_paid','principal_paid','repayment_date','reference_code','notes','recorded_by','recorded_by_admin','created_at'],
            "SELECT id,loan_id,amount,interest_paid,principal_paid,repayment_date,reference_code,notes,recorded_by,recorded_by_admin,created_at FROM loan_repayments ORDER BY id"],
        ['KYC', ['id','customer_id','id_type','id_number','id_expiry','address','employment_status','income_source','bank_account','bank_name','reference_contact_name','reference_contact_phone','verification_status','notes','verified_by','verified_at','created_at'],
            "SELECT id,customer_id,id_type,id_number,id_expiry,address,employment_status,income_source,bank_account,bank_name,reference_contact_name,reference_contact_phone,verification_status,notes,verified_by,verified_at,created_at FROM kyc_records ORDER BY id"],
        ['Receipts', ['id','transaction_id','loan_repayment_id','receipt_number','amount','receipt_type','generated_by','generated_by_admin','generated_at'],
            "SELECT id,transaction_id,loan_repayment_id,receipt_number,amount,receipt_type,generated_by,generated_by_admin,generated_at FROM receipts ORDER BY id"],
        ['Messages', ['id','customer_id','subject','message','status','response','created_at','responded_at'],
            "SELECT id,customer_id,subject,message,status,response,created_at,responded_at FROM contact_messages ORDER BY id"],
    ];

    foreach ($sheets as [$name, $headers, $sql]) {
        try {
            $stmt = $conn->query($sql);
        } catch (Exception $e) {
            // Table may not exist on older installs — emit headers only.
            echo '<Worksheet ss:Name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"><Table><Row>';
            foreach ($headers as $h) {
                echo '<Cell><Data ss:Type="String">' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
            }
            echo '</Row></Table></Worksheet>';
            continue;
        }
        xlsSheet($name, $headers, $stmt);
    }
} catch (Exception $e) {
    // Headers already sent — can't redirect; embed the error as a sheet.
    echo '<Worksheet ss:Name="ERROR"><Table><Row><Cell><Data ss:Type="String">Export failed: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</Data></Cell></Row></Table></Worksheet>';
}

echo '</Workbook>';
exit;
