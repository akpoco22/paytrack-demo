<?php
/**
 * Loan Model
 * Extol Global Service
 * Supports both MySQLi and SQLite
 */

class Loan {
    private $conn;
    private $is_pdo;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->is_pdo = ($database_connection instanceof PDO);
    }

    /**
     * Create new loan (admin only) - KYC must be approved first
     */
    public function createLoan($customer_id, $loan_amount, $interest_rate, $repayment_frequency, $purpose,
                               $guarantor_name, $guarantor_phone, $guarantor_address, $collateral,
                               $date_given, $due_date, $created_by = null, $created_by_admin = null) {

        // Validate input
        if ($loan_amount <= 0 || $interest_rate < 0) {
            return ['success' => false, 'message' => 'Invalid loan amount or interest rate'];
        }

        // Enforce one open loan even for loans created directly by an admin.
        $open_query = "SELECT id FROM loans WHERE customer_id = ? AND status IN ('active', 'overdue', 'default') LIMIT 1";
        if ($this->is_pdo) {
            $open_stmt = $this->conn->prepare($open_query);
            $open_stmt->execute([(int)$customer_id]);
            $has_open_loan = $open_stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } else {
            $open_stmt = $this->conn->prepare($open_query);
            $customer_id_int = (int)$customer_id;
            $open_stmt->bind_param('i', $customer_id_int);
            $open_stmt->execute();
            $has_open_loan = $open_stmt->get_result()->num_rows > 0;
        }
        if ($has_open_loan) {
            return ['success' => false, 'message' => 'Customer already has an unpaid loan'];
        }

        // Check if customer exists and has approved KYC
        if (!$this->hasApprovedKYC($customer_id)) {
            return ['success' => false, 'message' => 'Customer must have approved KYC before loan creation'];
        }

        $created_by = $created_by === null ? null : (int)$created_by;
        $created_by_admin = $created_by_admin === null ? null : (int)$created_by_admin;
        if ($created_by !== null && $created_by <= 0) $created_by = null;
        if ($created_by_admin !== null && $created_by_admin <= 0) $created_by_admin = null;
        // Pre-migration DB has no created_by_admin — allow either actor and fall back gracefully.
        $has_admin_col = $this->is_pdo
            ? in_array('created_by_admin', array_column($this->conn->query("PRAGMA table_info(loans)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
            : true;
        if (!$has_admin_col) {
            if ($created_by === null && $created_by_admin !== null) $created_by = $created_by_admin;
            if ($created_by === null) return ['success' => false, 'message' => 'A valid creator must be specified'];
        } elseif ((($created_by === null) === ($created_by_admin === null))) {
            return ['success' => false, 'message' => 'A valid staff or admin creator must be specified'];
        }
        $use_admin_col = $has_admin_col;
        $query = $use_admin_col
            ? "INSERT INTO loans (customer_id, loan_amount, interest_rate, repayment_frequency, purpose, guarantor_name, guarantor_phone, guarantor_address, collateral, date_given, due_date, created_by, created_by_admin, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
            : "INSERT INTO loans (customer_id, loan_amount, interest_rate, repayment_frequency, purpose, guarantor_name, guarantor_phone, guarantor_address, collateral, date_given, due_date, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";

        try {
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($query);
                $result = $use_admin_col
                    ? $stmt->execute([$customer_id, $loan_amount, $interest_rate, $repayment_frequency, $purpose, $guarantor_name, $guarantor_phone, $guarantor_address, $collateral, $date_given, $due_date, $created_by, $created_by_admin])
                    : $stmt->execute([$customer_id, $loan_amount, $interest_rate, $repayment_frequency, $purpose, $guarantor_name, $guarantor_phone, $guarantor_address, $collateral, $date_given, $due_date, $created_by]);
            } else {
                $stmt = $this->conn->prepare($query);
                if ($use_admin_col) {
                    $stmt->bind_param("iddssssssssii", $customer_id, $loan_amount, $interest_rate, $repayment_frequency, $purpose, $guarantor_name, $guarantor_phone, $guarantor_address, $collateral, $date_given, $due_date, $created_by, $created_by_admin);
                } else {
                    $stmt->bind_param("idsssssssssi", $customer_id, $loan_amount, $interest_rate, $repayment_frequency, $purpose, $guarantor_name, $guarantor_phone, $guarantor_address, $collateral, $date_given, $due_date, $created_by);
                }
                $result = $stmt->execute();
            }

            if ($result) {
                return ['success' => true, 'message' => 'Loan created successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to create loan'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Check if customer has approved KYC
     */
    private function hasApprovedKYC($customer_id) {
        $query = "SELECT id FROM kyc_records WHERE customer_id = ? AND verification_status = 'approved'";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$customer_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            return $result->num_rows > 0;
        }
    }

    /**
     * Get loan by ID
     */
    public function getLoanById($loan_id) {
        $has_admin = $this->is_pdo
            ? in_array('created_by_admin', array_column($this->conn->query("PRAGMA table_info(loans)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
            : true;
        $query = $has_admin
            ? "SELECT l.*, creator.name AS admin_creator_name, c.name as customer_name, c.phone_number, c.phone_number as customer_phone, c.email as customer_email FROM loans l JOIN customers c ON l.customer_id=c.id LEFT JOIN customers creator ON creator.id=l.created_by_admin WHERE l.id=?"
            : "SELECT l.*, NULL AS admin_creator_name, c.name as customer_name, c.phone_number, c.phone_number as customer_phone, c.email as customer_email FROM loans l JOIN customers c ON l.customer_id=c.id WHERE l.id=?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$loan_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $loan_id);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc();
        }
    }

    /**
     * Get all loans for customer
     */
    public function getCustomerLoans($customer_id, $limit = 15, $offset = 0) {
        $query = "SELECT id, loan_amount, interest_rate, date_given, due_date, status, total_repaid, interest_charged
                  FROM loans WHERE customer_id = ?
                  ORDER BY date_given DESC LIMIT ? OFFSET ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$customer_id, $limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iii", $customer_id, $limit, $offset);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Get all loans (admin)
     */
    public function getAllLoans($limit = 15, $offset = 0) {
        $query = "SELECT l.id, l.customer_id, c.name as customer_name, l.loan_amount, l.interest_rate,
                         c.email as customer_email,
                         l.date_given, l.due_date, l.status, l.total_repaid
                  FROM loans l
                  JOIN customers c ON l.customer_id = c.id
                  ORDER BY l.date_given DESC LIMIT ? OFFSET ?";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $limit, $offset);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Get overdue loans
     */
    public function getOverdueLoans() {
        $today = date('Y-m-d');

        if ($this->is_pdo) {
            $query = "SELECT l.id, l.customer_id, c.name as customer_name, l.loan_amount, l.due_date,
                             l.status, l.total_repaid
                      FROM loans l
                      JOIN customers c ON l.customer_id = c.id
                      WHERE l.due_date < ? AND l.status IN ('active', 'overdue')
                      ORDER BY l.due_date ASC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$today]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $query = "SELECT l.id, l.customer_id, c.name as customer_name, l.loan_amount, l.due_date,
                             l.status, l.total_repaid
                      FROM loans l
                      JOIN customers c ON l.customer_id = c.id
                      WHERE l.due_date < CURDATE() AND l.status IN ('active', 'overdue')
                      ORDER BY l.due_date ASC";
            $result = $this->conn->query($query);
            return $result->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Record a repayment against an active or overdue loan.
     * Amount is the total collected; principal and interest must add up to it.
     */
    public function recordRepayment($loan_id, $amount, $interest_paid, $principal_paid, $repayment_date, $recorded_by = null, $notes = '', $recorded_by_admin = null) {
        $loan_id = (int)$loan_id;
        $recorded_by = $recorded_by === null ? null : (int)$recorded_by;
        $recorded_by_admin = $recorded_by_admin === null ? null : (int)$recorded_by_admin;
        if ($recorded_by !== null && $recorded_by <= 0) $recorded_by = null;
        if ($recorded_by_admin !== null && $recorded_by_admin <= 0) $recorded_by_admin = null;
        $has_admin_col = $this->is_pdo
            ? in_array('recorded_by_admin', array_column($this->conn->query("PRAGMA table_info(loan_repayments)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
            : true;
        if (!$has_admin_col) {
            if ($recorded_by === null && $recorded_by_admin !== null) $recorded_by = $recorded_by_admin;
            if ($recorded_by === null) return ['success' => false, 'message' => 'A valid recorder must be specified'];
        } elseif ((($recorded_by === null) === ($recorded_by_admin === null))) {
            return ['success' => false, 'message' => 'A valid staff or admin recorder must be specified'];
        }
        $amount = round((float)$amount, 2);
        $interest_paid = round((float)$interest_paid, 2);
        $principal_paid = round((float)$principal_paid, 2);
        $repayment_date = (string)$repayment_date;

        if ($loan_id <= 0 || !is_finite($amount) || !is_finite($interest_paid) || !is_finite($principal_paid)
            || $amount <= 0 || $interest_paid < 0 || $principal_paid < 0 || ($recorded_by !== null && $recorded_by <= 0)
            || ($recorded_by_admin !== null && $recorded_by_admin <= 0)) {
            return ['success' => false, 'message' => 'Enter a valid loan and non-negative repayment amounts'];
        }
        if ((int)round($amount * 100) !== (int)round(($interest_paid + $principal_paid) * 100)) {
            return ['success' => false, 'message' => 'Total amount must equal principal plus interest'];
        }
        $date = DateTime::createFromFormat('!Y-m-d', $repayment_date);
        if (!$date || $date->format('Y-m-d') !== $repayment_date) {
            return ['success' => false, 'message' => 'Enter a valid repayment date'];
        }

        $started = false;

        try {
            $reference_code = 'REPM-' . strtoupper(bin2hex(random_bytes(6)));
            if ($this->is_pdo) {
                $this->conn->beginTransaction();
            } else {
                $this->conn->begin_transaction();
            }
            $started = true;

            // Conditional update makes eligibility and balance checks atomic with the write.
            $update_query = "UPDATE loans
                             SET status = CASE WHEN ROUND(total_repaid + ?, 2) >= ROUND(loan_amount, 2) THEN 'completed' ELSE status END,
                                 total_repaid = ROUND(total_repaid + ?, 2),
                                 interest_charged = ROUND(interest_charged + ?, 2)
                             WHERE id = ? AND status IN ('active', 'overdue')
                               AND ROUND(total_repaid + ?, 2) <= ROUND(loan_amount, 2)";
            if ($this->is_pdo) {
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->execute([$principal_paid, $principal_paid, $interest_paid, $loan_id, $principal_paid]);
                $updated = $update_stmt->rowCount();
            } else {
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->bind_param("dddid", $principal_paid, $principal_paid, $interest_paid, $loan_id, $principal_paid);
                $update_stmt->execute();
                $updated = $update_stmt->affected_rows;
            }

            if ($updated !== 1) {
                $check_query = "SELECT status, loan_amount, total_repaid FROM loans WHERE id = ?";
                if ($this->is_pdo) {
                    $check_stmt = $this->conn->prepare($check_query);
                    $check_stmt->execute([$loan_id]);
                    $loan = $check_stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $check_stmt = $this->conn->prepare($check_query);
                    $check_stmt->bind_param("i", $loan_id);
                    $check_stmt->execute();
                    $loan = $check_stmt->get_result()->fetch_assoc();
                }
                if ($this->is_pdo) {
                    $this->conn->rollBack();
                } else {
                    $this->conn->rollback();
                }
                $started = false;

                if (!$loan) {
                    return ['success' => false, 'message' => 'Loan not found'];
                }
                if (!in_array($loan['status'], ['active', 'overdue'], true)) {
                    return ['success' => false, 'message' => 'Repayments are only accepted for active or overdue loans'];
                }
                return ['success' => false, 'message' => 'Principal paid cannot exceed the outstanding balance'];
            }

            $insert_query = "INSERT INTO loan_repayments (loan_id, amount, interest_paid, principal_paid, repayment_date, reference_code, notes, recorded_by, recorded_by_admin)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($insert_query);
                $stmt->execute([$loan_id, $amount, $interest_paid, $principal_paid, $repayment_date, $reference_code, $notes, $recorded_by, $recorded_by_admin]);
                $this->conn->commit();
            } else {
                $stmt = $this->conn->prepare($insert_query);
                $stmt->bind_param("idddsssii", $loan_id, $amount, $interest_paid, $principal_paid, $repayment_date, $reference_code, $notes, $recorded_by, $recorded_by_admin);
                $stmt->execute();
                $this->conn->commit();
            }
            $started = false;

            return ['success' => true, 'message' => 'Repayment recorded', 'reference_code' => $reference_code];
        } catch (Throwable $e) {
            if ($started) {
                if ($this->is_pdo) {
                    $this->conn->rollBack();
                } else {
                    $this->conn->rollback();
                }
            }
            return ['success' => false, 'message' => 'Unable to record repayment. Please check the details and try again.'];
        }
    }

    /**
     * Get repayment history for loan
     */
    public function getRepaymentHistory($loan_id) {
        $columns = $this->is_pdo
            ? array_column($this->conn->query("PRAGMA table_info(loan_repayments)")->fetchAll(PDO::FETCH_ASSOC), 'name')
            : [];
        $has_admin = !$this->is_pdo || in_array('recorded_by_admin', $columns, true);
        $has_source = !$this->is_pdo || in_array('source', $columns, true);
        $source_select = $has_source ? 'r.source' : "'manual' AS source";
        $admin_select = $has_admin ? 'a.name AS admin_name' : 'NULL AS admin_name';
        $admin_join = $has_admin ? ' LEFT JOIN customers a ON a.id=r.recorded_by_admin' : '';
        $query = "SELECT r.id, r.amount, r.interest_paid, r.principal_paid, r.repayment_date, r.reference_code, r.notes, r.created_at, {$source_select}, s.name AS staff_name, {$admin_select}
                  FROM loan_repayments r LEFT JOIN staff s ON s.id=r.recorded_by{$admin_join}
                  WHERE r.loan_id=? ORDER BY r.repayment_date DESC";

        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$loan_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $loan_id);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Get total count of loans
     */
    public function getTotalLoanCount() {
        $query = "SELECT COUNT(*) as count FROM loans";
        if ($this->is_pdo) {
            $result = $this->conn->query($query);
            $row = $result->fetch(PDO::FETCH_ASSOC);
        } else {
            $result = $this->conn->query($query);
            $row = $result->fetch_assoc();
        }
        return $row['count'];
    }

    /**
     * Get loans by status with real SQL pagination (1000-user safe).
     * $status: 'all', 'active', 'completed', 'overdue'
     */
    public function getLoansByStatus($status = 'all', $limit = 15, $offset = 0) {
        $allowed = ['active', 'completed', 'overdue'];
        $where = '';
        $params = [];
        if (in_array($status, $allowed, true)) {
            $where = "WHERE l.status = ?";
            $params[] = $status;
        }
        $query = "SELECT l.id, l.customer_id, c.name as customer_name, l.loan_amount, l.interest_rate,
                         c.email as customer_email,
                         l.date_given, l.due_date, l.status, l.total_repaid
                  FROM loans l
                  JOIN customers c ON l.customer_id = c.id
                  $where
                  ORDER BY l.date_given DESC LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $types = ($where ? 's' : '') . 'ii';
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Count loans by status (for pagination + tab badges).
     */
    public function getLoanCountByStatus($status = 'all') {
        $allowed = ['active', 'completed', 'overdue'];
        if (in_array($status, $allowed, true)) {
            $query = "SELECT COUNT(*) as count FROM loans WHERE status = ?";
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$status]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("s", $status);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
            }
            return (int)($row['count'] ?? 0);
        }
        return (int)$this->getTotalLoanCount();
    }

    /**
     * Staff counts: customers with outstanding loans (active/overdue),
     * loans current (active, not past due) and loans paid in full (completed).
     * Single aggregated query — no N+1, no full scan.
     */
    public function getStaffLoanCounts() {
        if ($this->is_pdo) {
            $row = $this->conn->query("SELECT
                COUNT(DISTINCT CASE WHEN l.status IN ('active','overdue') THEN l.customer_id END) as customers_with_outstanding,
                SUM(CASE WHEN l.status = 'active' AND l.due_date >= date('now') THEN 1 ELSE 0 END) as current_loans,
                SUM(CASE WHEN l.status IN ('active','overdue') THEN 1 ELSE 0 END) as outstanding_loans,
                SUM(CASE WHEN l.status = 'completed' THEN 1 ELSE 0 END) as paid_loans
              FROM loans l")->fetch(PDO::FETCH_ASSOC);
        } else {
            $row = $this->conn->query("SELECT
                COUNT(DISTINCT CASE WHEN l.status IN ('active','overdue') THEN l.customer_id END) as customers_with_outstanding,
                SUM(CASE WHEN l.status = 'active' AND l.due_date >= CURDATE() THEN 1 ELSE 0 END) as current_loans,
                SUM(CASE WHEN l.status IN ('active','overdue') THEN 1 ELSE 0 END) as outstanding_loans,
                SUM(CASE WHEN l.status = 'completed' THEN 1 ELSE 0 END) as paid_loans
              FROM loans l")->fetch_assoc();
        }
        return [
            'customers_with_outstanding' => (int)($row['customers_with_outstanding'] ?? 0),
            'current_loans'              => (int)($row['current_loans'] ?? 0),
            'outstanding_loans'          => (int)($row['outstanding_loans'] ?? 0),
            'paid_loans'                 => (int)($row['paid_loans'] ?? 0),
        ];
    }

    /**
     * Loans with each customer's running balance outstanding (admin/staff view).
     * $bucket: 'outstanding' (active/overdue), 'current' (active, not past due),
     *          'paid' (completed).
     */
    public function getLoansByBucket($bucket = 'outstanding', $limit = 15, $offset = 0) {
        $where = match($bucket) {
            'current'   => "WHERE l.status = 'active' AND l.due_date >= " . ($this->is_pdo ? "date('now')" : "CURDATE()"),
            'paid'      => "WHERE l.status = 'completed'",
            default     => "WHERE l.status IN ('active','overdue')",
        };
        $query = "SELECT l.id, l.customer_id, c.name as customer_name, c.phone_number, l.loan_amount,
                         l.interest_rate, l.repayment_frequency, l.date_given, l.due_date, l.status, l.total_repaid
                  FROM loans l
                  JOIN customers c ON l.customer_id = c.id
                  $where
                  ORDER BY l.date_given DESC LIMIT ? OFFSET ?";
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([(int)$limit, (int)$offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $limit, $offset);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Count loans in a bucket (for pagination).
     */
    public function countLoansByBucket($bucket = 'outstanding') {
        $where = match($bucket) {
            'current'   => "WHERE status = 'active' AND due_date >= " . ($this->is_pdo ? "date('now')" : "CURDATE()"),
            'paid'      => "WHERE status = 'completed'",
            default     => "WHERE status IN ('active','overdue')",
        };
        $query = "SELECT COUNT(*) as count FROM loans $where";
        if ($this->is_pdo) {
            $row = $this->conn->query($query)->fetch(PDO::FETCH_ASSOC);
        } else {
            $row = $this->conn->query($query)->fetch_assoc();
        }
        return (int)($row['count'] ?? 0);
    }
}
?>
