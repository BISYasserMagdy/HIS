<?php
/**
 * subscribe.php
 * Handles new subscription form submissions.
 * Called via POST from Subscription.html (JavaScript fetch).
 *
 * Saves: email, plan, payment method, generated manager & employee credentials
 * In production, also sends credentials to the user's Gmail via PHPMailer/SMTP.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "pharmacy_erp";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed.']);
    exit;
}

// ── Create subscriptions table if not exists ──────────────────────────────────
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
$plan          = $conn->real_escape_string($data['plan'] ?? '');
$paymentMethod = $conn->real_escape_string($data['payment_method'] ?? '');

if (!$email || !$plan || !$paymentMethod) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid fields.']);
    exit;
}

// ── Check for duplicate email ─────────────────────────────────────────────────
$check = $conn->query("SELECT id, status FROM subscriptions WHERE email = '$email'");
if ($check->num_rows > 0) {
    $row = $check->fetch_assoc();
    if ($row['status'] === 'active') {
        echo json_encode(['success' => false, 'message' => 'This email is already subscribed.']);
        exit;
    }
    // Re-activating a cancelled subscription: update instead of insert
    $reactivate = true;
} else {
    $reactivate = false;
}

// ── Generate unique IDs & passwords ──────────────────────────────────────────
function generateId(string $prefix): string {
    return strtoupper($prefix) . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
}
function generatePassword(int $length = 12): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#\$';
    $pass  = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}

$managerId   = generateId('MGR');
$managerPass = generatePassword();
$employeeId  = generateId('EMP');
$employeePass = generatePassword();

// Store hashed passwords in DB; send plain-text ones to email
$managerPassHash  = password_hash($managerPass,  PASSWORD_BCRYPT);
$employeePassHash = password_hash($employeePass, PASSWORD_BCRYPT);

if ($reactivate) {
    $sql = "UPDATE subscriptions SET
                plan='$plan', payment_method='$paymentMethod',
                manager_id='$managerId', manager_pass='$managerPassHash',
                employee_id='$employeeId', employee_pass='$employeePassHash',
                status='active', subscribed_at=NOW()
            WHERE email='$email'";
} else {
    $sql = "INSERT INTO subscriptions
                (email, plan, payment_method, manager_id, manager_pass, employee_id, employee_pass)
            VALUES
                ('$email', '$plan', '$paymentMethod', '$managerId', '$managerPassHash', '$employeeId', '$employeePassHash')";
}

if (!$conn->query($sql)) {
    echo json_encode(['success' => false, 'message' => 'Could not save subscription: ' . $conn->error]);
    exit;
}

// ── Also upsert into pharmacists table so manager can log in ──────────────────
$managerName = 'Manager (' . $email . ')';
$conn->query("INSERT IGNORE INTO pharmacists (pharmacist_id, name, email, status, password_hash)
              VALUES ('$managerId', '$managerName', '$email', 'active', '$managerPassHash')");

// ── Send credentials email (requires PHPMailer or similar) ───────────────────
// Uncomment and configure once SMTP is set up.
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
$mail->setFrom('your-sender@gmail.com', 'Health Information System');
$mail->addAddress($email);
$mail->Subject = 'Your HIS Login Credentials';
$mail->isHTML(true);
$mail->Body = "
<h2>Welcome to Health Information System</h2>
<p>Your subscription is now active. Here are your login credentials:</p>
<h3>Manager Account</h3>
<p>ID: <strong>$managerId</strong><br>Password: <strong>$managerPass</strong></p>
<h3>Employee Account</h3>
<p>ID: <strong>$employeeId</strong><br>Password: <strong>$employeePass</strong></p>
<p>Please change your passwords after first login.</p>
";
$mail->send();
*/

echo json_encode([
    'success'      => true,
    'message'      => 'Subscription successful. Credentials sent to ' . $email,
    'manager_id'   => $managerId,
    'employee_id'  => $employeeId,
    // Remove the plain-text passwords from the response once email is wired up.
    // Included here for development/testing only.
    'manager_pass_dev'  => $managerPass,
    'employee_pass_dev' => $employeePass,
]);

$conn->close();
