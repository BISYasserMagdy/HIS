<?php
/**
 * Database Setup Script for Pharmacy ERP
 * Run this once to create the database and tables
 */

$servername = "localhost";
$username = "root";
$password = "";

// Create connection
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS pharmacy_erp";
if ($conn->query($sql) === TRUE) {
    echo "Database created successfully or already exists.<br>";
} else {
    echo "Error creating database: " . $conn->error . "<br>";
}

// Select the database
$conn->select_db("pharmacy_erp");

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

if ($conn->query($sql) === TRUE) {
    echo "Pharmacists table created successfully or already exists.<br>";
} else {
    echo "Error creating pharmacists table: " . $conn->error . "<br>";
}

// Insert demo data if not exists
$sql = "INSERT IGNORE INTO pharmacists (pharmacist_id, name, email, status) VALUES 
    ('PHARM001', 'Dr. Ahmed Hassan', 'ahmed@pharmacy.com', 'active'),
    ('PHARM002', 'Dr. Sarah Johnson', 'sarah@pharmacy.com', 'active')";

if ($conn->query($sql) === TRUE) {
    echo "Demo pharmacists added or already exist.<br>";
} else {
    echo "Error inserting demo data: " . $conn->error . "<br>";
}

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

if ($conn->query($sql) === TRUE) {
    echo "Medicines table created successfully or already exists.<br>";
} else {
    echo "Error creating medicines table: " . $conn->error . "<br>";
}

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

if ($conn->query($sql) === TRUE) {
    echo "Prescriptions table created successfully or already exists.<br>";
} else {
    echo "Error creating prescriptions table: " . $conn->error . "<br>";
}

echo "<h3 style='color: green; margin-top: 20px;'>Database Setup Complete!</h3>";
echo "<p><a href='../Front End/ERP Pharmacy Sign in Page.html'>Go to Login Page</a></p>";
echo "<p><strong>Demo Credentials:</strong></p>";
echo "<ul>";
echo "<li>ID: PHARM001 | Password: password123</li>";
echo "<li>ID: PHARM002 | Password: secure456</li>";
echo "</ul>";

$conn->close();
?>
