<?php
/**
 * Send a notification email without ever breaking the admin/staff action.
 *
 * Usage (after a successful DB write):
 *   $mail_note = notifyCustomer('loan_created', [
 *       'customer' => ['name' => ..., 'email' => ..., 'phone_number' => ...],
 *       'loan_amount' => ..., 'due_date' => ...,
 *   ]);
 *   // then append $mail_note to the success flash message if non-empty.
 *
 * Returns '' on success or skipped-without-error, otherwise a short
 * human-readable note like ' (email failed: ...)'. Never throws.
 */

function notifyCustomer($event, $data = []) {
    try {
        if (!class_exists('Mailer')) {
            $mailer_path = dirname(__DIR__) . '/src/mail/Mailer.php';
            if (file_exists($mailer_path)) {
                require_once $mailer_path;
            }
        }
        if (!class_exists('Mailer') || !Mailer::isConfigured()) {
            return ''; // mail not set up yet — stay silent
        }

        switch ($event) {
            case 'loan_created':
                $r = Mailer::notifyLoanCreated(
                    $data['customer'], $data['loan_amount'], $data['due_date'], $data['reference'] ?? ''
                );
                break;
            case 'repayment':
                $r = Mailer::notifyRepayment(
                    $data['customer'], $data['amount'], $data['loan_amount'],
                    $data['total_repaid'], $data['reference_code']
                );
                break;
            case 'kyc_approved':
                $r = Mailer::notifyKycApproved($data['customer'], $data['notes'] ?? '');
                break;
            case 'kyc_rejected':
                $r = Mailer::notifyKycRejected($data['customer'], $data['notes'] ?? '');
                break;
            case 'transaction':
                $r = Mailer::notifyTransaction(
                    $data['customer'], $data['type'], $data['amount'], $data['reference_code']
                );
                break;
            case 'welcome':
                $r = Mailer::notifyWelcome(
                    $data['customer'], $data['password'], $data['card_id'] ?? ''
                );
                break;
            default:
                return '';
        }

        if (!empty($r['success'])) {
            return ' Email sent.';
        }
        // "Skipped" (no email on file) is normal — don't nag the admin.
        if (isset($r['error']) && stripos($r['error'], 'skipped') !== false) {
            return '';
        }
        return ' (email failed: ' . $r['error'] . ')';
    } catch (Throwable $e) {
        return ' (email failed: ' . $e->getMessage() . ')';
    }
}

/**
 * Send a notification SMS (Termii) without ever breaking the action.
 *
 * Same contract as notifyCustomer(): '' on success or silent skip,
 * otherwise ' (SMS failed: ...)'. Never throws.
 *
 * The customer array needs 'phone_number' (and 'name' for templates).
 */
function notifyCustomerSms($event, $data = []) {
    try {
        if (!class_exists('Sms')) {
            $sms_path = dirname(__DIR__) . '/src/sms/Sms.php';
            if (file_exists($sms_path)) {
                require_once $sms_path;
            }
        }
        if (!class_exists('Sms') || !Sms::isConfigured()) {
            return ''; // SMS not set up yet — stay silent
        }

        $customer = $data['customer'] ?? [];
        $phone = $customer['phone_number'] ?? '';
        $name = $customer['name'] ?? 'there';
        // First name only — SMS space is precious.
        $name = trim(strtok((string)$name, ' '));

        switch ($event) {
            case 'loan_created':
                $r = Sms::notifyLoanCreated($phone, $name, $data['loan_amount'], $data['due_date']);
                break;
            case 'repayment':
                $balance = max(0, (float)$data['loan_amount'] - (float)$data['total_repaid']);
                $r = Sms::notifyRepayment($phone, $name, $data['amount'], $balance, $data['reference_code']);
                break;
            case 'kyc_approved':
                $r = Sms::notifyKycApproved($phone, $name);
                break;
            case 'kyc_rejected':
                $r = Sms::notifyKycRejected($phone, $name);
                break;
            case 'transaction':
                $r = Sms::notifyTransaction($phone, $name, $data['type'], $data['amount'], $data['reference_code']);
                break;
            default:
                return ''; // no SMS for welcome (password over SMS is unsafe)
        }

        if (!empty($r['success'])) {
            return ' SMS sent.';
        }
        if (isset($r['error']) && stripos($r['error'], 'skipped') !== false) {
            return '';
        }
        return ' (SMS failed: ' . $r['error'] . ')';
    } catch (Throwable $e) {
        return ' (SMS failed: ' . $e->getMessage() . ')';
    }
}
?>
