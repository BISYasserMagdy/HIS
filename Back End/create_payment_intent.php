<?php
/**
 * create_payment_intent.php
 * ════════════════════════════════════════════════════════════════════════════
 * Creates a Stripe PaymentIntent for an EHR subscription plan and returns its
 * client_secret so the browser can confirm the card payment with Stripe.js.
 *
 * The plan price is looked up server-side (see stripe_config.php) — the
 * amount is never taken from the client, so a tampered request can't change
 * what gets charged.
 *
 * Requires: composer require stripe/stripe-php   (run inside Back End/)
 *           stripe_config.php (same folder)
 * ════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/stripe_config.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$data         = json_decode(file_get_contents('php://input'), true);
$plan         = trim($data['plan'] ?? '');
$email        = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$hospitalName = trim($data['hospital_name'] ?? '');

$prices = EHR_PLAN_PRICES_CENTS;

if (!isset($prices[$plan])) {
    echo json_encode(['success' => false, 'message' => 'Unknown or missing plan.']);
    exit;
}
if (!$email) {
    echo json_encode(['success' => false, 'message' => 'A valid email address is required.']);
    exit;
}
if ($hospitalName === '') {
    echo json_encode(['success' => false, 'message' => 'Hospital name is required.']);
    exit;
}

// ── Don't charge the card if this hospital is already actively subscribed ──
$conn = new mysqli('localhost', 'root', '', 'healthcare_ehr');
if (!$conn->connect_error) {
    $conn->set_charset('utf8mb4');
    $check = $conn->prepare("SELECT status FROM hospitals WHERE name = ?");
    $check->bind_param('s', $hospitalName);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    $conn->close();

    if ($existing && $existing['status'] === 'active') {
        echo json_encode(['success' => false, 'message' => 'A hospital with this name is already subscribed.']);
        exit;
    }
}

try {
    $intent = \Stripe\PaymentIntent::create([
        'amount'   => $prices[$plan],
        'currency' => 'usd',
        'metadata' => [
            'plan'          => $plan,
            'email'         => $email,
            'hospital_name' => $hospitalName,
            'product'       => 'pharos_his_ehr_subscription',
        ],
        'receipt_email' => $email,
    ]);

    echo json_encode([
        'success'       => true,
        'client_secret' => $intent->client_secret,
    ]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    echo json_encode(['success' => false, 'message' => 'Could not start payment: ' . $e->getMessage()]);
}
