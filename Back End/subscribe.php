<?php
/**
 * subscribe.php
 * ════════════════════════════════════════════════════════════════════════════
 * Handles new ERP subscription form submissions from Subscription.html.
 *
 * On success:
 *   - Saves subscription + hashed passwords to `pharmacy_erp`.`subscriptions`
 *   - Creates/updates pharmacist record in `pharmacists`
 *   - Sends an HTML credentials email to the subscriber via Gmail SMTP
 *
 * Requires: composer require phpmailer/phpmailer
 *           mailer_config.php (in the same Back End/ folder)
 * ════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── PHPMailer bootstrap ───────────────────────────────────────────────────────
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/mailer_config.php';

use PHPMailer\PHPMailer\Exception as MailException;

// ── Database ──────────────────────────────────────────────────────────────────
$conn = new mysqli('localhost', 'root', '', 'pharmacy_erp');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed.']);
    exit;
}

// ── Create subscriptions table if needed ─────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS subscriptions (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    email          VARCHAR(150) UNIQUE NOT NULL,
    plan           VARCHAR(50)  NOT NULL,
    payment_method VARCHAR(50)  NOT NULL,
    manager_id     VARCHAR(50)  NOT NULL,
    manager_pass   VARCHAR(100) NOT NULL,
    employee_id    VARCHAR(50)  NOT NULL,
    employee_pass  VARCHAR(100) NOT NULL,
    status         ENUM('active','cancelled') DEFAULT 'active',
    subscribed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ── Read & sanitise input ─────────────────────────────────────────────────────
$data          = json_decode(file_get_contents('php://input'), true);
$email         = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$plan          = trim($data['plan'] ?? '');
$paymentMethod = trim($data['payment_method'] ?? '');

if (!$email || !$plan || !$paymentMethod) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid fields.']);
    exit;
}

// ── Check duplicate ───────────────────────────────────────────────────────────
$checkStmt = $conn->prepare("SELECT id, status FROM subscriptions WHERE email = ?");
$checkStmt->bind_param('s', $email);
$checkStmt->execute();
$check      = $checkStmt->get_result();
$reactivate = false;
if ($check->num_rows > 0) {
    $row = $check->fetch_assoc();
    $checkStmt->close();
    if ($row['status'] === 'active') {
        echo json_encode(['success' => false, 'message' => 'This email is already subscribed.']);
        exit;
    }
    $reactivate = true;
} else {
    $checkStmt->close();
}

// ── Generate credentials ──────────────────────────────────────────────────────
function generateId(string $prefix): string {
    return strtoupper($prefix) . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
}
function generatePassword(int $length = 12): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$';
    $pass  = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}

$managerId        = generateId('MGR');
$managerPass      = generatePassword();
$employeeId       = generateId('EMP');
$employeePass     = generatePassword();
$managerPassHash  = password_hash($managerPass,  PASSWORD_BCRYPT);
$employeePassHash = password_hash($employeePass, PASSWORD_BCRYPT);

// ── Save to database ──────────────────────────────────────────────────────────
if ($reactivate) {
    $stmt = $conn->prepare(
        "UPDATE subscriptions SET
             plan=?, payment_method=?,
             manager_id=?, manager_pass=?,
             employee_id=?, employee_pass=?,
             status='active', subscribed_at=NOW()
         WHERE email=?"
    );
    $stmt->bind_param(
        'sssssss',
        $plan, $paymentMethod, $managerId, $managerPassHash, $employeeId, $employeePassHash, $email
    );
} else {
    $stmt = $conn->prepare(
        "INSERT INTO subscriptions
             (email, plan, payment_method, manager_id, manager_pass, employee_id, employee_pass)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'sssssss',
        $email, $plan, $paymentMethod, $managerId, $managerPassHash, $employeeId, $employeePassHash
    );
}

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Could not save subscription: ' . $stmt->error]);
    exit;
}
$stmt->close();

// Upsert pharmacists table so manager can log in
$managerName = 'Manager (' . $email . ')';
$pharmStmt = $conn->prepare(
    "INSERT IGNORE INTO pharmacists (pharmacist_id, name, email, status, password_hash)
     VALUES (?, ?, ?, 'active', ?)"
);
$pharmStmt->bind_param('ssss', $managerId, $managerName, $email, $managerPassHash);
$pharmStmt->execute();
$pharmStmt->close();

$conn->close();

// ── Send credentials email ────────────────────────────────────────────────────
$planLabel = strtoupper($plan);

$credRow = fn(string $label, string $value, string $icon) =>
    "<tr>
       <td style='padding:12px 16px;background:#f8fafc;border-radius:8px;font-weight:600;color:#374151;font-size:14px;width:40%;'>
         {$icon} {$label}
       </td>
       <td style='padding:12px 16px;font-family:monospace;font-size:15px;color:#1d4ed8;font-weight:700;'>
         {$value}
       </td>
     </tr>";

$emailBody = "
<p style='margin:0 0 24px;color:#374151;font-size:16px;line-height:1.6;'>
  Hello! 👋 Your <strong>Pharos HIS — Pharmacy ERP</strong> subscription is now 
  <span style='color:#059669;font-weight:700;'>active</span>.
  Here are your login credentials:
</p>

<!-- Plan badge -->
<div style='background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:14px 20px;margin-bottom:28px;text-align:center;'>
  <span style='color:#1d4ed8;font-weight:700;font-size:15px;'>📦 Plan: {$planLabel}</span>
</div>

<!-- Manager credentials -->
<h3 style='margin:0 0 12px;color:#1e3a8a;font-size:16px;border-left:4px solid #1d4ed8;padding-left:12px;'>
  👔 Manager Account
</h3>
<table width='100%' cellspacing='0' cellpadding='0' style='border-collapse:separate;border-spacing:0 6px;margin-bottom:28px;'>
  " . $credRow('Manager ID',       $managerId,   '🪪') . "
  " . $credRow('Password',         $managerPass, '🔑') . "
</table>

<!-- Employee credentials -->
<h3 style='margin:0 0 12px;color:#1e3a8a;font-size:16px;border-left:4px solid #059669;padding-left:12px;'>
  👷 Employee Account
</h3>
<table width='100%' cellspacing='0' cellpadding='0' style='border-collapse:separate;border-spacing:0 6px;margin-bottom:28px;'>
  " . $credRow('Employee ID',  $employeeId,   '🪪') . "
  " . $credRow('Password',     $employeePass, '🔑') . "
</table>

<!-- Warning box -->
<div style='background:#fef3c7;border:1px solid #fbbf24;border-radius:10px;padding:14px 18px;color:#92400e;font-size:13px;line-height:1.6;'>
  ⚠️ <strong>Security reminder:</strong> Please change your passwords after your first login.
  Do not share these credentials with anyone.
</div>
";

$emailSent = false;
$emailError = '';

try {
    $mail = createMailer();
    $mail->addAddress($email);
    $mail->Subject = '🏥 Your Pharos HIS (ERP) Login Credentials';
    $mail->Body    = emailWrapper('Pharmacy ERP — Subscription Confirmed', $emailBody);
    $mail->AltBody = "Your ERP subscription is active.\n\n"
                   . "Manager ID: $managerId\nManager Password: $managerPass\n\n"
                   . "Employee ID: $employeeId\nEmployee Password: $employeePass\n\n"
                   . "Please change your passwords after first login.";
    $mail->send();
    $emailSent = true;
} catch (MailException $e) {
    $emailError = $e->getMessage();
}

// ── Response ──────────────────────────────────────────────────────────────────
// NOTE: plaintext passwords are intentionally NOT included here — they only
// ever go out via the credentials email. Returning them in the API response
// puts them in browser dev tools, proxy logs, and server access logs.
echo json_encode([
    'success'     => true,
    'message'     => $emailSent
        ? "Subscription successful. Credentials sent to $email."
        : "Subscription saved, but the credentials email could not be sent ($emailError). Please use 'Resend Credentials' from the admin dashboard once email delivery is working, or contact support.",
    'email_sent'  => $emailSent,
    'manager_id'  => $managerId,
    'employee_id' => $employeeId,
]);
