<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

session_start();

// Initialize database if it doesn't exist (on first run)
initializeDatabase();

// If Composer autoload exists (Dompdf), include it
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Handle GET request for API endpoints
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = isset($_GET['action']) ? trim($_GET['action']) : '';
    $lang = isset($_GET['lang']) && strtolower($_GET['lang']) === 'ar' ? 'ar' : 'en';

    if ($action === 'products') {
        sendProductList($lang);
    } elseif ($action === 'sales') {
        sendSalesList();
    } elseif ($action === 'dashboard_stats') {
        sendDashboardStats();
    } elseif ($action === 'monthly_summary') {
        sendMonthlySummary();
    } elseif ($action === 'sales_pdf') {
        generateSalesPDF();
    } elseif ($action === 'inventory_pdf') {
        generateInventoryPDF();
    } elseif ($action === 'financial_pdf') {
        generateFinancialPDF();
    } elseif ($action === 'trend_pdf') {
        generateTrendPDF();
    } elseif ($action === 'sales_report') {
        generateSalesReport();
    } elseif ($action === 'inventory_report') {
        generateInventoryReport();
    } elseif ($action === 'financial_report') {
        generateFinancialReport();
    } elseif ($action === 'sales_data') {
        sendSalesData();
    } elseif ($action === 'trend_data') {
        sendTrendData();
    } elseif ($action === 'trend_analysis') {
        generateTrendAnalysis();
    } elseif ($action === 'get_suppliers') {
        sendSupplierList();
    } elseif ($action === 'near_expiry_medicines') {
        sendNearExpiryMedicines();
    } elseif ($action === 'backup_database') {
        backupDatabase();
    } elseif ($action === 'confirm_factory_reset') {
        confirmFactoryReset();
    } else {
    }
    exit;
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = '';
    if (isset($_POST['action'])) {
        $action = trim($_POST['action']);
    } elseif (isset($_GET['action'])) {
        $action = trim($_GET['action']);
    }

    if ($action === 'add_supplier') {
        handleAddSupplier();
        exit;
    }

    if ($action === 'edit_supplier') {
        handleEditSupplier();
        exit;
    }

    if ($action === 'delete_supplier') {
        handleDeleteSupplier();
        exit;
    }

    if ($action === 'factory_reset_request') {
        handleFactoryResetRequest();
        exit;
    }

    if ($action === 'restore_database') {
        handleRestoreDatabase();
        exit;
    }

    if ($action === 'submit_sale') {
        handleSubmitSale();
        exit;
    }

    if ($action === 'trend_pdf') {
        generateTrendPDF();
        exit;
    }

    // Get POST data
    $user_type = isset($_POST['user_type']) ? trim($_POST['user_type']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $lang = isset($_POST['lang']) && strtolower($_POST['lang']) === 'ar' ? 'ar' : 'en';
    $user_id = '';

    if (isset($_POST['user_id'])) {
        $user_id = trim($_POST['user_id']);
    } elseif (isset($_POST['pharmacist_id'])) {
        $user_id = trim($_POST['pharmacist_id']);
    } elseif (isset($_POST['employee_id'])) {
        $user_id = trim($_POST['employee_id']);
    }

    // Auto-detect user type by ID prefix when not explicitly provided
    if (empty($user_type)) {
        if (stripos($user_id, 'EMP') === 0) {
            $user_type = 'employee';
        } else {
            $user_type = 'pharmacist';
        }
    }

    // Validate input
    if (empty($user_id) || empty($password)) {
        http_response_code(400);
        echo json_encode(array(
            "success" => false,
            "message" => "User ID and password are required"
        ));
        exit;
    }

    // Authenticate user
    $result = authenticateUser($user_type, $user_id, $password);
    
    if ($result['success']) {
        // Store session data
        $_SESSION['user_type'] = $user_type;
        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_name'] = $result['name'];
        $_SESSION['login_time'] = time();

        if ($user_type === 'employee') {
            $redirect = $lang === 'ar' ? 'ERP POS System AR.html' : 'ERP_POS_System.html';
        } else {
            $redirect = $lang === 'ar' ? 'ERP Dashboard AR.html' : 'ERP_Dashboard.html';
        }

        http_response_code(200);
        echo json_encode(array(
            "success" => true,
            "message" => "Login successful! Welcome " . $result['name'],
            "name" => $result['name'],
            "user_type" => $user_type,
            "redirect" => $redirect
        ));
    } else {
        http_response_code(401);
        echo json_encode(array(
            "success" => false,
            "message" => $result['message']
        ));
    }
    exit;
}

http_response_code(405);
echo json_encode(array("success" => false, "message" => "Method not allowed"));

// ============ HELPER FUNCTIONS ============

/**
 * Initialize database - Creates database and tables if they don't exist
 */
