<?php
/**
 * cancel_subscription.php
 * Marks a subscription as cancelled in the database.
 * Called via POST from Subscription.html (JavaScript fetch).
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

$data  = json_decode(file_get_contents('php://input'), true);
$email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);

if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

$safe = $conn->real_escape_string($email);
$check = $conn->query("SELECT id, status FROM subscriptions WHERE email='$safe'");

if ($check->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'No subscription found for this email.']);
    exit;
}

$row = $check->fetch_assoc();
if ($row['status'] === 'cancelled') {
    echo json_encode(['success' => false, 'message' => 'Subscription is already cancelled.']);
    exit;
}

$conn->query("UPDATE subscriptions SET status='cancelled' WHERE email='$safe'");
// Also deactivate pharmacist record
$conn->query("UPDATE pharmacists SET status='inactive' WHERE email='$safe'");

echo json_encode([
    'success' => true,
    'message' => 'Subscription for ' . $email . ' has been cancelled. Access remains active until end of billing period.'
]);

$conn->close();
