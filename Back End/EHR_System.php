<?php
// ════════════════════════════════════════════════════════════════════════════
// EHR_System.php  —  Med-Alex Health Information System  |  Primary API Router
// ════════════════════════════════════════════════════════════════════════════

// ── STEP 1: session_start() — must be FIRST, before any output or headers ────
// Correct cookie params ensure the session cookie is shared across all
// PHP scripts regardless of subdirectory depth.
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps, // auto: true once you're actually serving over HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── STEP 2: CORS + output headers — before any echo or require_once ──────────
// When credentials: 'include' is used in fetch(), CORS requires an explicit
// origin — the wildcard '*' is rejected by the browser for credentialed requests.
//
// IMPORTANT: we do NOT blindly reflect whatever Origin header the browser
// sends. Doing that + Access-Control-Allow-Credentials: true would let ANY
// website on the internet make authenticated, cookie-carrying requests to
// this API on behalf of a logged-in user (a classic CORS/CSRF hole) — which
// is especially bad here since this API serves patient records.
//
// Instead we only ever echo back an Origin that's on our explicit allow-list.
// Add every real frontend origin you serve this from (scheme + host + port).
$EHR_ALLOWED_ORIGINS = [
    'http://localhost',
    'http://localhost:5500',
    'http://127.0.0.1:5500',
    // 'https://your-production-domain.com',
];

$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($requestOrigin, $EHR_ALLOWED_ORIGINS, true)) {
    $allowedOrigin = $requestOrigin;
} else {
    // Unknown/no Origin (e.g. same-origin or server-to-server call): don't
    // grant credentialed cross-origin access at all.
    $allowedOrigin = 'null';
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Vary: Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE, PUT');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── STEP 3: Global JSON error handler — catches any crash in require_once ────
// By registering this BEFORE the require_once calls below, any fatal error
// inside CdssEngine.php, auth.php etc. returns clean JSON instead of an HTML
// error page that would cause res.json() in the browser to throw and show
// "Connection error — please try again."
set_exception_handler(function ($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
}, E_ALL & ~E_NOTICE & ~E_WARNING);

// ── STEP 4: Load dependencies — AFTER headers and error handlers ──────────────
require_once __DIR__ . '/CdssEngine.php';
require_once __DIR__ . '/CdssCrossDomainAndFatigue.php';
require_once __DIR__ . '/auth.php';  // calls session_start() only if not already started

// ─── DB CONFIG ───────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'healthcare_ehr');

// ─── GET CONNECTION (with strict mode OFF) ───────────────────────────────────
function getConn() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['success' => false,
            'message' => 'DB connection failed: ' . $conn->connect_error]);
        exit;
    }
    $conn->set_charset('utf8mb4');
    $conn->query("SET SESSION sql_mode = ''");
    return $conn;
}

