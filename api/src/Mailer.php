<?php

class Mailer
{
    /**
     * Sends a password reset email containing the raw token.
     * In production, replace this with a real transactional email service
     * (SendGrid, SES, Mailgun, etc). For local/dev use we fall back to
     * writing the email to a log file so you can test the flow end-to-end.
     */
    public static function sendPasswordResetEmail(string $toEmail, string $toName, string $resetToken): bool
    {
        $subject = 'Reset your Expense Tracker password';
        $body = "Hi {$toName},\n\n"
              . "We received a request to reset your password.\n"
              . "Use the token below in the /api/reset-password endpoint (valid for 30 minutes):\n\n"
              . "Reset Token: {$resetToken}\n\n"
              . "If you didn't request this, you can safely ignore this email.\n";

        $sent = @mail($toEmail, $subject, $body, "From: no-reply@expense-tracker.local\r\n");

        if (!$sent) {
            // Dev fallback: log instead of failing hard so the flow is testable locally.
            $logDir = __DIR__ . '/../storage';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0775, true);
            }
            file_put_contents(
                $logDir . '/mail.log',
                "[" . date('Y-m-d H:i:s') . "] To: {$toEmail}\nSubject: {$subject}\n{$body}\n---\n",
                FILE_APPEND
            );
        }

        return true;
    }
}
