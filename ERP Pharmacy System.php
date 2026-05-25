<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

session_start();

// Initialize database if it doesn't exist (on first run)
initializeDatabase();

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get POST data
    $pharmacist_id = isset($_POST['pharmacist_id']) ? trim($_POST['pharmacist_id']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    // Validate input
    if (empty($pharmacist_id) || empty($password)) {
        http_response_code(400);
        echo json_encode(array(
            "success" => false,
            "message" => "Pharmacist ID and password are required"
        ));
        exit;
    }

    // Authenticate user
    $result = authenticatePharmacist($pharmacist_id, $password);
    
    if ($result['success']) {
        // Store session data
        $_SESSION['pharmacist_id'] = $pharmacist_id;
        $_SESSION['pharmacist_name'] = $result['pharmacist_name'];
        $_SESSION['login_time'] = time();

        http_response_code(200);
        echo json_encode(array(
            "success" => true,
            "message" => "Login successful! Welcome " . $result['pharmacist_name'],
            "pharmacist_name" => $result['pharmacist_name']
        ));
    } else {
        http_response_code(401);
        echo json_encode(array(
            "success" => false,
            "message" => $result['message']
        ));
    }
    exit;
} else {
    http_response_code(405);
    echo json_encode(array("success" => false, "message" => "Method not allowed"));
}

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

    // Insert demo data if not exists
    $sql = "INSERT IGNORE INTO pharmacists (pharmacist_id, name, email, status) VALUES 
        ('PHARM001', 'Dr. Ahmed Hassan', 'ahmed@pharmacy.com', 'active'),
        ('PHARM002', 'Dr. Sarah Johnson', 'sarah@pharmacy.com', 'active')";
    $conn->query($sql);

    // Create medicines table
    $sql = "CREATE TABLE IF NOT EXISTS medicines (
        id INT PRIMARY KEY AUTO_INCREMENT,
        medicine_code VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(150) NOT NULL,
        description TEXT,
        strength VARCHAR(50),
        quantity_in_stock INT DEFAULT 0,
        unit_price DECIMAL(10, 2),
        reorder_level INT DEFAULT 10,
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

    $conn->close();
}

/**
 * Authenticate pharmacist credentials
 */
function authenticatePharmacist($pharmacist_id, $password) {
    // Demo credentials for testing
    $demo_credentials = array(
        "PHARM001" => array(
            "password" => "password123",
            "name" => "Dr. Ahmed Hassan"
        ),
        "PHARM002" => array(
            "password" => "secure456",
            "name" => "Dr. Sarah Johnson"
        )
    );

    // Check demo credentials
    if (isset($demo_credentials[$pharmacist_id])) {
        if ($demo_credentials[$pharmacist_id]['password'] === $password) {
            return array(
                "success" => true,
                "pharmacist_name" => $demo_credentials[$pharmacist_id]['name']
            );
        } else {
            return array(
                "success" => false,
                "message" => "Invalid pharmacist ID or password"
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
        
        $stmt = $conn->prepare("SELECT id, pharmacist_id, name, email, status FROM pharmacists WHERE pharmacist_id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $pharmacist_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $row = $result->fetch_assoc();

                // Verify password
                if (verifyPassword($password, $row['pharmacist_id'])) {
                    if ($row['status'] === 'active') {
                        $stmt->close();
                        $conn->close();
                        
                        return array(
                            "success" => true,
                            "pharmacist_name" => $row['name']
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
        "message" => "Invalid pharmacist ID or password"
    );
}

/**
 * Verify password
 */
function verifyPassword($password, $pharmacist_id) {
    $demo_credentials = array(
        "PHARM001" => "password123",
        "PHARM002" => "secure456"
    );

    if (isset($demo_credentials[$pharmacist_id])) {
        return $password === $demo_credentials[$pharmacist_id];
    }

    return false;
}

?>