// ─── INIT DB ─────────────────────────────────────────────────────────────────
function initDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) { return; }

    $conn->query("SET SESSION sql_mode = ''");
    $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    if (!$conn->select_db(DB_NAME)) { $conn->close(); return; }
    $conn->set_charset('utf8mb4');

    // ── Hospitals: created on subscription, holds branding (logo/name) ──────
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


    $conn->query("CREATE TABLE IF NOT EXISTS patients (
        id           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id   VARCHAR(20)  NOT NULL,
        first_name   VARCHAR(80)  NOT NULL,
        last_name    VARCHAR(80)  NOT NULL,
        dob          DATE         NOT NULL,
        gender       VARCHAR(10)  NOT NULL,
        blood_type   VARCHAR(10)  NULL,
        phone        VARCHAR(30)  NULL,
        email        VARCHAR(120) NULL,
        insurance    VARCHAR(100) NULL,
        physician    VARCHAR(100) NULL,
        physician_id INT          NULL,
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
        created_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_patient_id (patient_id),
        KEY idx_physician_id (physician_id),
        CONSTRAINT fk_patients_physician
            FOREIGN KEY (physician_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    // ── Migration: add physician_id to pre-existing installs that lack it ──
    $colCheck = $conn->query("SHOW COLUMNS FROM patients LIKE 'physician_id'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE patients ADD COLUMN physician_id INT NULL AFTER physician");
        $conn->query("ALTER TABLE patients ADD KEY idx_physician_id (physician_id)");
        $conn->query("ALTER TABLE patients ADD CONSTRAINT fk_patients_physician
                       FOREIGN KEY (physician_id) REFERENCES users(id) ON DELETE SET NULL");
    }
    // ── Backfill: match existing free-text `physician` names to users.id ──
    $conn->query(
        "UPDATE patients p
         JOIN users u ON u.full_name = p.physician AND u.role IN ('doctor','nurse')
         SET p.physician_id = u.id
         WHERE p.physician_id IS NULL AND p.physician IS NOT NULL AND p.physician <> ''"
    );

    $conn->query("CREATE TABLE IF NOT EXISTS vitals (
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
        note        TEXT         NULL,
        created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $conn->query("CREATE TABLE IF NOT EXISTS timeline (
        id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id  VARCHAR(20)  NOT NULL,
        entry_date  VARCHAR(30)  NOT NULL,
        dot_type    VARCHAR(10)  NULL,
        entry_text  TEXT         NOT NULL,
        created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $conn->query("CREATE TABLE IF NOT EXISTS medications (
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

    $conn->query("CREATE TABLE IF NOT EXISTS lab_results (
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

    // ── RBAC: staff accounts table ───────────────────────────────────────────
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

    // ── Migration: widen role ENUM to include 'manager' on pre-existing installs ──
    $conn->query("ALTER TABLE users MODIFY role ENUM('admin','manager','doctor','nurse') NOT NULL DEFAULT 'nurse'");

    // ── Migration: add `specialty` column for pre-existing installs ──
    $specCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'specialty'");
    if ($specCheck && $specCheck->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN specialty VARCHAR(100) NULL AFTER full_name");
    }

    // ── Hospitals: one row per subscribed hospital. Stores branding (logo,
    //    display name) and subscription/billing metadata. `name` matches
    //    the free-text `users.hospital` value used for scoping. ──────────
    $conn->query("CREATE TABLE IF NOT EXISTS `hospitals` (
        id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        name            VARCHAR(120)    NOT NULL,
        contact_email   VARCHAR(150)    NOT NULL,
        plan            VARCHAR(50)     NOT NULL DEFAULT 'monthly',
        payment_method  VARCHAR(50)     NULL,
        logo_data       MEDIUMTEXT      NULL COMMENT 'Base64 data URL for hospital logo',
        status          ENUM('active','cancelled') NOT NULL DEFAULT 'active',
        subscribed_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_hospital_name  (name),
        UNIQUE KEY uq_contact_email  (contact_email)
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `user_sessions` (
        id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        user_id       INT UNSIGNED    NOT NULL,
        session_token VARCHAR(64)     NOT NULL,
        ip_address    VARCHAR(45)     NULL,
        user_agent    VARCHAR(255)    NULL,
        action        ENUM('login','logout','expired') NOT NULL DEFAULT 'login',
        created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        KEY idx_token   (session_token),
        CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // ── SEED DEFAULT STAFF ACCOUNTS ───────────────────────────────────────────
    // Generates REAL bcrypt hashes at runtime — no manual SQL editing needed.
    // INSERT IGNORE means this runs on every request but only inserts once per
    // username, making it completely idempotent.
    //
    // Default login credentials (change passwords after first login):
    //   Username: admin_user   Password: AdminPass123!   Role: admin
    //   Username: manager_lee   Password: ManagerPass123! Role: manager
    //   Username: dr_smith      Password: DoctorPass123!  Role: doctor
    //   Username: nurse_jones   Password: NursePass123!   Role: nurse
    //
    $defaultUsers = [
        ['admin_user',  'admin@medalex.local',      'AdminPass123!',  'admin',  'System Administrator', null],
        ['manager_lee', 'manager.lee@medalex.local', 'ManagerPass123!','manager','Manager Daniel Lee'  , null],
        ['dr_smith',    'dr.smith@medalex.local',    'DoctorPass123!', 'doctor', 'Dr. Sarah Smith'      , 'Nephrology'],
        ['nurse_jones', 'nurse.jones@medalex.local', 'NursePass123!',  'nurse',  'Nurse Emily Jones'    , null],
    ];

    $seedStmt = $conn->prepare(
        "INSERT IGNORE INTO users (username, email, password_hash, role, full_name, specialty)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($defaultUsers as [$uname, $uemail, $plainPass, $urole, $uname_full, $uspecialty]) {
        // password_hash() with BCRYPT — takes ~250 ms per call, but only runs
        // the very first time (INSERT IGNORE skips all subsequent attempts).
        $hash = password_hash($plainPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $seedStmt->bind_param('ssssss', $uname, $uemail, $hash, $urole, $uname_full, $uspecialty);
        $seedStmt->execute();
    }
    $seedStmt->close();


    // ── Fix-up: if manager_lee was added to a DB that already had other
    //    staff seeded under a different hospital name (e.g. 'Med-Alex
    //    Central'), align its hospital so the manager sees the same
    //    staff directory as the admin. Only runs if manager_lee still
    //    has the column default 'General Hospital' but real staff exist
    //    elsewhere. ──────────────────────────────────────────────────────
    $hospRes = $conn->query(
        "SELECT hospital, COUNT(*) AS n FROM users
         WHERE role IN ('doctor','nurse') AND hospital <> 'General Hospital'
         GROUP BY hospital ORDER BY n DESC LIMIT 1"
    );
    if ($hospRow = $hospRes->fetch_assoc()) {
        $targetHospital = $hospRow['hospital'];
        $conn->query(
            "UPDATE users SET hospital = '" . $conn->real_escape_string($targetHospital) . "'
             WHERE username = 'manager_lee' AND hospital = 'General Hospital'"
        );
    }

    // ── CDSS support tables ──────────────────────────────────────────────────
    $conn->query("CREATE TABLE IF NOT EXISTS clinical_rules (
        id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
        rule_code               VARCHAR(50)     NOT NULL,
        rule_name               VARCHAR(200)    NOT NULL,
        description             TEXT            NULL,
        domain                  VARCHAR(50)     NOT NULL DEFAULT 'vital_sign',
        severity                VARCHAR(20)     NOT NULL DEFAULT 'warning',
        severity_tier           TINYINT UNSIGNED NOT NULL DEFAULT 2,
        is_active               TINYINT(1)      NOT NULL DEFAULT 1,
        suppression_window_hrs  INT UNSIGNED    NOT NULL DEFAULT 24,
        cooldown_hrs            INT UNSIGNED    NOT NULL DEFAULT 0,
        requires_acknowledgment TINYINT(1)      NOT NULL DEFAULT 0,
        fhir_detected_issue_meta JSON           NULL,
        created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rule_code (rule_code)
    ) ENGINE=InnoDB");

    $conn->query("CREATE TABLE IF NOT EXISTS rule_criteria (
        id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
        rule_id             INT UNSIGNED    NOT NULL,
        data_domain         VARCHAR(20)     NOT NULL,
        parameter_key       VARCHAR(100)    NOT NULL,
        operator            VARCHAR(20)     NOT NULL,
        threshold_value     DECIMAL(12,4)   NULL,
        threshold_value2    DECIMAL(12,4)   NULL,
        threshold_unit      VARCHAR(30)     NULL,
        string_match_pattern VARCHAR(500)   NULL,
        logic_join          VARCHAR(3)      NOT NULL DEFAULT 'AND',
        sort_order          TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_rule_id (rule_id)
    ) ENGINE=InnoDB");

    $conn->query("CREATE TABLE IF NOT EXISTS patient_observations (
        id               INT UNSIGNED        NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id       VARCHAR(20)         NOT NULL,
        observation_type VARCHAR(30)         NOT NULL DEFAULT 'vital_sign',
        loinc_code       VARCHAR(20)         NULL,
        parameter_key    VARCHAR(100)        NOT NULL,
        numeric_value    DECIMAL(12,4)       NULL,
        string_value     VARCHAR(500)        NULL,
        unit             VARCHAR(30)         NULL,
        status           VARCHAR(20)         NOT NULL DEFAULT 'final',
        recorded_by      VARCHAR(100)        NULL,
        recorded_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_patient_param (patient_id, parameter_key),
        KEY idx_patient_type  (patient_id, observation_type),
        KEY idx_recorded_at   (recorded_at)
    ) ENGINE=InnoDB");

    $conn->query("CREATE TABLE IF NOT EXISTS patient_alert_states (
        id                          INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id                  VARCHAR(20)     NOT NULL,
        rule_id                     INT UNSIGNED    NOT NULL,
        triggering_observation_id   INT UNSIGNED    NULL,
        state                       VARCHAR(20)     NOT NULL DEFAULT 'active',
        severity_tier               TINYINT UNSIGNED NOT NULL DEFAULT 2,
        matched_criteria_snapshot   JSON            NULL,
        fhir_detected_issue_payload JSON            NULL,
        triggered_at                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at                 TIMESTAMP       NULL,
        expires_at                  TIMESTAMP       NULL,
        KEY idx_patient_state (patient_id, state),
        KEY idx_patient_rule  (patient_id, rule_id),
        KEY idx_expires       (expires_at)
    ) ENGINE=InnoDB");

    // Seed clinical rules (idempotent — INSERT IGNORE skips existing rule_codes)
    seedCdssRules($conn);

    $conn->close();
}

// ─── SEED CDSS RULES ─────────────────────────────────────────────────────────
// Inserts all built-in clinical rules + criteria.
// Uses INSERT IGNORE so re-running initDB() is always safe.
function seedCdssRules(mysqli $conn): void {

    // ── 1. Insert rules ───────────────────────────────────────────────────────
    $rules = [
        // Vital-sign rules
        ['HYPERTENSION-001',     'Hypertensive Crisis',                        'vital_sign',  'critical', 1, 12,  4, 1, 'BP Systolic > 180 mmHg or Diastolic > 120 mmHg — immediate intervention required.'],
        ['TACHYCARDIA-001',      'Critical Tachycardia',                       'vital_sign',  'critical', 1, 12,  4, 1, 'Heart rate > 150 bpm — evaluate for arrhythmia or haemodynamic instability.'],
        ['BRADYCARDIA-001',      'Critical Bradycardia',                       'vital_sign',  'critical', 1, 12,  4, 1, 'Heart rate < 40 bpm — risk of haemodynamic compromise.'],
        ['HYPOXIA-001',          'Critical Hypoxia',                           'vital_sign',  'critical', 1, 12,  4, 1, 'SpO₂ < 90% — supplemental O₂ urgently required.'],
        ['HYPERTHERMIA-001',     'Hyperthermia / Fever',                       'vital_sign',  'warning',  2, 24,  8, 0, 'Temperature > 38.5 °C — evaluate for infection or sepsis.'],
        ['HYPOTHERMIA-001',      'Hypothermia',                                'vital_sign',  'warning',  2, 24,  8, 0, 'Temperature < 35 °C — active warming and monitoring required.'],

        // Lab-result rules
        ['GLUCOSE-CRITICAL-LOW', 'Severe Hypoglycaemia',                       'lab_result',  'critical', 1, 24, 12, 1, 'Glucose < 54 mg/dL — administer glucose immediately.'],
        ['GLUCOSE-LOW-001',      'Hypoglycaemia',                              'lab_result',  'warning',  2, 24,  8, 1, 'Glucose < 70 mg/dL — monitor closely and consider carbohydrate supplementation.'],
        ['GLUCOSE-HIGH-001',     'Hyperglycaemia',                             'lab_result',  'warning',  2, 24,  8, 0, 'Glucose > 200 mg/dL — review diabetic management plan.'],
        ['GLUCOSE-CRITICAL-HIGH','Critical Hyperglycaemia',                   'lab_result',  'critical', 1, 24, 12, 1, 'Glucose > 500 mg/dL — risk of diabetic ketoacidosis. Urgent review required.'],
        ['HGB-CRITICAL-LOW',     'Critical Anaemia',                           'lab_result',  'critical', 1, 24, 12, 1, 'Haemoglobin < 7 g/dL — consider urgent transfusion.'],
        ['HGB-LOW-001',          'Anaemia',                                    'lab_result',  'warning',  2, 24,  8, 0, 'Haemoglobin < 12 g/dL — evaluate for cause of anaemia.'],
        ['CHOL-HIGH-001',        'High Total Cholesterol',                     'lab_result',  'warning',  2, 48, 24, 0, 'Total Cholesterol > 200 mg/dL — review cardiovascular risk and lipid therapy.'],
        ['CHOL-CRITICAL-001',    'Critically High Cholesterol',                'lab_result',  'critical', 1, 48, 24, 1, 'Total Cholesterol > 300 mg/dL — significantly elevated cardiovascular risk.'],
        ['HYPER-K-001',          'Hyperkalemia — Critical',                    'lab_result',  'critical', 1, 48, 12, 1, 'Serum Potassium > 5.5 mEq/L — risk of cardiac arrhythmia.'],
        ['HYPO-K-001',           'Hypokalaemia',                               'lab_result',  'warning',  2, 24,  8, 1, 'Serum Potassium < 3.5 mEq/L — risk of arrhythmia; potassium replacement indicated.'],
        ['SODIUM-HIGH-001',      'Hypernatraemia',                             'lab_result',  'warning',  2, 24,  8, 0, 'Serum Sodium > 150 mEq/L — evaluate for dehydration.'],
        ['SODIUM-LOW-001',       'Hyponatraemia',                              'lab_result',  'warning',  2, 24,  8, 0, 'Serum Sodium < 130 mEq/L — monitor for neurological symptoms.'],
        ['CREATININE-HIGH-001',  'Elevated Creatinine',                        'lab_result',  'warning',  2, 24,  8, 0, 'Creatinine > 1.5 mg/dL — evaluate renal function.'],
        ['WBC-HIGH-001',         'Leukocytosis',                               'lab_result',  'warning',  2, 24,  8, 0, 'WBC > 11 × 10³/µL — evaluate for infection or inflammation.'],
        ['WBC-LOW-001',          'Leukopenia',                                 'lab_result',  'warning',  2, 24,  8, 0, 'WBC < 4 × 10³/µL — evaluate for immunosuppression or haematological disorder.'],
        ['PLATELETS-LOW-001',    'Thrombocytopenia',                           'lab_result',  'critical', 1, 24, 12, 1, 'Platelets < 50 × 10³/µL — bleeding risk; review medications.'],
        ['HYPER-K-DIGOXIN-001',  'Hyperkalemia with Active Digoxin',           'drug_lab_interaction', 'critical', 1, 48, 12, 1, 'Potassium > 5.0 mEq/L with active Digoxin — high risk of cardiac arrhythmia.'],
        ['NSAID-ACE-001',        'NSAID + ACE Inhibitor Interaction',          'drug_drug_interaction', 'warning', 2, 72, 24, 1, 'Concurrent NSAID and ACE inhibitor — risk of renal impairment and reduced antihypertensive efficacy.'],
        ['PENICILLIN-ALLERGY',   'Penicillin Allergy — Active Prescription',   'allergy_interaction', 'critical', 1, 0, 0, 1, 'Active penicillin allergy on record — review current prescriptions immediately.'],
        // Alias rules — same thresholds under the un-prefixed key users commonly type
        ['SODIUM-HIGH-002',       'Hypernatraemia (Sodium)',                    'lab_result',  'warning',  2, 24,  8, 0, 'Sodium > 150 mEq/L — evaluate for dehydration.'],
        ['SODIUM-LOW-002',        'Hyponatraemia (Sodium)',                     'lab_result',  'warning',  2, 24,  8, 0, 'Sodium < 130 mEq/L — monitor for neurological symptoms.'],
        ['POTASSIUM-HIGH-002',    'Hyperkalaemia (Potassium)',                  'lab_result',  'critical', 1, 48, 12, 1, 'Potassium > 5.5 mEq/L — risk of cardiac arrhythmia.'],
        ['POTASSIUM-LOW-002',     'Hypokalaemia (Potassium)',                   'lab_result',  'warning',  2, 24,  8, 1, 'Potassium < 3.5 mEq/L — arrhythmia risk; potassium replacement indicated.'],
        // Additional lab rules
        ['HBA1C-HIGH-001',        'Poorly Controlled Diabetes (HbA1c)',         'lab_result',  'warning',  2, 48, 24, 0, 'HbA1c ≥ 7% — review glycaemic control and management plan.'],
        ['HBA1C-CRITICAL-001',    'Severely Uncontrolled Diabetes (HbA1c)',     'lab_result',  'critical', 1, 48, 24, 1, 'HbA1c ≥ 10% — urgent diabetes management review required.'],
        ['TSH-HIGH-001',          'Elevated TSH — Hypothyroidism',              'lab_result',  'warning',  2, 48, 24, 0, 'TSH > 4.0 mIU/L — evaluate for hypothyroidism.'],
        ['TSH-LOW-001',           'Suppressed TSH — Hyperthyroidism',           'lab_result',  'warning',  2, 48, 24, 0, 'TSH < 0.4 mIU/L — evaluate for hyperthyroidism or overtreatment.'],
        ['BUN-HIGH-001',          'Elevated BUN',                               'lab_result',  'warning',  2, 24,  8, 0, 'BUN > 25 mg/dL — evaluate renal function and hydration status.'],
        ['ALT-HIGH-001',          'Elevated ALT — Liver Function',              'lab_result',  'warning',  2, 48, 24, 0, 'ALT > 56 U/L — evaluate for hepatic dysfunction or medication toxicity.'],
        ['AST-HIGH-001',          'Elevated AST — Liver Function',              'lab_result',  'warning',  2, 48, 24, 0, 'AST > 40 U/L — evaluate for hepatic or cardiac pathology.'],
    ];

    $ruleStmt = $conn->prepare("
        INSERT IGNORE INTO clinical_rules
            (rule_code, rule_name, domain, severity, severity_tier,
             suppression_window_hrs, cooldown_hrs, requires_acknowledgment, description)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");

    foreach ($rules as [$code,$name,$domain,$sev,$tier,$supHrs,$coolHrs,$reqAck,$desc]) {
        $ruleStmt->bind_param('ssssiiiis', $code,$name,$domain,$sev,$tier,$supHrs,$coolHrs,$reqAck,$desc);
        $ruleStmt->execute();
    }
    $ruleStmt->close();

    // ── 2. Insert criteria only for rules that have none yet ──────────────────
    // Helper: insert one criterion row if that rule exists and has zero criteria
    $insertCrit = function(string $ruleCode, string $dataDomain, string $paramKey,
                           string $op, ?float $threshold, ?string $unit,
                           string $join, int $order, ?string $strPattern = null)
                           use ($conn): void {
        // Fetch rule id
        $s = $conn->prepare("SELECT id FROM clinical_rules WHERE rule_code=? LIMIT 1");
        $s->bind_param('s', $ruleCode); $s->execute();
        $row = $s->get_result()->fetch_assoc(); $s->close();
        if (!$row) return;
        $ruleId = (int)$row['id'];

        // Skip if criteria already exist for this rule
        $s2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM rule_criteria WHERE rule_id=?");
        $s2->bind_param('i', $ruleId); $s2->execute();
        $cnt = (int)($s2->get_result()->fetch_assoc()['cnt'] ?? 0); $s2->close();
        if ($cnt > 0) return;

        $s3 = $conn->prepare("
            INSERT INTO rule_criteria
                (rule_id, data_domain, parameter_key, operator,
                 threshold_value, threshold_unit, string_match_pattern,
                 logic_join, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?)
        ");
        $s3->bind_param('isssdsssi',
            $ruleId, $dataDomain, $paramKey, $op,
            $threshold, $unit, $strPattern,
            $join, $order
        );
        $s3->execute(); $s3->close();
    };

    // Also a variant for a second criterion on the same rule (only inserts when count < expected)
    $insertCrit2 = function(string $ruleCode, string $dataDomain, string $paramKey,
                            string $op, ?float $threshold, ?string $unit,
                            string $join, int $order, int $expectedMin, ?string $strPattern = null)
                            use ($conn): void {
        $s = $conn->prepare("SELECT id FROM clinical_rules WHERE rule_code=? LIMIT 1");
        $s->bind_param('s', $ruleCode); $s->execute();
        $row = $s->get_result()->fetch_assoc(); $s->close();
        if (!$row) return;
        $ruleId = (int)$row['id'];

        $s2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM rule_criteria WHERE rule_id=?");
        $s2->bind_param('i', $ruleId); $s2->execute();
        $cnt = (int)($s2->get_result()->fetch_assoc()['cnt'] ?? 0); $s2->close();
        if ($cnt >= $expectedMin) return;

        $s3 = $conn->prepare("
            INSERT INTO rule_criteria
                (rule_id, data_domain, parameter_key, operator,
                 threshold_value, threshold_unit, string_match_pattern,
                 logic_join, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?)
        ");
        $s3->bind_param('isssdsssi',
            $ruleId, $dataDomain, $paramKey, $op,
            $threshold, $unit, $strPattern,
            $join, $order
        );
        $s3->execute(); $s3->close();
    };

    // Vital sign rules
    $insertCrit('HYPERTENSION-001',     'vital', 'systolic_bp',       'gt',  180,   'mmHg',   'OR',  1);
    $insertCrit2('HYPERTENSION-001',    'vital', 'diastolic_bp',      'gt',  120,   'mmHg',   'AND', 2, 2);
    $insertCrit('TACHYCARDIA-001',      'vital', 'heart_rate',        'gt',  150,   'bpm',    'AND', 1);
    $insertCrit('BRADYCARDIA-001',      'vital', 'heart_rate',        'lt',  40,    'bpm',    'AND', 1);
    $insertCrit('HYPOXIA-001',          'vital', 'spo2',              'lt',  90,    '%',      'AND', 1);
    $insertCrit('HYPERTHERMIA-001',     'vital', 'body_temperature',  'gt',  38.5,  'C',      'AND', 1);
    $insertCrit('HYPOTHERMIA-001',      'vital', 'body_temperature',  'lt',  35,    'C',      'AND', 1);

    // Glucose rules — parameter_key = 'glucose' (from addLab normalisation)
    $insertCrit('GLUCOSE-CRITICAL-LOW', 'lab',   'glucose',           'lt',  54,    'mg/dL',  'AND', 1);
    $insertCrit('GLUCOSE-LOW-001',      'lab',   'glucose',           'lt',  70,    'mg/dL',  'AND', 1);
    $insertCrit('GLUCOSE-HIGH-001',     'lab',   'glucose',           'gt',  200,   'mg/dL',  'AND', 1);
    $insertCrit('GLUCOSE-CRITICAL-HIGH','lab',   'glucose',           'gt',  500,   'mg/dL',  'AND', 1);

    // Haemoglobin rules — parameter_key = 'hemoglobin'
    $insertCrit('HGB-CRITICAL-LOW',     'lab',   'hemoglobin',        'lt',  7,     'g/dL',   'AND', 1);
    $insertCrit('HGB-LOW-001',          'lab',   'hemoglobin',        'lt',  12,    'g/dL',   'AND', 1);

    // Cholesterol rules — parameter_key = 'total_cholesterol'
    $insertCrit('CHOL-HIGH-001',        'lab',   'total_cholesterol', 'gt',  200,   'mg/dL',  'AND', 1);
    $insertCrit('CHOL-CRITICAL-001',    'lab',   'total_cholesterol', 'gt',  300,   'mg/dL',  'AND', 1);

    // Electrolyte rules
    $insertCrit('HYPER-K-001',          'lab',   'serum_potassium',   'gt',  5.5,   'mEq/L',  'AND', 1);
    $insertCrit('HYPO-K-001',           'lab',   'serum_potassium',   'lt',  3.5,   'mEq/L',  'AND', 1);
    $insertCrit('SODIUM-HIGH-001',      'lab',   'serum_sodium',      'gt',  150,   'mEq/L',  'AND', 1);
    $insertCrit('SODIUM-LOW-001',       'lab',   'serum_sodium',      'lt',  130,   'mEq/L',  'AND', 1);

    // Renal / Haematology
    $insertCrit('CREATININE-HIGH-001',  'lab',   'creatinine',        'gt',  1.5,   'mg/dL',  'AND', 1);
    $insertCrit('WBC-HIGH-001',         'lab',   'wbc',               'gt',  11,    'x10³/µL','AND', 1);
    $insertCrit('WBC-LOW-001',          'lab',   'wbc',               'lt',  4,     'x10³/µL','AND', 1);
    $insertCrit('PLATELETS-LOW-001',    'lab',   'platelets',         'lt',  50,    'x10³/µL','AND', 1);

    // Drug-lab interaction: Hyperkalemia + Digoxin
    $insertCrit('HYPER-K-DIGOXIN-001',  'lab',       'serum_potassium', 'gt',     5.0, 'mEq/L', 'AND', 1);
    $insertCrit2('HYPER-K-DIGOXIN-001', 'medication','medication_name', 'contains', null, null, 'AND', 2, 2, 'digoxin');

    // Drug-drug: NSAID + ACE inhibitor
    $insertCrit('NSAID-ACE-001',        'medication', 'medication_name', 'contains', null, null, 'AND', 1, 'nsaid,ibuprofen,naproxen,diclofenac,aspirin');
    $insertCrit2('NSAID-ACE-001',       'medication', 'medication_name', 'contains', null, null, 'AND', 2, 2, 'lisinopril,enalapril,ramipril,perindopril,captopril');

    // Penicillin allergy
    $insertCrit('PENICILLIN-ALLERGY',   'allergy',    'allergy_flag',    'contains', null, null, 'AND', 1, 'penicillin,amoxicillin,ampicillin');

    // Sodium alias rules (parameter_key = 'sodium' - what users type without 'Serum' prefix)
    $insertCrit('SODIUM-HIGH-002',      'lab',   'sodium',           'gt',  150,   'mEq/L',  'AND', 1);
    $insertCrit('SODIUM-LOW-002',       'lab',   'sodium',           'lt',  130,   'mEq/L',  'AND', 1);
    $insertCrit('POTASSIUM-HIGH-002',   'lab',   'potassium',        'gt',  5.5,   'mEq/L',  'AND', 1);
    $insertCrit('POTASSIUM-LOW-002',    'lab',   'potassium',        'lt',  3.5,   'mEq/L',  'AND', 1);

    // HbA1c rules
    $insertCrit('HBA1C-HIGH-001',       'lab',   'hba1c',            'gte', 7.0,   '%',      'AND', 1);
    $insertCrit('HBA1C-CRITICAL-001',   'lab',   'hba1c',            'gte', 10.0,  '%',      'AND', 1);

    // TSH rules
    $insertCrit('TSH-HIGH-001',         'lab',   'tsh',              'gt',  4.0,   'mIU/L',  'AND', 1);
    $insertCrit('TSH-LOW-001',          'lab',   'tsh',              'lt',  0.4,   'mIU/L',  'AND', 1);

    // Other metabolic
    $insertCrit('BUN-HIGH-001',         'lab',   'bun',              'gt',  25,    'mg/dL',  'AND', 1);
    $insertCrit('ALT-HIGH-001',         'lab',   'alt',              'gt',  56,    'U/L',    'AND', 1);
    $insertCrit('AST-HIGH-001',         'lab',   'ast',              'gt',  40,    'U/L',    'AND', 1);
}

initDB();

// ─── ROUTER ──────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = '';
if (isset($_GET['action']))  $action = trim($_GET['action']);
if (isset($_POST['action'])) $action = trim($_POST['action']);

$body = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $body = $decoded;
    }
    foreach ($_POST as $k => $v) $body[$k] = $v;
}

switch ($action) {
    // ── Auth ──────────────────────────────────────────────────────────────────
    case 'login':           loginUser($body);            break;
    case 'logout':          logoutUser();                break;
    case 'whoami':          whoAmI();                    break;  // any logged-in user

    // ── Admin: user management (admin only) ───────────────────────────────────
    case 'get_users':           requireRole(['admin','manager']); getUsers();               break;
    case 'create_user':         requireRole(['admin']); createUser($body);        break;
    case 'update_user':         requireRole(['admin']); updateUser($body);        break;
    case 'deactivate_user':     requireRole(['admin']); deactivateUser($body);    break;
    case 'reset_password':      requireRole(['admin']); handleResetPassword($body); break;
    case 'get_audit_log':       requireRole(['admin','manager']); getAuditLog();            break;
    case 'get_staff_patients':   requireRole(['admin','manager']); getStaffPatients();      break;

    // ── Profile change requests (doctor/nurse/manager submit, admin approves) ──
    case 'submit_profile_request':  requireRole(['doctor','nurse','manager']); submitProfileRequest($body); break;
    case 'get_my_profile_requests': requireRole(['doctor','nurse','manager']); getMyProfileRequests();      break;
    case 'get_profile_requests':    requireRole(['admin']); getProfileRequests();                           break;
    case 'review_profile_request':  requireRole(['admin']); reviewProfileRequest($body);                    break;

    // ── Patients ───────────────────────────────────────────────────────────────
    // View: all roles (no gate — session checked by requireRole inside whoAmI / or open)
    case 'get_patients':        requireRole(['admin','manager','doctor','nurse']); getPatients();        break;
    case 'get_patient':         requireRole(['admin','manager','doctor','nurse']); getPatient();         break;
    case 'next_patient_id':     requireRole(['admin','doctor','nurse']); nextPatientId();      break;
    // Register / Edit: all roles
    case 'add_patient':         requireRole(['admin','doctor','nurse']); addPatient($body);    break;
    case 'update_patient':      requireRole(['admin','doctor','nurse']); updatePatient($body); break;
    // Delete: admin only
    case 'delete_patient':      requireRole(['admin']);                  deletePatient($body); break;

    // ── Timeline ──────────────────────────────────────────────────────────────
    case 'get_timeline':        requireRole(['admin','manager','doctor','nurse']); getTimeline();        break;
    case 'add_timeline':        requireRole(['admin','doctor','nurse']); addTimeline($body);   break;
    case 'delete_timeline':     requireRole(['admin','doctor','nurse']); deleteTimeline($body);break;

    // ── Vitals: all roles ─────────────────────────────────────────────────────
    case 'get_vitals':          requireRole(['admin','manager','doctor','nurse']); getVitals();          break;
    case 'save_vitals':         saveVitals($body);           break;  // requireRole inside fn
    case 'update_vitals':       updateVitals($body);         break;  // requireRole inside fn

    // ── Medications ───────────────────────────────────────────────────────────
    case 'get_meds':            requireRole(['admin','manager','doctor','nurse']); getMeds();            break;
    case 'add_med':             requireRole(['admin','doctor']);         addMed($body);        break;  // prescribe: admin+doctor
    case 'delete_med':          requireRole(['admin','doctor']);         deleteMed($body);     break;  // remove: admin+doctor
    case 'request_refill':      requireRole(['admin','doctor','nurse']); requestRefill($body); break; // refill: all roles

    // ── Labs ──────────────────────────────────────────────────────────────────
    case 'get_labs':            requireRole(['admin','manager','doctor','nurse']); getLabs();            break;
    case 'add_lab':             addLab($body);               break;  // requireRole inside fn (admin+doctor+nurse)
    case 'update_lab':          updateLab($body);            break;  // requireRole inside fn (admin+doctor)
    case 'delete_lab':          deleteLab($body);            break;  // requireRole inside fn (admin+doctor)

    // ── CDSS Alerts ───────────────────────────────────────────────────────────
    // View: all roles
    case 'get_clinical_alerts': requireRole(['admin','manager','doctor','nurse']); getClinicalAlerts();  break;
    // Dismiss: admin + doctor only
    case 'dismiss_alert':       requireRole(['admin','doctor']); dismissAlert($body);         break;
    // CDSS rule seeding + clear stale/broken alert states (admin only)
    case 'reseed_cdss_rules':
        requireRole(['admin']);  // ── RBAC: admin only ──
        $conn = getConn();
        seedCdssRules($conn);
        // Expire any alert states older than their suppression window that are still
        // marked 'active' — clears stale rows left by previous failed engine runs.
        $conn->query("
            UPDATE patient_alert_states pas
            JOIN   clinical_rules cr ON pas.rule_id = cr.id
            SET    pas.state = 'expired'
            WHERE  pas.state = 'active'
              AND  pas.expires_at IS NOT NULL
              AND  pas.expires_at < NOW()
        ");
        $conn->close();
        echo json_encode(['success' => true, 'message' => 'CDSS rules seeded/verified.']);
        break;
    // Health check
    case 'ping':
        echo json_encode(['success' => true, 'message' => 'EHR_System.php is reachable',
                          'db' => DB_NAME, 'time' => date('Y-m-d H:i:s')]);
        break;
    default:
        echo json_encode(['success' => false,
            'message' => 'Unknown action: "' . htmlspecialchars($action) . '"']);
}


// ═════════════════════════════════════════════════════════════════════════════
// CDSS — SECTION 1: ALERTS DISPATCHER
//
// Action:  GET  ?action=get_clinical_alerts&patient_id=P-00001
//
// Queries patient_alert_states JOIN clinical_rules.
// Filters for state = 'active' AND not yet expired.
// Maps integer severity_tier to triage icons and labels.
// Returns a 🟢 nominal baseline row when no rules are violated.
// ═════════════════════════════════════════════════════════════════════════════
function getClinicalAlerts() {

    // ── 1. Validate patient_id ────────────────────────────────────────────────
    $pid = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';
    if (!$pid) {
        echo json_encode(['success' => false, 'message' => 'patient_id is required']);
        return;
    }

    // ── 2. Triage tier → UI metadata map ─────────────────────────────────────
    //
    //  Tier 1 — 🔴 Critical Warning : hard-stop, blocks workflow
    //  Tier 2 — 🟡 Soft Warning     : interruptive, requires reason
    //  Tier 3 — 🔵 Informational    : passive sidebar notification
    //
    $tierMeta = [
        1 => ['icon' => '🔴', 'label' => 'Critical Warning', 'severity' => 'critical', 'css_class' => 'alert-critical'],
        2 => ['icon' => '🟡', 'label' => 'Soft Warning',     'severity' => 'warning',  'css_class' => 'alert-warning'],
        3 => ['icon' => '🔵', 'label' => 'Informational',    'severity' => 'info',     'css_class' => 'alert-info'],
    ];

    // ── 3. Query active alerts for this patient ───────────────────────────────
    //
    //  - Only state = 'active' rows are returned.
    //    (suppressed / overridden / resolved / expired are excluded.)
    //  - expires_at guard prevents surfacing stale rows that the batch worker
    //    has not yet swept up.
    //  - ORDER: severity_tier ASC puts critical alerts first;
    //           triggered_at DESC puts the freshest alert of each tier first.
    //
    $conn = getConn();

    $stmt = $conn->prepare("
        SELECT
            pas.id                          AS alert_state_id,
            pas.state,
            pas.severity_tier,
            pas.triggered_at,
            pas.expires_at,
            pas.matched_criteria_snapshot,
            cr.rule_code,
            cr.rule_name,
            cr.description,
            cr.severity,
            cr.requires_acknowledgment,
            cr.suppression_window_hrs
        FROM   patient_alert_states   pas
        JOIN   clinical_rules         cr  ON pas.rule_id = cr.id
        WHERE  pas.patient_id = ?
          AND  pas.state      = 'active'
          AND  (pas.expires_at IS NULL OR pas.expires_at > NOW())
        ORDER  BY pas.severity_tier ASC,
                  pas.triggered_at  DESC
    ");
    $stmt->bind_param('s', $pid);
    $stmt->execute();
    $result = $stmt->get_result();

    $alerts = [];
    while ($row = $result->fetch_assoc()) {
        $tier = (int) $row['severity_tier'];
        $meta = $tierMeta[$tier] ?? $tierMeta[3];   // default to info tier if value is unmapped

        // Decode the JSON criteria snapshot for detail rendering
        $snapshot = [];
        if (!empty($row['matched_criteria_snapshot'])) {
            $decoded = json_decode($row['matched_criteria_snapshot'], true);
            if (is_array($decoded)) {
                $snapshot = $decoded;
            }
        }

        // Build a pipe-delimited human-readable trigger detail string.
        // e.g. "Systolic Bp: 185 mmHg (threshold: > 180 mmHg)"
        $triggerParts = [];
        foreach ($snapshot as $criterion) {
            $paramLabel = ucwords(str_replace('_', ' ', $criterion['parameter_key'] ?? ''));
            $value      = $criterion['patient_value'] ?? '—';
            $op         = $criterion['operator']       ?? '';
            $threshold  = $criterion['threshold']      ?? '';
            $unit       = $criterion['unit']           ?? '';
            $opLabel    = match ($op) {
                'gt'    => '>',
                'gte'   => '≥',
                'lt'    => '<',
                'lte'   => '≤',
                'eq'    => '=',
                default => $op,
            };
            $triggerParts[] = trim("{$paramLabel}: {$value} {$unit} (threshold: {$opLabel} {$threshold} {$unit})");
        }

        // Relative time from triggered_at timestamp
        $diffSeconds = time() - strtotime($row['triggered_at']);
        if      ($diffSeconds < 60)    $relTime = 'Just now';
        elseif  ($diffSeconds < 3600)  $relTime = (int)($diffSeconds / 60)   . ' mins ago';
        elseif  ($diffSeconds < 86400) $relTime = (int)($diffSeconds / 3600)  . ' hrs ago';
        else                           $relTime = (int)($diffSeconds / 86400) . ' days ago';

        $alerts[] = [
            // ── Frontend-compatible fields (drop-in for existing alert panel) ──
            'id'        => 'ALT-' . $row['alert_state_id'],
            'type'      => $meta['icon'] . ' ' . $meta['label'],
            'severity'  => $meta['severity'],
            'patient_id'=> $pid,
            'message'   => '[' . $pid . '] ' . (($row['description'] !== '' && $row['description'] !== null)
                            ? $row['description']
                            : $row['rule_name']),
            'time'      => $relTime,
            // ── Extended CDSS metadata ────────────────────────────────────────
            'alert_state_id'          => (int) $row['alert_state_id'],
            'severity_tier'           => $tier,
            'icon'                    => $meta['icon'],
            'triage_label'            => $meta['label'],
            'css_class'               => $meta['css_class'],
            'rule_code'               => $row['rule_code'],
            'rule_name'               => $row['rule_name'],
            'requires_acknowledgment' => (bool) $row['requires_acknowledgment'],
            'trigger_detail'          => implode(' | ', $triggerParts),
            'triggered_at'            => $row['triggered_at'],
            'expires_at'              => $row['expires_at'],
        ];
    }

    $stmt->close();
    $conn->close();

    // ── 4. Nominal baseline: returned when no active alerts exist ─────────────
    //
    //  Keeps the frontend alert panel populated and informative at all times.
    //  'severity' = 'resolved' drives the existing green badge styling.
    //
    if (empty($alerts)) {
        $alerts = [[
            'id'                      => 'ALT-NOMINAL',
            'type'                    => '🟢 System Status',
            'severity'                => 'resolved',
            'message'                 => '🟢 No Active Alerts — All tracked parameters operating within nominal margins.',
            'time'                    => 'Now',
            'alert_state_id'          => null,
            'severity_tier'           => 0,
            'icon'                    => '🟢',
            'triage_label'            => 'All Clear',
            'css_class'               => 'alert-nominal',
            'rule_code'               => 'NOMINAL',
            'rule_name'               => 'Baseline — No Active Alerts',
            'requires_acknowledgment' => false,
            'trigger_detail'          => '',
            'triggered_at'            => date('Y-m-d H:i:s'),
            'expires_at'              => null,
        ]];
    }

    echo json_encode([
        'success'      => true,
        'patient_id'   => $pid,
        'alert_count'  => ($alerts[0]['rule_code'] ?? '') === 'NOMINAL' ? 0 : count($alerts),
        'has_critical' => !empty(array_filter($alerts, fn($a) => $a['severity_tier'] === 1)),
        'data'         => $alerts,
    ]);
}


// ═════════════════════════════════════════════════════════════════════════════
// SESSION HELPER
// ═════════════════════════════════════════════════════════════════════════════
function whoAmI(): void {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'logged_in' => false]);
        return;
    }
    // Fetch specialty fresh so the profile modal can show current value
    // without needing admin-only endpoints.
    $specialty = null;
    $conn = getConn();
    $stmt = $conn->prepare("SELECT specialty FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    if ($row) $specialty = $row['specialty'];

    echo json_encode([
        'success'   => true,
        'logged_in' => true,
        'user_id'   => $_SESSION['user_id'],
        'username'  => $_SESSION['username'],
        'role'      => $_SESSION['role'],
        'name'      => $_SESSION['full_name'] ?? $_SESSION['username'],
        'hospital'  => $_SESSION['hospital'] ?? null,
        'specialty' => $specialty,
    ]);
}


// ═════════════════════════════════════════════════════════════════════════════
// ADMIN: USER MANAGEMENT
// All functions below require requireRole(['admin']) — called in the router.
// ═════════════════════════════════════════════════════════════════════════════

/** GET ?action=get_users  — list all staff accounts at the admin's hospital */
function getUsers(): void {
    // ── Hospital scoping: admins only see/manage staff at their own hospital ──
    $hospital = $_SESSION['hospital'] ?? '';

    $conn = getConn();
    $stmt = $conn->prepare(
        "SELECT id, username, email, role, full_name, specialty, hospital, is_active, created_at
         FROM users
         WHERE hospital = ?
           AND role IN ('doctor','nurse')
         ORDER BY role, full_name"
    );
    $stmt->bind_param('s', $hospital);
    $stmt->execute();
    $rows = []; $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => true, 'hospital' => $hospital, 'data' => $rows]);
}

/** POST action=create_user  body: {username, email, password, role, full_name}
 *  Admins may only create 'doctor' or 'nurse' accounts, and the new account
 *  is automatically assigned to the admin's own hospital — an admin cannot
 *  plant staff in a hospital they don't belong to.
 */
function createUser(array $b): void {
    $username  = trim($b['username']  ?? '');
    $email     = trim($b['email']     ?? '');
    $password  = $b['password']  ?? '';
    $role      = $b['role']      ?? 'nurse';
    $fullName  = trim($b['full_name'] ?? '');
    $specialty = trim($b['specialty'] ?? '');
    $hospital  = $_SESSION['hospital'] ?? '';   // ── inherited from the admin's own account ──

    if (!$username || !$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'username, email, password are required']);
        return;
    }
    // ── Admins may only create clinical staff — never other admins ──
    if (!in_array($role, ['doctor','nurse'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid role. Admins may only create doctor or nurse accounts.']);
        return;
    }
    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        return;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $specialtyVal = $specialty !== '' ? $specialty : null;
    $conn = getConn();
    $stmt = $conn->prepare(
        "INSERT INTO users (username, email, password_hash, role, full_name, specialty, hospital)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('sssssss', $username, $email, $hash, $role, $fullName, $specialtyVal, $hospital);
    $ok = $stmt->execute();
    $newId = (int)$conn->insert_id;
    $stmt->close();
    $conn->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Username or email already exists.']);
        return;
    }
    echo json_encode(['success' => true, 'id' => $newId, 'message' => "User $username created."]);
}

/** POST action=update_user  body: {id, email?, role?, full_name?, is_active?}
 *  Admins may only edit doctor/nurse accounts within their own hospital, and
 *  may not change a user's role to/from 'admin' or move them to another hospital.
 */
function updateUser(array $b): void {
    $id = (int)($b['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'message' => 'id required']); return; }

    $hospital = $_SESSION['hospital'] ?? '';
    $conn = getConn();

    // ── Ownership check: target must be a doctor/nurse at this admin's hospital ──
    $chk = $conn->prepare("SELECT role, hospital FROM users WHERE id = ?");
    $chk->bind_param('i', $id);
    $chk->execute();
    $target = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$target || $target['hospital'] !== $hospital || !in_array($target['role'], ['doctor','nurse'], true)) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'User not found in your hospital.']);
        return;
    }

    // ── Allowed fields. 'role' may only flip between doctor <-> nurse;
    //    'hospital' is intentionally NOT editable here. ──
    $allowed = ['email', 'role', 'full_name', 'specialty', 'is_active'];
    $sets = []; $vals = []; $types = '';
    foreach ($allowed as $f) {
        if (!array_key_exists($f, $b)) continue;
        if ($f === 'role' && !in_array($b[$f], ['doctor','nurse'], true)) continue;
        $sets[]  = "$f = ?";
        $vals[]  = $b[$f];
        $types  .= ($f === 'is_active') ? 'i' : 's';
    }
    if (!$sets) { $conn->close(); echo json_encode(['success' => false, 'message' => 'Nothing to update']); return; }

    $vals[]  = $id;
    $types  .= 'i';
    $stmt    = $conn->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->bind_param($types, ...$vals);
    $ok = $stmt->execute();
    $stmt->close(); $conn->close();
    echo json_encode(['success' => $ok]);
}

/** POST action=deactivate_user  body: {id}  (sets is_active = 0)
 *  Admins may only deactivate doctor/nurse accounts within their own hospital.
 */
function deactivateUser(array $b): void {
    $id = (int)($b['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'message' => 'id required']); return; }
    // Prevent admin from deactivating their own account
    if ((int)($_SESSION['user_id'] ?? 0) === $id) {
        echo json_encode(['success' => false, 'message' => 'You cannot deactivate your own account.']);
        return;
    }

    $hospital = $_SESSION['hospital'] ?? '';
    $conn = getConn();

    // ── Ownership check: target must be a doctor/nurse at this admin's hospital ──
    $chk = $conn->prepare("SELECT role, hospital FROM users WHERE id = ?");
    $chk->bind_param('i', $id);
    $chk->execute();
    $target = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$target || $target['hospital'] !== $hospital || !in_array($target['role'], ['doctor','nurse'], true)) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'User not found in your hospital.']);
        return;
    }

    $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute(); $stmt->close(); $conn->close();
    echo json_encode(['success' => $ok]);
}

/** POST action=reset_password  body: {id, new_password} */
// ── Helper: send updated credentials email to a staff member ─────────────────
function sendCredentialsEmail(string $toEmail, string $fullName, string $username,
                              string $plainPassword, string $hospital, string $role): bool {
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/mailer_config.php';

    $roleIcon = match($role) { 'doctor' => '🩺', 'manager' => '👔', default => '💊' };
    $roleName = ucfirst($role);

    $emailBody = "
<p style='margin:0 0 20px;color:#374151;font-size:16px;line-height:1.6;'>
  Hello <strong>{$fullName}</strong>! 👋 An admin at <strong>{$hospital}</strong> has reset your login credentials.
  Here are your updated details:
</p>
<table width='100%' cellspacing='0' cellpadding='0' style='border-collapse:separate;border-spacing:0 6px;margin-bottom:24px;'>
  <tr>
    <td style='padding:12px 16px;background:#f8fafc;border-radius:8px;font-weight:600;color:#374151;font-size:14px;width:38%;'>
      {$roleIcon} {$roleName} Username
    </td>
    <td style='padding:12px 16px;font-family:monospace;font-size:15px;color:#1d4ed8;font-weight:700;'>
      {$username}
    </td>
  </tr>
  <tr>
    <td style='padding:12px 16px;background:#f8fafc;border-radius:8px;font-weight:600;color:#374151;font-size:14px;'>
      🔑 New Password
    </td>
    <td style='padding:12px 16px;font-family:monospace;font-size:15px;color:#1d4ed8;font-weight:700;'>
      {$plainPassword}
    </td>
  </tr>
</table>
<div style='background:#fef3c7;border:1px solid #fbbf24;border-radius:10px;padding:14px 18px;color:#92400e;font-size:13px;line-height:1.6;'>
  ⚠️ <strong>Security reminder:</strong> Please change your password after first login.
  Do not share these credentials with anyone.
</div>
";
    try {
        $mail = createMailer();
        $mail->addAddress($toEmail);
        $mail->Subject = "🏥 Your Pharos HIS Login Credentials — {$hospital}";
        $mail->Body    = emailWrapper("Your Updated Login Credentials", $emailBody);
        $mail->AltBody = "Hello {$fullName},\n\nYour credentials were reset by an admin at {$hospital}.\n\n"
                       . "Username: {$username}\nNew Password: {$plainPassword}\n\nPlease change your password after first login.";
        $mail->send();
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

/** POST action=reset_password  body: {id, new_password}
 *  Hashes and saves the new password, then emails it directly to the
 *  staff member. The admin never sees the plain-text password.
 */
function handleResetPassword(array $b): void {
    $id  = (int)($b['id']           ?? 0);
    $pwd = trim($b['new_password']   ?? '');
    if (!$id || strlen($pwd) < 8) {
        echo json_encode(['success' => false, 'message' => 'id and new_password (min 8 chars) required']);
        return;
    }

    $hospital = $_SESSION['hospital'] ?? '';
    $conn = getConn();

    $stmt = $conn->prepare(
        "SELECT id, username, email, full_name, role, hospital FROM users
         WHERE id = ? AND role IN ('doctor','nurse','manager') LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || $user['hospital'] !== $hospital) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Staff member not found in your hospital.']);
        return;
    }

    $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
    $upd  = $conn->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
    $upd->bind_param('si', $hash, $id);
    $upd->execute();
    $upd->close();
    $conn->close();

    $sent = sendCredentialsEmail(
        $user['email'],
        $user['full_name'] ?: $user['username'],
        $user['username'],
        $pwd,
        $user['hospital'],
        $user['role']
    );

    echo json_encode([
        'success'    => true,
        'message'    => $sent
            ? "Password reset and emailed to {$user['email']}."
            : "Password reset, but email could not be sent to {$user['email']}.",
        'email_sent' => $sent,
    ]);
}

/** GET ?action=get_staff_patients&staff_id=...  — patients whose `physician_id`
 *  FK matches the given staff member's user id. Used by the admin dashboard
 *  to show which patients a doctor/nurse is supervising.
 *  Restricted to admins; the target staff member must belong to the
 *  admin's own hospital.
 */
function getStaffPatients(): void {
    $staffId = (int)($_GET['staff_id'] ?? 0);
    if (!$staffId) {
        echo json_encode(['success' => false, 'message' => 'staff_id required']);
        return;
    }

    $hospital = $_SESSION['hospital'] ?? '';
    $conn = getConn();

    // ── Ownership check: the staff member must exist in this admin's hospital ──
    $chk = $conn->prepare(
        "SELECT full_name FROM users WHERE id = ? AND hospital = ? AND role IN ('doctor','nurse') LIMIT 1"
    );
    $chk->bind_param('is', $staffId, $hospital);
    $chk->execute();
    $staff = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$staff) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Staff member not found in your hospital.']);
        return;
    }

    $stmt = $conn->prepare(
        "SELECT patient_id, first_name, last_name, dob, gender, status, last_visit, next_appt, conditions
         FROM patients
         WHERE physician_id = ?
         ORDER BY last_visit DESC"
    );
    $stmt->bind_param('i', $staffId);
    $stmt->execute();
    $rows = []; $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close(); $conn->close();

    echo json_encode(['success' => true, 'physician' => $staff['full_name'], 'count' => count($rows), 'data' => $rows]);
}


function getAuditLog(): void {
    $limit    = min((int)($_GET['limit'] ?? 100), 500);
    $hospital = $_SESSION['hospital'] ?? '';
    $conn  = getConn();
    $stmt  = $conn->prepare(
        "SELECT us.id, u.username, u.full_name, u.role,
                us.action, us.ip_address, us.user_agent, us.created_at
         FROM user_sessions us
         JOIN users u ON u.id = us.user_id
         WHERE u.hospital = ?
         ORDER BY us.created_at DESC
         LIMIT ?"
    );
    $stmt->bind_param('si', $hospital, $limit);
    $stmt->execute();
    $rows = []; $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close(); $conn->close();
    echo json_encode(['success' => true, 'hospital' => $hospital, 'data' => $rows]);
}


// ═════════════════════════════════════════════════════════════════════════════
// CDSS ALERT DISMISS
// Admin and Doctor can dismiss/acknowledge active alerts.
// ═════════════════════════════════════════════════════════════════════════════
function dismissAlert(array $b): void {
    $alertStateId = (int)($b['alert_state_id'] ?? 0);
    if (!$alertStateId) {
        echo json_encode(['success' => false, 'message' => 'alert_state_id required']);
        return;
    }
    $conn = getConn();
    $stmt = $conn->prepare(
        "UPDATE patient_alert_states
         SET state = 'dismissed', resolved_at = NOW()
         WHERE id = ? AND state = 'active'"
    );
    $stmt->bind_param('i', $alertStateId);
    $ok = $stmt->execute();
    $stmt->close(); $conn->close();
    echo json_encode(['success' => $ok]);
}


// ═════════════════════════════════════════════════════════════════════════════
// PATIENTS  (unchanged from original)
// ═════════════════════════════════════════════════════════════════════════════
function getPatients() {
    $conn = getConn();
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if ($q !== '') {
        $like = '%' . $conn->real_escape_string($q) . '%';
        $sql  = "SELECT * FROM patients
                 WHERE first_name LIKE '$like' OR last_name  LIKE '$like'
                    OR patient_id LIKE '$like' OR conditions LIKE '$like'
                    OR physician  LIKE '$like'
                 ORDER BY created_at DESC";
    } else {
        $sql = "SELECT * FROM patients ORDER BY created_at DESC";
    }
    $res = $conn->query($sql);
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = fmtPatient($row);
    echo json_encode(['success' => true, 'data' => $out]);
    $conn->close();
}

function getPatient() {
    $pid = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param('s', $pid); $stmt->execute();
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close(); $conn->close();
    if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); return; }
    echo json_encode(['success'=>true,'data'=>fmtPatient($row)]);
}