function initializeDatabase() {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    // First connection without database to check/create it
    $conn = new mysqli($servername, $username, $password);

    if ($conn->connect_error) {
        // Silently fail - will try again later
        return;
    }

    // Create database if it doesn't exist
    $sql = "CREATE DATABASE IF NOT EXISTS `" . $database . "`";
    $conn->query($sql);

    // Select the database
    $conn->select_db($database);

    // Create pharmacists table
    $sql = "CREATE TABLE IF NOT EXISTS pharmacists (
        id INT PRIMARY KEY AUTO_INCREMENT,
        pharmacist_id VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100),
        status ENUM('active', 'inactive') DEFAULT 'active',
        password_hash VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($sql);

    // Create employees table
    $sql = "CREATE TABLE IF NOT EXISTS employees (
        id INT PRIMARY KEY AUTO_INCREMENT,
        employee_id VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100),
        status ENUM('active', 'inactive') DEFAULT 'active',
        password_hash VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($sql);

    // Insert demo pharmacist and employee data if not exists
    $sql = "INSERT IGNORE INTO pharmacists (pharmacist_id, name, email, status) VALUES 
        ('PHARM001', 'Dr. Ahmed Hassan', 'ahmed@pharmacy.com', 'active'),
        ('PHARM002', 'Dr. Sarah Johnson', 'sarah@pharmacy.com', 'active')";
    $conn->query($sql);

    $sql = "INSERT IGNORE INTO employees (employee_id, name, email, status) VALUES 
        ('EMP001', 'Amina Saleh', 'amina@pharmacy.com', 'active'),
        ('EMP002', 'Omar Ali', 'omar@pharmacy.com', 'active')";
    $conn->query($sql);

    // Create medicines table
    $sql = "CREATE TABLE IF NOT EXISTS medicines (
        id INT PRIMARY KEY AUTO_INCREMENT,
        medicine_code VARCHAR(50) UNIQUE NOT NULL,
        name_en VARCHAR(150) NOT NULL,
        name_ar VARCHAR(150) NOT NULL,
        description TEXT,
        strength VARCHAR(50),
        category VARCHAR(100),
        quantity_in_stock INT DEFAULT 0,
        unit_price DECIMAL(10, 2),
        reorder_level INT DEFAULT 10,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($sql);

    // Add missing medicine columns if schema existed earlier
    $result = $conn->query("SHOW COLUMNS FROM medicines LIKE 'name_ar'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE medicines ADD COLUMN name_ar VARCHAR(150) NOT NULL DEFAULT ''");
    }
    $result = $conn->query("SHOW COLUMNS FROM medicines LIKE 'name_en'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE medicines ADD COLUMN name_en VARCHAR(150) NOT NULL DEFAULT name");
    }
    $result = $conn->query("SHOW COLUMNS FROM medicines LIKE 'category'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE medicines ADD COLUMN category VARCHAR(100) DEFAULT ''");
    }

    // Insert demo products if not exists
    $sql = "INSERT IGNORE INTO medicines (medicine_code, name_en, name_ar, description, strength, category, quantity_in_stock, unit_price, reorder_level) VALUES 
        ('P001', 'Paracetamol 500mg', 'باراسيتامول 500 مجم', 'Pain relief tablet', '500mg', 'Pain Relief', 120, 3.50, 10),
        ('P002', 'Cough Syrup', 'شراب السعال', 'Soothing cough syrup', '200ml', 'Cough & Cold', 45, 9.75, 8),
        ('P003', 'Antacid Tablets', 'أقراص مضادة للحموضة', 'Antacid for heartburn relief', '20 tablets', 'Digestive', 78, 5.20, 12),
        ('P004', 'Vitamin C Pack', 'حزمة فيتامين سي', 'Vitamin C supplement pack', '60 tablets', 'Vitamins', 200, 7.40, 15),
        ('P005', 'Bandage Roll', 'لفافة ضماد', 'Flexible bandage roll', '1 roll', 'First Aid', 60, 2.80, 20)";
    $conn->query($sql);

    // Create sales table
    $sql = "CREATE TABLE IF NOT EXISTS sales (
        id INT PRIMARY KEY AUTO_INCREMENT,
        invoice_number VARCHAR(50) UNIQUE NOT NULL,
        user_id VARCHAR(50) NOT NULL,
        user_name VARCHAR(100),
        items TEXT NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL,
        status ENUM('pending','completed') DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($sql);

    // Create prescriptions table
    $sql = "CREATE TABLE IF NOT EXISTS prescriptions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        prescription_code VARCHAR(50) UNIQUE NOT NULL,
        patient_id VARCHAR(50),
        patient_name VARCHAR(100),
        pharmacist_id VARCHAR(50),
        medicine_id INT,
        quantity INT,
        notes TEXT,
        status ENUM('pending', 'approved', 'dispensed', 'rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (medicine_id) REFERENCES medicines(id)
    )";
    $conn->query($sql);

    // Create purchases table (optional demo table)
    $sql = "CREATE TABLE IF NOT EXISTS purchases (
        id INT PRIMARY KEY AUTO_INCREMENT,
        po_number VARCHAR(100) UNIQUE NOT NULL,
        supplier VARCHAR(150),
        items TEXT,
        total_amount DECIMAL(10,2) DEFAULT 0,
        status ENUM('pending','received','cancelled') DEFAULT 'received',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($sql);

    // Create returns table (optional demo table)
    $sql = "CREATE TABLE IF NOT EXISTS returns (
        id INT PRIMARY KEY AUTO_INCREMENT,
        return_code VARCHAR(100) UNIQUE NOT NULL,
        sale_invoice VARCHAR(100),
        items TEXT,
        total_amount DECIMAL(10,2) DEFAULT 0,
        status ENUM('pending','processed') DEFAULT 'processed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($sql);

    // Insert demo purchases/returns if not exists (small sample)
    $conn->query("INSERT IGNORE INTO purchases (po_number, supplier, items, total_amount) VALUES
        ('PO-2026-001', 'Prime Pharma Supplies', '[{\"code\":\"P001\",\"qty\":50}]', 175.00),
        ('PO-2026-002', 'Global Meds', '[{\"code\":\"P004\",\"qty\":100}]', 740.00)");

    $conn->query("INSERT IGNORE INTO returns (return_code, sale_invoice, items, total_amount) VALUES
        ('RT-2026-001', 'INV-1780268671022', '[{\"code\":\"P002\",\"qty\":1}]', 9.75)");

    // Additional demo purchases/returns
    $conn->query("INSERT IGNORE INTO purchases (po_number, supplier, items, total_amount) VALUES
        ('PO-2026-003', 'Local Distributors', '[{\"code\":\"P002\",\"qty\":30}]', 292.50)");
    $conn->query("INSERT IGNORE INTO returns (return_code, sale_invoice, items, total_amount) VALUES
        ('RT-2026-002', 'INV-1780270892914', '[{\"code\":\"P004\",\"qty\":1}]', 7.40)");

    $conn->close();
}

function sendProductList($lang = 'en') {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Unable to connect to database"));
        exit;
    }

    $conn->set_charset("utf8");
    $sql = "SELECT id, medicine_code, name_en, name_ar, category, quantity_in_stock, unit_price FROM medicines ORDER BY id";
    $result = $conn->query($sql);

    $products = array();
    while ($row = $result->fetch_assoc()) {
        $products[] = array(
            "id" => $row['id'],
            "code" => $row['medicine_code'],
            "name" => $lang === 'ar' ? $row['name_ar'] : $row['name_en'],
            "category" => $row['category'],
            "quantity_in_stock" => (int)$row['quantity_in_stock'],
            "unit_price" => (float)$row['unit_price']
        );
    }

    $conn->close();

    echo json_encode(array("success" => true, "products" => $products));
}

function sendSalesList() {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Unable to connect to database"));
        exit;
    }

    $conn->set_charset("utf8");
    $sql = "SELECT invoice_number, user_id, user_name, items, total_amount, status, created_at FROM sales ORDER BY created_at DESC LIMIT 20";
    $result = $conn->query($sql);

    $sales = array();
    while ($row = $result->fetch_assoc()) {
        $sales[] = array(
            "invoice_number" => $row['invoice_number'],
            "user_id" => $row['user_id'],
            "user_name" => $row['user_name'],
            "items" => json_decode($row['items'], true),
            "total_amount" => (float)$row['total_amount'],
            "status" => $row['status'],
            "created_at" => $row['created_at']
        );
    }

    $conn->close();

    echo json_encode(array("success" => true, "sales" => $sales));
}

function sendDashboardStats() {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Unable to connect to database"));
        exit;
    }

    $conn->set_charset("utf8");

    // Total items in stock
    $result = $conn->query("SELECT SUM(quantity_in_stock) as total FROM medicines");
    $totalStock = $result->fetch_assoc()['total'] ?? 0;

    // Low stock items (below reorder level)
    $result = $conn->query("SELECT COUNT(*) as count FROM medicines WHERE quantity_in_stock < reorder_level");
    $lowStockCount = $result->fetch_assoc()['count'] ?? 0;

    // Today's sales (sum of total_amount for sales created today)
    $today = date('Y-m-d');
    $result = $conn->query("SELECT SUM(total_amount) as total FROM sales WHERE DATE(created_at) = '$today'");
    $todaysSales = $result->fetch_assoc()['total'] ?? 0;

    // Recent transactions for the table
    $result = $conn->query("SELECT invoice_number, user_id, user_name, items, total_amount, status, created_at FROM sales ORDER BY created_at DESC LIMIT 10");
    $transactions = array();
    while ($row = $result->fetch_assoc()) {
        $items = json_decode($row['items'], true);
        $transactions[] = array(
            "invoice_number" => $row['invoice_number'],
            "user_id" => $row['user_id'],
            "user_name" => $row['user_name'],
            "item_count" => count($items),
            "total_amount" => (float)$row['total_amount'],
            "status" => $row['status'],
            "created_at" => $row['created_at']
        );
    }

    $conn->close();

    echo json_encode(array(
        "success" => true,
        "total_stock" => (int)$totalStock,
        "low_stock_count" => (int)$lowStockCount,
        "todays_sales" => (float)$todaysSales,
        "transactions" => $transactions
    ));
}

