-- PayTrack Bookkeeping System Database Schema (Complete)

-- Create customers table
CREATE TABLE IF NOT EXISTS customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    password_hash VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    card_id VARCHAR(50),
    address TEXT,
    gender VARCHAR(20),
    date_of_birth DATE,
    notes TEXT,
    is_admin BOOLEAN DEFAULT FALSE,
    balance DECIMAL(15, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create staff table
CREATE TABLE IF NOT EXISTS staff (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    staff_id VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role = 'loans'),
    address TEXT,
    gender VARCHAR(20),
    date_of_birth DATE,
    notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create transactions table (updated with staff tracking)
CREATE TABLE IF NOT EXISTS transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    staff_id INT,
    admin_id INT,
    type ENUM('deposit', 'withdrawal', 'loan_repayment') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    reference_code VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL,
    FOREIGN KEY (admin_id) REFERENCES customers(id) ON DELETE SET NULL
);

-- Create contact messages table
CREATE TABLE IF NOT EXISTS contact_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending', 'responded') DEFAULT 'pending',
    response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- Create KYC records table
CREATE TABLE IF NOT EXISTS kyc_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL UNIQUE,
    id_type VARCHAR(50),
    id_number VARCHAR(100),
    id_expiry DATE,
    address TEXT,
    employment_status VARCHAR(100),
    income_source TEXT,
    bank_account VARCHAR(50),
    bank_name VARCHAR(255),
    reference_contact_name VARCHAR(255),
    reference_contact_phone VARCHAR(20),
    bvn VARCHAR(50),
    nin VARCHAR(50),
    guarantor_name VARCHAR(255),
    guarantor_phone VARCHAR(20),
    guarantor_address TEXT,
    collateral TEXT,
    verification_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    verified_by INT,
    verified_at TIMESTAMP NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES staff(id) ON DELETE SET NULL
);

-- Create loans table
CREATE TABLE IF NOT EXISTS loans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    loan_amount DECIMAL(15, 2) NOT NULL,
    interest_rate DECIMAL(5, 2) NOT NULL,
    repayment_frequency VARCHAR(50),
    purpose TEXT,
    guarantor_name VARCHAR(255),
    guarantor_phone VARCHAR(20),
    guarantor_address TEXT,
    collateral TEXT,
    date_given DATE NOT NULL,
    due_date DATE NOT NULL,
    created_by INT,
    created_by_admin INT,
    status ENUM('active', 'completed', 'overdue', 'default') DEFAULT 'active',
    total_repaid DECIMAL(15, 2) DEFAULT 0.00,
    interest_charged DECIMAL(15, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES staff(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_admin) REFERENCES customers(id) ON DELETE SET NULL
);

-- Create loan repayments table
CREATE TABLE IF NOT EXISTS loan_repayments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    loan_id INT NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    interest_paid DECIMAL(15, 2) DEFAULT 0.00,
    principal_paid DECIMAL(15, 2) DEFAULT 0.00,
    repayment_date DATE NOT NULL,
    reference_code VARCHAR(50) NOT NULL UNIQUE,
    notes TEXT,
    recorded_by INT NULL,
    recorded_by_admin INT,
    source ENUM('manual','auto') NOT NULL DEFAULT 'manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES staff(id) ON DELETE RESTRICT,
    FOREIGN KEY (recorded_by_admin) REFERENCES customers(id) ON DELETE SET NULL
);

-- One automatic repayment attempt per loan and processing month.
CREATE TABLE IF NOT EXISTS loan_auto_deductions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    period_month CHAR(7) NOT NULL,
    due_on DATE NOT NULL,
    processed_date DATE NOT NULL,
    amount_taken DECIMAL(15, 2) NOT NULL,
    amount_owed_after DECIMAL(15, 2) NOT NULL,
    transaction_id INT NULL,
    repayment_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_auto_deduction_loan_period (loan_id, period_month),
    KEY idx_auto_deduction_transaction (transaction_id),
    KEY idx_auto_deduction_repayment (repayment_id),
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
    FOREIGN KEY (repayment_id) REFERENCES loan_repayments(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS loan_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    loan_amount DECIMAL(15, 2) NOT NULL,
    interest_rate DECIMAL(5, 2) NOT NULL DEFAULT 5.00,
    repayment_frequency VARCHAR(50) NOT NULL DEFAULT 'daily',
    purpose TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by INT,
    reviewed_by_admin INT,
    reviewed_at TIMESTAMP NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES staff(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by_admin) REFERENCES customers(id) ON DELETE SET NULL
);

-- Create receipts table
CREATE TABLE IF NOT EXISTS receipts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id INT,
    loan_repayment_id INT,
    receipt_number VARCHAR(50) NOT NULL UNIQUE,
    amount DECIMAL(15, 2) NOT NULL,
    receipt_type ENUM('transaction', 'loan_repayment') NOT NULL,
    generated_by INT,
    generated_by_admin INT,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
    FOREIGN KEY (loan_repayment_id) REFERENCES loan_repayments(id) ON DELETE SET NULL,
    FOREIGN KEY (generated_by) REFERENCES staff(id) ON DELETE RESTRICT,
    FOREIGN KEY (generated_by_admin) REFERENCES customers(id) ON DELETE SET NULL
);

-- Create indexes for performance
CREATE INDEX idx_customer_phone ON customers(phone_number);
CREATE INDEX idx_staff_phone ON staff(phone_number);
CREATE INDEX idx_staff_id ON staff(staff_id);
CREATE INDEX idx_transaction_customer ON transactions(customer_id);
CREATE INDEX idx_transaction_staff ON transactions(staff_id);
CREATE INDEX idx_transaction_date ON transactions(created_at);
CREATE INDEX idx_message_customer ON contact_messages(customer_id);
CREATE INDEX idx_kyc_customer ON kyc_records(customer_id);
CREATE INDEX idx_loan_customer ON loans(customer_id);
CREATE INDEX idx_loan_status ON loans(status);
CREATE INDEX idx_repayment_loan ON loan_repayments(loan_id);
CREATE INDEX idx_loan_app_customer ON loan_applications(customer_id);
CREATE INDEX idx_loan_app_status ON loan_applications(status);
CREATE INDEX idx_receipt_number ON receipts(receipt_number);