function fmtPatient($row) {
    $dob    = !empty($row['dob']) ? date('d M Y', strtotime($row['dob'])) : '—';
    $dobDt  = !empty($row['dob']) ? new DateTime($row['dob']) : null;
    $age    = $dobDt ? (int)$dobDt->diff(new DateTime())->y : 0;
    $algRaw = !empty($row['allergies']) ? $row['allergies'] : '';
    $algs   = $algRaw ? array_values(array_filter(array_map('trim', explode(',', $algRaw)))) : [];
    return [
        'id'         => $row['patient_id'],
        'name'       => $row['first_name'] . ' ' . $row['last_name'],
        'first_name' => $row['first_name'],
        'last_name'  => $row['last_name'],
        'gender'     => $row['gender'],
        'age'        => $age,
        'dob'        => $dob,
        'dob_raw'    => $row['dob'],
        'blood'      => $row['blood_type']  ?: 'Unknown',
        'phone'      => $row['phone']       ?: '—',
        'email'      => $row['email']       ?: '—',
        'insurance'  => $row['insurance']   ?: '—',
        'physician'  => $row['physician']   ?: '—',
        'allergies'  => $algs,
        'conditions' => $row['conditions']  ?: 'None recorded',
        'smoking'    => $row['smoking']     ?: 'Not recorded',
        'alcohol'    => $row['alcohol']     ?: 'Not recorded',
        'pmh'        => $row['pmh']         ?: '',
        'fmh'        => $row['fmh']         ?: '',
        'surgical'   => $row['surgical']    ?: '',
        'vaccines'   => $row['vaccines']    ?: '',
        'color'      => $row['avatar_color']?: '#1a73e8',
        'status'     => $row['status']      ?: 'active',
        'lastVisit'  => $row['last_visit']  ?: 'Not yet visited',
        'nextAppt'   => $row['next_appt']   ?: 'None scheduled',
    ];
}

