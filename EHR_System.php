<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE, PUT');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ─── DB CONFIG ───────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'healthcare_ehr');

function getConn() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'DB connection failed: ' . $conn->connect_error]);
        exit;
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

// ─── INIT DB ─────────────────────────────────────────────────────────
function initDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) return;
    $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->select_db(DB_NAME);
    $conn->set_charset('utf8mb4');

    // Patients table
    $conn->query("CREATE TABLE IF NOT EXISTS patients (
        id            INT PRIMARY KEY AUTO_INCREMENT,
        patient_id    VARCHAR(20)  UNIQUE NOT NULL,
        first_name    VARCHAR(80)  NOT NULL,
        last_name     VARCHAR(80)  NOT NULL,
        dob           DATE         NOT NULL,
        gender        ENUM('Male','Female','Other') NOT NULL,
        blood_type    VARCHAR(5)   DEFAULT 'Unknown',
        phone         VARCHAR(30),
        email         VARCHAR(120),
        insurance     VARCHAR(100),
        physician     VARCHAR(100),
        allergies     TEXT,
        conditions    TEXT,
        smoking       VARCHAR(60)  DEFAULT 'Not recorded',
        alcohol       VARCHAR(60)  DEFAULT 'Not recorded',
        pmh           TEXT,
        fmh           TEXT,
        surgical      TEXT,
        vaccines      TEXT,
        avatar_color  VARCHAR(20)  DEFAULT '#1a73e8',
        status        ENUM('active','inactive') DEFAULT 'active',
        last_visit    VARCHAR(60)  DEFAULT 'Not yet visited',
        next_appt     VARCHAR(100) DEFAULT 'None scheduled',
        created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )");

    // Vitals table
    $conn->query("CREATE TABLE IF NOT EXISTS vitals (
        id         INT PRIMARY KEY AUTO_INCREMENT,
        patient_id VARCHAR(20) NOT NULL,
        recorded_at VARCHAR(60),
        nurse      VARCHAR(100),
        bp         VARCHAR(20) DEFAULT '—',
        bp_status  ENUM('ok','warn','red') DEFAULT 'ok',
        hr         VARCHAR(10) DEFAULT '—',
        temp       VARCHAR(10) DEFAULT '—',
        spo2       VARCHAR(10) DEFAULT '—',
        rr         VARCHAR(10) DEFAULT '—',
        bmi        VARCHAR(10) DEFAULT '—',
        note       TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
    )");

    // Timeline / Medical History entries
    $conn->query("CREATE TABLE IF NOT EXISTS timeline (
        id          INT PRIMARY KEY AUTO_INCREMENT,
        patient_id  VARCHAR(20) NOT NULL,
        entry_date  VARCHAR(30) NOT NULL,
        dot_type    ENUM('','ok','warn','red') DEFAULT '',
        entry_text  TEXT        NOT NULL,
        created_at  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
    )");

    // Medications
    $conn->query("CREATE TABLE IF NOT EXISTS medications (
        id          INT PRIMARY KEY AUTO_INCREMENT,
        patient_id  VARCHAR(20) NOT NULL,
        med_name    VARCHAR(150) NOT NULL,
        dose        VARCHAR(200),
        prescribed_by VARCHAR(100),
        start_date  VARCHAR(30),
        status      ENUM('active','inactive') DEFAULT 'active',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
    )");

    // Lab Results
    $conn->query("CREATE TABLE IF NOT EXISTS lab_results (
        id          INT PRIMARY KEY AUTO_INCREMENT,
        patient_id  VARCHAR(20) NOT NULL,
        panel_date  VARCHAR(30),
        test_name   VARCHAR(150) NOT NULL,
        test_value  VARCHAR(80),
        ref_range   VARCHAR(80),
        pct         INT DEFAULT 50,
        cls         ENUM('ok','med','hi') DEFAULT 'ok',
        label       VARCHAR(80),
        color_type  ENUM('success','accent2','danger') DEFAULT 'success',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
    )");

    $conn->close();
}

initDB();

// ─── ROUTER ──────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Parse JSON body for PUT/DELETE
$body = [];
if (in_array($method, ['PUT','DELETE','POST'])) {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
    // Merge with $_POST
    $body = array_merge($_POST, $body);
}

