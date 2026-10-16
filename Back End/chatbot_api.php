<?php
/**
 * chatbot_api.php — public API for the floating health chatbot on Home_Page.html
 *
 *   GET  ?action=suggest&topic=pain&lat=31.2&lng=29.9   OTC medicines in stock + nearest branches
 *   POST ?action=order   (JSON body)                    place an online order with one branch
 *
 * Safety rules enforced HERE (not only in the browser):
 *   - Only the OTC keyword allow-list below can be suggested or ordered.
 *     Prescription-only drugs (antibiotics, insulin, antivirals, ...) are never exposed.
 *   - Prices and totals always come from the database, never from the client.
 *   - Orders are "pending" until the pharmacy confirms by phone; no online payment.
 *
 * Tables created on first use (no FOREIGN KEYs on purpose, to avoid errno 150):
 *   medicines (same shape as the ERP one), pharmacy_branches, branch_stock, online_orders
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// Same-origin only: no Access-Control-Allow-Origin header is sent on purpose.

mysqli_report(MYSQLI_REPORT_OFF);
session_start();

require_once __DIR__ . '/erp_db_config.php';

/** OTC allow-list: topic => name keywords matched against medicines.name_en / name_ar */
const OTC_TOPICS = [
    'pain'         => ['paracetamol', 'باراسيتامول'],
    'cold'         => ['cough', 'سعال', 'vitamin c', 'فيتامين سي', 'nasal', 'الأنف'],
    'stomach'      => ['antacid', 'الحموضة', 'حموضة'],
    'constipation' => ['laxative', 'lactulose', 'ملين', 'لاكتولوز'],
    'firstaid'     => ['bandage', 'ضماد'],
    'vitamins'     => ['vitamin', 'فيتامين'],
];

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void {
    out(['success' => false, 'message' => $msg], $code);
}

