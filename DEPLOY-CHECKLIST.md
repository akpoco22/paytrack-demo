# Extol Global Service — Deploy Checklist (Hostinger)

Date: Oct 7, 2026. Hardening + scale + Excel backup done; Phase 5 adds automatic monthly loan repayment.

## 1. Before you zip — DELETE these from the upload (keep locally)
- seed.php, migrate.php, migrate-phase*.php, setup.php, setup-sqlite.php, api-test.php, tests/
- public/admin/mail-test.php, public/admin/sms-test.php
- *.backup.*, *.db-journal, *.db-wal, *.db-shm, export-verify.xls (if any)

## 2. Upload layout (domain root, NOT a subfolder)
- public_html/ ← contents of public/ (login.php, admin/, user/, staff/, manifest.json, sw.js, icons/, logo.png, offline.php, logout.php)
- public_html/../extol-app/ ← config/, src/, includes/, cli/ (NOT web-accessible)
- .htaccess (root) ← already in project root, upload to public_html
- paytrack.db → move OUTSIDE public_html, e.g. /home/YOURUSER/extol-data/paytrack.db
- extol-data/ must also hold the WAL/SHM sidecar files; SQLite writes there.

Why: helpers.php redirects to /public/login.php, manifest start_url is
/public/login.php, sw.js uses /public/* — a subfolder deploy 404s all of these.

## 3. Hostinger env vars (hPanel → Advanced → Environment, or .user.ini)
- EXTOL_APP_URL=https://yourdomain.com
- EXTOL_APP_ENV=production
- EXTOL_DB_PATH=/home/YOURUSER/extol-data/paytrack.db
- (Mail/SMS creds stay in config/mail.php + config/sms.php on the server only —
  never commit or zip them. config/.htaccess denies web access as backup.)

## 4. On-server steps
1. Enable free SSL first (hPanel → Security) — .htaccess forces HTTPS.
2. PHP 8.1+ with pdo_sqlite + curl enabled.
3. Make the DB dir writable by PHP, file itself writable.
4. First login: admin@paytrack.local / Admin123! → change password immediately
   (profile/reset-password), then create real staff + customers.
5. Test: login → dashboard → Export page → Download Excel Backup → open in Excel.
6. Termii: fill config/sms.php with API key + Sender ID, test via SMS flow.

## 5. Phase 5: automatic monthly loan repayment
Before going live, decide and document:
- **Rate**: the app deducts 5% of outstanding principal 1 month+ after issue and monthly after that.
- **Customer impact**: on an unpaid loan, up to 5% leaves the customer balance monthly and reduces the loan.
- **Zero balance**: if the balance can’t cover it, the app takes nothing that day, and won’t retry
  until the next month (each run inside a month marks the attempt).
- **Notification**: automatic repayments send the same email/SMS as manual repayments.

Deployment:
1. Back up the DB (hPanel file manager: download paytrack.db) before migration.
2. Run migrations in SSH: `EXTOL_DB_PATH=/home/USER/extol-data/paytrack.db php migrate/phase5.php`
   (must run before the cron starts deductions; re-running is a no-op).
3. Keep the cron runner OUTSIDE public_html, e.g. /home/USER/extol-app/cli/auto-deduct.php.
4. Cron (hPanel → Advanced → Cron Jobs), daily at a quiet minute:
   `EXTOL_DB_PATH=/home/USER/extol-data/paytrack.db EXTOL_APP_ENV=production /usr/local/bin/php /home/USER/extol-app/cli/auto-deduct.php`
   Daily (not monthly) is deliberate: it retries repeatedly until the balance can cover the 5%.
5. Log: /home/USER/extol-app/logs/auto-deduct.log (auto-created, append-only, rotate by hand).

## 6. What changed this session (so you know what's new)
- Hardening: root .htaccess (blocks .db, /config, dev scripts, forces HTTPS,
  security headers) + config/.htaccess + SQLite WAL/busy_timeout + env-based
  APP_URL/APP_ENV/DB_PATH.
- Scale: server-side pagination for loans/KYC/staff (SQL WHERE+LIMIT,
  no more load-1000-then-filter), capped customer dropdowns (20) + live AJAX
  search (public/admin/customer-search.php) in loans/users modals.
- Backup: public/admin/export-backup.php (counts + 1-click download) +
  public/admin/export.php (8-sheet .xls, streams row-by-row, NO password
  hashes). Export link added to all admin navs.
- Phase 5: loan_repayment transaction type; loan_auto_deductions ledger;
  cli/auto-deduct-loans.php; monthly 5% deduction with partial collections;
  one-open-loan rule on apply, approve, and admin loan create.

## 7. Limits to know
- SQLite + WAL handles 1000 users and a handful of concurrent staff fine.
  If you grow past ~5 staff writing at the exact same second, move to MySQL
  (schema.sql is MySQL-ready; Database.php + models already speak both).
- Tailwind is CDN — the app needs internet to look right.
- SMS skips silently until the Termii key is set (by design, never breaks actions).
- The auto-deduction cron only runs while hosting is active — if the plan
  expires, deductions pause silently until it resumes.