switch ($action) {
    // ── PATIENTS ──────────────────────────────────────────────────
    case 'get_patients':       getPatients();       break;
    case 'get_patient':        getPatient();        break;
    case 'add_patient':        addPatient($body);   break;
    case 'update_patient':     updatePatient($body);break;
    case 'delete_patient':     deletePatient($body);break;
    case 'next_patient_id':    nextPatientId();     break;

    // ── TIMELINE ──────────────────────────────────────────────────
    case 'get_timeline':       getTimeline();       break;
    case 'add_timeline':       addTimeline($body);  break;
    case 'delete_timeline':    deleteTimeline($body);break;

    // ── VITALS ────────────────────────────────────────────────────
    case 'get_vitals':         getVitals();         break;
    case 'save_vitals':        saveVitals($body);   break;

    // ── MEDICATIONS ───────────────────────────────────────────────
    case 'get_meds':           getMeds();           break;
    case 'add_med':            addMed($body);       break;
    case 'delete_med':         deleteMed($body);    break;
    case 'request_refill':     requestRefill($body);break;

    // ── LABS ──────────────────────────────────────────────────────
    case 'get_labs':           getLabs();           break;
    case 'add_lab':            addLab($body);       break;
    case 'update_lab':         updateLab($body);    break;
    case 'delete_lab':         deleteLab($body);    break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
}

// ═════════════════════════════════════════════════════════════════════
// PATIENTS
// ═════════════════════════════════════════════════════════════════════

