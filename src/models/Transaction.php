<?php
/**
 * Transaction Model
 * Extol Global Service
 * Supports both MySQLi and SQLite
 */

class Transaction {
    private $conn;
    private $is_pdo;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->is_pdo = ($database_connection instanceof PDO);
    }

    /** Create a deposit or withdrawal and update the customer balance atomically. */
    public function createTransactionWithDate($customer_id, $type, $amount, $description = '', $transaction_date = null, $staff_id = null, $admin_id = null) {
        return $this->createTransactionRecord($customer_id, $type, $amount, $description, $transaction_date, $staff_id, $admin_id);
    }

    /** Create a deposit or withdrawal without backdating. */
    public function createTransaction($customer_id, $type, $amount, $description = '', $staff_id = null, $admin_id = null) {
        return $this->createTransactionRecord($customer_id, $type, $amount, $description, null, $staff_id, $admin_id);
    }

    private function createTransactionRecord($customer_id, $type, $amount, $description, $transaction_date, $staff_id, $admin_id) {
        $customer_id = (int)$customer_id;
        $amount = round((float)$amount, 2);
        if ($customer_id <= 0 || !in_array($type, ['deposit', 'withdrawal'], true)) {
            return ['success' => false, 'message' => 'Invalid customer or transaction type'];
        }
        if (!is_finite($amount) || $amount <= 0) {
            return ['success' => false, 'message' => 'Amount must be positive'];
        }
        $staff_id = $staff_id === null ? null : (int)$staff_id;
        $admin_id = $admin_id === null ? null : (int)$admin_id;
        if (($admin_id !== null && $admin_id <= 0) || ($staff_id !== null && $staff_id <= 0)) {
            return ['success' => false, 'message' => 'Provide one valid transaction recorder'];
        }
        // Legacy databases may not have admin_id yet; treat both NULL as admin-recorded (legacy).
        if ($staff_id === null && $admin_id === null) {
            $staff_id = null;
            $admin_id = null;
        } elseif ($staff_id !== null && $admin_id !== null) {
            return ['success' => false, 'message' => 'Provide one valid transaction recorder'];
        }
        if ($transaction_date === null || $transaction_date === '') {
            $transaction_date = date('Y-m-d H:i:s');
        }
        $started = false;

        try {
            $reference_code = 'TXN-' . strtoupper(bin2hex(random_bytes(8)));
            if ($this->is_pdo) {
                $this->conn->beginTransaction();
            } else {
                $this->conn->begin_transaction();
            }
            $started = true;
            $has_admin_column = $this->is_pdo
                ? in_array('admin_id', array_column($this->conn->query("PRAGMA table_info(transactions)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
                : true;
            $query = $has_admin_column
                ? "INSERT INTO transactions (customer_id, staff_id, admin_id, type, amount, reference_code, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                : "INSERT INTO transactions (customer_id, staff_id, type, amount, reference_code, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $values = $has_admin_column
                ? [$customer_id, $staff_id, $admin_id, $type, $amount, $reference_code, $description, $transaction_date]
                : [$customer_id, $staff_id, $type, $amount, $reference_code, $description, $transaction_date];
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($query);
                $stmt->execute($values);
                $has_balance_column = in_array('balance', array_column($this->conn->query("PRAGMA table_info(customers)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
                if ($has_balance_column) {
                    $update = $this->conn->prepare('UPDATE customers SET balance = balance + ? WHERE id = ?');
                    $update->execute([$type === 'deposit' ? $amount : -$amount, $customer_id]);
                } else {
                    $update = $this->conn->prepare('SELECT id FROM customers WHERE id = ?');
                    $update->execute([$customer_id]);
                    $update->fetchColumn();
                }
                if ($update->rowCount() !== 1) throw new RuntimeException('Customer not found');
                $this->conn->commit();
            } else {
                $types = $has_admin_column ? 'iiisdsss' : 'iisdsss';
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param($types, ...$values);
                $stmt->execute();
                $balance_change = $type === 'deposit' ? $amount : -$amount;
                $update = $this->conn->prepare('UPDATE customers SET balance = balance + ? WHERE id = ?');
                $update->bind_param('di', $balance_change, $customer_id);
                $update->execute();
                if ($update->affected_rows !== 1) throw new RuntimeException('Customer not found');
                $this->conn->commit();
            }
            $started = false;
            return ['success' => true, 'message' => 'Transaction created', 'reference_code' => $reference_code];
        } catch (Throwable $e) {
            if ($started) {
                if ($this->is_pdo) $this->conn->rollBack(); else $this->conn->rollback();
            }
            return ['success' => false, 'message' => 'Transaction failed. Please verify the details and try again.'];
        }
    }

    public function getCustomerTransactions($customer_id, $limit = 15, $offset = 0) {
        $query = "SELECT id, type, amount, reference_code, description, created_at FROM transactions
                  WHERE customer_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([(int)$customer_id, (int)$limit, (int)$offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $stmt = $this->conn->prepare($query);
        $customer_id = (int)$customer_id; $limit = (int)$limit; $offset = (int)$offset;
        $stmt->bind_param('iii', $customer_id, $limit, $offset);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getAllTransactions($limit = 15, $offset = 0, $type = null, $date_from = null, $date_to = null) {
        $where = [];
        $params = [];
        $types = '';
        if ($type !== null && $type !== '' && !in_array($type, ['deposit', 'withdrawal', 'loan_repayment'], true)) $type = null;
        if (in_array($type, ['deposit', 'withdrawal', 'loan_repayment'], true)) {
            $where[] = 't.type = ?'; $params[] = $type; $types .= 's';
        }
        if ($date_from) {
            $where[] = 'DATE(t.created_at) >= ?'; $params[] = $date_from; $types .= 's';
        }
        if ($date_to) {
            $where[] = 'DATE(t.created_at) <= ?'; $params[] = $date_to; $types .= 's';
        }
        $has_admin_column = $this->is_pdo
            ? in_array('admin_id', array_column($this->conn->query("PRAGMA table_info(transactions)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
            : true;
        $query = $has_admin_column
            ? "SELECT t.id,t.customer_id,c.name,t.staff_id,s.name AS staff_name,s.staff_id AS staff_code,t.admin_id,a.name AS admin_name,t.type,t.amount,t.reference_code,t.description,t.created_at FROM transactions t JOIN customers c ON t.customer_id=c.id LEFT JOIN staff s ON t.staff_id=s.id LEFT JOIN customers a ON t.admin_id=a.id"
            : "SELECT t.id,t.customer_id,c.name,t.staff_id,s.name AS staff_name,s.staff_id AS staff_code,NULL AS admin_id,NULL AS admin_name,t.type,t.amount,t.reference_code,t.description,t.created_at FROM transactions t JOIN customers c ON t.customer_id=c.id LEFT JOIN staff s ON t.staff_id=s.id";
        if ($where) $query .= ' WHERE ' . implode(' AND ', $where);
        $query .= ' ORDER BY t.created_at DESC LIMIT ? OFFSET ?';
        $params[] = (int)$limit; $params[] = (int)$offset; $types .= 'ii';
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query); $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $stmt = $this->conn->prepare($query); $stmt->bind_param($types, ...$params); $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function countTransactions($type = null, $date_from = null, $date_to = null) {
        [$where, $params, $types] = $this->transactionFilters($type, $date_from, $date_to);
        $query = 'SELECT COUNT(*) FROM transactions t' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query); $stmt->execute($params); return (int)$stmt->fetchColumn();
        }
        $stmt = $this->conn->prepare($query);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute(); return (int)$stmt->get_result()->fetch_row()[0];
    }

    public function getFilteredTransactionStats($type = null, $date_from = null, $date_to = null) {
        [$where, $params, $types] = $this->transactionFilters($type, $date_from, $date_to);
        $query = "SELECT COUNT(*) AS total_count,
                    COALESCE(SUM(CASE WHEN t.type='deposit' THEN t.amount ELSE 0 END),0) AS total_deposits,
                    COALESCE(SUM(CASE WHEN t.type='withdrawal' THEN t.amount ELSE 0 END),0) AS total_withdrawals,
                    COALESCE(SUM(CASE WHEN t.type='loan_repayment' THEN t.amount ELSE 0 END),0) AS total_loan_repayments
                  FROM transactions t" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query); $stmt->execute($params); return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $stmt = $this->conn->prepare($query);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute(); return $stmt->get_result()->fetch_assoc();
    }

    private function transactionFilters($type, $date_from, $date_to) {
        $where = []; $params = []; $types = '';
        if ($type !== null && $type !== '' && !in_array($type, ['deposit', 'withdrawal', 'loan_repayment'], true)) $type = null;
        if (in_array($type, ['deposit', 'withdrawal', 'loan_repayment'], true)) { $where[] = 't.type = ?'; $params[] = $type; $types .= 's'; }
        foreach ([['DATE(t.created_at) >= ?', $date_from], ['DATE(t.created_at) <= ?', $date_to]] as [$condition, $value]) {
            if ($value) { $where[] = $condition; $params[] = $value; $types .= 's'; }
        }
        return [$where, $params, $types];
    }

    public function getCustomerTransactionCount($customer_id) {
        $query = 'SELECT COUNT(*) FROM transactions WHERE customer_id = ?';
        if ($this->is_pdo) { $stmt = $this->conn->prepare($query); $stmt->execute([(int)$customer_id]); return (int)$stmt->fetchColumn(); }
        $stmt = $this->conn->prepare($query); $customer_id = (int)$customer_id; $stmt->bind_param('i', $customer_id); $stmt->execute(); return (int)$stmt->get_result()->fetch_row()[0];
    }

    public function getTransactionSummary($customer_id) {
        $query = "SELECT COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END),0) AS total_deposits,
                         COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END),0) AS total_withdrawals,
                         COALESCE(SUM(CASE WHEN type='loan_repayment' THEN amount ELSE 0 END),0) AS total_loan_repayments,
                         COUNT(*) AS total_transactions FROM transactions WHERE customer_id = ?";
        if ($this->is_pdo) { $stmt = $this->conn->prepare($query); $stmt->execute([(int)$customer_id]); return $stmt->fetch(PDO::FETCH_ASSOC); }
        $stmt = $this->conn->prepare($query); $customer_id = (int)$customer_id; $stmt->bind_param('i', $customer_id); $stmt->execute(); return $stmt->get_result()->fetch_assoc();
    }

    public function getSystemStats() {
        $query = "SELECT COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END),0) AS total_deposits,
                         COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END),0) AS total_withdrawals,
                         COALESCE(SUM(CASE WHEN type='loan_repayment' THEN amount ELSE 0 END),0) AS total_loan_repayments,
                         COUNT(*) AS total_transactions FROM transactions";
        $row = $this->conn->query($query);
        return $this->is_pdo ? $row->fetch(PDO::FETCH_ASSOC) : $row->fetch_assoc();
    }

    public function getDailyTotals($days = 30) {
        $days = max(1, (int)$days);
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare("SELECT DATE(created_at) AS transaction_date,
                    SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END) AS daily_deposits,
                    SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END) AS daily_withdrawals, COUNT(*) AS transaction_count
                    FROM transactions WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY transaction_date DESC");
            $stmt->execute([date('Y-m-d H:i:s', strtotime("-{$days} days"))]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $stmt = $this->conn->prepare("SELECT DATE(created_at) AS transaction_date,
                SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END) AS daily_deposits,
                SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END) AS daily_withdrawals, COUNT(*) AS transaction_count
                FROM transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at) ORDER BY transaction_date DESC");
        $stmt->bind_param('i', $days); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getTransactionsByDate($date) {
        $has_admin_column = $this->is_pdo
            ? in_array('admin_id', array_column($this->conn->query("PRAGMA table_info(transactions)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
            : true;
        $query = $has_admin_column
            ? "SELECT t.id,t.customer_id,t.staff_id,t.admin_id,c.name AS customer_name,t.type,t.amount,t.reference_code,t.description,t.created_at,s.name AS staff_name,a.name AS admin_name FROM transactions t JOIN customers c ON t.customer_id=c.id LEFT JOIN staff s ON t.staff_id=s.id LEFT JOIN customers a ON t.admin_id=a.id WHERE DATE(t.created_at)=? ORDER BY t.created_at DESC"
            : "SELECT t.id,t.customer_id,t.staff_id,NULL AS admin_id,c.name AS customer_name,t.type,t.amount,t.reference_code,t.description,t.created_at,s.name AS staff_name,NULL AS admin_name FROM transactions t JOIN customers c ON t.customer_id=c.id LEFT JOIN staff s ON t.staff_id=s.id WHERE DATE(t.created_at)=? ORDER BY t.created_at DESC";
        if ($this->is_pdo) { $stmt = $this->conn->prepare($query); $stmt->execute([$date]); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
        $stmt = $this->conn->prepare($query); $stmt->bind_param('s', $date); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getDateTotals($date) {
        $query = "SELECT COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END),0) AS total_deposits,
                         COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END),0) AS total_withdrawals, COUNT(*) AS total_count
                  FROM transactions WHERE DATE(created_at)=?";
        if ($this->is_pdo) { $stmt = $this->conn->prepare($query); $stmt->execute([$date]); return $stmt->fetch(PDO::FETCH_ASSOC); }
        $stmt = $this->conn->prepare($query); $stmt->bind_param('s', $date); $stmt->execute(); return $stmt->get_result()->fetch_assoc();
    }

    /** Legacy update retained for backwards compatibility; admin UI does not expose editing. */
    public function editTransaction($transaction_id, $amount, $description) {
        return ['success' => false, 'message' => 'Transaction editing is disabled until balance reconciliation and audit logging are implemented'];
    }

    public function getTransactionDetail($transaction_id) {
        $has_admin_column = $this->is_pdo
            ? in_array('admin_id', array_column($this->conn->query("PRAGMA table_info(transactions)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
            : true;
        $query = $has_admin_column
            ? "SELECT t.id,t.customer_id,c.name AS customer_name,t.staff_id,s.name AS staff_name,t.admin_id,a.name AS admin_name,t.type,t.amount,t.reference_code,t.description,t.created_at,t.updated_at FROM transactions t JOIN customers c ON t.customer_id=c.id LEFT JOIN staff s ON t.staff_id=s.id LEFT JOIN customers a ON t.admin_id=a.id WHERE t.id=?"
            : "SELECT t.id,t.customer_id,c.name AS customer_name,t.staff_id,s.name AS staff_name,NULL AS admin_id,NULL AS admin_name,t.type,t.amount,t.reference_code,t.description,t.created_at,t.updated_at FROM transactions t JOIN customers c ON t.customer_id=c.id LEFT JOIN staff s ON t.staff_id=s.id WHERE t.id=?";
        if ($this->is_pdo) { $stmt = $this->conn->prepare($query); $stmt->execute([(int)$transaction_id]); return $stmt->fetch(PDO::FETCH_ASSOC); }
        $stmt = $this->conn->prepare($query); $transaction_id = (int)$transaction_id; $stmt->bind_param('i', $transaction_id); $stmt->execute(); return $stmt->get_result()->fetch_assoc();
    }

    public function getAllStaffTransactions($limit = 20, $offset = 0, $staff_id = null, $date_from = null, $date_to = null, $type = null) {
        $where = ['t.staff_id IS NOT NULL']; $params = []; $types = '';
        if ($type !== null && $type !== '' && !in_array($type, ['deposit','withdrawal'], true)) $type = null;
        if ($staff_id) { $where[] = 't.staff_id = ?'; $params[] = (int)$staff_id; $types .= 'i'; }
        if ($date_from) { $where[] = 'DATE(t.created_at) >= ?'; $params[] = $date_from; $types .= 's'; }
        if ($date_to) { $where[] = 'DATE(t.created_at) <= ?'; $params[] = $date_to; $types .= 's'; }
        if (in_array($type, ['deposit','withdrawal'], true)) { $where[] = 't.type = ?'; $params[] = $type; $types .= 's'; }
        $query = "SELECT t.id,t.customer_id,c.name AS customer_name,t.type,t.amount,t.reference_code,t.description,t.created_at,
                         s.name AS staff_name,s.staff_id AS staff_code FROM transactions t JOIN customers c ON c.id=t.customer_id
                  LEFT JOIN staff s ON s.id=t.staff_id WHERE " . implode(' AND ', $where) . ' ORDER BY t.created_at DESC LIMIT ? OFFSET ?';
        $params[] = (int)$limit; $params[] = (int)$offset; $types .= 'ii';
        if ($this->is_pdo) { $stmt = $this->conn->prepare($query); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
        $stmt = $this->conn->prepare($query); $stmt->bind_param($types, ...$params); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function countAllStaffTransactions($staff_id = null, $date_from = null, $date_to = null, $type = null) {
        $where = ['t.staff_id IS NOT NULL']; $params = []; $types = '';
        if ($type !== null && $type !== '' && !in_array($type, ['deposit','withdrawal'], true)) $type = null;
        if ($staff_id) { $where[] = 't.staff_id = ?'; $params[] = (int)$staff_id; $types .= 'i'; }
        if ($date_from) { $where[] = 'DATE(t.created_at) >= ?'; $params[] = $date_from; $types .= 's'; }
        if ($date_to) { $where[] = 'DATE(t.created_at) <= ?'; $params[] = $date_to; $types .= 's'; }
        if (in_array($type, ['deposit','withdrawal'], true)) { $where[] = 't.type = ?'; $params[] = $type; $types .= 's'; }
        $query = 'SELECT COUNT(*) FROM transactions t WHERE ' . implode(' AND ', $where);
        if ($this->is_pdo) { $stmt = $this->conn->prepare($query); $stmt->execute($params); return (int)$stmt->fetchColumn(); }
        $stmt = $this->conn->prepare($query); if ($params) $stmt->bind_param($types, ...$params); $stmt->execute(); return (int)$stmt->get_result()->fetch_row()[0];
    }
}
?>
