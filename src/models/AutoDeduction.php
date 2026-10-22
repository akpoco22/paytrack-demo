<?php
/**
 * Automatic monthly principal deductions from customer account balances.
 * This is an internal scheduled operation; manual repayments continue to use Loan::recordRepayment().
 */
class AutoDeduction {
    private PDO $conn;

    public function __construct($database_connection) {
        if (!$database_connection instanceof PDO) {
            throw new InvalidArgumentException('Automatic deductions require a PDO/SQLite connection');
        }
        $this->conn = $database_connection;
    }

    /**
     * Process each eligible open loan at most once per calendar month.
     * One month must have elapsed since the loan was issued. An empty account is retried
     * later in the same month; any positive partial collection closes that month's attempt.
     */
    public function runDue(DateTimeImmutable $today, $dry_run = false) {
        $today = $today->setTime(0, 0, 0);
        $period = $today->format('Y-m');
        $query = "SELECT l.id AS loan_id, l.customer_id, l.loan_amount, l.total_repaid, l.interest_rate,
                         l.date_given, l.status, c.name AS customer_name, c.email, c.phone_number,
                         COALESCE((SELECT MAX(d.due_on) FROM loan_auto_deductions d WHERE d.loan_id=l.id), l.date_given) AS last_due_on
                  FROM loans l JOIN customers c ON c.id = l.customer_id
                  WHERE l.status IN ('active','overdue','default')
                    AND ROUND(l.loan_amount - l.total_repaid, 2) > 0
                  ORDER BY l.date_given ASC, l.id ASC";
        $loans = $this->conn->query($query)->fetchAll(PDO::FETCH_ASSOC);
        $results = [];

        foreach ($loans as $loan) {
            $results[] = $this->processLoan($loan, $period, $today, (bool)$dry_run);
        }
        return $results;
    }

