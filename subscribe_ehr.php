<?php
/**
 * subscribe_ehr.php
 * ════════════════════════════════════════════════════════════════════════════
 * Handles new EHR subscription form submissions from Subscription_EHR.html.
 *
 * On success:
 *   - Creates hospital row in `healthcare_ehr`.`hospitals`
 *   - Auto-generates 4 staff accounts (admin / manager / doctor / nurse)
 *   - Sends a single HTML credentials email with all 4 accounts to the
 *     subscriber via Gmail SMTP
 *
 * For card payments (payment_method === 'visa'), this file re-verifies the
 * Stripe PaymentIntent with Stripe's API before creating anything — it never
 * trusts the browser's claim that a card was valid. See create_payment_intent.php
 * and stripe_config.php for the rest of the payment flow.
 *
 * Requires: composer require phpmailer/phpmailer stripe/stripe-php
 *           mailer_config.php and stripe_config.php (in the same Back End/ folder)
 * ════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── PHPMailer bootstrap ───────────────────────────────────────────────────────
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/mailer_config.php';

use PHPMailer\PHPMailer\Exception as MailException;

// ── Database ──────────────────────────────────────────────────────────────────
$conn = new mysqli('localhost', 'root', '', 'healthcare_ehr');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed: ' . $conn->connect_error]);
    exit;
}
$conn->set_charset('utf8mb4');
$conn->query("SET SESSION sql_mode = ''");

// ── Ensure tables exist ───────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `hospitals` (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(160) NOT NULL,
    logo_url   VARCHAR(255) NULL,
    email      VARCHAR(150) NULL,
    plan       VARCHAR(50)  NULL,
    status     ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hospital_name (name)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `users` (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(80)  NOT NULL,
    email         VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','manager','doctor','nurse') NOT NULL DEFAULT 'nurse',
    hospital      VARCHAR(120) NOT NULL DEFAULT 'General Hospital',
    full_name     VARCHAR(160) NULL,
    specialty     VARCHAR(100) NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username),
    UNIQUE KEY uq_email    (email),
    KEY idx_role     (role),
    KEY idx_hospital (hospital)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// ── Read & sanitise input ─────────────────────────────────────────────────────
$data          = json_decode(file_get_contents('php://input'), true);
$email         = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$plan          = trim($data['plan'] ?? '');
$paymentMethod = trim($data['payment_method'] ?? '');
$hospitalName  = trim($data['hospital_name'] ?? '');

if (!$email || !$plan || !$paymentMethod || $hospitalName === '') {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid fields. Hospital name and a valid email are required.']);
    exit;
}

// ── Check for duplicate hospital ─────────────────────────────────────────────
$check = $conn->prepare("SELECT id, status FROM hospitals WHERE name = ?");
$check->bind_param('s', $hospitalName);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing && $existing['status'] === 'active') {
    echo json_encode(['success' => false, 'message' => 'A hospital with this name is already subscribed.']);
    exit;
}

// ── Verify the card payment actually succeeded ────────────────────────────────
// We never trust the browser's word that "the card was valid" — a fake card
// number can pass a client-side Luhn check trivially (Luhn is just a checksum
// format, not proof of a real, funded account). Here we ask Stripe directly,
// using the PaymentIntent id the browser got back, whether the card network
// actually approved a real charge for this exact plan/email/hospital.
if ($paymentMethod === 'visa') {
    $paymentIntentId = trim($data['payment_intent_id'] ?? '');
    if ($paymentIntentId === '') {
        echo json_encode(['success' => false, 'message' => 'Missing payment confirmation. Please try again.']);
        exit;
    }

    require __DIR__ . '/stripe_config.php';
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

    try {
        $intent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        echo json_encode(['success' => false, 'message' => 'Could not verify payment: ' . $e->getMessage()]);
        exit;
    }

    $expectedAmount = EHR_PLAN_PRICES_CENTS[$plan] ?? null;

    if ($intent->status !== 'succeeded') {
        echo json_encode(['success' => false, 'message' => 'Payment was not completed (status: ' . $intent->status . ').']);
        exit;
    }
    // Make sure this PaymentIntent was actually created for this plan/email/
    // hospital — otherwise someone could reuse an unrelated successful
    // payment id from a different, cheaper transaction.
    if ($expectedAmount === null
        || $intent->amount !== $expectedAmount
        || ($intent->metadata->plan ?? null) !== $plan
        || ($intent->metadata->email ?? null) !== $email
        || ($intent->metadata->hospital_name ?? null) !== $hospitalName) {
        echo json_encode(['success' => false, 'message' => 'Payment details do not match this subscription request.']);
        exit;
    }
}

// ── Generate credentials ──────────────────────────────────────────────────────
function ehrSlug(string $name): string {
    return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
}
function ehrGeneratePassword(int $length = 12): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$';
    $pass  = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}

$suffix = strtoupper(substr(md5(uniqid((string)random_int(0, 999999), true)), 0, 4));
$slug   = ehrSlug($hospitalName) ?: 'hospital';

$roleConfig = [
    'admin'   => ['full_name' => 'System Administrator', 'specialty' => null,               'icon' => '🛡️'],
    'manager' => ['full_name' => 'Hospital Manager',      'specialty' => null,               'icon' => '👔'],
    'doctor'  => ['full_name' => 'Dr. New Doctor',        'specialty' => 'General Medicine', 'icon' => '🩺'],
    'nurse'   => ['full_name' => 'New Nurse',             'specialty' => null,               'icon' => '💊'],
];

$accounts = [];
$emailParts = explode('@', $email, 2);

foreach ($roleConfig as $role => $cfg) {
    $plain = ehrGeneratePassword();
    $accounts[$role] = [
        'username'      => "{$role}_{$slug}_{$suffix}",
        'email'         => $emailParts[0] . '+' . $role . '_' . strtolower($suffix) . '@' . ($emailParts[1] ?? 'example.com'),
        'password'      => $plain,
        'password_hash' => password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]),
        'full_name'     => $cfg['full_name'],
        'specialty'     => $cfg['specialty'],
        'icon'          => $cfg['icon'],
    ];
}

// ── Persist in a transaction ──────────────────────────────────────────────────
$conn->begin_transaction();
try {
    if ($existing) {
        $upd = $conn->prepare("UPDATE hospitals SET email = ?, plan = ?, status = 'active' WHERE id = ?");
        $upd->bind_param('ssi', $email, $plan, $existing['id']);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare("INSERT INTO hospitals (name, email, plan, status) VALUES (?, ?, ?, 'active')");
        $ins->bind_param('sss', $hospitalName, $email, $plan);
        $ins->execute();
        $ins->close();
    }

    $stmt = $conn->prepare(
        "INSERT INTO users (username, email, password_hash, role, full_name, specialty, hospital)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($accounts as $role => $acct) {
        $stmt->bind_param('sssssss',
            $acct['username'], $acct['email'], $acct['password_hash'],
            $role, $acct['full_name'], $acct['specialty'], $hospitalName
        );
        $stmt->execute();
    }
    $stmt->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'Could not create accounts: ' . $e->getMessage()]);
    exit;
}

$conn->close();

// ── Build and send credentials email ─────────────────────────────────────────
$planLabel = strtoupper($plan);

$credRow = fn(string $label, string $value) =>
    "<tr>
       <td style='padding:10px 14px;background:#f8fafc;border-radius:6px;font-weight:600;color:#374151;font-size:13px;width:38%;'>
         {$label}
       </td>
       <td style='padding:10px 14px;font-family:monospace;font-size:14px;color:#1d4ed8;font-weight:700;'>
         {$value}
       </td>
     </tr>";

$accountSections = '';
foreach ($accounts as $role => $acct) {
    $roleName  = ucfirst($role);
    $icon      = $acct['icon'];
    $borderClr = match($role) {
        'admin'   => '#7c3aed',
        'manager' => '#1d4ed8',
        'doctor'  => '#059669',
        'nurse'   => '#db2777',
        default   => '#6b7280',
    };
    $accountSections .= "
    <div style='margin-bottom:22px;'>
      <h3 style='margin:0 0 10px;color:#1e3a8a;font-size:15px;border-left:4px solid {$borderClr};padding-left:12px;'>
        {$icon} {$roleName} Account
      </h3>
      <table width='100%' cellspacing='0' cellpadding='0' style='border-collapse:separate;border-spacing:0 5px;'>
        " . $credRow('Username', $acct['username']) . "
        " . $credRow('Password', $acct['password']) . "
      </table>
    </div>";
}

$emailBody = "
<p style='margin:0 0 24px;color:#374151;font-size:16px;line-height:1.6;'>
  Hello! 👋 Your <strong>Pharos HIS — EHR</strong> subscription for 
  <strong style='color:#1d4ed8;'>{$hospitalName}</strong> is now 
  <span style='color:#059669;font-weight:700;'>active</span>.
  Here are the login credentials for all staff accounts:
</p>

<!-- Plan badge -->
<div style='background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:14px 20px;margin-bottom:28px;text-align:center;'>
  <span style='color:#1d4ed8;font-weight:700;font-size:15px;'>📦 Plan: {$planLabel} &nbsp;|&nbsp; 🏥 {$hospitalName}</span>
</div>

{$accountSections}

<!-- Warning box -->
<div style='background:#fef3c7;border:1px solid #fbbf24;border-radius:10px;padding:14px 18px;color:#92400e;font-size:13px;line-height:1.6;margin-top:8px;'>
  ⚠️ <strong>Security reminder:</strong> Please change all passwords after your first login.
  The <em>Admin</em> account can manage staff, branding, and settings from the EHR dashboard.
  Do not share these credentials with anyone.
</div>
";

$emailSent  = false;
$emailError = '';

try {
    $mail = createMailer();
    $mail->addAddress($email);
    $mail->Subject = "🏥 Your Pharos HIS (EHR) Login Credentials — {$hospitalName}";
    $mail->Body    = emailWrapper("EHR — Subscription Confirmed for {$hospitalName}", $emailBody);

    // Plain-text fallback
    $altText = "Your EHR subscription is active for {$hospitalName}.\n\n";
    foreach ($accounts as $role => $acct) {
        $altText .= strtoupper($role) . " Account\n"
                  . "Username: {$acct['username']}\nPassword: {$acct['password']}\n\n";
    }
    $altText .= "Please change all passwords after first login.";
    $mail->AltBody = $altText;

    $mail->send();
    $emailSent = true;
} catch (MailException $e) {
    $emailError = $e->getMessage();
}

// ── Response ──────────────────────────────────────────────────────────────────
// Passwords are only echoed back as a FALLBACK when the email failed to send
// — otherwise they only ever leave the server via the credentials email, so
// they don't sit in browser dev tools / proxy / server logs unnecessarily.
$responsePayload = [
    'success'    => true,
    'message'    => $emailSent
        ? "Subscription successful for {$hospitalName}. Credentials sent to {$email}."
        : "Subscription saved, but the credentials email failed ({$emailError}). Showing credentials below — please save them now and change passwords after first login.",
    'email_sent' => $emailSent,
    'hospital'   => $hospitalName,
    'usernames'  => array_map(fn($a) => $a['username'], $accounts),
];
if (!$emailSent) {
    $responsePayload['accounts'] = array_map(
        fn($a) => ['username' => $a['username'], 'password' => $a['password']],
        $accounts
    );
}
echo json_encode($responsePayload);
