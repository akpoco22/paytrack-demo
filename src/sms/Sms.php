<?php
/**
 * Sms — small Termii (https://termii.com) client + notification templates
 * Extol Global Service
 *
 * Fail-closed like Mailer: isConfigured() false (no API key yet) means
 * every notify*() returns a skipped array and nobody tries the network.
 * sendSms() never throws — it returns ['success' => bool, 'error' => str].
 *
 * Phone numbers: Termii wants international format without '+' (234...).
 * toIntl() accepts 0803..., +234803..., 234803... and normalises.
 */

class Sms {

    /**
     * Is SMS configured? Empty/placeholder key => disabled.
     */
    public static function isConfigured() {
        return defined('TERMII_API_KEY')
            && trim(TERMII_API_KEY) !== ''
            && stripos(TERMII_API_KEY, 'example') === false
            && stripos(TERMII_API_KEY, 'change-me') === false
            && defined('TERMII_SENDER_ID')
            && trim(TERMII_SENDER_ID) !== '';
    }

    /**
     * Normalise a Nigerian phone number to Termii format (234...).
     * Returns '' when the input is unusable.
     */
    public static function toIntl($phone) {
        $digits = preg_replace('/\D+/', '', (string)$phone);
        if ($digits === '') {
            return '';
        }
        // 0803... (11 digits starting 0) -> 234803...
        if (strlen($digits) === 11 && $digits[0] === '0') {
            return '234' . substr($digits, 1);
        }
        // 803... (10 digits) -> 234803...
        if (strlen($digits) === 10) {
            return '234' . $digits;
        }
        // Already 234... (13 digits)
        if (strlen($digits) === 13 && substr($digits, 0, 3) === '234') {
            return $digits;
        }
        return '';
    }

    /**
     * Low-level send via Termii send-sms endpoint.
     */
    public static function sendSms($to_phone, $message) {
        if (!self::isConfigured()) {
            return ['success' => false, 'error' => 'SMS not configured'];
        }
        $to = self::toIntl($to_phone);
        if ($to === '') {
            return ['success' => false, 'error' => 'Invalid phone number'];
        }
        if (trim($message) === '') {
            return ['success' => false, 'error' => 'Empty message'];
        }

        $payload = json_encode([
            'to'      => $to,
            'from'    => TERMII_SENDER_ID,
            'sms'     => $message,
            'type'    => 'plain',
            'channel' => 'generic',
            'api_key' => TERMII_API_KEY,
        ]);

        $ch = curl_init('https://v3.api.termii.com/api/sms/send');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, defined('TERMII_TIMEOUT') ? (int)TERMII_TIMEOUT : 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['success' => false, 'error' => 'SMS request failed: ' . $err];
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($resp, true);
        if ($code >= 200 && $code < 300 && is_array($data)
            && isset($data['message_id'])) {
            return ['success' => true, 'error' => ''];
        }
        $msg = is_array($data) ? ($data['message'] ?? $resp) : $resp;
        return ['success' => false, 'error' => 'Termii error: ' . substr((string)$msg, 0, 200)];
    }

    // ------------------------------------------------------------------
    // Notification helpers — short plain-text templates (SMS ≤ 160 chars
    // per segment; keep them tight). Same fail-closed contract as Mailer.
    // ------------------------------------------------------------------

    public static function notifyLoanCreated($phone, $name, $loan_amount, $due_date) {
        if (!self::shouldSms($phone)) {
            return self::skipped($phone);
        }
        $msg = "Hi {$name}, your Extol loan of " . self::naira($loan_amount)
             . " is approved. Due: {$due_date}. - Extol Global Service";
        return self::sendSms($phone, $msg);
    }

    public static function notifyRepayment($phone, $name, $amount, $balance, $reference_code) {
        if (!self::shouldSms($phone)) {
            return self::skipped($phone);
        }
        $msg = "Hi {$name}, we received your repayment of " . self::naira($amount)
             . " ({$reference_code}). Balance: " . self::naira($balance) . ". - Extol";
        return self::sendSms($phone, $msg);
    }

    public static function notifyKycApproved($phone, $name) {
        if (!self::shouldSms($phone)) {
            return self::skipped($phone);
        }
        return self::sendSms($phone, "Hi {$name}, your Extol identity verification is APPROVED. You can now access loans. - Extol");
    }

    public static function notifyKycRejected($phone, $name) {
        if (!self::shouldSms($phone)) {
            return self::skipped($phone);
        }
        return self::sendSms($phone, "Hi {$name}, your Extol identity verification needs attention. Please contact our office. - Extol");
    }

    public static function notifyTransaction($phone, $name, $type, $amount, $reference_code) {
        if (!self::shouldSms($phone)) {
            return self::skipped($phone);
        }
        $label = strtolower($type) === 'withdrawal' ? 'Withdrawal' : 'Deposit';
        $msg = "Hi {$name}, {$label} of " . self::naira($amount)
             . " ({$reference_code}) on your Extol account. - Extol";
        return self::sendSms($phone, $msg);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function shouldSms($phone) {
        return self::isConfigured() && self::toIntl($phone) !== '';
    }

    private static function skipped($phone) {
        if (!self::isConfigured()) {
            return ['success' => false, 'error' => 'SMS not configured — skipped'];
        }
        return ['success' => false, 'error' => 'No valid phone number — skipped'];
    }

    private static function naira($amount) {
        return 'N' . number_format((float)$amount, 2);
    }
}
?>
