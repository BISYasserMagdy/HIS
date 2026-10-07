<?php
/**
 * mailer_config.php
 * ═══════════════════════════════════════════════════════════════════
 * Shared PHPMailer SMTP configuration for Pharos HIS.
 * Include this file in subscribe.php and subscribe_ehr.php.
 *
 * HOW TO SET UP:
 *   1. Run:  composer require phpmailer/phpmailer
 *   2. Fill in YOUR_GMAIL and YOUR_APP_PASSWORD below.
 *      (Gmail → Settings → Security → App Passwords → generate one)
 *   3. Make sure "vendor/autoload.php" path matches your project structure.
 * ═══════════════════════════════════════════════════════════════════
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ── CONFIGURE THESE ───────────────────────────────────────────────
define('SMTP_FROM_EMAIL', 'bisyassermagdy@gmail.com');   // ← your Gmail address
define('SMTP_FROM_NAME',  'Pharos HIS');
define('SMTP_PASSWORD',   'bumt jsso tcxk aqfo');    // ← 16-char Gmail App Password
define('SMTP_HOST',       'smtp.gmail.com');
define('SMTP_PORT',       587);
// ─────────────────────────────────────────────────────────────────

/**
 * Create and return a pre-configured PHPMailer instance.
 * Throws PHPMailer\PHPMailer\Exception on config error.
 */
function createMailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_FROM_EMAIL;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    return $mail;
}

/**
 * Shared HTML email wrapper — consistent header/footer for both ERP and EHR.
 */
function emailWrapper(string $title, string $body): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(30,58,138,0.10);">
        <!-- Header -->
        <tr>
          <td style="background:#1d4ed8;padding:32px 40px;text-align:center;">
            <h1 style="color:#ffffff;margin:0;font-size:24px;font-weight:700;letter-spacing:-0.5px;">
              🏥 Pharos HIS
            </h1>
            <p style="color:#bfdbfe;margin:6px 0 0;font-size:14px;">{$title}</p>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:36px 40px;">
            {$body}
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f8fafc;padding:24px 40px;text-align:center;border-top:1px solid #e2e8f0;">
            <p style="margin:0;color:#6b7280;font-size:13px;">
              This email was sent by <strong>Pharos HIS</strong>. Please keep your credentials safe.<br>
              If you did not request this, contact our support team immediately.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