function nextPatientId() {
    $conn = getConn();
    $res  = $conn->query("SELECT patient_id FROM patients ORDER BY id DESC LIMIT 1");
    $row  = $res->fetch_assoc();
    $max  = $row ? (int)substr($row['patient_id'], 2) : 0;
    $next = 'P-' . str_pad($max + 1, 5, '0', STR_PAD_LEFT);
    echo json_encode(['success'=>true,'patient_id'=>$next]);
    $conn->close();
}

function addPatient($b) {
    $fn=$b['first_name']??''; $ln=$b['last_name']??'';
    $dob=$b['dob']??'';       $gen=$b['gender']??'';
    if (!$fn||!$ln||!$dob||!$gen){echo json_encode(['success'=>false,'message'=>'first_name, last_name, dob, gender are required']);return;}
    $colors=['#1a73e8','#00b4a6','#8b5cf6','#ec4899','#f59e0b','#ef4444','#10b981','#06b6d4','#0b3c7a','#6366f1'];
    $conn=getConn();
    $res=$conn->query("SELECT patient_id FROM patients ORDER BY id DESC LIMIT 1");
    $row=$res->fetch_assoc();$max=$row?(int)substr($row['patient_id'],2):0;
    $pid='P-'.str_pad($max+1,5,'0',STR_PAD_LEFT);
    $cnt=(int)$conn->query("SELECT COUNT(*) AS c FROM patients")->fetch_assoc()['c'];
    $clr=$colors[$cnt%count($colors)];
    $bld=$b['blood_type']??'';$ph=$b['phone']??'';$em=$b['email']??'';
    $ins=$b['insurance']??'';$doc=$b['physician']??'';$alg=$b['allergies']??'';
    $cnd=$b['conditions']??'';$smk=$b['smoking']??'';$alc=$b['alcohol']??'';
    $pmh=$b['pmh']??'';$fmh=$b['fmh']??'';$surg=$b['surgical']??'';$vac=$b['vaccines']??'';

    // ── Resolve physician_id: prefer explicit id, else look up by full_name ──
    $docId = null;
    if (!empty($b['physician_id'])) {
        $docId = (int)$b['physician_id'];
    } elseif ($doc !== '') {
        $lk = $conn->prepare("SELECT id FROM users WHERE full_name = ? AND role IN ('doctor','nurse') LIMIT 1");
        $lk->bind_param('s', $doc);
        $lk->execute();
        $row2 = $lk->get_result()->fetch_assoc();
        $lk->close();
        if ($row2) $docId = (int)$row2['id'];
    }

    $stmt=$conn->prepare("INSERT INTO patients (patient_id,first_name,last_name,dob,gender,blood_type,phone,email,insurance,physician,physician_id,allergies,conditions,smoking,alcohol,pmh,fmh,surgical,vaccines,avatar_color,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active')");
    $stmt->bind_param('sssssssssissssssssss',$pid,$fn,$ln,$dob,$gen,$bld,$ph,$em,$ins,$doc,$docId,$alg,$cnd,$smk,$alc,$pmh,$fmh,$surg,$vac,$clr);
    // NOTE: type string must have 20 chars matching 20 bound values above
    if(!$stmt->execute()){$err=$conn->error;$stmt->close();$conn->close();echo json_encode(['success'=>false,'message'=>$err]);return;}
    $stmt->close();
    $today=date('d M Y');$text='<strong>Patient Registration</strong> — New patient registered in the EHR system.';
    $ts=$conn->prepare("INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,'$today','ok',?)");
    $ts->bind_param('ss',$pid,$text);$ts->execute();$ts->close();
    $conn->close();
    echo json_encode(['success'=>true,'patient_id'=>$pid,'message'=>"Patient $fn $ln registered ($pid)"]);
}

