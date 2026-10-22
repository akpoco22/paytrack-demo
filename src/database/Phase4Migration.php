<?php
/** Phase 4 schema updates: retire savings targets, restrict staff roles, and add admin audit attribution. */
class Phase4Migration {
    private static function tableExists(PDO $db, $table) {
        $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
    private static function columns(PDO $db, $table) {
        return array_column($db->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
    private static function columnExists(PDO $db, $table, $column) {
        return in_array($column, self::columns($db, $table), true);
    }
    private static function saveObjects(PDO $db, $table) {
        $stmt = $db->prepare("SELECT type,name,sql FROM sqlite_master WHERE tbl_name=? AND type IN ('index','trigger') AND sql IS NOT NULL");
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    private static function rebuild(PDO $db, $table, $create_sql, array $columns, $transform_role = false) {
        $rows = $db->query("SELECT * FROM \"{$table}\"")->fetchAll(PDO::FETCH_ASSOC);
        $objects = self::saveObjects($db, $table);
        $new_table = $table . '_phase4_new';
        $db->exec("DROP TABLE IF EXISTS \"{$new_table}\"");
        $db->exec($create_sql);
        $insert = $db->prepare("INSERT INTO \"{$new_table}\" (" . implode(',', array_map(static fn($c) => '"' . $c . '"', $columns)) . ") VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ")");
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if ($transform_role && $column === 'role' && $value === 'savings') $value = 'loans';
                $values[] = $value;
            }
            $insert->execute($values);
        }
        $db->exec("DROP TABLE \"{$table}\"");
        $db->exec("ALTER TABLE \"{$new_table}\" RENAME TO \"{$table}\"");
        return $objects;
    }

    public static function apply(PDO $db) {
        if ($db->inTransaction()) throw new RuntimeException('Migration requires a connection with no active transaction');
        foreach (['customers','staff','transactions','loans','loan_repayments','loan_applications'] as $table) {
            if (!self::tableExists($db, $table)) throw new RuntimeException("Required table {$table} is missing; expected a Phase 3 SQLite database");
        }
        $foreign_keys_were_enabled = (int)$db->query('PRAGMA foreign_keys')->fetchColumn();
        $db->exec('PRAGMA foreign_keys = OFF');
        try {
            $db->beginTransaction();
            $objects_to_restore = [];
            // Receipts references transactions and loan_repayments; preserve it while rebuilding either parent.
            $has_receipts = self::tableExists($db, 'receipts');
            $receipt_rows = $has_receipts ? $db->query('SELECT * FROM receipts')->fetchAll(PDO::FETCH_ASSOC) : [];
            $receipt_objects = $has_receipts ? self::saveObjects($db, 'receipts') : [];
            if ($has_receipts) $db->exec('DROP TABLE receipts');

            $staff_ddl = "CREATE TABLE staff_phase4_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, phone_number TEXT NOT NULL UNIQUE,
                staff_id TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, role TEXT CHECK(role='loans') NOT NULL,
                address TEXT, gender TEXT, date_of_birth DATE, notes TEXT, is_active INTEGER DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $staff_schema = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='staff'")->fetchColumn();
            if ((int)$db->query("SELECT COUNT(*) FROM staff WHERE role <> 'loans'")->fetchColumn() > 0
                || stripos($staff_schema, "CHECK(role='loans')") === false) {
                $columns = ['id','name','phone_number','staff_id','password_hash','role','address','gender','date_of_birth','notes','is_active','created_at','updated_at'];
                $objects_to_restore = array_merge($objects_to_restore, self::rebuild($db, 'staff', $staff_ddl, $columns, true));
            }

            $transaction_ddl = "CREATE TABLE transactions_phase4_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, staff_id INTEGER, admin_id INTEGER,
                type TEXT CHECK(type IN ('deposit','withdrawal')) NOT NULL, amount REAL NOT NULL,
                reference_code TEXT NOT NULL UNIQUE, description TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                FOREIGN KEY(staff_id) REFERENCES staff(id) ON DELETE SET NULL,
                FOREIGN KEY(admin_id) REFERENCES customers(id) ON DELETE SET NULL
            )";
            if (!self::columnExists($db, 'transactions', 'admin_id')) {
                $columns = ['id','customer_id','staff_id','admin_id','type','amount','reference_code','description','created_at','updated_at'];
                $objects_to_restore = array_merge($objects_to_restore, self::rebuild($db, 'transactions', $transaction_ddl, $columns));
            }

            $loan_ddl = "CREATE TABLE loans_phase4_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, loan_amount REAL NOT NULL, interest_rate REAL NOT NULL,
                repayment_frequency TEXT, purpose TEXT, guarantor_name TEXT, guarantor_phone TEXT, guarantor_address TEXT, collateral TEXT,
                date_given DATE NOT NULL, due_date DATE NOT NULL, created_by INTEGER, created_by_admin INTEGER,
                status TEXT CHECK(status IN ('active','completed','overdue','default')) DEFAULT 'active',
                total_repaid REAL DEFAULT 0.00, interest_charged REAL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                FOREIGN KEY(created_by) REFERENCES staff(id) ON DELETE RESTRICT,
                FOREIGN KEY(created_by_admin) REFERENCES customers(id) ON DELETE SET NULL
            )";
            if (!self::columnExists($db, 'loans', 'created_by_admin')) {
                $columns = ['id','customer_id','loan_amount','interest_rate','repayment_frequency','purpose','guarantor_name','guarantor_phone','guarantor_address','collateral','date_given','due_date','created_by','created_by_admin','status','total_repaid','interest_charged','created_at','updated_at'];
                $objects_to_restore = array_merge($objects_to_restore, self::rebuild($db, 'loans', $loan_ddl, $columns));
            }

            $repayment_ddl = "CREATE TABLE loan_repayments_phase4_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT, loan_id INTEGER NOT NULL, amount REAL NOT NULL,
                interest_paid REAL DEFAULT 0.00, principal_paid REAL DEFAULT 0.00, repayment_date DATE NOT NULL,
                reference_code TEXT NOT NULL UNIQUE, notes TEXT, recorded_by INTEGER, recorded_by_admin INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(loan_id) REFERENCES loans(id) ON DELETE CASCADE,
                FOREIGN KEY(recorded_by) REFERENCES staff(id) ON DELETE RESTRICT,
                FOREIGN KEY(recorded_by_admin) REFERENCES customers(id) ON DELETE SET NULL
            )";
            if (!self::columnExists($db, 'loan_repayments', 'recorded_by_admin')) {
                $columns = ['id','loan_id','amount','interest_paid','principal_paid','repayment_date','reference_code','notes','recorded_by','recorded_by_admin','created_at'];
                $objects_to_restore = array_merge($objects_to_restore, self::rebuild($db, 'loan_repayments', $repayment_ddl, $columns));
            }

            $application_ddl = "CREATE TABLE loan_applications_phase4_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, loan_amount REAL NOT NULL,
                interest_rate REAL NOT NULL DEFAULT 5.00, repayment_frequency TEXT NOT NULL DEFAULT 'daily', purpose TEXT,
                status TEXT CHECK(status IN ('pending','approved','rejected')) DEFAULT 'pending', reviewed_by INTEGER,
                reviewed_by_admin INTEGER, reviewed_at TIMESTAMP, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                FOREIGN KEY(reviewed_by) REFERENCES staff(id) ON DELETE SET NULL,
                FOREIGN KEY(reviewed_by_admin) REFERENCES customers(id) ON DELETE SET NULL
            )";
            if (!self::columnExists($db, 'loan_applications', 'reviewed_by_admin')) {
                $columns = ['id','customer_id','loan_amount','interest_rate','repayment_frequency','purpose','status','reviewed_by','reviewed_by_admin','reviewed_at','notes','created_at','updated_at'];
                $objects_to_restore = array_merge($objects_to_restore, self::rebuild($db, 'loan_applications', $application_ddl, $columns));
            }