/*
 * Generate monthly summary for reports (total sales, purchases, returns, net profit)
 * Query params: start_date=YYYY-MM-DD, end_date=YYYY-MM-DD (optional; defaults to current month)
 */
function sendMonthlySummary() {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Unable to connect to database"));
        exit;
    }

    $conn->set_charset("utf8");

    $start = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $end = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

    // Total sales in range
    $sql = "SELECT SUM(total_amount) as total_sales FROM sales WHERE DATE(created_at) BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    $totalSales = (float)($res->fetch_assoc()['total_sales'] ?? 0);
    $stmt->close();

    // Total purchases (only if purchases table exists)
    $totalPurchases = 0.0;
    $resCheck = $conn->query("SHOW TABLES LIKE 'purchases'");
    if ($resCheck && $resCheck->num_rows > 0) {
        $result = $conn->query("SELECT SUM(total_amount) as total FROM purchases WHERE DATE(created_at) BETWEEN '$start' AND '$end'");
        if ($result) {
            $totalPurchases = (float)($result->fetch_assoc()['total'] ?? 0);
        }
    }

    // Total returns (only if returns table exists)
    $totalReturns = 0.0;
    $resCheck = $conn->query("SHOW TABLES LIKE 'returns'");
    if ($resCheck && $resCheck->num_rows > 0) {
        $result = $conn->query("SELECT SUM(total_amount) as total FROM returns WHERE DATE(created_at) BETWEEN '$start' AND '$end'");
        if ($result) {
            $totalReturns = (float)($result->fetch_assoc()['total'] ?? 0);
        }
    }

    // Net profit simple calc = sales - purchases - returns (demo)
    $netProfit = $totalSales - $totalPurchases - $totalReturns;

    $conn->close();

    echo json_encode(array(
        "success" => true,
        "start_date" => $start,
        "end_date" => $end,
        "total_sales" => $totalSales,
        "total_purchases" => $totalPurchases,
        "total_returns" => $totalReturns,
        "net_profit" => $netProfit
    ));
}

// Simple CSV report generators (demo implementations)
function generateSalesReport() {
    $start = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $end = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
    // Fetch sales
    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Unable to connect to database"));
        exit;
    }
    $conn->set_charset("utf8");

    $sql = "SELECT invoice_number, user_id, user_name, items, total_amount, status, created_at FROM sales WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_report_'. $start .'_to_'. $end .'.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, array('Invoice','User ID','User Name','Items JSON','Total Amount','Status','Created At'));
    while ($row = $res->fetch_assoc()) {
        fputcsv($output, array($row['invoice_number'],$row['user_id'],$row['user_name'],$row['items'],$row['total_amount'],$row['status'],$row['created_at']));
    }
    fclose($output);
    $stmt->close();
    $conn->close();
    exit;
}