    /**
     * Collect at most one deduction per loan per calendar month.
     * The loan becomes collectable one month after its issue date (or, afterwards,
     * one month after the previous deduction's due date).
     */
    private function processLoan(array $loan, $period, DateTimeImmutable $today, $dry_run) {
        $repayment_date = $today->format('Y-m-d');
        $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)$loan['last_due_on'], 0, 10));
        if (!$anchor) {
            return ['loan_id' => (int)$loan['loan_id'], 'period_month' => $period, 'status' => 'not-due'];
        }
        // The loan becomes collectable one month after the anchor (issue date or last due date).
        $next_due = self::oneMonthAfter($anchor);
        if ($today < $next_due) {
            return ['loan_id' => (int)$loan['loan_id'], 'period_month' => $period, 'status' => 'not-due'];
        }
        // One calendar month since issue/last deduction; the marker key is the same period.
        $existing = $this->conn->prepare('SELECT id FROM loan_auto_deductions WHERE loan_id = ? AND period_month = ?');
        $existing->execute([(int)$loan['loan_id'], $period]);
        if ($existing->fetchColumn()) {
            return ['loan_id' => (int)$loan['loan_id'], 'period_month' => $period, 'status' => 'already-processed'];
        }
        $outstanding = max(0, round((float)$loan['loan_amount'] - (float)$loan['total_repaid'], 2));
        $target = min($outstanding, max(0.01, round($outstanding * 0.05, 2)));
        if ($dry_run) {
            $check = $this->conn->prepare('SELECT balance FROM customers WHERE id = ?');
            $check->execute([(int)$loan['customer_id']]);
            $available = max(0, (float)$check->fetchColumn());
            return [
                'loan_id' => (int)$loan['loan_id'],
                'period_month' => $period,
                'status' => 'dry-run',
                'amount_due' => $target,
                'amount_available' => $available,
                'amount_to_deduct' => min($target, $available),
                'outstanding_after' => max(0, $outstanding - min($target, $available)),
            ];
        }

        $started = false;
        try {
            $this->conn->beginTransaction();
            $started = true;

            // Re-read the current loan and balance inside the transaction.
            $current_stmt = $this->conn->prepare("SELECT l.customer_id, l.loan_amount, l.total_repaid, l.status, c.balance
                FROM loans l JOIN customers c ON c.id = l.customer_id WHERE l.id = ?");
            $current_stmt->execute([(int)$loan['loan_id']]);
            $current = $current_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$current || !in_array($current['status'], ['active', 'overdue', 'default'], true)) {
                $this->conn->rollBack();
                return ['loan_id' => (int)$loan['loan_id'], 'period_month' => $period, 'status' => 'not-open'];
            }

            $current_outstanding = max(0, round((float)$current['loan_amount'] - (float)$current['total_repaid'], 2));
            if ($current_outstanding <= 0) {
                $this->conn->rollBack();
                return ['loan_id' => (int)$loan['loan_id'], 'period_month' => $period, 'status' => 'no-outstanding'];
            }
            $amount_due = min($current_outstanding, max(0.01, round($current_outstanding * 0.05, 2)));
            $available = max(0, (float)$current['balance']);
            $amount = min($amount_due, round($available, 2));
            if ($amount <= 0) {
                // No marker: the daily job can collect later in this month if the customer deposits funds.
                $this->conn->rollBack();
                return ['loan_id' => (int)$loan['loan_id'], 'period_month' => $period, 'status' => 'no-funds', 'amount_due' => $amount_due];
            }

            // Conditional update protects against an overlapping withdrawal or another cron worker.
            $debit = $this->conn->prepare('UPDATE customers SET balance = ROUND(balance - ?, 2) WHERE id = ? AND balance >= ?');
            $debit->execute([$amount, (int)$current['customer_id'], $amount]);
            if ($debit->rowCount() !== 1) throw new RuntimeException('Customer balance changed before deduction');

            $new_repaid = round((float)$current['total_repaid'] + $amount, 2);
            $new_outstanding = max(0, round((float)$current['loan_amount'] - $new_repaid, 2));
            $new_status = $new_outstanding <= 0 ? 'completed' : $current['status'];
            $update_loan = $this->conn->prepare('UPDATE loans SET total_repaid = ?, status = ? WHERE id = ? AND total_repaid = ? AND status IN (\'active\',\'overdue\',\'default\')');
            $update_loan->execute([$new_repaid, $new_status, (int)$loan['loan_id'], (float)$current['total_repaid']]);
            if ($update_loan->rowCount() !== 1) throw new RuntimeException('Loan changed before repayment was recorded');

            $txn_reference = 'TXN-' . strtoupper(bin2hex(random_bytes(8)));
            $transaction_columns = array_column($this->conn->query('PRAGMA table_info(transactions)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (in_array('admin_id', $transaction_columns, true)) {
                $txn = $this->conn->prepare("INSERT INTO transactions (customer_id, staff_id, admin_id, type, amount, reference_code, description, created_at)
                    VALUES (?, NULL, NULL, 'loan_repayment', ?, ?, ?, ?)");
                $txn->execute([(int)$current['customer_id'], $amount, $txn_reference, 'Automatic 5% monthly loan repayment', $repayment_date . ' 00:00:00']);
            } else {
                $txn = $this->conn->prepare("INSERT INTO transactions (customer_id, staff_id, type, amount, reference_code, description, created_at)
                    VALUES (?, NULL, 'loan_repayment', ?, ?, ?, ?)");
                $txn->execute([(int)$current['customer_id'], $amount, $txn_reference, 'Automatic 5% monthly loan repayment', $repayment_date . ' 00:00:00']);
            }
            $transaction_id = (int)$this->conn->lastInsertId();

            $repayment_reference = 'REPM-' . strtoupper(bin2hex(random_bytes(6)));
            $repayment_columns = array_column($this->conn->query('PRAGMA table_info(loan_repayments)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $has_admin_actor = in_array('recorded_by_admin', $repayment_columns, true);
            if ($has_admin_actor) {
                $repayment = $this->conn->prepare("INSERT INTO loan_repayments
                    (loan_id, amount, interest_paid, principal_paid, repayment_date, reference_code, notes, recorded_by, recorded_by_admin, source)
                    VALUES (?, ?, 0, ?, ?, ?, ?, NULL, NULL, 'auto')");
            } else {
                $repayment = $this->conn->prepare("INSERT INTO loan_repayments
                    (loan_id, amount, interest_paid, principal_paid, repayment_date, reference_code, notes, recorded_by, source)
                    VALUES (?, ?, 0, ?, ?, ?, ?, NULL, 'auto')");
            }
            $repayment->execute([
                (int)$loan['loan_id'], $amount, $amount, $repayment_date, $repayment_reference,
                'Automatic monthly deduction (5% of outstanding principal)'
            ]);
            $repayment_id = (int)$this->conn->lastInsertId();

            $marker = $this->conn->prepare('INSERT INTO loan_auto_deductions (loan_id, period_month, due_on, processed_date, amount_taken, amount_owed_after, transaction_id, repayment_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            try {
                $marker->execute([(int)$loan['loan_id'], $period, $next_due->format('Y-m-d'), $repayment_date, $amount, $new_outstanding, $transaction_id, $repayment_id]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' || ($e->errorInfo[1] ?? null) === 19) {
                    throw new RuntimeException('This loan already has a deduction recorded for this month');
                }
                throw $e;
            }
            $this->conn->commit();
            $started = false;

            return [
                'loan_id' => (int)$loan['loan_id'],
                'customer_id' => (int)$current['customer_id'],
                'customer_name' => $loan['customer_name'],
                'email' => $loan['email'] ?? null,
                'phone_number' => $loan['phone_number'] ?? null,
                'period_month' => $period,
                'status' => 'deducted',
                'amount_due' => $amount_due,
                'amount_taken' => $amount,
                'amount_owed_after' => $new_outstanding,
                'total_repaid' => $new_repaid,
                'loan_amount' => (float)$current['loan_amount'],
                'transaction_reference' => $txn_reference,
                'repayment_reference' => $repayment_reference,
            ];
        } catch (Throwable $e) {
            if ($started && $this->conn->inTransaction()) $this->conn->rollBack();
            return ['loan_id' => (int)$loan['loan_id'], 'period_month' => $period, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private static function oneMonthAfter(DateTimeImmutable $date) {
        $first_next_month = $date->modify('first day of next month');
        $day = min((int)$date->format('j'), (int)$first_next_month->format('t'));
        return $first_next_month->setDate(
            (int)$first_next_month->format('Y'),
            (int)$first_next_month->format('n'),
            $day
        );
    }
}