function getPatients() {
    $conn = getConn();
    $q = $_GET['q'] ?? '';
    if ($q) {
        $like = '%' . $conn->real_escape_string($q) . '%';
        $res = $conn->query("SELECT * FROM patients WHERE
            first_name LIKE '$like' OR last_name LIKE '$like' OR
            patient_id LIKE '$like' OR conditions LIKE '$like' OR
            physician LIKE '$like'
            ORDER BY created_at DESC");
    } else {
        $res = $conn->query("SELECT * FROM patients ORDER BY created_at DESC");
    }
    $patients = [];
    while ($row = $res->fetch_assoc()) $patients[] = formatPatient($row);
    echo json_encode(['success' => true, 'data' => $patients]);
    $conn->close();
}

function getPatient() {
    $pid = $_GET['patient_id'] ?? '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param('s', $pid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close(); $conn->close();
    if (!$row) { echo json_encode(['success'=>false,'message'=>'Patient not found']); return; }
    echo json_encode(['success'=>true,'data'=>formatPatient($row)]);
}

function formatPatient($row) {
    $dob = $row['dob'] ? date('d M Y', strtotime($row['dob'])) : '—';
    $dobDate = $row['dob'] ? new DateTime($row['dob']) : null;
    $age = $dobDate ? (int)$dobDate->diff(new DateTime())->y : 0;
    $allergies = $row['allergies'] ? array_filter(array_map('trim', explode(',', $row['allergies']))) : [];
    return [
        'id'           => $row['patient_id'],
        'name'         => $row['first_name'] . ' ' . $row['last_name'],
        'first_name'   => $row['first_name'],
        'last_name'    => $row['last_name'],
        'gender'       => $row['gender'],
        'age'          => $age,
        'dob'          => $dob,
        'dob_raw'      => $row['dob'],
        'blood'        => $row['blood_type'] ?: 'Unknown',
        'phone'        => $row['phone'] ?: '—',
        'email'        => $row['email'] ?: '—',
        'insurance'    => $row['insurance'] ?: '—',
        'physician'    => $row['physician'] ?: '—',
        'allergies'    => array_values($allergies),
        'conditions'   => $row['conditions'] ?: 'None recorded',
        'smoking'      => $row['smoking'] ?: 'Not recorded',
        'alcohol'      => $row['alcohol'] ?: 'Not recorded',
        'pmh'          => $row['pmh'] ?: '',
        'fmh'          => $row['fmh'] ?: '',
        'surgical'     => $row['surgical'] ?: '',
        'vaccines'     => $row['vaccines'] ?: '',
        'color'        => $row['avatar_color'] ?: '#1a73e8',
        'status'       => $row['status'] ?: 'active',
        'lastVisit'    => $row['last_visit'] ?: 'Not yet visited',
        'nextAppt'     => $row['next_appt'] ?: 'None scheduled',
    ];
}

function nextPatientId() {
    $conn = getConn();
    $res = $conn->query("SELECT patient_id FROM patients ORDER BY id DESC LIMIT 1");
    $row = $res->fetch_assoc();
    $max = $row ? (int)substr($row['patient_id'], 2) : 0;
    $next = 'P-' . str_pad($max + 1, 5, '0', STR_PAD_LEFT);
    echo json_encode(['success' => true, 'patient_id' => $next]);
    $conn->close();
}

function addPatient($body) {
    $required = ['first_name','last_name','dob','gender'];
    foreach ($required as $f) {
        if (empty($body[$f])) {
            echo json_encode(['success'=>false,'message'=>"Field '$f' is required"]); return;
        }
    }
    $colors = ['#1a73e8','#00b4a6','#8b5cf6','#ec4899','#f59e0b','#ef4444','#10b981','#06b6d4','#0b3c7a','#6366f1'];
    $conn = getConn();

    // Next ID
    $res = $conn->query("SELECT patient_id FROM patients ORDER BY id DESC LIMIT 1");
    $row = $res->fetch_assoc();
    $max = $row ? (int)substr($row['patient_id'], 2) : 0;
    $pid = 'P-' . str_pad($max + 1, 5, '0', STR_PAD_LEFT);

    $cntRes = $conn->query("SELECT COUNT(*) as c FROM patients");
    $cnt = $cntRes->fetch_assoc()['c'];
    $color = $colors[$cnt % count($colors)];

    $stmt = $conn->prepare("INSERT INTO patients
        (patient_id,first_name,last_name,dob,gender,blood_type,phone,email,insurance,physician,allergies,conditions,smoking,alcohol,pmh,fmh,surgical,vaccines,avatar_color)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $fn   = trim($body['first_name']);
    $ln   = trim($body['last_name']);
    $dob  = $body['dob'];
    $gen  = $body['gender'];
    $bld  = $body['blood_type'] ?? 'Unknown';
    $ph   = $body['phone'] ?? '';
    $em   = $body['email'] ?? '';
    $ins  = $body['insurance'] ?? '';
    $doc  = $body['physician'] ?? '';
    $alg  = $body['allergies'] ?? '';
    $cnd  = $body['conditions'] ?? '';
    $smk  = $body['smoking'] ?? 'Not recorded';
    $alc  = $body['alcohol'] ?? 'Not recorded';
    $pmh  = $body['pmh'] ?? '';
    $fmh  = $body['fmh'] ?? '';
    $surg = $body['surgical'] ?? '';
    $vac  = $body['vaccines'] ?? '';
    $stmt->bind_param('sssssssssssssssssss', $pid,$fn,$ln,$dob,$gen,$bld,$ph,$em,$ins,$doc,$alg,$cnd,$smk,$alc,$pmh,$fmh,$surg,$vac,$color);
    if (!$stmt->execute()) {
        echo json_encode(['success'=>false,'message'=>$conn->error]); $stmt->close(); $conn->close(); return;
    }
    $stmt->close();

    // Add registration timeline entry
    $today = date('d M Y');
    $text  = '<strong>Patient Registration</strong> — New patient registered in the EHR system.';
    $ts    = $conn->prepare("INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,?,'ok',?)");
    $ts->bind_param('sss', $pid, $today, $text);
    $ts->execute(); $ts->close();

    $conn->close();
    echo json_encode(['success'=>true,'patient_id'=>$pid,'message'=>"Patient $fn $ln registered ($pid)"]);
}

function updatePatient($body) {
    $pid = $body['patient_id'] ?? '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $fields = ['last_visit'=>'last_visit','next_appt'=>'next_appt'];
    // Build dynamic update
    $allowed = ['first_name','last_name','phone','email','insurance','physician','blood_type','allergies','conditions','smoking','alcohol','pmh','fmh','surgical','vaccines','last_visit','next_appt','status'];
    $sets = []; $params = []; $types = '';
    foreach ($allowed as $f) {
        if (isset($body[$f])) { $sets[] = "$f = ?"; $params[] = $body[$f]; $types .= 's'; }
    }
    if (empty($sets)) { echo json_encode(['success'=>false,'message'=>'Nothing to update']); $conn->close(); return; }
    $params[] = $pid; $types .= 's';
    $stmt = $conn->prepare("UPDATE patients SET " . implode(', ', $sets) . " WHERE patient_id = ?");
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>$ok]);
}

