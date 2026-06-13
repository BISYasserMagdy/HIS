<?php
/**
 * subscribe_ehr.php
 * ════════════════════════════════════════════════════════════════════════════
 * Handles new EHR subscription form submissions from Subscription.html.
 *
 * On a successful subscription, this script:
 *   1. Creates a new row in `hospitals` (name supplied by the subscriber;
 *      logo can be added later by the admin from EHR_System.php).
 *   2. Auto-generates FOUR staff accounts scoped to that hospital:
 *        - 1 Admin   (full access, manages staff for this hospital)
 *        - 1 Manager (view-only dashboards)
 *        - 1 Doctor  (sample clinical account)
 *        - 1 Nurse   (sample clinical account)
 *   3. Returns the generated usernames + plain-text passwords so the
 *      subscriber can log in immediately (dev/testing). In production,
 *      these should be emailed instead of returned in the response.
 *
 * Place this file alongside EHR_System.php (Back End/ folder) — it shares
 * the same `healthcare_ehr` database and `users`/`hospitals` tables.
 * ════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'healthcare_ehr');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed: ' . $conn->connect_error]);
    exit;
}
$conn->set_charset('utf8mb4');
$conn->query("SET SESSION sql_mode = ''");

// ── Ensure tables exist (in case this endpoint is hit before EHR_System.php) ──
$conn->query("CREATE TABLE IF NOT EXISTS `hospitals` (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(160) NOT NULL,
    logo_url    VARCHAR(255) NULL,
    email       VARCHAR(150) NULL,
    plan        VARCHAR(50)  NULL,
    status      ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hospital_name (name)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `users` (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    username      VARCHAR(80)     NOT NULL,
    email         VARCHAR(120)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    role          ENUM('admin','manager','doctor','nurse') NOT NULL DEFAULT 'nurse',
    hospital      VARCHAR(120)    NOT NULL DEFAULT 'General Hospital',
    full_name     VARCHAR(160)    NULL,
    specialty     VARCHAR(100)    NULL,
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username),
    UNIQUE KEY uq_email    (email),
    KEY idx_role (role),
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

// ── Check for duplicate hospital name ─────────────────────────────────────────
$check = $conn->prepare("SELECT id, status FROM hospitals WHERE name = ?");
$check->bind_param('s', $hospitalName);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing && $existing['status'] === 'active') {
    echo json_encode(['success' => false, 'message' => 'A hospital with this name is already subscribed. Please choose a different name or contact support.']);
    exit;
}

// ── Generate unique usernames & passwords ─────────────────────────────────────
function ehrSlug(string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    return trim($slug, '_');
}
function ehrGeneratePassword(int $length = 12): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$';
    $pass  = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}
// Append a short random suffix to keep usernames unique across hospitals
// that might otherwise collide on slug (e.g. two "City Hospital"s).
$suffix = strtoupper(substr(md5(uniqid((string)random_int(0, 999999), true)), 0, 4));
$slug   = ehrSlug($hospitalName) ?: 'hospital';

$accounts = [
    'admin'   => [
        'username'  => "admin_{$slug}_{$suffix}",
        'full_name' => 'System Administrator',
        'specialty' => null,
    ],
    'manager' => [
        'username'  => "manager_{$slug}_{$suffix}",
        'full_name' => 'Hospital Manager',
        'specialty' => null,
    ],
    'doctor'  => [
        'username'  => "doctor_{$slug}_{$suffix}",
        'full_name' => 'Dr. New Doctor',
        'specialty' => 'General Medicine',
    ],
    'nurse'   => [
        'username'  => "nurse_{$slug}_{$suffix}",
        'full_name' => 'New Nurse',
        'specialty' => null,
    ],
];

// Generate a plain-text password per account (returned for dev/testing,
// would be emailed in production) and its bcrypt hash for storage.
foreach ($accounts as $role => &$acct) {
    $acct['password']      = ehrGeneratePassword();
    $acct['password_hash'] = password_hash($acct['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    // Each account gets its own contact email derived from the subscriber's
    // email + role, so the `uq_email` constraint never collides across roles.
    $emailParts = explode('@', $email, 2);
    $acct['email'] = $emailParts[0] . '+' . $role . '_' . strtolower($suffix) . '@' . ($emailParts[1] ?? 'example.com');
}
unset($acct);

// ── Persist everything in a transaction ────────────────────────────────────────
$conn->begin_transaction();
try {
    // 1) Create or reactivate the hospital row
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

    // 2) Insert the four staff accounts
    $stmt = $conn->prepare(
        "INSERT INTO users (username, email, password_hash, role, full_name, specialty, hospital)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($accounts as $role => $acct) {
        $stmt->bind_param(
            'sssssss',
            $acct['username'],
            $acct['email'],
            $acct['password_hash'],
            $role,
            $acct['full_name'],
            $acct['specialty'],
            $hospitalName
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

// ── Send credentials email (requires PHPMailer or similar) ───────────────────
// Uncomment and configure once SMTP is set up. Sends all four sets of
// credentials to the subscriber's real email address.
/*
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host       = 'smtp.gmail.com';
$mail->SMTPAuth   = true;
$mail->Username   = 'your-sender@gmail.com';
$mail->Password   = 'app-password';
$mail->SMTPSecure = 'tls';
$mail->Port       = 587;
$mail->setFrom('your-sender@gmail.com', 'Pharos HIS');
$mail->addAddress($email);
$mail->Subject = 'Your Pharos HIS — EHR Login Credentials';
$mail->isHTML(true);
$mail->Body = "
<h2>Welcome to Pharos HIS — {$hospitalName}</h2>
<p>Your EHR subscription is now active. Here are your login credentials:</p>
<h3>Admin Account</h3>
<p>Username: <strong>{$accounts['admin']['username']}</strong><br>Password: <strong>{$accounts['admin']['password']}</strong></p>
<h3>Manager Account</h3>
<p>Username: <strong>{$accounts['manager']['username']}</strong><br>Password: <strong>{$accounts['manager']['password']}</strong></p>
<h3>Doctor Account</h3>
<p>Username: <strong>{$accounts['doctor']['username']}</strong><br>Password: <strong>{$accounts['doctor']['password']}</strong></p>
<h3>Nurse Account</h3>
<p>Username: <strong>{$accounts['nurse']['username']}</strong><br>Password: <strong>{$accounts['nurse']['password']}</strong></p>
<p>Please change all passwords after first login. The Admin account can manage staff, branding (logo), and other settings from the EHR dashboard.</p>
";
$mail->send();
*/

echo json_encode([
    'success'  => true,
    'message'  => 'Subscription successful for ' . $hospitalName . '. Credentials generated below.',
    'hospital' => $hospitalName,
    'accounts' => [
        'admin'   => ['username' => $accounts['admin']['username'],   'password' => $accounts['admin']['password']],
        'manager' => ['username' => $accounts['manager']['username'], 'password' => $accounts['manager']['password']],
        'doctor'  => ['username' => $accounts['doctor']['username'],  'password' => $accounts['doctor']['password']],
        'nurse'   => ['username' => $accounts['nurse']['username'],   'password' => $accounts['nurse']['password']],
    ],
]);