function generateInventoryReport() {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Unable to connect to database"));
        exit;
    }
    $conn->set_charset("utf8");

    $sql = "SELECT medicine_code, name_en, name_ar, category, quantity_in_stock, unit_price FROM medicines ORDER BY name_en";
    $result = $conn->query($sql);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_report.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, array('Code','Name EN','Name AR','Category','Quantity','Unit Price'));
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, array($row['medicine_code'],$row['name_en'],$row['name_ar'],$row['category'],$row['quantity_in_stock'],$row['unit_price']));
    }
    fclose($output);
    $conn->close();
    exit;
}

function generateFinancialReport() {
    $start = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $end = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Unable to connect to database"));
        exit;
    }
    $conn->set_charset("utf8");

    // Use monthly summary to compute numbers
    $sql = "SELECT SUM(total_amount) as total_sales FROM sales WHERE DATE(created_at) BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    $totalSales = (float)($res->fetch_assoc()['total_sales'] ?? 0);
    $stmt->close();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="financial_report_'. $start .'_to_'. $end .'.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Metric','Value'));
    fputcsv($output, array('Total Sales', $totalSales));
    fclose($output);
    $conn->close();
    exit;
}

function generateTrendAnalysis() {
    $start = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-07', strtotime('-7 days'));
    $end = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Unable to connect to database"));
        exit;
    }
    $conn->set_charset("utf8");

    $sql = "SELECT DATE(created_at) as day, SUM(total_amount) as total FROM sales WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY DATE(created_at) ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="trend_analysis_'. $start .'_to_'. $end .'.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, array('Date','Total Sales'));
    while ($row = $res->fetch_assoc()) {
        fputcsv($output, array($row['day'], $row['total']));
    }
    fclose($output);
    $stmt->close();
    $conn->close();
    exit;
}

// ------------------- Server-side PDF generators using Dompdf -------------------
use Dompdf\Dompdf;

function generateSalesPDF() {
    $start = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $end = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

    // reuse sendSalesData SQL
    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo "Unable to connect to database";
        exit;
    }
    $conn->set_charset("utf8");

    $sql = "SELECT invoice_number, user_id, user_name, items, total_amount, status, created_at FROM sales WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $html = '<h2>Sales Report</h2><table border="1" cellpadding="6" cellspacing="0" width="100%"><thead><tr><th>Invoice</th><th>User</th><th>Items</th><th>Total</th><th>Date</th><th>Status</th></tr></thead><tbody>';
    while ($row = $res->fetch_assoc()) {
        $items = htmlspecialchars($row['items']);
        $html .= "<tr><td>{$row['invoice_number']}</td><td>{$row['user_name']}</td><td>{$items}</td><td>{$row['total_amount']}</td><td>{$row['created_at']}</td><td>{$row['status']}</td></tr>";
    }
    $html .= '</tbody></table>';

    $stmt->close();
    $conn->close();

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="sales_report_'. $start .'_to_'. $end .'.pdf"');
    echo $dompdf->output();
    exit;
}

function generateInventoryPDF() {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo "Unable to connect to database";
        exit;
    }
    $conn->set_charset("utf8");

    $result = $conn->query("SELECT medicine_code, name_en, name_ar, category, quantity_in_stock, unit_price FROM medicines ORDER BY id");

    $html = '<h2>Inventory Report</h2><table border="1" cellpadding="6" cellspacing="0" width="100%"><thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Quantity</th><th>Unit Price</th></tr></thead><tbody>';
    while ($row = $result->fetch_assoc()) {
        $html .= "<tr><td>{$row['medicine_code']}</td><td>{$row['name_en']}</td><td>{$row['category']}</td><td>{$row['quantity_in_stock']}</td><td>{$row['unit_price']}</td></tr>";
    }
    $html .= '</tbody></table>';

    $conn->close();

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="inventory_report.pdf"');
    echo $dompdf->output();
    exit;
}

function generateFinancialPDF() {
    $start = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $end = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo "Unable to connect to database";
        exit;
    }
    $conn->set_charset("utf8");

    $sql = "SELECT SUM(total_amount) as total_sales FROM sales WHERE DATE(created_at) BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    $totalSales = (float)($res->fetch_assoc()['total_sales'] ?? 0);
    $stmt->close();

    $totalPurchases = 0.0;
    $resCheck = $conn->query("SHOW TABLES LIKE 'purchases'");
    if ($resCheck && $resCheck->num_rows > 0) {
        $result = $conn->query("SELECT SUM(total_amount) as total FROM purchases WHERE DATE(created_at) BETWEEN '$start' AND '$end'");
        if ($result) $totalPurchases = (float)($result->fetch_assoc()['total'] ?? 0);
    }

    $totalReturns = 0.0;
    $resCheck = $conn->query("SHOW TABLES LIKE 'returns'");
    if ($resCheck && $resCheck->num_rows > 0) {
        $result = $conn->query("SELECT SUM(total_amount) as total FROM returns WHERE DATE(created_at) BETWEEN '$start' AND '$end'");
        if ($result) $totalReturns = (float)($result->fetch_assoc()['total'] ?? 0);
    }

    $netProfit = $totalSales - $totalPurchases - $totalReturns;

    $html = '<h2>Financial Summary</h2><table border="1" cellpadding="6" cellspacing="0" width="100%"><tbody>';
    $html .= "<tr><td>Total Sales</td><td>{$totalSales}</td></tr>";
    $html .= "<tr><td>Total Purchases</td><td>{$totalPurchases}</td></tr>";
    $html .= "<tr><td>Total Returns</td><td>{$totalReturns}</td></tr>";
    $html .= "<tr><td>Net Profit</td><td>{$netProfit}</td></tr>";
    $html .= '</tbody></table>';

    $conn->close();

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="financial_report_'. $start .'_to_'. $end .'.pdf"');
    echo $dompdf->output();
    exit;
}