function updatePatient($b) {
    $pid=$b['patient_id']??'';
    if(!$pid){echo json_encode(['success'=>false,'message'=>'patient_id required']);return;}
    $allowed=['first_name','last_name','phone','email','insurance','physician','blood_type','allergies','conditions','smoking','alcohol','pmh','fmh','surgical','vaccines','last_visit','next_appt','status'];
    $sets=[];$vals=[];$types='';
    foreach($allowed as $f){if(array_key_exists($f,$b)){$sets[]="$f=?";$vals[]=$b[$f];$types.='s';}}

    $conn=getConn();

    // ── Keep physician_id in sync whenever `physician` (name) is updated ──
    if (array_key_exists('physician_id', $b)) {
        $sets[] = "physician_id=?"; $vals[] = ($b['physician_id'] !== '' ? (int)$b['physician_id'] : null); $types .= 'i';
    } elseif (array_key_exists('physician', $b)) {
        $docId = null;
        if ($b['physician'] !== '') {
            $lk = $conn->prepare("SELECT id FROM users WHERE full_name = ? AND role IN ('doctor','nurse') LIMIT 1");
            $lk->bind_param('s', $b['physician']);
            $lk->execute();
            $row2 = $lk->get_result()->fetch_assoc();
            $lk->close();
            if ($row2) $docId = (int)$row2['id'];
        }
        $sets[] = "physician_id=?"; $vals[] = $docId; $types .= 'i';
    }

    if(!$sets){$conn->close();echo json_encode(['success'=>false,'message'=>'Nothing to update']);return;}
    $vals[]=$pid;$types.='s';
    $stmt=$conn->prepare("UPDATE patients SET ".implode(',',$sets)." WHERE patient_id=?");
    $stmt->bind_param($types,...$vals);$ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}

