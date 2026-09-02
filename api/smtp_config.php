<?php
/**
 * Server-side SMTP config for transactional mail (password reset, etc.).
 * Prefer env vars; optional override via api/smtp_config.local.php.
 *
 * Env:
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME, SMTP_SECURE
 */

function smtp_load_config(): array
{
    $defaults = [
        'host'       => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
        'port'       => (int) (getenv('SMTP_PORT') ?: 587),
        'username'   => getenv('SMTP_USER') ?: '',
        'password'   => getenv('SMTP_PASS') ?: '',
        'from_email' => getenv('SMTP_FROM') ?: (getenv('SMTP_USER') ?: ''),
        'from_name'  => getenv('SMTP_FROM_NAME') ?: 'MiCampus',
        'secure'     => getenv('SMTP_SECURE') ?: 'tls', // tls | ssl | ''
    ];
    $local = __DIR__ . '/smtp_config.local.php';
    if (is_readable($local)) {
        $cfg = include $local;
        if (is_array($cfg)) {
            return array_merge($defaults, $cfg);
        }
    }
    return $defaults;
}

/**
 * Send a plain/HTML email using PHPMailer + server SMTP config.
 * @return array{ok:bool,error?:string}
 */
function smtp_send_mail(string $toEmail, string $subject, string $htmlBody, string $altBody = ''): array
{
    $cfg = smtp_load_config();
    if ($cfg['username'] === '' || $cfg['password'] === '' || $cfg['from_email'] === '') {
        return ['ok' => false, 'error' => 'SMTP is not configured (set SMTP_USER/SMTP_PASS or smtp_config.local.php)'];
    }

    $vendorPath = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($vendorPath)) {
        return ['ok' => false, 'error' => 'PHPMailer not installed'];
    }
    require_once $vendorPath;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $mail->Port = (int) $cfg['port'];
        if (($cfg['secure'] ?? '') === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif (($cfg['secure'] ?? '') === 'tls' || $cfg['secure'] === '') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->Timeout = 15;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $altBody !== '' ? $altBody : strip_tags($htmlBody);
        $mail->send();
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
