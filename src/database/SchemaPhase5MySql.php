<?php
/** Apply Phase 5 schema changes to a MySQL database (idempotent; explicit CLI invocation only). */
class SchemaPhase5MySql {
    public static function apply(mysqli $db) {
        $db->begin_transaction();
        try {
            $type_column = $db->query("SHOW COLUMNS FROM transactions LIKE 'type'")->fetch_assoc();
            if (!$type_column) throw new RuntimeException('transactions.type column is missing');
            if (stripos($type_column['Type'], 'loan_repayment') === false) {
                $db->query("ALTER TABLE transactions MODIFY type ENUM('deposit','withdrawal','loan_repayment') NOT NULL");
            }

            $columns = [];
            $result = $db->query('SHOW COLUMNS FROM loan_repayments');
            while ($row = $result->fetch_assoc()) $columns[$row['Field']] = $row;
            if (isset($columns['recorded_by']) && strtoupper($columns['recorded_by']['Null']) !== 'YES') {
                $db->query('ALTER TABLE loan_repayments MODIFY recorded_by INT NULL');
            }
            if (!isset($columns['source'])) {
                $after = isset($columns['recorded_by_admin']) ? ' AFTER recorded_by_admin' : ' AFTER recorded_by';
                $db->query("ALTER TABLE loan_repayments ADD COLUMN source ENUM('manual','auto') NOT NULL DEFAULT 'manual'{$after}");
            }

            $db->query("CREATE TABLE IF NOT EXISTS loan_auto_deductions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                loan_id INT NOT NULL,
                period_month CHAR(7) NOT NULL,
                due_on DATE NOT NULL,
                processed_date DATE NOT NULL,
                amount_taken DECIMAL(15,2) NOT NULL,
                amount_owed_after DECIMAL(15,2) NOT NULL,
                transaction_id INT NULL,
                repayment_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_auto_deduction_loan_period (loan_id, period_month),
                KEY idx_auto_deduction_transaction (transaction_id),
                KEY idx_auto_deduction_repayment (repayment_id),
                CONSTRAINT fk_auto_deduction_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
                CONSTRAINT fk_auto_deduction_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
                CONSTRAINT fk_auto_deduction_repayment FOREIGN KEY (repayment_id) REFERENCES loan_repayments(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->commit();
            return true;
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
