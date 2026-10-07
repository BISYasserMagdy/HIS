<?php
/**
 * cancel_subscription_ehr.php
 * ════════════════════════════════════════════════════════════════════════════
 * Cancels an EHR/hospital subscription created via subscribe_ehr.php.
 * Called via POST from Subscription_EHR.html (JavaScript fetch).
 *
 * Looks up the `hospitals` row by the subscriber's email, marks it
 * 'cancelled', and deactivates (is_active = 0) every staff account
 * (admin/manager/doctor/nurse) belonging to that hospital. Accounts and
 * data are NOT deleted — re-subscribing under the same hospital name via
 * subscribe_ehr.php will reactivate the hospital row, but staff accounts
 * remain inactive until an admin manually reactivates them.
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

// ── Read & validate input ─────────────────────────────────────────────────────
$data  = json_decode(file_get_contents('php://input'), true);
$email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);

if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// ── Find the hospital subscribed under this email ─────────────────────────────
$stmt = $conn->prepare("SELECT id, name, status FROM hospitals WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$hospital = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$hospital) {
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'No subscription found for this email address.']);
    exit;
}

if ($hospital['status'] === 'cancelled') {
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'This subscription is already cancelled.']);
    exit;
}

// ── Cancel the hospital + deactivate all its staff accounts ───────────────────
$conn->begin_transaction();
try {
    $upd = $conn->prepare("UPDATE hospitals SET status = 'cancelled' WHERE id = ?");
    $upd->bind_param('i', $hospital['id']);
    $upd->execute();
    $upd->close();

    $deact = $conn->prepare(
        "UPDATE users SET is_active = 0 WHERE hospital = ? AND role IN ('admin','manager','doctor','nurse')"
    );
    $deact->bind_param('s', $hospital['name']);
    $deact->execute();
    $affected = $deact->affected_rows;
    $deact->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'Could not cancel subscription: ' . $e->getMessage()]);
    exit;
}

$conn->close();

echo json_encode([
    'success'        => true,
    'message'        => 'The EHR subscription for ' . $hospital['name'] . ' has been cancelled and ' . $affected . ' staff account(s) deactivated.',
    'hospital'       => $hospital['name'],
    'deactivated'    => $affected,
]);
