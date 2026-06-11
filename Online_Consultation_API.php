<?php
// ── Shared EHR session (PHP session_start + requireRole), same as EHR_System.php ──
require_once __DIR__ . '/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
// Online_Consultation_API.php
// Backend for the Doctor Online Consultation Review Page
// Connects to:  healthcare_ehr  (patients, vitals, medications, lab_results)
//               healthcare_appointments (doctors, appointments)
//
// All responses are JSON. All patient-history reads are SELECT-only.
// No INSERT / UPDATE / DELETE is ever issued from this API for past records.
// ══════════════════════════════════════════════════════════════════════════════

// ── Always return JSON, even on fatal errors ──────────────────────────────────
set_exception_handler(function ($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
        header('Access-Control-Allow-Credentials: true');
    }
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
}, E_ALL & ~E_NOTICE & ~E_WARNING);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Database config ───────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');          // change if your MySQL root has a password
define('EHR_DB',  'healthcare_ehr');
define('APT_DB',  'healthcare_appointments');   // appointments + doctors DB

// ── Shared connection helper ──────────────────────────────────────────────────
function getConn(string $db): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $db);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->connect_error]);
        exit;
    }
    $conn->set_charset('utf8mb4');
    $conn->query("SET SESSION sql_mode = ''");
    return $conn;
}