function generateTrendPDF() {
    $start = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
    $end = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo "Unable to connect to database";
        exit;
    }
    $conn->set_charset("utf8");

    $sql = "SELECT DATE(created_at) as day, SUM(total_amount) as total FROM sales WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY DATE(created_at) ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $chartImage = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chart_image'])) {
        $chartImage = $_POST['chart_image'];
    }

    $html = '<h2>Sales Trend</h2>';
    if (!empty($chartImage)) {
        $html .= '<div style="margin-bottom: 1rem;"><img src="' . htmlspecialchars($chartImage, ENT_QUOTES, 'UTF-8') . '" style="width:100%; max-width:600px; border:1px solid #ddd; border-radius:8px;" /></div>';
    }
    $html .= '<table border="1" cellpadding="6" cellspacing="0" width="100%"><thead><tr><th>Date</th><th>Total Sales</th></tr></thead><tbody>';
    while ($row = $res->fetch_assoc()) {
        $html .= "<tr><td>{$row['day']}</td><td>{$row['total']}</td></tr>";
    }
    $html .= '</tbody></table>';

    $stmt->close();
    $conn->close();

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="trend_analysis_'. $start .'_to_'. $end .'.pdf"');
    echo $dompdf->output();
    exit;
}

// Return sales within a date range as JSON
function sendSalesData() {
    $start = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $end = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Unable to connect to database"));
        exit;
    }
    $conn->set_charset("utf8");

    $sql = "SELECT invoice_number, user_id, user_name, items, total_amount, status, created_at FROM sales WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = array();
    while ($row = $res->fetch_assoc()) {
        $row['items'] = json_decode($row['items'], true);
        $rows[] = $row;
    }

    $stmt->close();
    $conn->close();

    echo json_encode(array("success" => true, "sales" => $rows));
}

// Return daily sales totals as JSON for a date range
function sendTrendData() {
    $start = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
    $end = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Unable to connect to database"));
        exit;
    }
    $conn->set_charset("utf8");

    $sql = "SELECT DATE(created_at) as day, SUM(total_amount) as total FROM sales WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY DATE(created_at) ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = array();
    while ($row = $res->fetch_assoc()) {
        $rows[$row['day']] = (float)$row['total'];
    }

    // Fill missing days in the requested date range with zeros
    $current = strtotime($start);
    $endTs = strtotime($end);
    $filledRows = array();
    while ($current <= $endTs) {
        $day = date('Y-m-d', $current);
        $filledRows[] = array('day' => $day, 'total' => isset($rows[$day]) ? $rows[$day] : 0.0);
        $current = strtotime('+1 day', $current);
    }

    $stmt->close();
    $conn->close();

    echo json_encode(array("success" => true, "trend" => $filledRows));
}

function handleSubmitSale() {
    $saleData = null;
    if (isset($_POST['sale_data'])) {
        $saleData = json_decode($_POST['sale_data'], true);
    } else {
        $payload = file_get_contents('php://input');
        $saleData = json_decode($payload, true);
    }

    if (!$saleData || empty($saleData['items']) || empty($saleData['total_amount']) || empty($saleData['user_id'])) {
        http_response_code(400);
        echo json_encode(array("success" => false, "message" => "Incomplete sale data"));
        exit;
    }

    $invoiceNumber = isset($saleData['invoice_number']) ? trim($saleData['invoice_number']) : 'INV-' . time();
    $userId = trim($saleData['user_id']);
    $userName = isset($saleData['user_name']) ? trim($saleData['user_name']) : '';
    $items = $saleData['items'];
    $totalAmount = floatval($saleData['total_amount']);

    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Unable to connect to database"));
        exit;
    }

    $conn->set_charset("utf8");
    $conn->begin_transaction();

    try {
        foreach ($items as $item) {
            if (empty($item['id']) || empty($item['quantity'])) {
                continue;
            }
            $medicineId = intval($item['id']);
            $quantity = intval($item['quantity']);
            if ($quantity <= 0) {
                continue;
            }

            $stmt = $conn->prepare("UPDATE medicines SET quantity_in_stock = quantity_in_stock - ? WHERE id = ? AND quantity_in_stock >= ?");
            $stmt->bind_param("iii", $quantity, $medicineId, $quantity);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                $conn->rollback();
                http_response_code(400);
                echo json_encode(array("success" => false, "message" => "Insufficient stock or invalid product for sale"));
                exit;
            }
            $stmt->close();
        }

        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare("INSERT INTO sales (invoice_number, user_id, user_name, items, total_amount, status) VALUES (?, ?, ?, ?, ?, 'completed')");
        $stmt->bind_param("ssssd", $invoiceNumber, $userId, $userName, $itemsJson, $totalAmount);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $conn->close();

        echo json_encode(array("success" => true, "message" => "Sale recorded successfully", "invoice_number" => $invoiceNumber));
    } catch (Exception $e) {
        $conn->rollback();
        $conn->close();
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Failed to record sale"));
    }
}

/**
 * Authenticate pharmacist credentials
 */
