<?php
/**
 * Mailer — small dependency-free SMTP mailer + HTML email templates
 * Extol Global Service
 *
 * Fail-closed: send() never throws and never blocks a user action for
 * long. It returns ['success' => bool, 'error' => string] and every
 * notification call in the app checks mailConfigured() first, so with no
 * SMTP credentials set nothing ever tries to send.
 */

class Mailer {

    /**
     * Is SMTP configured? Any placeholder/unset credentials => disabled.
     */
    public static function isConfigured() {
        foreach (['MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_EMAIL'] as $c) {
            if (!defined($c) || trim(constant($c)) === '' || stripos(constant($c), 'example.com') !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Low-level SMTP send. Synchronous but with short timeouts, so a dead
     * server delays the request by seconds at most — and callers should
     * treat failure as "logged, not fatal".
     */
    public static function send($to_email, $to_name, $subject, $html_body, $text_body = '') {
        if (!self::isConfigured()) {
            return ['success' => false, 'error' => 'Mail not configured'];
        }
        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid recipient email'];
        }
        if ($text_body === '') {
            $text_body = trim(preg_replace('/\s+/', ' ', strip_tags(
                str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</tr>'], "\n", $html_body)
            )));
        }

        $boundary = 'extol_' . md5(uniqid((string)mt_rand(), true));
        $headers  = "From: " . self::encodeHeader(MAIL_FROM_NAME) . " <" . MAIL_FROM_EMAIL . ">\r\n";
        $headers .= "Reply-To: <" . MAIL_FROM_EMAIL . ">\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "X-Mailer: Extol-Global-Service";

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $text_body . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $html_body . "\r\n\r\n";
        $body .= "--{$boundary}--\r\n";

        $data = "Subject: " . self::encodeHeader($subject) . "\r\n"
              . "To: " . self::encodeHeader($to_name) . " <{$to_email}>\r\n"
              . $headers . "\r\n" . $body;

        try {
            return self::smtpSend(MAIL_HOST, (int)MAIL_PORT, MAIL_ENCRYPTION,
                                  MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_EMAIL,
                                  $to_email, $data, (int)MAIL_TIMEOUT);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // Notification helpers — one per event. Each returns the send() array
    // or a skipped/not-configured array. Never throws.
    // ------------------------------------------------------------------

    public static function notifyLoanCreated($customer, $loan_amount, $due_date, $reference = '') {
        if (!self::shouldMail($customer)) {
            return self::skipped($customer);
        }
        $subject = 'Your loan of ' . self::naira($loan_amount) . ' has been approved';
        $html = self::layout(
            'Loan approved 🎉',
            "<p>Dear " . self::h($customer['name']) . ",</p>"
            . "<p>Your loan of <strong>" . self::naira($loan_amount) . "</strong> has been approved and disbursed.</p>"
            . self::rows([
                'Due date'  => self::h($reference !== '' ? $due_date . ' (Ref: ' . $reference . ')' : $due_date),
            ])
            . "<p>Please ensure repayment on or before the due date. Contact us if you have any questions.</p>"
        );
        return self::send($customer['email'], $customer['name'], $subject, $html);
    }

    public static function notifyRepayment($customer, $amount, $loan_amount, $total_repaid, $reference_code) {
        if (!self::shouldMail($customer)) {
            return self::skipped($customer);
        }
        $balance = max(0, (float)$loan_amount - (float)$total_repaid);
        $subject = 'Repayment received — ' . self::naira($amount) . ' (' . $reference_code . ')';
        $html = self::layout(
            'Repayment received ✅',
            "<p>Dear " . self::h($customer['name']) . ",</p>"
            . "<p>We received your repayment of <strong>" . self::naira($amount) . "</strong>.</p>"
            . self::rows([
                'Reference'       => self::h($reference_code),
                'Total repaid'    => self::naira($total_repaid),
                'Remaining balance' => self::naira($balance),
            ])
            . ($balance <= 0 ? "<p><strong>Your loan is fully repaid. Thank you!</strong></p>" : "")
        );
        return self::send($customer['email'], $customer['name'], $subject, $html);
    }

    public static function notifyKycApproved($customer, $notes = '') {
        if (!self::shouldMail($customer)) {
            return self::skipped($customer);
        }
        $subject = 'Your identity verification was approved';
        $extra = $notes !== '' ? "<p>Note from our team: " . self::h($notes) . "</p>" : "";
        $html = self::layout(
            'KYC approved ✅',
            "<p>Dear " . self::h($customer['name']) . ",</p>"
            . "<p>Good news — your identity verification has been <strong>approved</strong>. You are now eligible for loans and full account services.</p>"
            . $extra
        );
        return self::send($customer['email'], $customer['name'], $subject, $html);
    }

    public static function notifyKycRejected($customer, $reason = '') {
        if (!self::shouldMail($customer)) {
            return self::skipped($customer);
        }
        $subject = 'Your identity verification needs attention';
        $extra = $reason !== '' ? "<p>Reason: " . self::h($reason) . "</p>" : "";
        $html = self::layout(
            'KYC needs attention ⚠️',
            "<p>Dear " . self::h($customer['name']) . ",</p>"
            . "<p>Your identity verification was <strong>not approved</strong>. Please review your documents and resubmit, or visit our office for help.</p>"
            . $extra
        );
        return self::send($customer['email'], $customer['name'], $subject, $html);
    }

    public static function notifyTransaction($customer, $type, $amount, $reference_code) {
        if (!self::shouldMail($customer)) {
            return self::skipped($customer);
        }
        $label = strtolower($type) === 'withdrawal' ? 'Withdrawal' : 'Deposit';
        $subject = $label . ' of ' . self::naira($amount) . ' (' . $reference_code . ')';
        $html = self::layout(
            $label . ' confirmation 🧾',
            "<p>Dear " . self::h($customer['name']) . ",</p>"
            . "<p>A <strong>" . strtolower($label) . "</strong> of <strong>" . self::naira($amount) . "</strong> was recorded on your account.</p>"
            . self::rows(['Reference' => self::h($reference_code)])
            . "<p>If you did not authorize this, please contact us immediately.</p>"
        );
        return self::send($customer['email'], $customer['name'], $subject, $html);
    }

    public static function notifyWelcome($customer, $plain_password, $card_id = '') {
        if (!self::shouldMail($customer)) {
            return self::skipped($customer);
        }
        $subject = 'Welcome to Extol Global Service 🎉';
        $rows = ['Phone number (login)' => self::h($customer['phone_number'] ?? '')];
        if ($card_id !== '') {
            $rows['Card ID'] = self::h($card_id);
        }
        $rows['Temporary password'] = self::h($plain_password);
        $html = self::layout(
            'Welcome to Extol Global Service 🎉',
            "<p>Dear " . self::h($customer['name']) . ",</p>"
            . "<p>Your account has been created. Use these credentials to log in, then change your password:</p>"
            . self::rows($rows)
            . "<p>Keep this email safe and never share your password with anyone.</p>"
        );
        return self::send($customer['email'], $customer['name'], $subject, $html);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function shouldMail($customer) {
        return self::isConfigured()
            && is_array($customer)
            && !empty($customer['email'])
            && filter_var($customer['email'], FILTER_VALIDATE_EMAIL);
    }

    private static function skipped($customer) {
        if (!self::isConfigured()) {
            return ['success' => false, 'error' => 'Mail not configured — skipped'];
        }
        return ['success' => false, 'error' => 'No valid customer email — skipped'];
    }

    private static function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    private static function naira($amount) {
        return '₦' . number_format((float)$amount, 2);
    }

    private static function encodeHeader($s) {
        if (preg_match('/[^\x20-\x7E]/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return str_replace(["\r", "\n"], '', $s);
    }

    private static function rows($pairs) {
        $html = '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        foreach ($pairs as $k => $v) {
            $html .= '<tr>'
                   . '<td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;">' . self::h($k) . '</td>'
                   . '<td style="padding:8px 12px;border:1px solid #e5e7eb;font-weight:600;">' . $v . '</td>'
                   . '</tr>';
        }
        return $html . '</table>';
    }

    private static function layout($heading, $inner) {
        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f3f4f6;">'
             . '<div style="max-width:560px;margin:0 auto;padding:24px;">'
             . '<div style="background:#2563eb;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0;">'
             . '<div style="font-size:20px;font-weight:700;">Extol Global Service</div>'
             . '<div style="font-size:14px;opacity:0.9;">' . self::h($heading) . '</div>'
             . '</div>'
             . '<div style="background:#fff;padding:24px;border-radius:0 0 12px 12px;font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#111827;">'
             . $inner
             . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;">'
             . '<p style="font-size:12px;color:#6b7280;">Extol Global Service — Savings &amp; Loans Management System.<br>'
             . 'This is an automated message, please do not reply directly.</p>'
             . '</div></div></body></html>';
    }

    // Minimal SMTP client (AUTH LOGIN, STARTTLS or implicit TLS).
    private static function smtpSend($host, $port, $enc, $user, $pass, $from, $to, $data, $timeout) {
        $enc = strtolower(trim($enc));
        $target = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . (int)$port;
        $ctx = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        $fp = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return ['success' => false, 'error' => "SMTP connect failed: {$errstr} ({$errno})"];
        }
        stream_set_timeout($fp, $timeout);

        $read = function ($want = null) use ($fp) {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                if (preg_match('/^\d{3} /', $line)) break; // last line of multiline reply
            }
            if ($out === '') {
                throw new Exception('SMTP connection lost while reading response');
            }
            $code = (int)substr($out, 0, 3);
            if ($want !== null && $code < 200 || $code >= 400) {
                throw new Exception('SMTP error: ' . trim($out));
            }
            return [$code, $out];
        };
        $cmd = function ($c) use ($fp, $read) {
            fwrite($fp, $c . "\r\n");
            return $read();
        };

        try {
            $read(); // server greeting
            $ehlo_host = gethostname() ?: 'localhost';
            if ($enc === 'tls') {
                $cmd("EHLO {$ehlo_host}");
                $cmd("STARTTLS");
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception('SMTP STARTTLS failed');
                }
                $cmd("EHLO {$ehlo_host}"); // re-greet after TLS
            } else {
                // SSL connects encrypted already; plain uses EHLO then straight to AUTH
                try { $cmd("EHLO {$ehlo_host}"); }
                catch (Exception $e) { $cmd("HELO {$ehlo_host}"); }
            }
            $cmd("AUTH LOGIN");
            $cmd(base64_encode($user));
            $cmd(base64_encode($pass));
            $cmd("MAIL FROM:<{$from}>");
            $cmd("RCPT TO:<{$to}>");
            $cmd("DATA");
            fwrite($fp, $data . "\r\n.\r\n");
            $read();
            $cmd("QUIT");
            fclose($fp);
            return ['success' => true, 'error' => ''];
        } catch (Exception $e) {
            fclose($fp);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
?>