function deletePatient($body) {
    $pid = $body['patient_id'] ?? '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("DELETE FROM patients WHERE patient_id = ?");
    $stmt->bind_param('s', $pid);
    $ok = $stmt->execute();
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>$ok]);
}

// ═════════════════════════════════════════════════════════════════════
// TIMELINE
// ═════════════════════════════════════════════════════════════════════

function getTimeline() {
    $pid = $_GET['patient_id'] ?? '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("SELECT * FROM timeline WHERE patient_id = ? ORDER BY id DESC");
    $stmt->bind_param('s', $pid);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) $items[] = $row;
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>true,'data'=>$items]);
}

function addTimeline($body) {
    $pid  = $body['patient_id'] ?? '';
    $date = $body['entry_date'] ?? date('d M Y');
    $dot  = $body['dot_type'] ?? '';
    $text = trim($body['entry_text'] ?? '');
    if (!$pid || !$text) { echo json_encode(['success'=>false,'message'=>'patient_id and entry_text required']); return; }
    $validDots = ['','ok','warn','red'];
    if (!in_array($dot, $validDots)) $dot = '';
    $conn = getConn();
    $stmt = $conn->prepare("INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,?,?,?)");
    $stmt->bind_param('ssss', $pid, $date, $dot, $text);
    $ok = $stmt->execute();
    $newId = $conn->insert_id;
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>$ok,'id'=>$newId]);
}

function deleteTimeline($body) {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("DELETE FROM timeline WHERE id = ?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>$ok]);
}

// ═════════════════════════════════════════════════════════════════════
// VITALS
// ═════════════════════════════════════════════════════════════════════

function getVitals() {
    $pid = $_GET['patient_id'] ?? '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("SELECT * FROM vitals WHERE patient_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $pid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>true,'data'=>$row]);
}

function saveVitals($body) {
    $pid = $body['patient_id'] ?? '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("INSERT INTO vitals (patient_id,recorded_at,nurse,bp,bp_status,hr,temp,spo2,rr,bmi,note) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $ra=$body['recorded_at']??date('d M Y, h:i A');
    $nu=$body['nurse']??'—';$bp=$body['bp']??'—';$bs=$body['bp_status']??'ok';
    $hr=$body['hr']??'—';$tp=$body['temp']??'—';$sp=$body['spo2']??'—';
    $rr=$body['rr']??'—';$bm=$body['bmi']??'—';$nt=$body['note']??'';
    $stmt->bind_param('sssssssssss',$pid,$ra,$nu,$bp,$bs,$hr,$tp,$sp,$rr,$bm,$nt);
    $ok=$stmt->execute(); $stmt->close(); $conn->close();
    echo json_encode(['success'=>$ok]);
}

// ═════════════════════════════════════════════════════════════════════
// MEDICATIONS
// ═════════════════════════════════════════════════════════════════════

function getMeds() {
    $pid = $_GET['patient_id'] ?? '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("SELECT * FROM medications WHERE patient_id = ? ORDER BY id DESC");
    $stmt->bind_param('s', $pid);
    $stmt->execute();
    $res = $stmt->get_result();
    $meds = [];
    while ($row = $res->fetch_assoc()) $meds[] = $row;
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>true,'data'=>$meds]);
}