function authenticateUser($user_type, $user_id, $password) {
    // Demo credentials for testing
    $demo_credentials = array(
        'pharmacist' => array(
            "PHARM001" => array(
                "password" => "password123",
                "name" => "Dr. Ahmed Hassan"
            ),
            "PHARM002" => array(
                "password" => "secure456",
                "name" => "Dr. Sarah Johnson"
            )
        ),
        'employee' => array(
            "EMP001" => array(
                "password" => "employee123",
                "name" => "Amina Saleh"
            ),
            "EMP002" => array(
                "password" => "employee456",
                "name" => "Omar Ali"
            )
        )
    );

    if (isset($demo_credentials[$user_type][$user_id])) {
        if ($demo_credentials[$user_type][$user_id]['password'] === $password) {
            return array(
                "success" => true,
                "name" => $demo_credentials[$user_type][$user_id]['name'],
                "user_type" => $user_type
            );
        } else {
            return array(
                "success" => false,
                "message" => "Invalid user ID or password"
            );
        }
    }

    // Try database authentication if database exists
    $servername = "localhost";
    $db_username = "root";
    $db_password = "";
    $database = "pharmacy_erp";

    $conn = new mysqli($servername, $db_username, $db_password, $database);

    if (!$conn->connect_error) {
        $conn->set_charset("utf8");

        if ($user_type === 'employee') {
            $stmt = $conn->prepare("SELECT id, employee_id, name, email, status FROM employees WHERE employee_id = ?");
        } else {
            $stmt = $conn->prepare("SELECT id, pharmacist_id, name, email, status FROM pharmacists WHERE pharmacist_id = ?");
        }

        if ($stmt) {
            $stmt->bind_param("s", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $row = $result->fetch_assoc();

                // Verify password
                if (verifyPassword($password, $user_type, $row[$user_type . '_id'])) {
                    if ($row['status'] === 'active') {
                        $stmt->close();
                        $conn->close();

                        return array(
                            "success" => true,
                            "name" => $row['name'],
                            "user_type" => $user_type
                        );
                    } else {
                        $stmt->close();
                        $conn->close();

                        return array(
                            "success" => false,
                            "message" => "Your account is inactive. Contact administrator."
                        );
                    }
                }
            }
            $stmt->close();
        }
        $conn->close();
    }

    return array(
        "success" => false,
        "message" => "Invalid user ID or password"
    );
}

/**
 * Verify password
 */
function verifyPassword($password, $user_type, $user_id) {
    $demo_credentials = array(
        'pharmacist' => array(
            "PHARM001" => "password123",
            "PHARM002" => "secure456"
        ),
        'employee' => array(
            "EMP001" => "employee123",
            "EMP002" => "employee456"
        )
    );

    if (isset($demo_credentials[$user_type][$user_id])) {
        return $password === $demo_credentials[$user_type][$user_id];
    }

    return false;
}

/**
 * Create suppliers table (called from initializeDatabase)
 */
function createSuppliersTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS suppliers (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        contact_person VARCHAR(100),
        address VARCHAR(255),
        phone VARCHAR(50),
        email VARCHAR(100),
        city VARCHAR(100),
        status ENUM('Active','Inactive') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($sql);

    // Seed demo data once
    $conn->query("INSERT IGNORE INTO suppliers (id, name, contact_person, address, phone, email, city, status) VALUES
        (1, 'Prime Pharma Supplies',  'Ahmed Hassan',  '12 Pharma St, Nasr City', '+1 (555) 123-4567', 'ahmed@primepharma.com',    'Cairo',      'Active'),
        (2, 'Global Med Distributors','Sarah Johnson', '5 Health Blvd, Smouha',   '+1 (555) 234-5678', 'sarah@globalmed.com',      'Alexandria', 'Active'),
        (3, 'Quality Healthcare Ltd', 'Mohammed Ali',  '88 Wellness Ave, Dokki',  '+1 (555) 345-6789', 'contact@qualityhealth.com','Giza',       'Active')");
}

function getSupplierConn() {
    $conn = new mysqli("localhost", "root", "", "pharmacy_erp");
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Database connection failed"));
        exit;
    }
    $conn->set_charset("utf8");
    // Ensure table exists
    createSuppliersTable($conn);
    return $conn;
}

/**
 * GET ?action=get_suppliers  — return all suppliers
 */
function sendSupplierList() {
    $conn = getSupplierConn();
    $result = $conn->query("SELECT * FROM suppliers ORDER BY id ASC");
    $rows = array();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $conn->close();
    echo json_encode(array("success" => true, "suppliers" => $rows));
}

/**
 * POST action=add_supplier
 * Required: name  |  Optional: contact_person, address, phone, email, city, status
 */
function handleAddSupplier() {
    $name    = isset($_POST['name'])           ? trim($_POST['name'])           : '';
    $contact = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
    $address = isset($_POST['address'])        ? trim($_POST['address'])        : '';
    $phone   = isset($_POST['phone'])          ? trim($_POST['phone'])          : '';
    $email   = isset($_POST['email'])          ? trim($_POST['email'])          : '';
    $city    = isset($_POST['city'])           ? trim($_POST['city'])           : '';
    $status  = (isset($_POST['status']) && $_POST['status'] === 'Inactive') ? 'Inactive' : 'Active';

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(array("success" => false, "message" => "Supplier name is required"));
        exit;
    }

    $conn = getSupplierConn();
    $stmt = $conn->prepare("INSERT INTO suppliers (name, contact_person, address, phone, email, city, status) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param("sssssss", $name, $contact, $address, $phone, $email, $city, $status);
    if ($stmt->execute()) {
        $id = $conn->insert_id;
        $stmt->close(); $conn->close();
        echo json_encode(array("success" => true, "message" => "Supplier added successfully", "id" => $id));
    } else {
        $err = $conn->error; $stmt->close(); $conn->close();
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Failed to add supplier: " . $err));
    }
}

/**
 * POST action=edit_supplier
 * Required: id  |  Any of: name, contact_person, address, phone, email, city, status
 */