            if (self::tableExists($db, 'savings_targets')) $db->exec('DROP TABLE savings_targets');
            $db->exec('DROP INDEX IF EXISTS idx_target_customer');

            if ($has_receipts) {
                $db->exec("CREATE TABLE receipts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, loan_repayment_id INTEGER,
                    receipt_number TEXT NOT NULL UNIQUE, amount REAL NOT NULL,
                    receipt_type TEXT CHECK(receipt_type IN ('transaction','loan_repayment')) NOT NULL,
                    generated_by INTEGER, generated_by_admin INTEGER, generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
                    FOREIGN KEY(loan_repayment_id) REFERENCES loan_repayments(id) ON DELETE SET NULL,
                    FOREIGN KEY(generated_by) REFERENCES staff(id) ON DELETE RESTRICT,
                    FOREIGN KEY(generated_by_admin) REFERENCES customers(id) ON DELETE SET NULL
                )");
                $columns = ['id','transaction_id','loan_repayment_id','receipt_number','amount','receipt_type','generated_by','generated_by_admin','generated_at'];
                $stmt = $db->prepare('INSERT INTO receipts (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
                foreach ($receipt_rows as $row) {
                    $values = [];
                    foreach ($columns as $column) $values[] = $row[$column] ?? null;
                    $stmt->execute($values);
                }
                foreach ($receipt_objects as $object) $db->exec($object['sql']);
            }

            foreach ($objects_to_restore as $object) $db->exec($object['sql']);
            foreach ([
                'CREATE INDEX IF NOT EXISTS idx_transaction_customer ON transactions(customer_id)',
                'CREATE INDEX IF NOT EXISTS idx_transaction_staff ON transactions(staff_id)',
                'CREATE INDEX IF NOT EXISTS idx_transaction_date ON transactions(created_at)',
                'CREATE INDEX IF NOT EXISTS idx_loan_customer ON loans(customer_id)',
                'CREATE INDEX IF NOT EXISTS idx_loan_status ON loans(status)',
                'CREATE INDEX IF NOT EXISTS idx_repayment_loan ON loan_repayments(loan_id)',
                'CREATE INDEX IF NOT EXISTS idx_loan_app_customer ON loan_applications(customer_id)',
                'CREATE INDEX IF NOT EXISTS idx_loan_app_status ON loan_applications(status)',
                'CREATE INDEX IF NOT EXISTS idx_receipt_number ON receipts(receipt_number)',
            ] as $index_sql) $db->exec($index_sql);

            $violations = $db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
            if ($violations) throw new RuntimeException('Foreign-key check failed: ' . json_encode($violations));
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        } finally {
            $db->exec('PRAGMA foreign_keys = ' . ($foreign_keys_were_enabled ? 'ON' : 'OFF'));
        }
        $violations = $db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        if ($violations) throw new RuntimeException('Post-migration foreign-key check failed: ' . json_encode($violations));
        return [
            'staff_reassigned' => (int)$db->query("SELECT COUNT(*) FROM staff WHERE role='loans'")->fetchColumn(),
            'savings_table_removed' => !self::tableExists($db, 'savings_targets'),
        ];
    }
}