function deletePatient($b) {
    $pid=$b['patient_id']??'';
    if(!$pid){echo json_encode(['success'=>false,'message'=>'patient_id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("DELETE FROM patients WHERE patient_id=?");
    $stmt->bind_param('s',$pid);$ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}


// ═════════════════════════════════════════════════════════════════════════════
// TIMELINE  (unchanged from original)
// ═════════════════════════════════════════════════════════════════════════════
function getTimeline() {
    $pid=isset($_GET['patient_id'])?trim($_GET['patient_id']):'';
    if(!$pid){echo json_encode(['success'=>false,'message'=>'patient_id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("SELECT * FROM timeline WHERE patient_id=? ORDER BY id DESC");
    $stmt->bind_param('s',$pid);$stmt->execute();
    $rows=[];$res=$stmt->get_result();
    while($r=$res->fetch_assoc())$rows[]=$r;
    $stmt->close();$conn->close();
    echo json_encode(['success'=>true,'data'=>$rows]);
}

function addTimeline($b) {
    $pid=$b['patient_id']??'';$date=$b['entry_date']??date('d M Y');
    $dot=$b['dot_type']??'';$text=trim($b['entry_text']??'');
    if(!$pid||!$text){echo json_encode(['success'=>false,'message'=>'patient_id and entry_text required']);return;}
    if(!in_array($dot,['','ok','warn','red']))$dot='';
    $conn=getConn();
    $stmt=$conn->prepare("INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,?,?,?)");
    $stmt->bind_param('ssss',$pid,$date,$dot,$text);
    $ok=$stmt->execute();$id=$conn->insert_id;$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok,'id'=>$id]);
}

function deleteTimeline($b) {
    $id=(int)($b['id']??0);
    if(!$id){echo json_encode(['success'=>false,'message'=>'id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("DELETE FROM timeline WHERE id=?");
    $stmt->bind_param('i',$id);$ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}


// ═════════════════════════════════════════════════════════════════════════════
// VITALS
// ═════════════════════════════════════════════════════════════════════════════
function getVitals() {
    $pid=isset($_GET['patient_id'])?trim($_GET['patient_id']):'';
    if(!$pid){echo json_encode(['success'=>false,'message'=>'patient_id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("SELECT * FROM vitals WHERE patient_id=? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s',$pid);$stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();$stmt->close();$conn->close();
    echo json_encode(['success'=>true,'data'=>$row]);
}

// ─────────────────────────────────────────────────────────────────────────────
// saveVitals()
//
// ── CDSS Section 2: Event-Driven Ingestion Trigger ───────────────────────────
//
// Placement of CDSS block: immediately after $ok = $stmt->execute() confirms
// the vitals row was saved, and before $conn->close().
//
// The block:
//   a) Parses the BP string ("185/112") → systolic (float) + diastolic (float).
//   b) Builds a definitions array for 5 vital sign observations:
//      systolic_bp, diastolic_bp, heart_rate, spo2, body_temperature.
//   c) Skips any observation where the incoming value was a placeholder ('—')
//      or could not yield a non-zero float.
//   d) INSERTs each valid observation into `patient_observations` using a
//      single prepared statement (re-bound per iteration — one round-trip each).
//   e) Instantiates CdssEngine($conn) ONCE for the request and calls
//      onNewObservation($insertedObsId) per row → synchronous Tier 1+2 evaluation.
//   f) The entire CDSS block is wrapped in try/catch (Throwable).
//      Engine errors are logged and silently swallowed — the primary vitals
//      INSERT result ($ok) is never altered by a CDSS failure.
// ─────────────────────────────────────────────────────────────────────────────
function saveVitals($b) {
    // ── RBAC: only nurses and doctors may record vitals ───────────────────────
    requireRole(['admin', 'nurse', 'doctor']);

    $pid = $b['patient_id'] ?? '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }

    $conn = getConn();
    $ra = $b['recorded_at'] ?? date('d M Y, h:i A');
    $nu = $b['nurse']       ?? '—';
    $bp = $b['bp']          ?? '—';
    $bs = $b['bp_status']   ?? 'ok';
    $hr = $b['hr']          ?? '—';
    $tp = $b['temp']        ?? '—';
    $sp = $b['spo2']        ?? '—';
    $rr = $b['rr']          ?? '—';
    $bm = $b['bmi']         ?? '—';
    $nt = $b['note']        ?? '';

    // ── Primary vitals INSERT (original, unchanged) ──────────────────────────
    $stmt = $conn->prepare(
        "INSERT INTO vitals
             (patient_id,recorded_at,nurse,bp,bp_status,hr,temp,spo2,rr,bmi,note)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('sssssssssss', $pid,$ra,$nu,$bp,$bs,$hr,$tp,$sp,$rr,$bm,$nt);
    $ok = $stmt->execute();
    $stmt->close();
    // ────────────────────────────────────────────────────────────────────────

    // ┌─────────────────────────────────────────────────────────────────────┐
    // │  CDSS SECTION 2 — INSERT BLOCK (place here, after $ok, before      │
    // │  $conn->close())                                                     │
    // └─────────────────────────────────────────────────────────────────────┘
    if ($ok) {
        try {
            // a) Parse BP string ── "185/112" → systolic=185.0, diastolic=112.0
            //    explode gives ['185','112']; floatval() is safe on any string.
            $bpParts   = explode('/', $bp);
            $systolic  = isset($bpParts[0]) ? (float) $bpParts[0] : null;
            $diastolic = isset($bpParts[1]) ? (float) $bpParts[1] : null;

            // b) Observation definitions
            //    parameter_key must match rule_criteria.parameter_key exactly.
            //    loinc_code is stored for FHIR forward-compatibility.
            //    A null or 0 numeric_value causes the row to be skipped (step c).
            $vitalDefs = [
                // parameter_key      raw value string  unit     LOINC
                ['systolic_bp',       $systolic,         'mmHg',  '8480-6' ],
                ['diastolic_bp',      $diastolic,        'mmHg',  '8462-4' ],
                ['heart_rate',        ($hr !== '—' && $hr !== '') ? (float)$hr : null, 'bpm', '8867-4'   ],
                ['spo2',              ($sp !== '—' && $sp !== '') ? (float)$sp : null, '%',   '59408-5'  ],
                ['body_temperature',  ($tp !== '—' && $tp !== '') ? (float)$tp : null, 'C',   '8310-5'   ],
            ];

            // c) Single prepared statement reused for all 5 observations.
            //    Columns: patient_id, observation_type (literal), loinc_code,
            //    parameter_key, numeric_value (DECIMAL), unit, status (literal),
            //    recorded_by, recorded_at (NOW()).
            $obsStmt = $conn->prepare(
                "INSERT INTO patient_observations
                     (patient_id, observation_type, loinc_code,
                      parameter_key, numeric_value, unit, status,
                      recorded_by, recorded_at)
                 VALUES (?, 'vital_sign', ?, ?, ?, ?, 'final', ?, NOW())"
            );
            // bind_param types: s=patient_id, s=loinc, s=param_key,
            //                   d=numeric_value (DECIMAL), s=unit, s=recorded_by
            $loincCode    = '';
            $paramKey     = '';
            $numericValue = 0.0;
            $unitStr      = '';
            $obsStmt->bind_param('sssdss',
                $pid, $loincCode, $paramKey, $numericValue, $unitStr, $nu
            );

            // d) Instantiate the engine ONCE — it caches rule lookups internally.
            $engine = new CdssEngine($conn);

            foreach ($vitalDefs as [$paramKeyVal, $numVal, $unitVal, $loincVal]) {
                // Skip missing or zero values — placeholder inputs like '—'
                // produce null or 0.0 which are not valid clinical observations.
                if ($numVal === null || $numVal <= 0.0) {
                    continue;
                }

                // Re-bind variables (bind_param uses references)
                $loincCode    = $loincVal;
                $paramKey     = $paramKeyVal;
                $numericValue = $numVal;
                $unitStr      = $unitVal;

                if ($obsStmt->execute()) {
                    $insertedObsId = (int) $conn->insert_id;

                    // e) Fire immediate synchronous CDSS evaluation.
                    //    onNewObservation() loads Tier 1+2 rules for 'vital_sign',
                    //    evaluates the full patient payload, checks suppression,
                    //    and writes to patient_alert_states if a rule fires.
                    $engine->onNewObservation($insertedObsId);
                }
            }

            $obsStmt->close();

        } catch (Throwable $cdssErr) {
            // f) Non-fatal: vitals save already succeeded. Log and continue.
            //    Never change $ok or the HTTP response on engine failure.
            error_log('[CDSS][saveVitals] patient=' . $pid . ' | ' . $cdssErr->getMessage());
        }
    }
    // └─────────────────────────────────────────────────────────────────────┘

    $conn->close();
    echo json_encode(['success' => $ok]);
}


// ═════════════════════════════════════════════════════════════════════════════
// updateVitals()
// Replaces the existing vitals row for a patient and re-fires CDSS evaluation.
// Called by the frontend when currentVitalsData already exists (edit flow).
// ═════════════════════════════════════════════════════════════════════════════
function updateVitals($b) {
    // ── RBAC: admin, nurse, and doctors may update vitals ───────────────────────
    requireRole(['admin', 'nurse', 'doctor']);

    $pid = $b["patient_id"] ?? "";
    if (!$pid) { echo json_encode(["success"=>false,"message"=>"patient_id required"]); return; }

    $conn = getConn();
    $ra = $b["recorded_at"] ?? date("d M Y, h:i A");
    $nu = $b["nurse"]       ?? "—";
    $bp = $b["bp"]          ?? "—";
    $bs = $b["bp_status"]   ?? "ok";
    $hr = $b["hr"]          ?? "—";
    $tp = $b["temp"]        ?? "—";
    $sp = $b["spo2"]        ?? "—";
    $rr = $b["rr"]          ?? "—";
    $bm = $b["bmi"]         ?? "—";
    $nt = $b["note"]        ?? "";

    $stmt = $conn->prepare(
        "UPDATE vitals
         SET recorded_at=?, nurse=?, bp=?, bp_status=?, hr=?, temp=?, spo2=?, rr=?, bmi=?, note=?
         WHERE patient_id=?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param("sssssssssss", $ra,$nu,$bp,$bs,$hr,$tp,$sp,$rr,$bm,$nt,$pid);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        try {
            $bpParts   = explode("/", $bp);
            $systolic  = isset($bpParts[0]) ? (float) $bpParts[0] : null;
            $diastolic = isset($bpParts[1]) ? (float) $bpParts[1] : null;
            $vitalDefs = [
                ["systolic_bp",      $systolic,  "mmHg", "8480-6"],
                ["diastolic_bp",     $diastolic, "mmHg", "8462-4"],
                ["heart_rate",       ($hr !== "—" && $hr !== "") ? (float)$hr : null, "bpm", "8867-4"],
                ["spo2",             ($sp !== "—" && $sp !== "") ? (float)$sp : null, "%",   "59408-5"],
                ["body_temperature", ($tp !== "—" && $tp !== "") ? (float)$tp : null, "C",   "8310-5"],
            ];
            $obsStmt = $conn->prepare(
                "INSERT INTO patient_observations
                     (patient_id, observation_type, loinc_code,
                      parameter_key, numeric_value, unit, status,
                      recorded_by, recorded_at)
                 VALUES (?, 'vital_sign', ?, ?, ?, ?, 'final', ?, NOW())"
            );
            $loincCode = ""; $paramKey = ""; $numericValue = 0.0; $unitStr = "";
            $obsStmt->bind_param("sssdss", $pid, $loincCode, $paramKey, $numericValue, $unitStr, $nu);
            $engine = new CdssEngine($conn);
            foreach ($vitalDefs as [$paramKeyVal, $numVal, $unitVal, $loincVal]) {
                if ($numVal === null || $numVal <= 0.0) continue;
                $loincCode = $loincVal; $paramKey = $paramKeyVal;
                $numericValue = $numVal; $unitStr = $unitVal;
                if ($obsStmt->execute()) { $engine->onNewObservation((int) $conn->insert_id); }
            }
            $obsStmt->close();
        } catch (Throwable $cdssErr) {
            error_log("[CDSS][updateVitals] patient=" . $pid . " | " . $cdssErr->getMessage());
        }
    }
    $conn->close();
    echo json_encode(["success" => $ok]);
}


// ═════════════════════════════════════════════════════════════════════════════
// MEDICATIONS  (unchanged from original)
// ═════════════════════════════════════════════════════════════════════════════
function getMeds() {
    $pid=isset($_GET['patient_id'])?trim($_GET['patient_id']):'';
    if(!$pid){echo json_encode(['success'=>false,'message'=>'patient_id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("SELECT * FROM medications WHERE patient_id=? ORDER BY id DESC");
    $stmt->bind_param('s',$pid);$stmt->execute();
    $rows=[];$res=$stmt->get_result();
    while($r=$res->fetch_assoc())$rows[]=$r;
    $stmt->close();$conn->close();
    echo json_encode(['success'=>true,'data'=>$rows]);
}

function addMed($b) {
    $pid=trim($b['patient_id']??'');$nm=trim($b['med_name']??'');
    $dose=trim($b['dose']??'');$by=trim($b['prescribed_by']??'');
    if(!$pid||!$nm||!$dose||!$by){echo json_encode(['success'=>false,'message'=>'Required fields missing']);return;}
    $conn=getConn();
    $sd=$b['start_date']??date('d M Y');$st=$b['status']??'active';
    $stmt=$conn->prepare("INSERT INTO medications (patient_id,med_name,dose,prescribed_by,start_date,status) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('ssssss',$pid,$nm,$dose,$by,$sd,$st);
    $ok=$stmt->execute();$nid=$conn->insert_id;$stmt->close();
    $tl=$conn->prepare("INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,?,'ok',?)");
    $txt="<strong>E-Prescription: $nm</strong> — $dose prescribed by $by.";
    $tl->bind_param('sss',$pid,$sd,$txt);$tl->execute();$tl->close();
    $conn->close();
    echo json_encode(['success'=>$ok,'id'=>$nid]);
}

function deleteMed($b) {
    $id=(int)($b['id']??0);
    if(!$id){echo json_encode(['success'=>false,'message'=>'id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("DELETE FROM medications WHERE id=?");
    $stmt->bind_param('i',$id);$ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}

function requestRefill($b) {
    $pid=trim($b['patient_id']??'');$nm=trim($b['med_name']??'');
    $ph=trim($b['pharmacy']??'HealthCare Hub Pharmacy');$qty=trim($b['qty']??'30 days');
    if(!$pid||!$nm){echo json_encode(['success'=>false,'message'=>'patient_id and med_name required']);return;}
    $conn=getConn();$today=date('d M Y');
    $txt="<strong>Refill Request: $nm</strong> — $qty supply sent to $ph.";
    $stmt=$conn->prepare("INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,'$today','ok',?)");
    $stmt->bind_param('ss',$pid,$txt);$ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}


// ═════════════════════════════════════════════════════════════════════════════
// LAB RESULTS
// ═════════════════════════════════════════════════════════════════════════════
function getLabs() {
    $pid=isset($_GET['patient_id'])?trim($_GET['patient_id']):'';
    if(!$pid){echo json_encode(['success'=>false,'message'=>'patient_id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("SELECT * FROM lab_results WHERE patient_id=? ORDER BY id DESC");
    $stmt->bind_param('s',$pid);$stmt->execute();
    $rows=[];$res=$stmt->get_result();
    while($r=$res->fetch_assoc())$rows[]=$r;
    $stmt->close();$conn->close();
    echo json_encode(['success'=>true,'data'=>$rows]);
}

// ─────────────────────────────────────────────────────────────────────────────
// addLab()
//
// ── CDSS Section 3: Event-Driven Ingestion Trigger ───────────────────────────
//
// Placement of CDSS block: immediately after $ok and $nid are captured from
// the primary lab_results INSERT, and before $conn->close().
//
// The block:
//   a) Normalises test_name to a snake_case parameter_key that matches what is
//      stored in rule_criteria.parameter_key.
//      "Serum Potassium" → "serum_potassium"
//   b) Derives a numeric value from the string test_value using floatval().
//      Determines whether the result is numeric-domain or string-domain.
//      Numeric: numeric_value = float, string_value = NULL.
//      String:  numeric_value = NULL,  string_value = raw val (e.g. "Positive").
//   c) Extracts a unit string from the ref_range field using a trailing
//      alpha-regex (e.g. "3.5–5.0 mEq/L" → "mEq/L").
//   d) INSERTs one row into `patient_observations` for the lab_result domain.
//   e) Fires CdssEngine::onNewObservation($insertedObsId) for synchronous
//      Tier 1+2 rule evaluation against the lab_result domain.
//   f) Wrapped in try/catch (Throwable) — engine errors never affect $ok/$nid.
// ─────────────────────────────────────────────────────────────────────────────
function addLab($b) {
    // ── RBAC: admin, doctors, and nurses may add lab results ─────────────────
    requireRole(['admin', 'doctor', 'nurse']);

    $pid = trim($b['patient_id'] ?? '');
    $nm  = trim($b['test_name']  ?? '');
    $val = trim($b['test_value'] ?? '');
    $ref = trim($b['ref_range']  ?? '');
    $lbl = trim($b['label']      ?? '');
    if (!$pid||!$nm||!$val||!$ref||!$lbl) {
        echo json_encode(['success'=>false,'message'=>'Required fields missing']);
        return;
    }

    $conn = getConn();
    $pd   = $b['panel_date'] ?? date('d M Y');
    $pct  = min(100,max(0,(int)($b['pct']??50)));
    $cls  = in_array($b['cls']??'',['ok','med','hi']) ? $b['cls'] : 'ok';
    $clrMap = ['ok'=>'success','med'=>'accent2','hi'=>'danger'];
    $clr  = $clrMap[$cls];

    // ── Primary lab_results INSERT (original, unchanged) ─────────────────────
    $stmt = $conn->prepare(
        "INSERT INTO lab_results
             (patient_id,panel_date,test_name,test_value,ref_range,pct,cls,label,color_type)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('sssssisss', $pid,$pd,$nm,$val,$ref,$pct,$cls,$lbl,$clr);
    $ok  = $stmt->execute();
    $nid = (int) $conn->insert_id;
    $stmt->close();
    // ─────────────────────────────────────────────────────────────────────────

    // ┌─────────────────────────────────────────────────────────────────────┐
    // │  CDSS SECTION 3 — INSERT BLOCK (place here, after $nid, before     │
    // │  $conn->close())                                                     │
    // └─────────────────────────────────────────────────────────────────────┘
    if ($ok) {
        try {
            // a) Normalise test_name → snake_case parameter_key.
            //    preg_replace collapses any run of non-alphanumeric chars to '_'.
            //    "Serum Potassium"  → "serum_potassium"
            //    "WBC Count"        → "wbc_count"
            //    "ALT (SGPT)"       → "alt_sgpt"
            $parameterKey = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $nm));
            $parameterKey = trim($parameterKey, '_');

            // Canonical alias map: normalise common short names to the keys used
            // in rule_criteria so rules fire regardless of how the lab is named.
            $paramAliasMap = [
                'sodium'              => 'serum_sodium',
                'potassium'           => 'serum_potassium',
                'k'                   => 'serum_potassium',
                'na'                  => 'serum_sodium',
                'hgb'                 => 'hemoglobin',
                'hb'                  => 'hemoglobin',
                'haemoglobin'         => 'hemoglobin',
                'hba1c'               => 'hba1c',
                'glycated_hemoglobin' => 'hba1c',
                'chol'                => 'total_cholesterol',
                'cholesterol'         => 'total_cholesterol',
                'wbc_count'           => 'wbc',
                'white_blood_cell'    => 'wbc',
                'white_blood_cells'   => 'wbc',
                'platelet'            => 'platelets',
                'platelet_count'      => 'platelets',
                'plt'                 => 'platelets',
                'creat'               => 'creatinine',
                'scr'                 => 'creatinine',
                'urea'                => 'bun',
                'blood_urea_nitrogen' => 'bun',
                'alt_sgpt'            => 'alt',
                'ast_sgot'            => 'ast',
                'tsh_thyroid'         => 'tsh',
            ];
            if (isset($paramAliasMap[$parameterKey])) {
                $parameterKey = $paramAliasMap[$parameterKey];
            }

            // b) Determine whether the result is numeric or categorical.
            //    is_numeric() on the raw string is the most reliable check:
            //    is_numeric('6.1')      → true   → numeric domain
            //    is_numeric('Positive') → false  → string domain
            //    is_numeric('< 0.5')    → false  → string domain (operator prefix)
            $numericValue = null;
            $stringValue  = null;
            if (is_numeric($val)) {
                $numericValue = (float) $val;
            } else {
                $stringValue = $val;    // e.g. "Positive", "Negative", "Trace"
            }

            // c) Extract unit from ref_range.
            //    Regex matches a trailing sequence of letters, slashes, ° or %:
            //    "3.5–5.0 mEq/L"  → "mEq/L"
            //    "70–100 mg/dL"   → "mg/dL"
            //    "0.5–1.2 mg/dL"  → "mg/dL"
            //    "Negative"       → ""  (no match → empty string)
            $unitFromRef = '';
            if (preg_match('/[a-zA-Z\/°%]+$/', $ref, $unitMatch)) {
                $unitFromRef = trim($unitMatch[0]);
            }

            // d) INSERT one patient_observations row for this lab result.
            //    observation_type = 'lab_result' (literal, not a variable).
            //    status = 'final' for all records received through this endpoint.
            //    bind types: s=patient_id, s=param_key, d=numeric (or null),
            //                s=string_value (or null), s=unit.
            $obsStmt = $conn->prepare(
                "INSERT INTO patient_observations
                     (patient_id, observation_type, parameter_key,
                      numeric_value, string_value, unit, status, recorded_at)
                 VALUES (?, 'lab_result', ?, ?, ?, ?, 'final', NOW())"
            );
            $obsStmt->bind_param('ssdss',
                $pid,
                $parameterKey,
                $numericValue,   // NULL for string-domain results
                $stringValue,    // NULL for numeric-domain results
                $unitFromRef
            );

            if ($obsStmt->execute()) {
                $insertedObsId = (int) $conn->insert_id;
                $obsStmt->close();

                // e) Fire synchronous CDSS evaluation.
                //    onNewObservation() fetches Tier 1+2 rules for 'lab_result',
                //    builds the full patient payload, evaluates every criterion,
                //    checks the suppression window, and writes a patient_alert_states
                //    row if any rule is satisfied.
                $engine = new CdssEngine($conn);
                $engine->onNewObservation($insertedObsId);

            } else {
                $obsStmt->close();
            }

        } catch (Throwable $cdssErr) {
            // f) Non-fatal: primary lab save already succeeded ($ok=true, $nid set).
            //    Log and continue — $ok and $nid must not be changed.
            error_log('[CDSS][addLab] patient=' . $pid . ' test=' . $nm . ' | ' . $cdssErr->getMessage());
        }
    }
    // └─────────────────────────────────────────────────────────────────────┘

    $conn->close();
    echo json_encode(['success' => $ok, 'id' => $nid]);
}

function updateLab($b) {
    // ── RBAC: admin and doctors may edit lab results ──────────────────────────
    requireRole(['admin', 'doctor']);

    $id=(int)($b['id']??0);
    if(!$id){echo json_encode(['success'=>false,'message'=>'id required']);return;}
    $nm=trim($b['test_name']??'');$val=trim($b['test_value']??'');
    $ref=trim($b['ref_range']??'');$lbl=trim($b['label']??'');
    $pct=min(100,max(0,(int)($b['pct']??50)));
    $cls=in_array($b['cls']??'',['ok','med','hi'])?$b['cls']:'ok';
    $clrMap=['ok'=>'success','med'=>'accent2','hi'=>'danger'];$clr=$clrMap[$cls];
    $pd=$b['panel_date']??date('d M Y');
    $conn=getConn();
    $stmt=$conn->prepare("UPDATE lab_results SET test_name=?,test_value=?,ref_range=?,pct=?,cls=?,label=?,color_type=?,panel_date=? WHERE id=?");
    $stmt->bind_param('sssissssi',$nm,$val,$ref,$pct,$cls,$lbl,$clr,$pd,$id);
    $ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}

function deleteLab($b) {
    // ── RBAC: admin and doctors may delete lab results ───────────────────────
    requireRole(['admin', 'doctor']);

    $id=(int)($b['id']??0);
    if(!$id){echo json_encode(['success'=>false,'message'=>'id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("DELETE FROM lab_results WHERE id=?");
    $stmt->bind_param('i',$id);$ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}


// ═════════════════════════════════════════════════════════════════════════════
// PROFILE CHANGE REQUESTS
// Doctors / nurses / managers submit requests to change their own name or
// specialty. An admin at the same hospital must approve before the change
// is applied to `users`. Nothing is altered without admin sign-off.
// ═════════════════════════════════════════════════════════════════════════════

/** Ensures the profile_requests table exists (idempotent). */
function ensureProfileRequestsTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `profile_requests` (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id       INT UNSIGNED NOT NULL,
        hospital      VARCHAR(120) NOT NULL,
        field         ENUM('full_name','specialty') NOT NULL,
        old_value     VARCHAR(160) NULL,
        new_value     VARCHAR(160) NOT NULL,
        status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        reviewed_by   INT UNSIGNED NULL,
        reviewed_at   TIMESTAMP NULL,
        review_note   VARCHAR(255) NULL,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_hospital_status (hospital, status)
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

/** POST action=submit_profile_request  body: {field, new_value}
 *  Blocks duplicate pending requests for the same field and no-op submissions.
 */
function submitProfileRequest(array $b): void {
    $field    = trim($b['field']     ?? '');
    $newValue = trim($b['new_value'] ?? '');
    $userId   = (int)($_SESSION['user_id'] ?? 0);
    $hospital = $_SESSION['hospital'] ?? '';

    if (!in_array($field, ['full_name','specialty'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid field. Only full_name or specialty can be changed.']); return;
    }
    if ($newValue === '' || strlen($newValue) > 160) {
        echo json_encode(['success' => false, 'message' => 'New value is empty or too long.']); return;
    }

    $conn = getConn();
    ensureProfileRequestsTable($conn);

    // Block duplicate pending requests for the same field
    $dup = $conn->prepare("SELECT id FROM profile_requests WHERE user_id = ? AND field = ? AND status = 'pending' LIMIT 1");
    $dup->bind_param('is', $userId, $field);
    $dup->execute();
    if ($dup->get_result()->fetch_assoc()) {
        $dup->close(); $conn->close();
        echo json_encode(['success' => false, 'message' => 'You already have a pending request for this field. Wait for admin review.']); return;
    }
    $dup->close();

    // Get current value for the audit trail
    $cur = $conn->prepare("SELECT $field AS val FROM users WHERE id = ? LIMIT 1");
    $cur->bind_param('i', $userId);
    $cur->execute();
    $curRow   = $cur->get_result()->fetch_assoc();
    $cur->close();
    $oldValue = $curRow['val'] ?? null;

    if ($oldValue !== null && trim((string)$oldValue) === $newValue) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'That is already your current value.']); return;
    }

    $stmt = $conn->prepare("INSERT INTO profile_requests (user_id, hospital, field, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('issss', $userId, $hospital, $field, $oldValue, $newValue);
    $ok = $stmt->execute(); $stmt->close(); $conn->close();

    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'Request submitted — pending admin approval.' : 'Could not submit request.',
    ]);
}

/** GET action=get_my_profile_requests  — own request history (newest first). */
function getMyProfileRequests(): void {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $conn   = getConn();
    ensureProfileRequestsTable($conn);

    $stmt = $conn->prepare(
        "SELECT id, field, old_value, new_value, status, review_note, created_at, reviewed_at
         FROM profile_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 20"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = []; $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close(); $conn->close();
    echo json_encode(['success' => true, 'data' => $rows]);
}

/** GET action=get_profile_requests  (admin only)
 *  Lists pending requests for the admin's hospital, joined with staff identity.
 */
function getProfileRequests(): void {
    $hospital = $_SESSION['hospital'] ?? '';
    $status   = $_GET['status'] ?? 'pending';

    $conn = getConn();
    ensureProfileRequestsTable($conn);

    $sql = "SELECT pr.id, pr.user_id, pr.field, pr.old_value, pr.new_value, pr.status,
                   pr.review_note, pr.created_at, pr.reviewed_at,
                   u.username, u.full_name, u.role, u.email
            FROM profile_requests pr
            JOIN users u ON u.id = pr.user_id
            WHERE pr.hospital = ?";
    if ($status !== 'all') $sql .= " AND pr.status = 'pending'";
    $sql .= " ORDER BY pr.created_at DESC LIMIT 100";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $hospital);
    $stmt->execute();
    $rows = []; $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close(); $conn->close();
    echo json_encode(['success' => true, 'data' => $rows]);
}

/** POST action=review_profile_request  body: {id, decision: 'approve'|'reject', note?}
 *  On approval, applies the change to `users` inside a transaction.
 */
function reviewProfileRequest(array $b): void {
    $id       = (int)($b['id']       ?? 0);
    $decision = $b['decision']        ?? '';
    $note     = trim($b['note']       ?? '');
    $adminId  = (int)($_SESSION['user_id'] ?? 0);
    $hospital = $_SESSION['hospital'] ?? '';

    if (!$id || !in_array($decision, ['approve','reject'], true)) {
        echo json_encode(['success' => false, 'message' => 'id and a valid decision (approve|reject) are required.']); return;
    }

    $conn = getConn();
    ensureProfileRequestsTable($conn);

    $stmt = $conn->prepare(
        "SELECT pr.*, u.hospital AS user_hospital FROM profile_requests pr
         JOIN users u ON u.id = pr.user_id WHERE pr.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req || $req['user_hospital'] !== $hospital) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Request not found at your hospital.']); return;
    }
    if ($req['status'] !== 'pending') {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'This request has already been reviewed.']); return;
    }

    $conn->begin_transaction();
    try {
        if ($decision === 'approve') {
            // field is ENUM('full_name','specialty') — safe to interpolate
            $upd = $conn->prepare("UPDATE users SET {$req['field']} = ? WHERE id = ?");
            $upd->bind_param('si', $req['new_value'], $req['user_id']);
            $upd->execute(); $upd->close();
        }
        $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
        $noteVal   = $note !== '' ? $note : null;
        $rev = $conn->prepare(
            "UPDATE profile_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?"
        );
        $rev->bind_param('sisi', $newStatus, $adminId, $noteVal, $id);
        $rev->execute(); $rev->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback(); $conn->close();
        echo json_encode(['success' => false, 'message' => 'Could not process: ' . $e->getMessage()]); return;
    }
    $conn->close();

    echo json_encode([
        'success' => true,
        'message' => $decision === 'approve' ? 'Request approved — profile updated.' : 'Request rejected.',
    ]);
}
