<?php
declare(strict_types=1);

// Send the verification email via PHP's mail(). Most shared hosting has
// mail() enabled and routes through a local MTA — that's the expected path
// in prod. On dev (no MTA configured) mail() returns false; if
// 'mail_dev_log' is set in config we log the code so the dev can pick it up
// without a real SMTP server. Production must NOT enable that flag — it
// would land verification codes in the access log.
function send_verification_email(string $email, string $name, string $code): bool {
    // Belt-and-braces: refuse if anything looks like a header-injection
    // attempt, even though FILTER_VALIDATE_EMAIL already rejected CRLF and
    // we never put $name into the headers.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (preg_match('/[\r\n]/', $name)) $name = '';

    $cfg = config();
    $appName = (string) ($cfg['app_name'] ?? 'shortly');
    $publicUrl = (string) ($cfg['public_url'] ?? '');
    $signature = $publicUrl !== '' ? parse_url($publicUrl, PHP_URL_HOST) ?: $appName : $appName;

    $subject = 'Verify your ' . $appName . ' account';
    $body = "Hi " . $name . ",\n\n"
          . "Your verification code:\n\n"
          . "    " . $code . "\n\n"
          . "It expires in 15 minutes. If you didn't request this, just ignore this email.\n\n"
          . "— " . $signature . "\n";

    $from = (string) ($cfg['mail_from'] ?? 'noreply@' . ($signature ?: 'localhost'));
    $headers  = "From: " . $appName . " <" . $from . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    $sent = @mail($email, $subject, $body, $headers);

    if (!empty(config()['mail_dev_log'])) {
        // ONLY for local dev. Logs the code to PHP's error log so the
        // developer can see it without a working MTA. Never enable in prod.
        error_log("[shortly:dev-mail] code for {$email}: {$code} (mail()="
                  . ($sent ? 'ok' : 'failed') . ')');
    }

    return (bool) $sent;
}

// Password-reset email: a one-shot link that the recipient pastes back to
// /reset?token=…. Same plain-text format + dev-log fallback as the verify
// email; the link IS the credential so we keep it conspicuous, no other links.
function send_password_reset_email(string $email, string $name, string $resetUrl): bool {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (preg_match('/[\r\n]/', $name)) $name = '';

    $cfg = config();
    $appName   = (string) ($cfg['app_name'] ?? 'shortly');
    $publicUrl = (string) ($cfg['public_url'] ?? '');
    $signature = $publicUrl !== '' ? parse_url($publicUrl, PHP_URL_HOST) ?: $appName : $appName;

    $greeting = $name !== '' ? ('Hi ' . $name) : 'Hi';
    $subject  = 'Reset your ' . $appName . ' password';
    $body = $greeting . ",\n\n"
          . "Click the link below to choose a new password:\n\n"
          . "    " . $resetUrl . "\n\n"
          . "The link expires in 1 hour and can only be used once.\n"
          . "If you didn't request a reset, ignore this email — your password stays as-is.\n\n"
          . "— " . $signature . "\n";

    $from = (string) ($cfg['mail_from'] ?? 'noreply@' . ($signature ?: 'localhost'));
    $headers  = "From: " . $appName . " <" . $from . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    $sent = @mail($email, $subject, $body, $headers);

    if (!empty(config()['mail_dev_log'])) {
        error_log("[shortly:dev-mail] password reset for {$email}: {$resetUrl} (mail()="
                  . ($sent ? 'ok' : 'failed') . ')');
    }

    return (bool) $sent;
}
