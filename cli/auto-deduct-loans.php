<?php
/**
 * CLI-only daily runner for automatic monthly loan repayments.
 * Keep this script and its log outside public_html on Hostinger.
 * Usage: EXTOL_DB_PATH=/absolute/path/paytrack.db php auto-deduct-loans.php [--dry-run]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$project_root = dirname(__DIR__);
$production = (getenv('EXTOL_APP_ENV') ?: 'development') === 'production';
$db_path = getenv('EXTOL_DB_PATH') ?: (getenv('DB_PATH') ?: ($production ? '' : $project_root . '/paytrack.db'));
if ($db_path === '') {
    fwrite(STDERR, "EXTOL_DB_PATH must be set for production cron runs; refusing to use a default database.\n");
    exit(2);
}
if (!is_file($db_path)) {
    fwrite(STDERR, "Database does not exist at configured path: {$db_path}\n");
    exit(2);
}

$log_dir = $project_root . '/logs';
if (!is_dir($log_dir) && !mkdir($log_dir, 0750, true) && !is_dir($log_dir)) {
    fwrite(STDERR, "Unable to create private log directory: {$log_dir}\n");
    exit(2);
}
$lock_file = fopen($log_dir . '/auto-deduct.lock', 'c');
if (!$lock_file || !flock($lock_file, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another automatic deduction run is already active.\n");
    exit(0);
}

$dry_run = in_array('--dry-run', $argv, true);
$log_path = $log_dir . '/auto-deduct.log';
$log = static function ($message) use ($log_path) {
    $line = '[' . date('Y-m-d H:i:s') . ' Africa/Lagos] ' . $message . PHP_EOL;
    file_put_contents($log_path, $line, FILE_APPEND | LOCK_EX);
    fwrite(STDOUT, $line);
};

try {
    date_default_timezone_set('Africa/Lagos');
    require_once $project_root . '/config/environment.php';
    require_once $project_root . '/config/mail.php';
    require_once $project_root . '/config/sms.php';
    require_once $project_root . '/includes/mail-notify.php';
    require_once $project_root . '/src/models/AutoDeduction.php';

    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 10000');

    $runner = new AutoDeduction($db);
    $results = $runner->runDue(new DateTimeImmutable('today'), $dry_run);
    $deducted = 0;
    $total_taken = 0.0;
    $errors = 0;
    foreach ($results as $result) {
        if ($result['status'] === 'deducted') {
            $deducted++;
            $total_taken += (float)$result['amount_taken'];
            $log(sprintf(
                'loan=%d customer=%d period=%s status=deducted amount=%.2f outstanding_after=%.2f ref=%s',
                $result['loan_id'], $result['customer_id'], $result['period_month'],
                $result['amount_taken'], $result['amount_owed_after'], $result['transaction_reference']
            ));
            // Notifications happen only after the DB commit. A notification failure never rolls back money movement.
            $customer = [
                'name' => $result['customer_name'],
                'email' => $result['email'],
                'phone_number' => $result['phone_number'],
            ];
            $notification_data = [
                'customer' => $customer,
                'amount' => $result['amount_taken'],
                'loan_amount' => $result['loan_amount'],
                'total_repaid' => $result['total_repaid'],
                'reference_code' => $result['transaction_reference'],
            ];
            $email_result = notifyCustomer('repayment', $notification_data);
            $sms_result = notifyCustomerSms('repayment', $notification_data);
            if ($email_result !== '' || $sms_result !== '') {
                $log('loan=' . $result['loan_id'] . ' notification=' . trim($email_result . $sms_result));
            }
        } elseif ($result['status'] === 'error') {
            $errors++;
            $log('loan=' . $result['loan_id'] . ' period=' . $result['period_month'] . ' status=error message=' . $result['message']);
        } elseif ($dry_run && $result['status'] === 'dry-run') {
            $log(sprintf(
                'loan=%d period=%s status=dry-run amount_due=%.2f available=%.2f would_deduct=%.2f outstanding_after=%.2f',
                $result['loan_id'], $result['period_month'], $result['amount_due'],
                $result['amount_available'], $result['amount_to_deduct'], $result['outstanding_after']
            ));
        }
    }
    $log(sprintf(
        'run_complete mode=%s loans_checked=%d deducted=%d amount_total=%.2f errors=%d',
        $dry_run ? 'dry-run' : 'live', count($results), $deducted, $total_taken, $errors
    ));
    if ($errors > 0) exit(1);
} catch (Throwable $e) {
    $log('run_failed error=' . $e->getMessage());
    exit(1);
} finally {
    if (isset($lock_file) && is_resource($lock_file)) {
        flock($lock_file, LOCK_UN);
        fclose($lock_file);
    }
}