// ── Bootstrap: create both databases and all required tables if missing ───────
function bootstrap(): void {

    // ── Step 1: appointments database + its tables ────────────────────────────
    $c = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($c->connect_error) return;
    $c->query("SET SESSION sql_mode = ''");
    $c->query("CREATE DATABASE IF NOT EXISTS `" . APT_DB . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $c->select_db(APT_DB);
    $c->set_charset('utf8mb4');

    // doctors table
    $c->query("CREATE TABLE IF NOT EXISTS doctors (
        id            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        doctor_id     VARCHAR(20)  NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name     VARCHAR(120) NOT NULL,
        specialty     VARCHAR(100) NULL,
        email         VARCHAR(120) NULL,
        phone         VARCHAR(30)  NULL,
        avatar_color  VARCHAR(20)  NULL DEFAULT '#1a73e8',
        created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // appointments table
    // patient_phone / patient_email = submitted by the patient during online booking
    // complaint      = free-text chief complaint from the booking form
    // type           = 'online' | 'in-person'
    $c->query("CREATE TABLE IF NOT EXISTS appointments (
        id              INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        doctor_id       VARCHAR(20)  NOT NULL,
        patient_name    VARCHAR(120) NOT NULL,
        patient_email   VARCHAR(120) NULL,
        patient_phone   VARCHAR(30)  NULL,
        ehr_patient_id  VARCHAR(20)  NULL COMMENT 'Links to patients.patient_id in healthcare_ehr — NULL for brand-new patients',
        appt_date       DATE         NOT NULL,
        appt_time       TIME         NOT NULL,
        complaint       TEXT         NULL,
        status          VARCHAR(20)  NOT NULL DEFAULT 'scheduled' COMMENT 'scheduled | completed | cancelled | no_show',
        type            VARCHAR(20)  NOT NULL DEFAULT 'online',
        notes           TEXT         NULL,
        created_at      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_doctor_date (doctor_id, appt_date),
        INDEX idx_status (status),
        INDEX idx_type (type)
    ) ENGINE=InnoDB");

    // ── Seed one demo doctor if table is empty ────────────────────────────────
    $row = $c->query("SELECT COUNT(*) AS cnt FROM doctors")->fetch_assoc();
    if ((int)$row['cnt'] === 0) {
        // password = "doctor123"  (bcrypt)
        $hash = password_hash('doctor123', PASSWORD_BCRYPT);
        $c->query("INSERT INTO doctors (doctor_id, password_hash, full_name, specialty, email, avatar_color)
                   VALUES ('DR-001', '$hash', 'Dr. Sarah Al-Hassan', 'Internal Medicine', 'sarah@healthcarehub.example', '#0b3c7a')");
    }

    // ── Seed demo appointments if table is empty ──────────────────────────────
    $row2 = $c->query("SELECT COUNT(*) AS cnt FROM appointments WHERE type='online'")->fetch_assoc();
    if ((int)$row2['cnt'] === 0) {
        $today = date('Y-m-d');
        $c->query("INSERT INTO appointments
            (doctor_id, patient_name, patient_email, patient_phone, ehr_patient_id, appt_date, appt_time, complaint, status, type)
            VALUES
            -- Existing EHR patient (links to EHR record)
            ('DR-001','Ahmad Karimi','ahmad@mail.com','+201001234567','P-001','$today','09:00:00','Persistent headache and dizziness for 3 days.','scheduled','online'),
            -- New patient (no EHR record yet)
            ('DR-001','Layla Mansour','layla@mail.com','+201009876543',NULL,'$today','10:30:00','Seasonal allergy symptoms — sneezing and watery eyes.','scheduled','online'),
            -- Another existing patient
            ('DR-001','Omar Khalil','omar@mail.com','+201005551234','P-002','$today','11:00:00','Follow-up on blood pressure medication.','scheduled','online'),
            -- Completed appointment (should NOT appear in upcoming list)
            ('DR-001','Nadia Farouk','nadia@mail.com','+201007778888',NULL,'$today','08:00:00','Skin rash consultation.','completed','online')
        ");
    }

    $c->close();

    // ── Step 2: ensure EHR database + tables exist (idempotent) ──────────────
    $e = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($e->connect_error) return;
    $e->query("SET SESSION sql_mode = ''");
    $e->query("CREATE DATABASE IF NOT EXISTS `" . EHR_DB . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $e->select_db(EHR_DB);
    $e->set_charset('utf8mb4');

    $e->query("CREATE TABLE IF NOT EXISTS patients (
        id           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id   VARCHAR(20)  NOT NULL UNIQUE,
        first_name   VARCHAR(80)  NOT NULL,
        last_name    VARCHAR(80)  NOT NULL,
        dob          DATE         NOT NULL,
        gender       VARCHAR(10)  NOT NULL,
        blood_type   VARCHAR(10)  NULL,
        phone        VARCHAR(30)  NULL,
        email        VARCHAR(120) NULL,
        insurance    VARCHAR(100) NULL,
        physician    VARCHAR(100) NULL,
        allergies    TEXT         NULL,
        conditions   TEXT         NULL,
        smoking      VARCHAR(80)  NULL,
        alcohol      VARCHAR(60)  NULL,
        pmh          TEXT         NULL,
        fmh          TEXT         NULL,
        surgical     TEXT         NULL,
        vaccines     TEXT         NULL,
        avatar_color VARCHAR(20)  NULL,
        status       VARCHAR(10)  NULL,
        last_visit   VARCHAR(60)  NULL,
        next_appt    VARCHAR(100) NULL,
        created_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $e->query("CREATE TABLE IF NOT EXISTS vitals (
        id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id  VARCHAR(20)  NOT NULL,
        recorded_at VARCHAR(60)  NULL,
        nurse       VARCHAR(100) NULL,
        bp          VARCHAR(20)  NULL,
        bp_status   VARCHAR(10)  NULL,
        hr          VARCHAR(10)  NULL,
        temp        VARCHAR(10)  NULL,
        spo2        VARCHAR(10)  NULL,
        rr          VARCHAR(10)  NULL,
        bmi         VARCHAR(10)  NULL,
        pain        VARCHAR(10)  NULL,
        note        TEXT         NULL,
        created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $e->query("CREATE TABLE IF NOT EXISTS medications (
        id            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id    VARCHAR(20)  NOT NULL,
        med_name      VARCHAR(150) NOT NULL,
        dose          VARCHAR(200) NULL,
        prescribed_by VARCHAR(100) NULL,
        start_date    VARCHAR(30)  NULL,
        status        VARCHAR(10)  NULL,
        created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $e->query("CREATE TABLE IF NOT EXISTS lab_results (
        id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id  VARCHAR(20)  NOT NULL,
        panel_date  VARCHAR(30)  NULL,
        test_name   VARCHAR(150) NOT NULL,
        test_value  VARCHAR(80)  NULL,
        ref_range   VARCHAR(80)  NULL,
        pct         INT          NULL,
        cls         VARCHAR(10)  NULL,
        label       VARCHAR(80)  NULL,
        color_type  VARCHAR(10)  NULL,
        created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $e->close();
}

bootstrap();

// ── Request router ────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Parse JSON body
$body = [];
$raw  = file_get_contents('php://input');
if ($raw) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $body = $decoded;
}
foreach ($_POST as $k => $v) $body[$k] = $v;

switch ($action) {
    case 'login':              handleLogin($body);                     break;
    case 'session_login':      handleSessionLogin();                   break;
    case 'get_appointments':   getAppointments();                      break;
    case 'get_consultation':   getConsultation();                      break;
    case 'logout':             handleLogout();                         break;
    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: session_login
// Uses the existing EHR PHP session (set by EHR_System.php's loginUser()) to
// authenticate the doctor here too — no separate Doctor ID/password needed.
// Requires the logged-in EHR user to have role = 'doctor'.
// Finds (or creates) a matching row in `doctors` keyed by the EHR username,
// then issues the same kind of token handleLogin() returns.
// Returns: { success, token, doctor: {...} }  — same shape as action=login
// ══════════════════════════════════════════════════════════════════════════════
function handleSessionLogin(): void {
    // requireRole() exits with a 401 JSON "Unauthorized access" response
    // if there's no active session or the role doesn't match.
    requireRole(['doctor']);

    $username = $_SESSION['username']  ?? '';
    $fullName = $_SESSION['full_name'] ?? $username;

    if ($username === '') {
        echo json_encode(['success' => false, 'message' => 'No EHR session found.']);
        return;
    }

    $conn = getConn(APT_DB);

    // Use the EHR username as the doctor_id in this system too.
    $stmt = $conn->prepare("SELECT * FROM doctors WHERE doctor_id = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        // First time this EHR doctor uses the consultation portal — create a
        // matching record automatically. password_hash is unused for SSO
        // logins but the column is NOT NULL, so store a random unusable hash.
        $randomHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $colors = ['#0b3c7a', '#1a73e8', '#00b4a6', '#7c3aed', '#c2410c'];
        $color  = $colors[array_rand($colors)];

        $insert = $conn->prepare(
            "INSERT INTO doctors (doctor_id, password_hash, full_name, specialty, email, avatar_color)
             VALUES (?, ?, ?, NULL, NULL, ?)"
        );
        $insert->bind_param('ssss', $username, $randomHash, $fullName, $color);
        $insert->execute();
        $insert->close();

        $stmt = $conn->prepare("SELECT * FROM doctors WHERE doctor_id = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $conn->close();

    $token = base64_encode($row['doctor_id'] . ':' . time() . ':' . bin2hex(random_bytes(8)));

    echo json_encode([
        'success' => true,
        'token'   => $token,
        'doctor'  => [
            'doctor_id'    => $row['doctor_id'],
            'full_name'    => $row['full_name'],
            'specialty'    => $row['specialty'],
            'email'        => $row['email'],
            'avatar_color' => $row['avatar_color'],
        ]
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: login
// Body: { doctor_id, password }
// Returns: { success, doctor: { doctor_id, full_name, specialty, avatar_color } }
// ══════════════════════════════════════════════════════════════════════════════
function handleLogin(array $b): void {
    $did = trim($b['doctor_id'] ?? '');
    $pwd = trim($b['password']  ?? '');

    if (!$did || !$pwd) {
        echo json_encode(['success' => false, 'message' => 'Doctor ID and password are required.']);
        return;
    }

    $conn = getConn(APT_DB);
    $stmt = $conn->prepare("SELECT * FROM doctors WHERE doctor_id = ? LIMIT 1");
    $stmt->bind_param('s', $did);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Invalid Doctor ID or password.']);
        return;
    }

    if (!password_verify($pwd, $row['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid Doctor ID or password.']);
        return;
    }

    // Issue a simple session token stored client-side (stateless for this demo).
    // In production, use PHP sessions or JWT with proper expiry.
    $token = base64_encode($row['doctor_id'] . ':' . time() . ':' . bin2hex(random_bytes(8)));

    echo json_encode([
        'success' => true,
        'token'   => $token,
        'doctor'  => [
            'doctor_id'    => $row['doctor_id'],
            'full_name'    => $row['full_name'],
            'specialty'    => $row['specialty'],
            'email'        => $row['email'],
            'avatar_color' => $row['avatar_color'],
        ]
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// Token guard — extracts and validates the doctor_id from the bearer token.
// For a production system, replace with signed JWT verification.
// ══════════════════════════════════════════════════════════════════════════════
function requireAuth(): string {
    $token = '';

    // Accept token from Authorization header or query string
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $token = substr($authHeader, 7);
    } elseif (!empty($_GET['token'])) {
        $token = $_GET['token'];
    }

    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorised. Please log in.']);
        exit;
    }

    // Decode — token format: base64( doctor_id : timestamp : nonce )
    $parts = explode(':', base64_decode($token));
    if (count($parts) < 2) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid session token.']);
        exit;
    }

    $doctorId  = $parts[0];
    $issuedAt  = (int)$parts[1];

    // Token expires after 8 hours
    if (time() - $issuedAt > 28800) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
        exit;
    }

    // Verify doctor still exists
    $conn = getConn(APT_DB);
    $stmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE doctor_id = ? LIMIT 1");
    $stmt->bind_param('s', $doctorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$row) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Doctor account not found.']);
        exit;
    }

    return $doctorId;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: get_appointments
// GET ?action=get_appointments&token=xxx
// Returns today's upcoming ONLINE appointments for the authenticated doctor,
// ordered by appointment time ASC.
// ══════════════════════════════════════════════════════════════════════════════
function getAppointments(): void {
    $doctorId = requireAuth();
    $today    = date('Y-m-d');

    $conn = getConn(APT_DB);

    // Fetch all SCHEDULED online appointments for this doctor today
    $stmt = $conn->prepare("
        SELECT
            id,
            patient_name,
            patient_email,
            patient_phone,
            ehr_patient_id,
            appt_date,
            TIME_FORMAT(appt_time, '%h:%i %p') AS appt_time_fmt,
            appt_time AS appt_time_raw,
            complaint,
            status
        FROM appointments
        WHERE doctor_id  = ?
          AND appt_date  = ?
          AND type       = 'online'
          AND status     = 'scheduled'
        ORDER BY appt_time ASC
    ");
    $stmt->bind_param('ss', $doctorId, $today);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'date' => $today, 'appointments' => $rows]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: get_consultation
// GET ?action=get_consultation&token=xxx&appointment_id=N
//
// 1. Verifies the appointment belongs to this doctor.
// 2. Checks if ehr_patient_id is set (existing patient vs. new patient).
// 3. If existing: fetches patient record, latest vitals, all medications,
//    and all lab results from healthcare_ehr — READ ONLY.
// 4. Returns combined payload to the frontend.
// ══════════════════════════════════════════════════════════════════════════════
function getConsultation(): void {
    $doctorId = requireAuth();
    $apptId   = (int)($_GET['appointment_id'] ?? 0);

    if (!$apptId) {
        echo json_encode(['success' => false, 'message' => 'appointment_id is required.']);
        return;
    }

    // ── 1. Fetch the appointment (must belong to this doctor) ─────────────────
    $conn = getConn(APT_DB);
    $stmt = $conn->prepare("
        SELECT
            id,
            patient_name,
            patient_email,
            patient_phone,
            ehr_patient_id,
            appt_date,
            TIME_FORMAT(appt_time, '%h:%i %p') AS appt_time_fmt,
            complaint,
            status,
            notes
        FROM appointments
        WHERE id        = ?
          AND doctor_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('is', $apptId, $doctorId);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$appt) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found or access denied.']);
        return;
    }

    $ehrPid = $appt['ehr_patient_id'];

    // ── 2. New patient — no EHR record ────────────────────────────────────────
    if (!$ehrPid) {
        echo json_encode([
            'success'      => true,
            'patient_type' => 'new',
            'appointment'  => $appt,
            'ehr'          => null,
        ]);
        return;
    }

    // ── 3. Existing patient — fetch READ-ONLY history from healthcare_ehr ─────
    $ehr = getConn(EHR_DB);

    // 3a. Patient demographics + medical history
    $s = $ehr->prepare("SELECT * FROM patients WHERE patient_id = ? LIMIT 1");
    $s->bind_param('s', $ehrPid);
    $s->execute();
    $patient = $s->get_result()->fetch_assoc();
    $s->close();

    if (!$patient) {
        // ehr_patient_id was set but record doesn't exist — treat as new
        $ehr->close();
        echo json_encode([
            'success'      => true,
            'patient_type' => 'new',
            'appointment'  => $appt,
            'ehr'          => null,
        ]);
        return;
    }

    // 3b. Latest vitals record only (most recent by id DESC)
    $s = $ehr->prepare("SELECT * FROM vitals WHERE patient_id = ? ORDER BY id DESC LIMIT 1");
    $s->bind_param('s', $ehrPid);
    $s->execute();
    $vitals = $s->get_result()->fetch_assoc();
    $s->close();

    // 3c. All medications (active first, then inactive)
    $s = $ehr->prepare("SELECT * FROM medications WHERE patient_id = ? ORDER BY FIELD(status,'active','inactive','stopped') ASC, id DESC");
    $s->bind_param('s', $ehrPid);
    $s->execute();
    $meds = [];
    $res  = $s->get_result();
    while ($r = $res->fetch_assoc()) $meds[] = $r;
    $s->close();

    // 3d. All lab results (most recent panel first)
    $s = $ehr->prepare("SELECT * FROM lab_results WHERE patient_id = ? ORDER BY id DESC");
    $s->bind_param('s', $ehrPid);
    $s->execute();
    $labs = [];
    $res  = $s->get_result();
    while ($r = $res->fetch_assoc()) $labs[] = $r;
    $s->close();

    $ehr->close();

    // ── 4. Strip password_hash / any internal fields just in case ────────────
    unset($patient['password_hash']);

    echo json_encode([
        'success'      => true,
        'patient_type' => 'existing',
        'appointment'  => $appt,
        'ehr'          => [
            'patient'      => $patient,
            'vitals'       => $vitals,       // object | null
            'medications'  => $meds,         // array
            'labs'         => $labs,         // array
        ],
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: logout (stateless — client simply discards the token)
// ══════════════════════════════════════════════════════════════════════════════
function handleLogout(): void {
    echo json_encode(['success' => true, 'message' => 'Logged out.']);
}
