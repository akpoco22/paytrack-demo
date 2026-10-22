<?php
/**
 * Loan Application Model
 * Extol Global Service
 * Supports both MySQLi and SQLite
 *
 * Handles customer-initiated loan applications.
 * Flow:
 *   1. Customer applies via /public/user/apply-loan.php (gated on approved KYC).
 *   2. Status is 'pending'.
 *   3. Admin reviews in /public/admin/loan-applications.php — approves (creates real loan)
 *      or rejects (with reason).
 */

class LoanApplication {
    private $conn;
    private $is_pdo;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->is_pdo = ($database_connection instanceof PDO);
    }

    /**
     * Submit new loan application (customer side)
     */
    public function apply($customer_id, $loan_amount, $interest_rate = 5.0, $repayment_frequency = 'daily', $purpose = '') {
        $loan_amount = (float)$loan_amount;
        $interest_rate = (float)$interest_rate;
        if ($loan_amount <= 0) {
            return ['success' => false, 'message' => 'Loan amount must be greater than zero'];
        }
        if ($interest_rate < 0) {
            return ['success' => false, 'message' => 'Interest rate cannot be negative'];
        }

        // Must have approved KYC
        if (!$this->hasApprovedKYC($customer_id)) {
            return ['success' => false, 'message' => 'Your KYC must be approved before applying for a loan'];
        }

        // Prevent multiple pending applications
        if ($this->hasPendingApplication($customer_id)) {
            return ['success' => false, 'message' => 'You already have a loan application under review'];
        }

        // One loan at a time: no new application until the open loan is fully repaid
        if ($this->hasOpenLoan($customer_id)) {
            return ['success' => false, 'message' => 'You have an unpaid loan. Repay it in full before applying for another.'];
        }

        $query = "INSERT INTO loan_applications (customer_id, loan_amount, interest_rate, repayment_frequency, purpose, status)
                  VALUES (?, ?, ?, ?, ?, 'pending')";

        try {
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($query);
                $result = $stmt->execute([$customer_id, $loan_amount, $interest_rate, $repayment_frequency, $purpose]);
            } else {
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("iddss", $customer_id, $loan_amount, $interest_rate, $repayment_frequency, $purpose);
                $result = $stmt->execute();
            }

            if ($result) {
                $app_id = $this->is_pdo ? $this->conn->lastInsertId() : $this->conn->insert_id;
                return ['success' => true, 'message' => 'Loan application submitted successfully', 'application_id' => $app_id];
            }
            return ['success' => false, 'message' => 'Failed to submit application'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Check if customer has approved KYC
     */
    public function hasApprovedKYC($customer_id) {
        $query = "SELECT id FROM kyc_records WHERE customer_id = ? AND verification_status = 'approved'";
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$customer_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            return $stmt->get_result()->num_rows > 0;
        }
    }

    /**
     * Check for pending application
     */
    public function hasPendingApplication($customer_id) {
        $query = "SELECT id FROM loan_applications WHERE customer_id = ? AND status = 'pending'";
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$customer_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            return $stmt->get_result()->num_rows > 0;
        }
    }

    /**
     * Check for an active or overdue loan (customers may hold only one at a time)
     */
    public function hasOpenLoan($customer_id) {
        $query = "SELECT id FROM loans WHERE customer_id = ? AND status IN ('active', 'overdue', 'default')";
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$customer_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            return $stmt->get_result()->num_rows > 0;
        }
    }

    /**
     * Get application by ID
     */
    public function getById($id) {
        $query = "SELECT a.*, c.name as customer_name, c.phone_number, c.email, c.card_id,
                         k.bvn, k.nin, k.guarantor_name, k.guarantor_phone, k.guarantor_address, k.collateral
                  FROM loan_applications a
                  JOIN customers c ON a.customer_id = c.id
                  LEFT JOIN kyc_records k ON k.customer_id = c.id
                  WHERE a.id = ?";
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc();
        }
    }

    /**
     * Get applications for a customer
     */
    public function getCustomerApplications($customer_id, $limit = 10) {
        $query = "SELECT * FROM loan_applications WHERE customer_id = ? ORDER BY created_at DESC LIMIT ?";
        if ($this->is_pdo) {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(1, (int)$customer_id, PDO::PARAM_INT);
            $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $customer_id, $limit);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    /**
     * Get applications by status with pagination (admin view, 1000-user safe)
     */
    public function getApplicationsByStatus($status = 'all', $limit = 15, $offset = 0) {
        $allowed = ['pending', 'approved', 'rejected'];
        $where = '';
        $params = [];
        if (in_array($status, $allowed, true)) {
            $where = "WHERE a.status = ?";
            $params[] = $status;
        }
        $query = "SELECT a.*, c.name as customer_name, c.phone_number, c.email
                  FROM loan_applications a
                  JOIN customers c ON a.customer_id = c.id
                  $where
                  ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
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
     * Count applications by status
     */
    public function countByStatus($status = 'all') {
        $allowed = ['pending', 'approved', 'rejected'];
        if (in_array($status, $allowed, true)) {
            $query = "SELECT COUNT(*) as count FROM loan_applications WHERE status = ?";
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
        $query = "SELECT COUNT(*) as count FROM loan_applications";
        if ($this->is_pdo) {
            $row = $this->conn->query($query)->fetch(PDO::FETCH_ASSOC);
        } else {
            $row = $this->conn->query($query)->fetch_assoc();
        }
        return (int)($row['count'] ?? 0);
    }

    /**
     * Approve application and create the actual loan.
     *
     * Exactly one actor ID must be provided: staff or admin.
     */
    public function approve($application_id, $staff_id, $date_given, $due_date, $notes = '', $admin_id = null) {
        $app = $this->getById($application_id);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found'];
        }
        if ($app['status'] !== 'pending') {
            return ['success' => false, 'message' => 'Application has already been processed'];
        }
        $staff_id = $staff_id === null ? null : (int)$staff_id;
        $admin_id = $admin_id === null ? null : (int)$admin_id;
        if ((($staff_id === null) === ($admin_id === null)) || ($staff_id !== null && $staff_id <= 0) || ($admin_id !== null && $admin_id <= 0)) {
            return ['success' => false, 'message' => 'A valid staff or admin reviewer must be specified'];
        }

        $gname  = $app['guarantor_name'] ?? '';
        $gphone = $app['guarantor_phone'] ?? '';
        $gaddr  = $app['guarantor_address'] ?? '';
        $collat = $app['collateral'] ?? '';

        try {
            $has_admin_app = $this->is_pdo
                ? in_array('reviewed_by_admin', array_column($this->conn->query("PRAGMA table_info(loan_applications)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
                : true;
            $has_admin_loan = $this->is_pdo
                ? in_array('created_by_admin', array_column($this->conn->query("PRAGMA table_info(loans)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
                : true;

            // If the database has not been migrated to have created_by_admin yet, loans.created_by requires an active staff ID.
            $fallback_staff_id = $staff_id;
            if (!$has_admin_loan && empty($fallback_staff_id)) {
                $staff_row = $this->is_pdo
                    ? $this->conn->query("SELECT id FROM staff WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC)
                    : $this->conn->query("SELECT id FROM staff WHERE is_active = 1 LIMIT 1")->fetch_assoc();
                $fallback_staff_id = $staff_row['id'] ?? null;
                if (!$fallback_staff_id) {
                    return ['success' => false, 'message' => 'No active staff member found to attribute the loan to'];
                }
            }

            if ($this->is_pdo) {
                $this->conn->beginTransaction();
                if ($this->hasOpenLoan($app['customer_id'])) {
                    throw new RuntimeException('Customer already has an unpaid loan');
                }

                if ($has_admin_app) {
                    $up = "UPDATE loan_applications
                           SET status = 'approved', reviewed_by = ?, reviewed_by_admin = ?, reviewed_at = datetime('now'), notes = ?
                           WHERE id = ?";
                    $stmt = $this->conn->prepare($up);
                    $stmt->execute([$staff_id, $admin_id, $notes, $application_id]);
                } else {
                    $up = "UPDATE loan_applications
                           SET status = 'approved', reviewed_by = ?, reviewed_at = datetime('now'), notes = ?
                           WHERE id = ?";
                    $stmt = $this->conn->prepare($up);
                    $stmt->execute([$fallback_staff_id, $notes, $application_id]);
                }
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Application is no longer pending');

                if ($has_admin_loan) {
                    $insert = "INSERT INTO loans (customer_id, loan_amount, interest_rate, repayment_frequency, purpose,
                                                  guarantor_name, guarantor_phone, guarantor_address, collateral,
                                              date_given, due_date, created_by, created_by_admin, status)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                    $lstmt = $this->conn->prepare($insert);
                    $lstmt->execute([
                        $app['customer_id'],
                        $app['loan_amount'],
                        $app['interest_rate'],
                        $app['repayment_frequency'],
                        $app['purpose'],
                        $gname, $gphone, $gaddr, $collat,
                        $date_given,
                        $due_date,
                        $staff_id,
                        $admin_id
                    ]);
                } else {
                    $insert = "INSERT INTO loans (customer_id, loan_amount, interest_rate, repayment_frequency, purpose,
                                                  guarantor_name, guarantor_phone, guarantor_address, collateral,
                                              date_given, due_date, created_by, status)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                    $lstmt = $this->conn->prepare($insert);
                    $lstmt->execute([
                        $app['customer_id'],
                        $app['loan_amount'],
                        $app['interest_rate'],
                        $app['repayment_frequency'],
                        $app['purpose'],
                        $gname, $gphone, $gaddr, $collat,
                        $date_given,
                        $due_date,
                        $fallback_staff_id
                    ]);
                }

                $loan_id = $this->conn->lastInsertId();
                $this->conn->commit();
                return ['success' => true, 'message' => 'Application approved and loan created', 'loan_id' => $loan_id];
            } else {
                $this->conn->begin_transaction();
                if ($this->hasOpenLoan($app['customer_id'])) {
                    throw new RuntimeException('Customer already has an unpaid loan');
                }

                if ($has_admin_app) {
                    $up = "UPDATE loan_applications
                           SET status = 'approved', reviewed_by = ?, reviewed_by_admin = ?, reviewed_at = NOW(), notes = ?
                           WHERE id = ?";
                    $stmt = $this->conn->prepare($up);
                    $stmt->bind_param("iisi", $staff_id, $admin_id, $notes, $application_id);
                } else {
                    $up = "UPDATE loan_applications
                           SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), notes = ?
                           WHERE id = ?";
                    $stmt = $this->conn->prepare($up);
                    $stmt->bind_param("isi", $fallback_staff_id, $notes, $application_id);
                }
                $stmt->execute();

                if ($has_admin_loan) {
                    $insert = "INSERT INTO loans (customer_id, loan_amount, interest_rate, repayment_frequency, purpose,
                                                  guarantor_name, guarantor_phone, guarantor_address, collateral,
                                                  date_given, due_date, created_by, created_by_admin, status)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                    $lstmt = $this->conn->prepare($insert);
                    $lstmt->bind_param("iddssssssssii",
                        $app['customer_id'], $app['loan_amount'], $app['interest_rate'], $app['repayment_frequency'],
                        $app['purpose'], $gname, $gphone, $gaddr, $collat,
                        $date_given, $due_date, $staff_id, $admin_id
                    );
                } else {
                    $insert = "INSERT INTO loans (customer_id, loan_amount, interest_rate, repayment_frequency, purpose,
                                                  guarantor_name, guarantor_phone, guarantor_address, collateral,
                                                  date_given, due_date, created_by, status)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                    $lstmt = $this->conn->prepare($insert);
                    $lstmt->bind_param("iddssssssssi",
                        $app['customer_id'], $app['loan_amount'], $app['interest_rate'], $app['repayment_frequency'],
                        $app['purpose'], $gname, $gphone, $gaddr, $collat,
                        $date_given, $due_date, $fallback_staff_id
                    );
                }
                $lstmt->execute();
                $loan_id = $this->conn->insert_id;

                $this->conn->commit();
                return ['success' => true, 'message' => 'Application approved and loan created', 'loan_id' => $loan_id];
            }
        } catch (Exception $e) {
            if ($this->is_pdo) $this->conn->rollBack(); else $this->conn->rollback();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Reject application
     */
    public function reject($application_id, $reviewed_by, $rejection_reason = '', $reviewed_by_admin = null) {
        $reviewed_by = $reviewed_by === null ? null : (int)$reviewed_by;
        $reviewed_by_admin = $reviewed_by_admin === null ? null : (int)$reviewed_by_admin;
        if ((($reviewed_by === null) === ($reviewed_by_admin === null)) || ($reviewed_by !== null && $reviewed_by <= 0) || ($reviewed_by_admin !== null && $reviewed_by_admin <= 0)) {
            return ['success' => false, 'message' => 'A valid staff or admin reviewer must be specified'];
        }
        $has_admin_app = $this->is_pdo
            ? in_array('reviewed_by_admin', array_column($this->conn->query("PRAGMA table_info(loan_applications)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
            : true;
        if ($has_admin_app) {
            $query = $this->is_pdo
                ? "UPDATE loan_applications SET status = 'rejected', reviewed_by = ?, reviewed_by_admin = ?, reviewed_at = datetime('now'), notes = ? WHERE id = ?"
                : "UPDATE loan_applications SET status = 'rejected', reviewed_by = ?, reviewed_by_admin = ?, reviewed_at = NOW(), notes = ? WHERE id = ?";
            $params = [$reviewed_by, $reviewed_by_admin, $rejection_reason, $application_id];
        } else {
            $query = $this->is_pdo
                ? "UPDATE loan_applications SET status = 'rejected', reviewed_by = ?, reviewed_at = datetime('now'), notes = ? WHERE id = ?"
                : "UPDATE loan_applications SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), notes = ? WHERE id = ?";
            $fallback_reviewer = $reviewed_by ?: $reviewed_by_admin;
            $params = [$fallback_reviewer, $rejection_reason, $application_id];
        }
        try {
            if ($this->is_pdo) {
                $stmt = $this->conn->prepare($query);
                $result = $stmt->execute($params);
            } else {
                $stmt = $this->conn->prepare($query);
                $types = $has_admin_app ? 'iisi' : 'isi';
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->affected_rows === 1;
            }
            if ($result) {
                return ['success' => true, 'message' => 'Application rejected'];
            }
            return ['success' => false, 'message' => 'Failed to reject application'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
?>
