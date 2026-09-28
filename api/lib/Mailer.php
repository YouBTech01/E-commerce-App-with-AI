<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Email via PHPMailer (installed via Composer into /vendor).
 * SMTP credentials come only from .env — never hard-coded, never sent to client.
 * If PHPMailer or SMTP is not configured, the call fails loudly in dev and is
 * logged (never silently "faked").
 */
final class Mailer
{
    public static function configured(): bool
    {
        return SMTP_HOST !== '' && class_exists(\PHPMailer\PHMailer\PHPMailer::class);
    }

    public static function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        if (SMTP_HOST === '') {
            error_log('[ExamLegacy] SMTP not configured; email to ' . $toEmail . ' not sent.');
            if (APP_DEBUG) {
                throw new ApiError('mail_not_configured', 'SMTP is not configured', 503);
            }
            return false;
        }
        if (!class_exists(\PHPMailer\PHMailer\PHPMailer::class)) {
            error_log('[ExamLegacy] PHPMailer not installed (run composer install).');
            if (APP_DEBUG) {
                throw new ApiError('mail_not_installed', 'PHPMailer not installed', 503);
            }
            return false;
        }
        $mail = new \PHPMailer\PHMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->Port = SMTP_PORT;
            $mail->SMTPAuth = SMTP_USER !== '';
            if (SMTP_USER !== '') {
                $mail->Username = SMTP_USER;
                $mail->Password = SMTP_PASS;
            }
            if (SMTP_SECURE !== '') {
                $mail->SMTPSecure = SMTP_SECURE === 'tls' ? \PHPMailer\PHMailer\PHPMailer::ENCRYPTION_STARTTLS : \PHPMailer\PHMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            $mail->CharSet = 'UTF-8';
            $mail->setFrom(SMTP_FROM !== '' ? SMTP_FROM : 'no-reply@example.com', SMTP_FROM_NAME);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('[ExamLegacy] Mail send failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function sendPurchaseReceipt(string $toEmail, string $toName, array $order): void
    {
        $rows = '';
        foreach ($order['items'] as $it) {
            $rows .= '<tr><td>' . htmlspecialchars((string) $it['title']) . '</td><td style="text-align:right">₹' . money((int) $it['price_paise']) . '</td></tr>';
        }
        $html = '<h2>Thank you for your purchase</h2>'
            . '<p>Order <strong>' . htmlspecialchars((string) $order['order_code']) . '</strong> is confirmed.</p>'
            . '<table cellpadding="6" cellspacing="0" border="0">' . $rows
            . '<tr><td><strong>Total</strong></td><td style="text-align:right"><strong>₹' . money((int) $order['total_paise']) . '</strong></td></tr></table>'
            . '<p>Your purchased documents are now available in your ExamLegacy Library.</p>';
        self::send($toEmail, $toName, 'Your ExamLegacy order ' . (string) $order['order_code'], $html);
    }
}
