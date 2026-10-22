<?php
/**
 * Database Seed Script
 * Extol Global Service - Test Data Generation
 * Creates sample staff, customers, KYC, loans, and transactions
 */

$db_path = dirname(__FILE__) . '/paytrack.db';

try {
    $conn = new PDO('sqlite:' . $db_path);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec('PRAGMA foreign_keys = ON');
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

echo "🌱 Extol Global Service Database Seeding Started\n\n";

try {
    // 1. Clear existing test data (optional)
    echo "Clearing existing test data...\n";
    $conn->exec("DELETE FROM receipts");
    $conn->exec("DELETE FROM loan_repayments");
    $conn->exec("DELETE FROM loans");
    $conn->exec("DELETE FROM kyc_records");
    $conn->exec("DELETE FROM transactions");
    $conn->exec("DELETE FROM contact_messages");
    $conn->exec("DELETE FROM staff");
    $conn->exec("DELETE FROM customers WHERE is_admin = 0");

    // 2. Create Staff Members
    echo "\n📋 Creating staff members...\n";
    $staff_data = [
        ['name' => 'Kunle Okafor', 'phone' => '08012345678', 'staff_id' => 'LN002', 'role' => 'loans'],
        ['name' => 'Amara Obi', 'phone' => '08023456789', 'staff_id' => 'LN001', 'role' => 'loans'],
        ['name' => 'Chidi Nwankwo', 'phone' => '08034567890', 'staff_id' => 'LN003', 'role' => 'loans'],
    ];

    $staff_ids = [];
    foreach ($staff_data as $staff) {
        $password_hash = password_hash($staff['staff_id'], PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO staff (name, phone_number, staff_id, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([$staff['name'], $staff['phone'], $staff['staff_id'], $password_hash, $staff['role']]);
        $staff_ids[] = $conn->lastInsertId();
        echo "  ✓ {$staff['name']} ({$staff['staff_id']})\n";
    }

    // 3. Create Test Customers
    echo "\n👥 Creating test customers...\n";
    $customer_data = [
        ['name' => 'Bola Ahmed', 'phone' => '08111111111', 'card_id' => 'CARD001', 'email' => 'bola@paytrack.local'],
        ['name' => 'Zainab Hassan', 'phone' => '08222222222', 'card_id' => 'CARD002', 'email' => 'zainab@paytrack.local'],
        ['name' => 'Tunde Adebayo', 'phone' => '08333333333', 'card_id' => 'CARD003', 'email' => 'tunde@paytrack.local'],
        ['name' => 'Chioma Eze', 'phone' => '08444444444', 'card_id' => 'CARD004', 'email' => 'chioma@paytrack.local'],
    ];

    $customer_ids = [];
    foreach ($customer_data as $customer) {
        $password_hash = password_hash($customer['card_id'], PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO customers (name, phone_number, card_id, email, password_hash, balance, is_admin) VALUES (?, ?, ?, ?, ?, 50000, 0)");
        $stmt->execute([$customer['name'], $customer['phone'], $customer['card_id'], $customer['email'], $password_hash]);
        $customer_ids[] = $conn->lastInsertId();
        echo "  ✓ {$customer['name']} ({$customer['phone']}) - Balance: ₦50,000\n";
    }

    // 4. Create KYC Records (some approved, some pending)
    echo "\n📝 Creating KYC records...\n";
    $kyc_data = [
        ['customer_id' => $customer_ids[0], 'id_type' => 'National ID', 'id_number' => 'NIN001234567', 'status' => 'approved', 'staff_id' => $staff_ids[0]],
        ['customer_id' => $customer_ids[1], 'id_type' => 'Passport', 'id_number' => 'PP001234', 'status' => 'approved', 'staff_id' => $staff_ids[0]],
        ['customer_id' => $customer_ids[2], 'id_type' => 'Driver License', 'id_number' => 'DL001234', 'status' => 'pending', 'staff_id' => null],
        ['customer_id' => $customer_ids[3], 'id_type' => 'National ID', 'id_number' => 'NIN987654321', 'status' => 'approved', 'staff_id' => $staff_ids[0]],
    ];

    foreach ($kyc_data as $kyc) {
        $verified_at = $kyc['status'] === 'approved' ? date('Y-m-d H:i:s') : null;
        $stmt = $conn->prepare("INSERT INTO kyc_records (customer_id, id_type, id_number, address, employment_status, bank_name, verification_status, verified_by, verified_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$kyc['customer_id'], $kyc['id_type'], $kyc['id_number'], 'Lagos, Nigeria', 'Self-employed', 'First Bank', $kyc['status'], $kyc['staff_id'], $verified_at]);
        echo "  ✓ {$kyc['id_type']} ({$kyc['status']})\n";
    }

    // 5. Create Sample Transactions
    echo "\n💱 Creating sample transactions...\n";
    $transaction_data = [
        ['customer_id' => $customer_ids[0], 'staff_id' => $staff_ids[0], 'type' => 'deposit', 'amount' => 25000, 'desc' => 'Savings deposit'],
        ['customer_id' => $customer_ids[0], 'staff_id' => $staff_ids[0], 'type' => 'withdrawal', 'amount' => 5000, 'desc' => 'Withdrawal'],
        ['customer_id' => $customer_ids[1], 'staff_id' => $staff_ids[0], 'type' => 'deposit', 'amount' => 15000, 'desc' => 'Weekly savings'],
        ['customer_id' => $customer_ids[2], 'staff_id' => $staff_ids[2], 'type' => 'deposit', 'amount' => 30000, 'desc' => 'Monthly contribution'],
        ['customer_id' => $customer_ids[3], 'staff_id' => $staff_ids[0], 'type' => 'deposit', 'amount' => 20000, 'desc' => 'Savings'],
    ];

    foreach ($transaction_data as $txn) {
        $ref_code = 'TXN-' . strtoupper(uniqid());
        $stmt = $conn->prepare("INSERT INTO transactions (customer_id, staff_id, type, amount, reference_code, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$txn['customer_id'], $txn['staff_id'], $txn['type'], $txn['amount'], $ref_code, $txn['desc']]);

        // Update customer balance
        $balance_change = $txn['type'] === 'deposit' ? $txn['amount'] : -$txn['amount'];
        $conn->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?")->execute([$balance_change, $txn['customer_id']]);

        echo "  ✓ {$txn['type']}: ₦" . number_format($txn['amount']) . " ({$txn['desc']})\n";
    }

    // 6. Create Sample Loans
    echo "\n📊 Creating sample loans...\n";
    $loan_data = [
        [
            'customer_id' => $customer_ids[0],
            'amount' => 500000,
            'interest' => 12.5,
            'purpose' => 'Business expansion',
            'guarantor' => 'Adekunle Johnson',
            'date_given' => date('Y-m-d', strtotime('-30 days')),
            'due_date' => date('Y-m-d', strtotime('+6 months'))
        ],
        [
            'customer_id' => $customer_ids[1],
            'amount' => 300000,
            'interest' => 15,
            'purpose' => 'Education',
            'guarantor' => 'Ibrahim Hassan',
            'date_given' => date('Y-m-d', strtotime('-15 days')),
            'due_date' => date('Y-m-d', strtotime('+12 months'))
        ],
    ];

    foreach ($loan_data as $loan) {
        $stmt = $conn->prepare("INSERT INTO loans (customer_id, loan_amount, interest_rate, purpose, guarantor_name, date_given, due_date, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$loan['customer_id'], $loan['amount'], $loan['interest'], $loan['purpose'], $loan['guarantor'], $loan['date_given'], $loan['due_date'], $staff_ids[1]]);
        $loan_id = $conn->lastInsertId();

        echo "  ✓ Loan ₦" . number_format($loan['amount']) . " @ {$loan['interest']}% ({$loan['purpose']})\n";
    }

    // 7. Create Sample Loan Repayments
    echo "\n💰 Creating sample loan repayments...\n";
    $repayment_data = [
        ['loan_id' => 1, 'principal' => 100000, 'interest' => 12500, 'total' => 112500],
        ['loan_id' => 1, 'principal' => 80000, 'interest' => 10000, 'total' => 90000],
    ];

    foreach ($repayment_data as $rep) {
        $ref = 'REPM-' . strtoupper(uniqid());
        $stmt = $conn->prepare("INSERT INTO loan_repayments (loan_id, amount, principal_paid, interest_paid, repayment_date, reference_code, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$rep['loan_id'], $rep['total'], $rep['principal'], $rep['interest'], date('Y-m-d'), $ref, $staff_ids[1]]);

        // Update loan
        $conn->prepare("UPDATE loans SET total_repaid = total_repaid + ?, interest_charged = interest_charged + ? WHERE id = ?")->execute([$rep['principal'], $rep['interest'], $rep['loan_id']]);

        echo "  ✓ Repayment: ₦" . number_format($rep['total']) . " (Principal: ₦" . number_format($rep['principal']) . ", Interest: ₦" . number_format($rep['interest']) . ")\n";
    }

    echo "\n✅ Seeding completed successfully!\n\n";
    echo "=== TEST CREDENTIALS ===\n";
    echo "\nAdmin:\n";
    echo "  Email: admin@paytrack.local\n";
    echo "  Password: Admin123!\n";

    echo "\nCustomers (Phone + Card):\n";
    foreach ($customer_data as $i => $customer) {
        echo "  {$customer['name']}: {$customer['phone']} / {$customer['card_id']}\n";
    }

    echo "\nStaff (Phone + Staff ID):\n";
    foreach ($staff_data as $i => $staff) {
        echo "  {$staff['name']}: {$staff['phone']} / {$staff['staff_id']}\n";
    }

    echo "\n=== TEST DATA SUMMARY ===\n";
    echo "✓ Staff members: " . count($staff_data) . "\n";
    echo "✓ Customers: " . count($customer_data) . "\n";
    echo "✓ KYC records: " . count($kyc_data) . "\n";
    echo "✓ Transactions: " . count($transaction_data) . "\n";
    echo "✓ Loans: " . count($loan_data) . "\n";
    echo "✓ Loan repayments: " . count($repayment_data) . "\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

?>
