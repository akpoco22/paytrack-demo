# PayTrack — Savings & Loans Management System

PayTrack is a modern, production-grade progressive web application (PWA) tailored for microfinance institutions, cooperative thrift collectors, and daily savings operators. It replaces paper ledgers and fragmented records with real-time balance transparency, automated loan deductions, and audited backups.

---

## Key Features

### 1. Customer Mobile PWA
- **Universal Login:** Access via Phone Number, Card ID, or Email.
- **Real-Time Running Balance:** Instant ledger balance updates as collections occur.
- **Transaction History:** Verifiable audit codes for all deposits and withdrawals.
- **Loan Applications & Status:** Track active loans and remaining principal.
- **Installable PWA:** Add to phone home screen with offline fallback support.

### 2. Admin & Manager Portal
- **Operational Dashboard:** Real-time ledger metrics, total system deposits, and active loans.
- **Staff Management:** Role-based access for field agents (Savings vs. Loans).
- **Fast Customer Lookup:** Scalable search and pagination supporting 1,000+ customer records.
- **1-Click Loan Approvals:** Automated 5% monthly loan deduction from daily thrift collections.
- **KYC Identity Verification:** Document review workflow.
- **1-Click 9-Sheet Excel Backup:** Complete export of customers, loans, transactions, and audit trails.

### 3. Security & Architecture
- Bcrypt password hashing (`PASSWORD_BCRYPT`).
- Role-based access control with HTTP-only, SameSite-hardened session cookies.
- Prepared PDO statements preventing SQL injection.
- SQLite WAL (Write-Ahead Logging) mode with busy timeout handling.

---

## Tech Stack
- **Backend:** PHP 8.2+
- **Database:** SQLite (WAL mode, foreign keys enforced)
- **Frontend:** Vanilla JS, Tailwind CSS, Progressive Web App (Service Worker + Web App Manifest)
- **Deployment:** Docker / Apache

---

## Live Demo Credentials
- **Admin Portal:**
  - Identifier: `admin@paytrack.local`
  - Password: `Admin123!`
- **Customer Portal:**
  - Identifier: `08111111111` (or `bola@paytrack.local`)
  - Password: `CARD001`
