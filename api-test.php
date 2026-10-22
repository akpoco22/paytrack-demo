<?php
/**
 * Extol Global Service API Test Suite
 * Tests all Phase 2+ features
 */

session_start();

$db_path = dirname(__FILE__) . '/paytrack.db';

try {
    $conn = new PDO('sqlite:' . $db_path);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec('PRAGMA foreign_keys = ON');
} catch (PDOException $e) {
    die(json_encode(['error' => $e->getMessage()]));
}

// Load models
require_once __DIR__ . '/src/Autoloader.php';
require_once __DIR__ . '/src/auth/Auth.php';
require_once __DIR__ . '/src/models/Customer.php';
require_once __DIR__ . '/src/models/Transaction.php';
require_once __DIR__ . '/src/models/Staff.php';
require_once __DIR__ . '/src/models/Loan.php';
require_once __DIR__ . '/src/models/KYC.php';
require_once __DIR__ . '/src/models/Receipt.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'help';
$response = [];

try {
    switch ($action) {
        // Auth Tests
        case 'test_staff_login':
            $auth = new Auth($conn);
            $result = $auth->loginStaff('08012345678', 'SV001');
            $response = $result;
            break;

        case 'test_customer_login':
            $auth = new Auth($conn);
            $result = $auth->loginCustomer('08111111111', 'CARD001');
            $response = $result;
            break;

        // Transaction Tests
        case 'test_create_transaction':
            $txn = new Transaction($conn);
            $result = $txn->createTransaction(1, 'deposit', 10000, 'Test deposit', 1, null);
            $response = $result;
            break;

        case 'test_daily_totals':
            $txn = new Transaction($conn);
            $result = $txn->getDailyTotals(30);
            $response = ['daily_totals' => $result];
            break;

        case 'test_date_search':
            $txn = new Transaction($conn);
            $date = $_GET['date'] ?? date('Y-m-d');
            $result = $txn->getTransactionsByDate($date);
            $totals = $txn->getDateTotals($date);
            $response = ['transactions' => $result, 'totals' => $totals];
            break;

        // Staff Tests
        case 'test_staff_info':
            $staff = new Staff($conn);
            $result = $staff->getStaffById(1);
            $response = ['staff' => $result];
            break;

        case 'test_staff_activity':
            $staff = new Staff($conn);
            $result = $staff->getStaffActivity(1);
            $response = ['activity' => $result];
            break;

        case 'test_all_staff':
            $staff = new Staff($conn);
            $result = $staff->getAllStaff();
            $response = ['staff_members' => $result];
            break;

        // KYC Tests
        case 'test_kyc_pending':
            $kyc = new KYC($conn);
            $result = $kyc->getPendingKYC();
            $response = ['pending_kyc' => $result];
            break;

        case 'test_kyc_approve':
            $kyc = new KYC($conn);
            $result = $kyc->approveKYC(3, 1, 'Verified and approved');
            $response = $result;
            break;

        case 'test_customer_kyc':
            $kyc = new KYC($conn);
            $result = $kyc->getKYCByCustomer(1);
            $response = ['kyc' => $result];
            break;

        // Loan Tests
        case 'test_create_loan':
            $loan = new Loan($conn);
            $result = $loan->createLoan(1, 1000000, 12.5, 'Monthly', 'Expansion', 'John Doe', '08012345678', 'Lagos', 'Title Deed', date('Y-m-d'), date('Y-m-d', strtotime('+12 months')), 2);
            $response = $result;
            break;

        case 'test_loan_info':
            $loan = new Loan($conn);
            $result = $loan->getLoanById(1);
            $response = ['loan' => $result];
            break;

        case 'test_overdue_loans':
            $loan = new Loan($conn);
            $result = $loan->getOverdueLoans();
            $response = ['overdue_loans' => $result];
            break;

        case 'test_loan_repayment_history':
            $loan = new Loan($conn);
            $result = $loan->getRepaymentHistory(1);
            $response = ['repayments' => $result];
            break;

        // Receipt Tests
        case 'test_transaction_receipt':
            $receipt = new Receipt($conn);
            $result = $receipt->createTransactionReceipt(1, 1);
            $response = $result;
            break;

        case 'test_receipt_html':
            $receipt = new Receipt($conn);
            $html = $receipt->generateTransactionHTML(1);
            header('Content-Type: text/html');
            echo $html;
            exit;
            break;

        case 'test_repayment_receipt':
            $receipt = new Receipt($conn);
            $result = $receipt->createRepaymentReceipt(1, 1);
            $response = $result;
            break;

        // System Stats
        case 'test_system_stats':
            $txn = new Transaction($conn);
            $stats = $txn->getSystemStats();
            $customer = new Customer($conn);
            $total_balance = $customer->getTotalSystemBalance();
            $total_customers = $customer->getTotalCustomerCount();
            $staff = new Staff($conn);
            $total_staff = $staff->getTotalStaffCount();
            $loan = new Loan($conn);
            $total_loans = $loan->getTotalLoanCount();

            $response = [
                'transactions' => $stats,
                'total_system_balance' => $total_balance,
                'total_customers' => $total_customers,
                'total_staff' => $total_staff,
                'total_loans' => $total_loans
            ];
            break;

        // Help
        case 'help':
        default:
            $response = [
                'message' => 'Extol Global Service API Test Suite',
                'tests' => [
                    'Authentication' => [
                        'test_staff_login' => 'Login staff (Kunle: 08012345678 / SV001)',
                        'test_customer_login' => 'Login customer (Bola: 08111111111 / CARD001)'
                    ],
                    'Transactions' => [
                        'test_create_transaction' => 'Create sample transaction',
                        'test_daily_totals' => 'Get daily totals for last 30 days',
                        'test_date_search?date=2026-10-03' => 'Search transactions by date'
                    ],
                    'Staff' => [
                        'test_staff_info' => 'Get staff member info',
                        'test_staff_activity' => 'Get staff activity (transactions)',
                        'test_all_staff' => 'List all staff'
                    ],
                    'KYC' => [
                        'test_kyc_pending' => 'Get pending KYC records',
                        'test_kyc_approve' => 'Approve KYC record',
                        'test_customer_kyc' => 'Get customer KYC'
                    ],
                    'Loans' => [
                        'test_loan_info' => 'Get loan details',
                        'test_overdue_loans' => 'List overdue loans',
                        'test_loan_repayment_history' => 'Get loan repayment history'
                    ],
                    'Receipts' => [
                        'test_transaction_receipt' => 'Create transaction receipt',
                        'test_receipt_html' => 'View receipt as HTML',
                        'test_repayment_receipt' => 'Create loan repayment receipt'
                    ],
                    'System' => [
                        'test_system_stats' => 'Get overall system statistics'
                    ]
                ],
                'usage' => 'http://localhost:8000/api-test.php?action=TEST_NAME'
            ];
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

?>