function db(): mysqli {
    static $conn = null;
    if ($conn) return $conn;
    $conn = @new mysqli(ERP_DB_HOST, ERP_DB_USER, ERP_DB_PASS, ERP_DB_NAME);
    if ($conn->connect_error) {
        error_log('chatbot_api DB connect: ' . $conn->connect_error);
        fail('الخدمة مش متاحة دلوقتي، حاول بعد شوية.', 503);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function ensureSchema(mysqli $c): void {
    // Same shape as ERP_Pharmacy_System.php so both can share the table.
    $c->query("CREATE TABLE IF NOT EXISTS medicines (
        id INT PRIMARY KEY AUTO_INCREMENT,
        medicine_code VARCHAR(50) UNIQUE NOT NULL,
        name_en VARCHAR(150) NOT NULL,
        name_ar VARCHAR(150) NOT NULL,
        description TEXT,
        strength VARCHAR(50),
        category VARCHAR(100),
        quantity_in_stock INT DEFAULT 0,
        unit_price DECIMAL(10,2),
        reorder_level INT DEFAULT 10,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB CHARACTER SET utf8mb4");

    $c->query("CREATE TABLE IF NOT EXISTS pharmacy_branches (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        address VARCHAR(255) NULL,
        phone VARCHAR(30) NULL,
        lat DECIMAL(9,6) NULL,
        lng DECIMAL(9,6) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB CHARACTER SET utf8mb4");

    $c->query("CREATE TABLE IF NOT EXISTS branch_stock (
        branch_id INT UNSIGNED NOT NULL,
        medicine_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        PRIMARY KEY (branch_id, medicine_id),
        KEY idx_medicine (medicine_id)
    ) ENGINE=InnoDB CHARACTER SET utf8mb4");

    $c->query("CREATE TABLE IF NOT EXISTS online_orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(24) NOT NULL,
        branch_id INT UNSIGNED NOT NULL,
        customer_name VARCHAR(100) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        fulfillment ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
        address VARCHAR(255) NULL,
        items TEXT NOT NULL,
        total DECIMAL(10,2) NOT NULL,
        status ENUM('pending','confirmed','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_order_number (order_number),
        KEY idx_status (status),
        KEY idx_branch (branch_id)
    ) ENGINE=InnoDB CHARACTER SET utf8mb4");

    // First run only: SAMPLE data so the chatbot works end to end.
    // Replace these rows with your real branches (phpMyAdmin > pharmacy_branches).
    $r = $c->query("SELECT COUNT(*) AS n FROM pharmacy_branches");
    if ($r && (int)$r->fetch_assoc()['n'] === 0) {
        $c->query("INSERT IGNORE INTO medicines
            (medicine_code, name_en, name_ar, description, strength, category, quantity_in_stock, unit_price, reorder_level) VALUES
            ('P001','Paracetamol 500mg','باراسيتامول 500 مجم','Pain relief tablet','500mg','Pain Relief',120,3.50,10),
            ('P002','Cough Syrup','شراب السعال','Soothing cough syrup','200ml','Cough & Cold',45,9.75,8),
            ('P003','Antacid Tablets','أقراص مضادة للحموضة','Antacid for heartburn relief','20 tablets','Digestive',78,5.20,12),
            ('P004','Vitamin C Pack','حزمة فيتامين سي','Vitamin C supplement pack','60 tablets','Vitamins',200,7.40,15),
            ('P005','Bandage Roll','لفافة ضماد','Flexible bandage roll','1 roll','First Aid',60,2.80,20)");
        $c->query("INSERT INTO pharmacy_branches (name, address, phone, lat, lng) VALUES
            ('فرع تجريبي 1 - سموحة', 'الإسكندرية - سموحة (بيانات تجريبية)', '0000000001', 31.215600, 29.944100),
            ('فرع تجريبي 2 - سيدي جابر', 'الإسكندرية - سيدي جابر (بيانات تجريبية)', '0000000002', 31.219500, 29.941500),
            ('فرع تجريبي 3 - العجمي', 'الإسكندرية - العجمي (بيانات تجريبية)', '0000000003', 31.096000, 29.763000)");
        $c->query("INSERT IGNORE INTO branch_stock (branch_id, medicine_id, quantity)
            SELECT b.id, m.id, GREATEST(1, FLOOR(m.quantity_in_stock / 3))
            FROM pharmacy_branches b CROSS JOIN medicines m");
    }
}

function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function allOtcKeywords(): array {
    return array_merge(...array_values(OTC_TOPICS));
}

function matchesKeywords(array $med, array $keywords): bool {
    $hay = mb_strtolower(($med['name_en'] ?? '') . ' ' . ($med['name_ar'] ?? ''), 'UTF-8');
    foreach ($keywords as $k) {
        if (mb_strpos($hay, mb_strtolower($k, 'UTF-8')) !== false) return true;
    }
    return false;
}

function floatOrNull($v): ?float {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return (float)$v;
}

// ───────────────────────────── suggest ─────────────────────────────
function actionSuggest(mysqli $c): void {
    $topic = $_GET['topic'] ?? '';
    if (!is_string($topic) || !isset(OTC_TOPICS[$topic])) fail('موضوع غير معروف.');
    $keywords = OTC_TOPICS[$topic];

    $lat = floatOrNull($_GET['lat'] ?? null);
    $lng = floatOrNull($_GET['lng'] ?? null);
    if ($lat !== null && ($lat < -90 || $lat > 90))   $lat = null;
    if ($lng !== null && ($lng < -180 || $lng > 180)) $lng = null;
    $hasLoc = $lat !== null && $lng !== null;

    $where = [];
    $types = '';
    $params = [];
    foreach ($keywords as $k) {
        $where[] = '(name_en LIKE ? OR name_ar LIKE ?)';
        $types .= 'ss';
        $like = '%' . $k . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql = "SELECT id, medicine_code, name_en, name_ar, strength, unit_price
            FROM medicines WHERE " . implode(' OR ', $where) . " ORDER BY name_en LIMIT 12";
    $st = $c->prepare($sql);
    if (!$st) fail('تعذر جلب الأدوية.', 500);
    $st->bind_param($types, ...$params);
    $st->execute();
    $meds = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $result = [];
    foreach ($meds as $m) {
        $q = $c->prepare("SELECT b.id, b.name, b.address, b.phone, b.lat, b.lng, s.quantity
                          FROM branch_stock s JOIN pharmacy_branches b ON b.id = s.branch_id
                          WHERE s.medicine_id = ? AND s.quantity > 0 AND b.is_active = 1");
        if (!$q) continue;
        $mid = (int)$m['id'];
        $q->bind_param('i', $mid);
        $q->execute();
        $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        $branches = [];
        foreach ($rows as $b) {
            $dist = null;
            if ($hasLoc && $b['lat'] !== null && $b['lng'] !== null) {
                $dist = round(haversineKm($lat, $lng, (float)$b['lat'], (float)$b['lng']), 1);
            }
            $branches[] = [
                'branch_id'   => (int)$b['id'],
                'name'        => $b['name'],
                'address'     => $b['address'],
                'phone'       => $b['phone'],
                'distance_km' => $dist,
                'in_stock'    => (int)$b['quantity'],
            ];
        }
        usort($branches, function ($a, $b) {
            if ($a['distance_km'] === null && $b['distance_km'] === null) return strcmp($a['name'], $b['name']);
            if ($a['distance_km'] === null) return 1;
            if ($b['distance_km'] === null) return -1;
            return $a['distance_km'] <=> $b['distance_km'];
        });
        if (!$branches) continue; // not available anywhere: don't offer it

        $result[] = [
            'medicine_id' => $mid,
            'name_ar'     => $m['name_ar'],
            'name_en'     => $m['name_en'],
            'strength'    => $m['strength'],
            'unit_price'  => (float)$m['unit_price'],
            'branches'    => array_slice($branches, 0, 3),
        ];
    }

    out(['success' => true, 'topic' => $topic, 'located' => $hasLoc, 'medicines' => $result]);
}

// ───────────────────────────── order ─────────────────────────────
function actionOrder(mysqli $c): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);

    // Basic abuse limit: 5 orders per hour per browser session.
    $now = time();
    $_SESSION['chat_orders'] = array_values(array_filter($_SESSION['chat_orders'] ?? [], fn($t) => $t > $now - 3600));
    if (count($_SESSION['chat_orders']) >= 5) fail('وصلت للحد الأقصى من الطلبات دلوقتي. حاول بعد شوية.', 429);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) fail('بيانات غير صالحة.');

    $branchId    = (int)($body['branch_id'] ?? 0);
    $name        = trim((string)($body['name'] ?? ''));
    $phone       = trim((string)($body['phone'] ?? ''));
    $fulfillment = ($body['fulfillment'] ?? '') === 'delivery' ? 'delivery' : 'pickup';
    $address     = trim((string)($body['address'] ?? ''));
    $items       = $body['items'] ?? [];

    if ($branchId < 1) fail('اختار الصيدلية.');
    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 100) fail('اكتب اسمك.');
    if (!preg_match('/^[0-9+\-\s]{8,20}$/', $phone)) fail('رقم التليفون مش صحيح.');
    if ($fulfillment === 'delivery' && (mb_strlen($address, 'UTF-8') < 6 || mb_strlen($address, 'UTF-8') > 255)) {
        fail('اكتب عنوان التوصيل.');
    }
    if ($fulfillment === 'pickup') $address = '';
    if (!is_array($items) || count($items) < 1 || count($items) > 6) fail('اختار دواء واحد على الأقل.');

    $b = $c->prepare("SELECT id, name FROM pharmacy_branches WHERE id = ? AND is_active = 1");
    $b->bind_param('i', $branchId);
    $b->execute();
    $branch = $b->get_result()->fetch_assoc();
    $b->close();
    if (!$branch) fail('الصيدلية دي مش متاحة.');

    $otc = allOtcKeywords();
    $lines = [];
    $total = 0.0;

    $c->begin_transaction();
    foreach ($items as $it) {
        $mid = (int)($it['medicine_id'] ?? 0);
        $qty = (int)($it['qty'] ?? 0);
        if ($mid < 1 || $qty < 1 || $qty > 10) { $c->rollback(); fail('الكمية غير صحيحة (من 1 لـ 10).'); }

        $m = $c->prepare("SELECT m.id, m.name_en, m.name_ar, m.unit_price, s.quantity
                          FROM medicines m JOIN branch_stock s ON s.medicine_id = m.id AND s.branch_id = ?
                          WHERE m.id = ? FOR UPDATE");
        $m->bind_param('ii', $branchId, $mid);
        $m->execute();
        $med = $m->get_result()->fetch_assoc();
        $m->close();

        if (!$med || !matchesKeywords($med, $otc)) { $c->rollback(); fail('دواء غير متاح للطلب أونلاين.'); }
        if ((int)$med['quantity'] < $qty)           { $c->rollback(); fail('الكمية المطلوبة من "' . $med['name_ar'] . '" مش متوفرة في الصيدلية دي.'); }

        $price = (float)$med['unit_price'];
        $total += $price * $qty;
        $lines[] = ['medicine_id' => $mid, 'name_ar' => $med['name_ar'], 'name_en' => $med['name_en'], 'qty' => $qty, 'unit_price' => $price];
    }

    $itemsJson = json_encode($lines, JSON_UNESCAPED_UNICODE);
    $orderNo = null;
    for ($i = 0; $i < 5 && !$orderNo; $i++) {
        $cand = 'WEB-' . date('ymd') . '-' . random_int(1000, 9999);
        $ins = $c->prepare("INSERT INTO online_orders (order_number, branch_id, customer_name, phone, fulfillment, address, items, total)
                            VALUES (?,?,?,?,?,?,?,?)");
        $ins->bind_param('sisssssd', $cand, $branchId, $name, $phone, $fulfillment, $address, $itemsJson, $total);
        if ($ins->execute()) $orderNo = $cand;
        $ins->close();
    }
    if (!$orderNo) { $c->rollback(); fail('تعذر تسجيل الطلب، حاول تاني.', 500); }
    $c->commit();

    $_SESSION['chat_orders'][] = $now;
    out([
        'success'      => true,
        'order_number' => $orderNo,
        'branch'       => $branch['name'],
        'total'        => round($total, 2),
        'fulfillment'  => $fulfillment,
    ]);
}

// ───────────────────────────── router ─────────────────────────────
$action = $_GET['action'] ?? '';
$conn = db();
ensureSchema($conn);

switch ($action) {
    case 'suggest': actionSuggest($conn); break;
    case 'order':   actionOrder($conn);   break;
    case 'ping':    out(['success' => true]); break;
    default:        fail('Unknown action', 404);
}