function handleEditSupplier() {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(array("success" => false, "message" => "Supplier id is required"));
        exit;
    }
    $conn = getSupplierConn();
    $allowed = array('name'=>'s','contact_person'=>'s','address'=>'s','phone'=>'s','email'=>'s','city'=>'s','status'=>'s');
    $setClauses = array(); $types = ''; $values = array();
    foreach ($allowed as $field => $type) {
        if (isset($_POST[$field])) {
            $setClauses[] = "$field = ?";
            $types .= $type;
            $values[] = trim($_POST[$field]);
        }
    }
    if (empty($setClauses)) {
        http_response_code(400);
        echo json_encode(array("success" => false, "message" => "No fields to update"));
        exit;
    }
    $sql = "UPDATE suppliers SET " . implode(', ', $setClauses) . " WHERE id = ?";
    $types .= 'i'; $values[] = $id;
    $stmt = $conn->prepare($sql);
    $bindValues = array_merge(array($types), $values);
    $refs = array();
    foreach ($bindValues as $k => $v) $refs[$k] = &$bindValues[$k];
    call_user_func_array(array($stmt, 'bind_param'), $refs);
    if ($stmt->execute()) {
        $stmt->close(); $conn->close();
        echo json_encode(array("success" => true, "message" => "Supplier updated successfully"));
    } else {
        $err = $conn->error; $stmt->close(); $conn->close();
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Failed to update supplier: " . $err));
    }
}

/**
 * POST action=delete_supplier
 * Required: id
 */
function handleDeleteSupplier() {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(array("success" => false, "message" => "Supplier id is required"));
        exit;
    }
    $conn = getSupplierConn();
    $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $stmt->close(); $conn->close();
        echo json_encode(array("success" => true, "message" => "Supplier deleted successfully"));
    } else {
        $err = $conn->error; $stmt->close(); $conn->close();
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Failed to delete supplier: " . $err));
    }
}

// ============================================================
// NEAR EXPIRY MEDICINES
// ============================================================

/**
 * GET ?action=near_expiry_medicines
 * Returns medicines expiring within 60 days.
 */
function sendNearExpiryMedicines() {
    $conn = new mysqli("localhost", "root", "", "pharmacy_erp");
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Database connection failed"));
        exit;
    }
    $conn->set_charset("utf8");

    // Add expiry_date column if missing (safe migration)
    $check = $conn->query("SHOW COLUMNS FROM medicines LIKE 'expiry_date'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE medicines ADD COLUMN expiry_date DATE NULL");
        $today = date('Y-m-d');
        $conn->query("UPDATE medicines SET expiry_date = DATE_ADD('$today', INTERVAL 12 DAY) WHERE medicine_code = 'P001'");
        $conn->query("UPDATE medicines SET expiry_date = DATE_ADD('$today', INTERVAL 28 DAY) WHERE medicine_code = 'P002'");
        $conn->query("UPDATE medicines SET expiry_date = DATE_ADD('$today', INTERVAL 5  DAY) WHERE medicine_code = 'P003'");
        $conn->query("UPDATE medicines SET expiry_date = DATE_ADD('$today', INTERVAL 45 DAY) WHERE medicine_code = 'P004'");
        $conn->query("UPDATE medicines SET expiry_date = DATE_ADD('$today', INTERVAL 7  DAY) WHERE medicine_code = 'P005'");
    }

    $sql = "SELECT medicine_code AS code, name_en AS name, category, quantity_in_stock, expiry_date
            FROM medicines
            WHERE expiry_date IS NOT NULL
              AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
            ORDER BY expiry_date ASC";
    $result = $conn->query($sql);
    $medicines = array();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $medicines[] = $row;
        }
    }
    $conn->close();
    echo json_encode(array("success" => true, "medicines" => $medicines));
}

// ============================================================
// BACKUP DATABASE
// ============================================================

/**
 * GET ?action=backup_database
 * Streams the pharmacy database as a plain-SQL backup.
 */
function backupDatabase() {
    $conn = new mysqli("localhost", "root", "", "pharmacy_erp");
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Database connection failed"));
        exit;
    }
    $conn->set_charset("utf8");

    $filename = "pharmacy_backup_" . date('Y-m-d_His') . ".sql";
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $tables = array();
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }

    $output  = "-- Pharmacy ERP Database Backup\n";
    $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $output .= "-- Database: pharmacy_erp\n\n";
    $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $createRes = $conn->query("SHOW CREATE TABLE `$table`");
        $createRow = $createRes->fetch_row();
        $output .= "DROP TABLE IF EXISTS `$table`;\n";
        $output .= $createRow[1] . ";\n\n";
        $rows = $conn->query("SELECT * FROM `$table`");
        while ($row = $rows->fetch_assoc()) {
            $values = array_map(function($v) use ($conn) {
                return $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
            }, array_values($row));
            $output .= "INSERT INTO `$table` VALUES (" . implode(", ", $values) . ");\n";
        }
        $output .= "\n";
    }
    $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $conn->close();
    echo $output;
    exit;
}

// ============================================================
// FACTORY RESET
// ============================================================

/**
 * POST action=factory_reset_request
 * Verifies manager credentials and sends a Gmail reset link.
 * Does NOT reset the database until the link is clicked.
 */