function addMed($body) {
    $pid  = $body['patient_id'] ?? '';
    $name = trim($body['med_name'] ?? '');
    $dose = trim($body['dose'] ?? '');
    $by   = trim($body['prescribed_by'] ?? '');
    if (!$pid || !$name || !$dose || !$by) {
        echo json_encode(['success'=>false,'message'=>'patient_id, med_name, dose, prescribed_by required']); return;
    }
    $conn = getConn();
    $sd   = $body['start_date'] ?? date('d M Y');
    $st   = $body['status'] ?? 'active';
    $stmt = $conn->prepare("INSERT INTO medications (patient_id,med_name,dose,prescribed_by,start_date,status) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('ssssss',$pid,$name,$dose,$by,$sd,$st);
    $ok=$stmt->execute();$newId=$conn->insert_id;
    $stmt->close();

    // Log to timeline
    $tl = $conn->prepare("INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,?,'ok',?)");
    $tlText = "<strong>E-Prescription: $name</strong> — $dose prescribed by $by.";
    $tl->bind_param('sss',$pid,$sd,$tlText);
    $tl->execute();$tl->close();
    $conn->close();
    echo json_encode(['success'=>$ok,'id'=>$newId]);
}

function deleteMed($body) {
    $id=(int)($body['id']??0);
    if (!$id){echo json_encode(['success'=>false,'message'=>'id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("DELETE FROM medications WHERE id=?");
    $stmt->bind_param('i',$id);$ok=$stmt->execute();
    $stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}

function requestRefill($body) {
    $pid  = $body['patient_id'] ?? '';
    $name = $body['med_name'] ?? '';
    $pharm= $body['pharmacy'] ?? 'HealthCare Hub Pharmacy';
    $qty  = $body['qty'] ?? '30 days';
    if (!$pid || !$name) { echo json_encode(['success'=>false,'message'=>'patient_id and med_name required']); return; }
    $conn = getConn();
    $today = date('d M Y');
    $tlText = "<strong>Refill Request: $name</strong> — $qty supply sent to $pharm.";
    $stmt = $conn->prepare("INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,?,'ok',?)");
    $stmt->bind_param('sss',$pid,$today,$tlText);
    $ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}

// ═════════════════════════════════════════════════════════════════════
// LAB RESULTS
// ═════════════════════════════════════════════════════════════════════

function getLabs() {
    $pid = $_GET['patient_id'] ?? '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("SELECT * FROM lab_results WHERE patient_id = ? ORDER BY id DESC");
    $stmt->bind_param('s', $pid);
    $stmt->execute();
    $res = $stmt->get_result();
    $labs = [];
    while ($row = $res->fetch_assoc()) $labs[] = $row;
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>true,'data'=>$labs]);
}

function addLab($body) {
    $pid  = $body['patient_id'] ?? '';
    $name = trim($body['test_name'] ?? '');
    $val  = trim($body['test_value'] ?? '');
    $ref  = trim($body['ref_range'] ?? '');
    $lbl  = trim($body['label'] ?? '');
    if (!$pid || !$name || !$val || !$ref || !$lbl) {
        echo json_encode(['success'=>false,'message'=>'Required fields missing']); return;
    }
    $conn = getConn();
    $pd   = $body['panel_date'] ?? date('d M Y');
    $pct  = min(100,max(0,(int)($body['pct']??50)));
    $cls  = in_array($body['cls']??'',['ok','med','hi']) ? $body['cls'] : 'ok';
    $clrMap = ['ok'=>'success','med'=>'accent2','hi'=>'danger'];
    $clr  = $clrMap[$cls];
    $stmt = $conn->prepare("INSERT INTO lab_results (patient_id,panel_date,test_name,test_value,ref_range,pct,cls,label,color_type) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssisss',$pid,$pd,$name,$val,$ref,$pct,$cls,$lbl,$clr);
    $ok=$stmt->execute();$newId=$conn->insert_id;$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok,'id'=>$newId]);
}

function updateLab($body) {
    $id=(int)($body['id']??0);
    if (!$id){echo json_encode(['success'=>false,'message'=>'id required']);return;}
    $conn=getConn();
    $name=trim($body['test_name']??'');$val=trim($body['test_value']??'');
    $ref=trim($body['ref_range']??'');$lbl=trim($body['label']??'');
    $pct=min(100,max(0,(int)($body['pct']??50)));
    $cls=in_array($body['cls']??'',['ok','med','hi'])?$body['cls']:'ok';
    $clrMap=['ok'=>'success','med'=>'accent2','hi'=>'danger'];$clr=$clrMap[$cls];
    $pd=$body['panel_date']??date('d M Y');
    $stmt=$conn->prepare("UPDATE lab_results SET test_name=?,test_value=?,ref_range=?,pct=?,cls=?,label=?,color_type=?,panel_date=? WHERE id=?");
    $stmt->bind_param('sssissssi',$name,$val,$ref,$pct,$cls,$lbl,$clr,$pd,$id);
    $ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}

function deleteLab($body) {
    $id=(int)($body['id']??0);
    if (!$id){echo json_encode(['success'=>false,'message'=>'id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("DELETE FROM lab_results WHERE id=?");
    $stmt->bind_param('i',$id);$ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}
?>
