<?php
/**
 * Phase 5 SQLite migration: automatic monthly loan deduction.
 * - transactions: allows type 'loan_repayment' (rebuild).
 * - loan_repayments: recorded_by becomes nullable and a 'source' column is added
 *   so automatic deductions can be stored without a staff actor (rebuild).
 * - loan_auto_deductions: one row per loan per month; UNIQUE(loan_id, period_month)
 *   guarantees a month is never charged twice.
 * Works on a Phase 3 schema (no admin audit columns). Idempotent.
 */
class Phase5Migration {
    private static function tableExists(PDO $db, $table) {
        $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private static function columns(PDO $db, $table) {
        return array_column($db->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    private static function tableSql(PDO $db, $table) {
        $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        return (string)$stmt->fetchColumn();
    }

    /** Indexes and triggers on a table, so they can be recreated after a rebuild. */
    private static function objectsFor(PDO $db, $table) {
        $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE tbl_name=? AND type IN ('index','trigger') AND sql IS NOT NULL");
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Rebuild a table: copy rows (only columns both schemas share), drop, rename, restore indexes.
     * Columns missing from the old table are filled with $defaults or NULL.
     */
    private static function rebuild(PDO $db, $table, $create_sql, array $new_columns, array $defaults = []) {
        $old_columns = self::columns($db, $table);
        $shared = array_values(array_intersect($new_columns, $old_columns));
        $objects = self::objectsFor($db, $table);

        $new_table = $table . '_phase5_new';
        $db->exec("DROP TABLE IF EXISTS \"{$new_table}\"");
        $db->exec($create_sql);

        if ($shared) {
            $list = implode(',', array_map(static fn($c) => '"' . $c . '"', $shared));
            $db->exec("INSERT INTO \"{$new_table}\" ({$list}) SELECT {$list} FROM \"{$table}\"");
        }
        foreach ($new_columns as $column) {
            if (!in_array($column, $shared, true) && array_key_exists($column, $defaults)) {
                $db->exec("UPDATE \"{$new_table}\" SET \"{$column}\" = " . $db->quote($defaults[$column]));
            }
        }
        $db->exec("DROP TABLE \"{$table}\"");
        $db->exec("ALTER TABLE \"{$new_table}\" RENAME TO \"{$table}\"");
        foreach ($objects as $sql) $db->exec($sql);
    }

    public static function apply(PDO $db) {
        if ($db->inTransaction()) throw new RuntimeException('Migration requires a connection with no active transaction');
        foreach (['customers', 'transactions', 'loans', 'loan_repayments'] as $table) {
            if (!self::tableExists($db, $table)) throw new RuntimeException("Required table {$table} is missing");
        }

        $result = ['transactions_rebuilt' => false, 'repayments_rebuilt' => false, 'deductions_table_created' => false];

        $foreign_keys_were_enabled = (int)$db->query('PRAGMA foreign_keys')->fetchColumn();
        $db->exec('PRAGMA foreign_keys = OFF');
        try {
            $db->beginTransaction();

            // Receipts reference both parent tables; rebuild them only if a parent needs rebuilding.
            $has_receipts = self::tableExists($db, 'receipts');
            $rebuild_transactions = !str_contains(self::tableSql($db, 'transactions'), "'loan_repayment'");
            $rebuild_repayments = !in_array('source', self::columns($db, 'loan_repayments'), true);
            $rebuild_receipts = $has_receipts && ($rebuild_transactions || $rebuild_repayments);
            $receipt_columns = $rebuild_receipts ? self::columns($db, 'receipts') : [];
            $receipt_rows = $rebuild_receipts ? $db->query('SELECT * FROM receipts')->fetchAll(PDO::FETCH_ASSOC) : [];
            $receipt_objects = $rebuild_receipts ? self::objectsFor($db, 'receipts') : [];
            if ($rebuild_receipts) $db->exec('DROP TABLE receipts');

            if ($rebuild_transactions) {
                $old_transaction_columns = self::columns($db, 'transactions');
                $transaction_admin = in_array('admin_id', $old_transaction_columns, true);
                $transaction_updated_at = in_array('updated_at', $old_transaction_columns, true);
                $transaction_columns = ['id','customer_id','staff_id'];
                if ($transaction_admin) $transaction_columns[] = 'admin_id';
                array_push($transaction_columns, 'type','amount','reference_code','description','created_at');
                if ($transaction_updated_at) $transaction_columns[] = 'updated_at';
                $transaction_ddl = "CREATE TABLE transactions_phase5_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, staff_id INTEGER,
                    type TEXT CHECK(type IN ('deposit','withdrawal','loan_repayment')) NOT NULL, amount REAL NOT NULL,
                    reference_code TEXT NOT NULL UNIQUE, description TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                    FOREIGN KEY(staff_id) REFERENCES staff(id) ON DELETE SET NULL
                )";
                if ($transaction_admin) {
                    $transaction_ddl = str_replace(
                        'staff_id INTEGER,',
                        "staff_id INTEGER, admin_id INTEGER,",
                        $transaction_ddl
                    );
                    $transaction_ddl = str_replace(
                        'FOREIGN KEY(staff_id) REFERENCES staff(id) ON DELETE SET NULL',
                        "FOREIGN KEY(staff_id) REFERENCES staff(id) ON DELETE SET NULL,\n                        FOREIGN KEY(admin_id) REFERENCES customers(id) ON DELETE SET NULL",
                        $transaction_ddl
                    );
                }
                if ($transaction_updated_at) {
                    $transaction_ddl = str_replace(
                        'description TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,',
                        "description TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,",
                        $transaction_ddl
                    );
                }
                self::rebuild($db, 'transactions', $transaction_ddl, $transaction_columns);
                $result['transactions_rebuilt'] = true;
            }

            if ($rebuild_repayments) {
                $old_repayment_columns = self::columns($db, 'loan_repayments');
                $repayment_admin = in_array('recorded_by_admin', $old_repayment_columns, true);
                $repayment_columns = ['id','loan_id','amount','interest_paid','principal_paid','repayment_date','reference_code','notes','recorded_by'];
                if ($repayment_admin) $repayment_columns[] = 'recorded_by_admin';
                array_push($repayment_columns, 'source','created_at');
                $repayment_ddl = "CREATE TABLE loan_repayments_phase5_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, loan_id INTEGER NOT NULL, amount REAL NOT NULL,
                    interest_paid REAL DEFAULT 0.00, principal_paid REAL DEFAULT 0.00, repayment_date DATE NOT NULL,
                    reference_code TEXT NOT NULL UNIQUE, notes TEXT, recorded_by INTEGER,
                    source TEXT NOT NULL DEFAULT 'manual' CHECK(source IN ('manual','auto')),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(loan_id) REFERENCES loans(id) ON DELETE CASCADE,
                    FOREIGN KEY(recorded_by) REFERENCES staff(id) ON DELETE RESTRICT
                )";
                if ($repayment_admin) {
                    $repayment_ddl = str_replace(
                        'recorded_by INTEGER,',
                        "recorded_by INTEGER, recorded_by_admin INTEGER,",
                        $repayment_ddl
                    );
                    $repayment_ddl = str_replace(
                        'FOREIGN KEY(recorded_by) REFERENCES staff(id) ON DELETE RESTRICT',
                        "FOREIGN KEY(recorded_by) REFERENCES staff(id) ON DELETE RESTRICT,\n                        FOREIGN KEY(recorded_by_admin) REFERENCES customers(id) ON DELETE SET NULL",
                        $repayment_ddl
                    );
                }
                self::rebuild($db, 'loan_repayments', $repayment_ddl, $repayment_columns, ['source' => 'manual']);
                $result['repayments_rebuilt'] = true;
            }

            if ($rebuild_receipts) {
                $has_admin_receipt = in_array('generated_by_admin', $receipt_columns, true);
                $receipt_ddl = $has_admin_receipt
                    ? "CREATE TABLE receipts (
                        id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, loan_repayment_id INTEGER,
                        receipt_number TEXT NOT NULL UNIQUE, amount REAL NOT NULL,
                        receipt_type TEXT CHECK(receipt_type IN ('transaction','loan_repayment')) NOT NULL,
                        generated_by INTEGER, generated_by_admin INTEGER, generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
                        FOREIGN KEY(loan_repayment_id) REFERENCES loan_repayments(id) ON DELETE SET NULL,
                        FOREIGN KEY(generated_by) REFERENCES staff(id) ON DELETE RESTRICT,
                        FOREIGN KEY(generated_by_admin) REFERENCES customers(id) ON DELETE SET NULL
                    )"
                    : "CREATE TABLE receipts (
                        id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, loan_repayment_id INTEGER,
                        receipt_number TEXT NOT NULL UNIQUE, amount REAL NOT NULL,
                        receipt_type TEXT CHECK(receipt_type IN ('transaction','loan_repayment')) NOT NULL,
                        generated_by INTEGER, generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
                        FOREIGN KEY(loan_repayment_id) REFERENCES loan_repayments(id) ON DELETE SET NULL,
                        FOREIGN KEY(generated_by) REFERENCES staff(id) ON DELETE RESTRICT
                    )";
                $db->exec($receipt_ddl);
                $rcols = $has_admin_receipt
                    ? ['id','transaction_id','loan_repayment_id','receipt_number','amount','receipt_type','generated_by','generated_by_admin','generated_at']
                    : ['id','transaction_id','loan_repayment_id','receipt_number','amount','receipt_type','generated_by','generated_at'];
                $rins = $db->prepare('INSERT INTO receipts (' . implode(',', $rcols) . ') VALUES (' . implode(',', array_fill(0, count($rcols), '?')) . ')');
                foreach ($receipt_rows as $row) {
                    $values = [];
                    foreach ($rcols as $column) $values[] = $row[$column] ?? null;
                    $rins->execute($values);
                }
                foreach ($receipt_objects as $sql) $db->exec($sql);
            }

            if (!self::tableExists($db, 'loan_auto_deductions')) {
                $db->exec("CREATE TABLE loan_auto_deductions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    loan_id INTEGER NOT NULL,
                    period_month TEXT NOT NULL,
                    due_on DATE NOT NULL,
                    processed_date DATE NOT NULL,
                    amount_taken REAL NOT NULL,
                    amount_owed_after REAL NOT NULL,
                    transaction_id INTEGER,
                    repayment_id INTEGER,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(loan_id, period_month),
                    FOREIGN KEY(loan_id) REFERENCES loans(id) ON DELETE CASCADE,
                    FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
                    FOREIGN KEY(repayment_id) REFERENCES loan_repayments(id) ON DELETE SET NULL
                )");
                $result['deductions_table_created'] = true;
            }

            $db->exec('CREATE INDEX IF NOT EXISTS idx_transaction_customer ON transactions(customer_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_transaction_date ON transactions(created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_repayment_loan ON loan_repayments(loan_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_auto_deduction_loan_month ON loan_auto_deductions(loan_id, period_month)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_loan_repayment_source ON loan_repayments(source)');

            $violations = $db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
            if ($violations) throw new RuntimeException('Foreign-key check failed: ' . json_encode($violations));
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        } finally {
            $db->exec('PRAGMA foreign_keys = ' . ($foreign_keys_were_enabled ? 'ON' : 'OFF'));
        }
        return $result;
    }
}
