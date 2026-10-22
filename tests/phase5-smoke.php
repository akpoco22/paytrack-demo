<?php
require_once __DIR__ . '/../src/database/Phase5Migration.php';
require_once __DIR__ . '/../src/models/AutoDeduction.php';

function expect($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function near($actual, $expected, $message) {
    expect(abs((float)$actual - (float)$expected) < 0.005, $message . ': expected ' . $expected . ', got ' . $actual);
}

/** Build a Phase 3-style in-memory database (no admin audit columns). */
function buildPhase3Db() {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec("CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, email TEXT, phone_number TEXT, balance REAL NOT NULL DEFAULT 0)");
    $db->exec("CREATE TABLE staff (id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER DEFAULT 1)");
    $db->exec("CREATE TABLE transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, staff_id INTEGER,
        type TEXT CHECK(type IN ('deposit','withdrawal')) NOT NULL, amount REAL NOT NULL,
        reference_code TEXT NOT NULL UNIQUE, description TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(customer_id) REFERENCES customers(id), FOREIGN KEY(staff_id) REFERENCES staff(id)
    )");
    $db->exec("CREATE TABLE loans (
        id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, loan_amount REAL NOT NULL,
        interest_rate REAL NOT NULL, date_given DATE NOT NULL, status TEXT DEFAULT 'active',
        total_repaid REAL DEFAULT 0, created_by INTEGER NOT NULL,
        FOREIGN KEY(customer_id) REFERENCES customers(id)
    )");
    $db->exec("CREATE TABLE loan_repayments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, loan_id INTEGER NOT NULL, amount REAL NOT NULL,
        interest_paid REAL DEFAULT 0, principal_paid REAL DEFAULT 0, repayment_date DATE NOT NULL,
        reference_code TEXT NOT NULL UNIQUE, notes TEXT, recorded_by INTEGER NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(loan_id) REFERENCES loans(id), FOREIGN KEY(recorded_by) REFERENCES staff(id)
    )");
    $db->exec("CREATE TABLE receipts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, loan_repayment_id INTEGER,
        receipt_number TEXT NOT NULL UNIQUE, amount REAL NOT NULL,
        receipt_type TEXT NOT NULL, generated_by INTEGER NOT NULL, generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
        FOREIGN KEY(loan_repayment_id) REFERENCES loan_repayments(id) ON DELETE SET NULL,
        FOREIGN KEY(generated_by) REFERENCES staff(id)
    )");
    $db->exec("CREATE INDEX idx_transaction_customer ON transactions(customer_id)");
    return $db;
}

$db = buildPhase3Db();
$db->exec("INSERT INTO customers VALUES (1,'Demo Customer','demo@example.test','08000000000',10000)");
$db->exec("INSERT INTO staff VALUES (1,'Demo Staff',1)");
$db->exec("INSERT INTO loans (id,customer_id,loan_amount,interest_rate,date_given,status,total_repaid,created_by) VALUES
    (1,1,100000,5,'2026-01-15','active',0,1),
    (2,1,200000,5,'2026-10-01','active',0,1)");
$db->exec("INSERT INTO transactions (customer_id,staff_id,type,amount,reference_code,description) VALUES (1,1,'deposit',10000,'TXN-SEED','seed')");
$db->exec("INSERT INTO loan_repayments (loan_id,amount,interest_paid,principal_paid,repayment_date,reference_code,notes,recorded_by) VALUES (1,1000,0,1000,'2026-02-01','REPM-SEED','seed',1)");
$db->exec("INSERT INTO receipts (transaction_id,receipt_number,amount,receipt_type,generated_by) VALUES (1,'RCT-SEED',10000,'transaction',1)");

$result = Phase5Migration::apply($db);
expect($result['transactions_rebuilt'] && $result['repayments_rebuilt'] && $result['deductions_table_created'], 'Phase 5 should migrate a Phase 3 database');
expect((int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn() === 1, 'transaction history must survive migration');
expect((int)$db->query('SELECT COUNT(*) FROM loan_repayments')->fetchColumn() === 1, 'repayment history must survive migration');
expect((int)$db->query('SELECT COUNT(*) FROM receipts')->fetchColumn() === 1, 'receipt history must survive migration');
expect((int)$db->query("SELECT COUNT(*) FROM loan_repayments WHERE source='manual'")->fetchColumn() === 1, 'existing repayments must be marked manual');
expect(!$db->query('PRAGMA foreign_key_check')->fetchAll(), 'foreign keys should remain valid');
$result2 = Phase5Migration::apply($db);
expect(!$result2['transactions_rebuilt'] && !$result2['repayments_rebuilt'] && !$result2['deductions_table_created'], 'second migration run should be a no-op');

$runner = new AutoDeduction($db);

// Loan issued 2026-01-15 is due 2026-02-15. Before that it must not be touched.
$early = $runner->runDue(new DateTimeImmutable('2026-02-14'));
expect($early[0]['status'] === 'not-due', 'loan under one month old should not be deducted');
expect($db->query('SELECT balance FROM customers WHERE id=1')->fetchColumn() == 10000, 'early run must not change balance');

$first_month = new DateTimeImmutable('2026-02-15');
$results = $runner->runDue($first_month);
expect(count($results) === 2, 'both open loans should be examined');
expect($results[0]['status'] === 'deducted', 'due loan should be deducted');
near($results[0]['amount_taken'], 5000, '5% should be deducted from first loan');
near($results[0]['amount_owed_after'], 95000, 'outstanding should reduce by the deduction');
expect($results[1]['status'] === 'not-due', 'newer loan should not be deducted yet');
near($db->query('SELECT balance FROM customers WHERE id=1')->fetchColumn(), 5000, 'customer balance should be debited');
expect((int)$db->query("SELECT COUNT(*) FROM transactions WHERE type='loan_repayment'")->fetchColumn() === 1, 'deduction should create one loan_repayment transaction');
expect((int)$db->query("SELECT COUNT(*) FROM loan_repayments WHERE source='auto'")->fetchColumn() === 1, 'deduction should create one auto repayment');
$repeat = $runner->runDue($first_month);
expect(in_array($repeat[0]['status'], ['not-due', 'already-processed'], true), 'repeat run must not be eligible again this month');
expect((int)$db->query('SELECT COUNT(*) FROM loan_auto_deductions')->fetchColumn() === 1, 'repeat run must not create a second marker');
near($db->query('SELECT balance FROM customers WHERE id=1')->fetchColumn(), 5000, 'repeat run must not debit again');

$second_month = new DateTimeImmutable('2026-03-15');
$partial = $runner->runDue($second_month);
expect($partial[0]['status'] === 'deducted', 'next month should deduct again');
near($partial[0]['amount_taken'], 4750, 'next monthly target should be 5% of new outstanding');
near($partial[0]['amount_owed_after'], 90250, 'partial schedule should carry outstanding forward');
near($db->query('SELECT balance FROM customers WHERE id=1')->fetchColumn(), 250, 'balance should reflect both deductions');

// Empty balance does not consume the monthly marker; a later deposit can be collected in-month.
$db->exec('UPDATE customers SET balance=0 WHERE id=1');
$third_month = new DateTimeImmutable('2026-04-15');
$no_funds = $runner->runDue($third_month);
expect($no_funds[0]['status'] === 'no-funds', 'zero balance should safely skip');
expect((int)$db->query("SELECT COUNT(*) FROM loan_auto_deductions WHERE period_month='2026-04'")->fetchColumn() === 0, 'zero-balance month must not leave a marker');
$db->exec('UPDATE customers SET balance=100 WHERE id=1');
$partial_funds = $runner->runDue($third_month);
near($partial_funds[0]['amount_taken'], 100, 'later same-month deposit should be partially collected');
near($partial_funds[0]['amount_owed_after'], 90150, 'remaining principal should include unpaid portion');
expect(!$db->query('PRAGMA foreign_key_check')->fetchAll(), 'foreign keys should remain valid after repayments');

// Loan 2 was issued 2026-10-01. One month later, 2026-11-01, it becomes eligible.
// A fresh deposit funds its 5% deduction; loan 1 also collects its monthly share first.
$db->exec('UPDATE customers SET balance=50000 WHERE id=1');
$loan2 = $runner->runDue(new DateTimeImmutable('2026-11-02'));
$loan2_result = array_values(array_filter($loan2, fn($r) => $r['loan_id'] === 2))[0];
expect($loan2_result['status'] === 'deducted', 'second loan should be deducted once it is a month old');
near($loan2_result['amount_due'], 10000, 'second loan target should be 5% of its principal');
near($loan2_result['amount_taken'], 10000, 'second loan should take its full 5% when funded');
near($loan2_result['amount_owed_after'], 190000, 'second loan outstanding should drop by what was taken');

echo "Phase 5 synthetic SQLite smoke tests passed.\n";