function handleFactoryResetRequest() {
    $manager_id = isset($_POST['manager_id']) ? trim($_POST['manager_id']) : '';
    $password   = isset($_POST['password'])   ? trim($_POST['password'])   : '';

    if (empty($manager_id) || empty($password)) {
        http_response_code(400);
        echo json_encode(array("success" => false, "message" => "Manager ID and password are required."));
        exit;
    }

    $result = authenticateUser('pharmacist', $manager_id, $password);
    if (!$result['success']) {
        http_response_code(401);
        echo json_encode(array("success" => false, "message" => "Invalid Manager ID or password."));
        exit;
    }

    // Fetch manager email
    $conn = new mysqli("localhost", "root", "", "pharmacy_erp");
    $managerEmail = '';
    $managerName  = $result['name'];
    if (!$conn->connect_error) {
        $conn->set_charset("utf8");
        $stmt = $conn->prepare("SELECT email FROM pharmacists WHERE pharmacist_id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $manager_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) $managerEmail = $row['email'];
            $stmt->close();
        }
        $conn->close();
    }
    if (empty($managerEmail)) {
        $managerEmail = strtolower($manager_id) . '@pharmacy.com';
    }

    // Generate secure one-time token
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    // Store token
    $conn2 = new mysqli("localhost", "root", "", "pharmacy_erp");
    if (!$conn2->connect_error) {
        $conn2->set_charset("utf8");
        $conn2->query("CREATE TABLE IF NOT EXISTS factory_reset_tokens (
            id INT PRIMARY KEY AUTO_INCREMENT,
            manager_id VARCHAR(50) NOT NULL,
            token VARCHAR(100) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $conn2->query("UPDATE factory_reset_tokens SET used = 1 WHERE manager_id = '" . $conn2->real_escape_string($manager_id) . "'");
        $stmt2 = $conn2->prepare("INSERT INTO factory_reset_tokens (manager_id, token, expires_at) VALUES (?, ?, ?)");
        if ($stmt2) {
            $stmt2->bind_param("sss", $manager_id, $token, $expires);
            $stmt2->execute();
            $stmt2->close();
        }
        $conn2->close();
    }

    // Build reset link
    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $resetLink = $protocol . '://' . $host . '/Back%20End/ERP_Pharmacy_System.php?action=confirm_factory_reset&token=' . urlencode($token);

    // Send email
    $subject = "=?UTF-8?B?" . base64_encode("⚠️ Pharmacy ERP – Factory Reset Confirmation") . "?=";
    $body    = "Dear {$managerName},\r\n\r\n"
             . "A factory reset has been requested using your Manager credentials.\r\n\r\n"
             . "Click the link below to CONFIRM the factory reset:\r\n\r\n"
             . $resetLink . "\r\n\r\n"
             . "⚠️  WARNING: This will PERMANENTLY DELETE ALL pharmacy data.\r\n"
             . "This link expires in 30 minutes.\r\n\r\n"
             . "If you did NOT request this, please ignore this email and change your password immediately.\r\n\r\n"
             . "— Pharmacy ERP System";
    $headers = "From: noreply@pharmacy-erp.com\r\nReply-To: noreply@pharmacy-erp.com\r\nX-Mailer: PHP/" . phpversion();
    $sent = @mail($managerEmail, $subject, $body, $headers);

    echo json_encode(array(
        "success"    => true,
        "message"    => "Reset link sent to " . $managerEmail,
        "email_sent" => $sent,
        "debug_link" => $resetLink  // Remove this in production
    ));
    exit;
}

/**
 * GET ?action=confirm_factory_reset&token=TOKEN
 * Validates token and executes the factory reset.
 */
function confirmFactoryReset() {
    $token = isset($_GET['token']) ? trim($_GET['token']) : '';
    header('Content-Type: text/html; charset=utf-8');
    if (empty($token)) {
        echo "<h2>Invalid reset link.</h2>"; exit;
    }

    $conn = new mysqli("localhost", "root", "", "pharmacy_erp");
    if ($conn->connect_error) { echo "<h2>Database connection failed.</h2>"; exit; }
    $conn->set_charset("utf8");

    $stmt = $conn->prepare("SELECT id, manager_id, expires_at, used FROM factory_reset_tokens WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $tokenRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tokenRow || $tokenRow['used']) {
        echo "<h2 style='color:#dc2626;'>This reset link is invalid or has already been used.</h2>"; $conn->close(); exit;
    }
    if (new DateTime() > new DateTime($tokenRow['expires_at'])) {
        echo "<h2 style='color:#dc2626;'>This reset link has expired. Please request a new one.</h2>"; $conn->close(); exit;
    }

    $conn->query("UPDATE factory_reset_tokens SET used = 1 WHERE id = " . intval($tokenRow['id']));

    // Execute factory reset
    $tables = array('sales', 'prescriptions', 'purchases', 'returns', 'suppliers', 'medicines', 'factory_reset_tokens');
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    foreach ($tables as $t) {
        $conn->query("TRUNCATE TABLE `$t`");
    }
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    $conn->close();

    echo "<!DOCTYPE html><html><head><title>Factory Reset Complete</title>
    <style>body{font-family:sans-serif;text-align:center;padding:4rem;background:#fff5f5;}</style>
    </head><body>
    <h1 style='color:#dc2626;'>⚠️ Factory Reset Complete</h1>
    <p style='font-size:1.1rem;'>All pharmacy data has been permanently deleted.</p>
    <p><a href='../ERP_Dashboard.html' style='color:#1d4ed8;'>Return to Dashboard</a></p>
    </body></html>";
    exit;
}

// ============================================================
// RESTORE DATABASE
// ============================================================

/**
 * POST action=restore_database
 * Accepts an uploaded .sql file and restores the database.
 */
function handleRestoreDatabase() {
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(array("success" => false, "message" => "No backup file uploaded."));
        exit;
    }
    $file = $_FILES['backup_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, array('sql'))) {
        http_response_code(400);
        echo json_encode(array("success" => false, "message" => "Only .sql files are accepted."));
        exit;
    }
    $sqlContent = file_get_contents($file['tmp_name']);
    if (empty(trim($sqlContent))) {
        http_response_code(400);
        echo json_encode(array("success" => false, "message" => "Backup file is empty."));
        exit;
    }

    $conn = new mysqli("localhost", "root", "", "pharmacy_erp");
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Database connection failed."));
        exit;
    }
    $conn->set_charset("utf8");
    $conn->query("SET FOREIGN_KEY_CHECKS=0");

    $statements = array_filter(
        array_map('trim', explode(";\n", $sqlContent)),
        function($s) { return strlen($s) > 5; }
    );

    $errors = array();
    foreach ($statements as $stmt) {
        if (!empty(trim($stmt)) && !$conn->query($stmt)) {
            $errors[] = $conn->error;
        }
    }
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    $conn->close();

    if (count($errors) > 5) {
        echo json_encode(array("success" => false, "message" => "Restore had errors: " . implode('; ', array_slice($errors, 0, 3))));
    } else {
        echo json_encode(array("success" => true, "message" => "Database restored successfully."));
    }
    exit;
}


?>
