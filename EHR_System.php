<?php
// ── Always output JSON, even on fatal errors ──────────────────────────
set_exception_handler(function($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
    }
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
});
// Convert PHP errors to exceptions so they are caught above
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
}, E_ALL & ~E_NOTICE & ~E_WARNING);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE, PUT');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ─── DB CONFIG ────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');       // change if your MySQL root has a password
define('DB_NAME', 'healthcare_ehr');

// ─── GET CONNECTION (with strict mode OFF) ────────────────────────────
function getConn() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['success' => false,
            'message' => 'DB connection failed: ' . $conn->connect_error]);
        exit;
    }
    $conn->set_charset('utf8mb4');
    $conn->query("SET SESSION sql_mode = ''");   // disable strict mode
    return $conn;
}

// ─── INIT DB (create database + tables if missing) ────────────────────
function initDB() {
    // Connect without selecting a DB first
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) { return; }   // MySQL not running — skip silently

    $conn->query("SET SESSION sql_mode = ''");
    $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    if (!$conn->select_db(DB_NAME)) { $conn->close(); return; }
    $conn->set_charset('utf8mb4');

    // patients — every column nullable to avoid strict-mode DEFAULT issues
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
        UNIQUE KEY uq_patient_id (patient_id)
    ) ENGINE=InnoDB");

    // vitals
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

    // timeline
    $conn->query("CREATE TABLE IF NOT EXISTS timeline (
        id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id  VARCHAR(20)  NOT NULL,
        entry_date  VARCHAR(30)  NOT NULL,
        dot_type    VARCHAR(10)  NULL,
        entry_text  TEXT         NOT NULL,
        created_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // medications
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

    // lab_results
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

    $conn->close();
}

// Run init — any exception returns JSON (caught by set_exception_handler above)
initDB();

// ─── ROUTER ──────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = '';
if (isset($_GET['action']))  $action = trim($_GET['action']);
if (isset($_POST['action'])) $action = trim($_POST['action']);

// Parse JSON body (for POST with Content-Type: application/json)
$body = [];
if (in_array($method, ['POST','PUT','DELETE'])) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $body = $decoded;
    }
    // merge $_POST on top (form-data wins over JSON for duplicate keys)
    foreach ($_POST as $k => $v) $body[$k] = $v;
}

switch ($action) {
    // Patients
    case 'get_patients':    getPatients();        break;
    case 'get_patient':     getPatient();         break;
    case 'add_patient':     addPatient($body);    break;
    case 'update_patient':  updatePatient($body); break;
    case 'delete_patient':  deletePatient($body); break;
    case 'next_patient_id': nextPatientId();      break;
    // Timeline
    case 'get_timeline':    getTimeline();        break;
    case 'add_timeline':    addTimeline($body);   break;
    case 'delete_timeline': deleteTimeline($body);break;
    // Vitals
    case 'get_vitals':      getVitals();          break;
    case 'save_vitals':     saveVitals($body);    break;
    // Medications
    case 'get_meds':        getMeds();            break;
    case 'add_med':         addMed($body);        break;
    case 'delete_med':      deleteMed($body);     break;
    case 'request_refill':  requestRefill($body); break;
    // Labs
    case 'get_labs':        getLabs();            break;
    case 'add_lab':         addLab($body);        break;
    case 'update_lab':      updateLab($body);     break;
    case 'delete_lab':      deleteLab($body);     break;
    // Health check
    case 'ping':
        echo json_encode(['success' => true, 'message' => 'EHR_System.php is reachable',
                          'db' => DB_NAME, 'time' => date('Y-m-d H:i:s')]);
        break;
    default:
        echo json_encode(['success' => false,
            'message' => 'Unknown action: "' . htmlspecialchars($action) . '"']);
}

// ═════════════════════════════════════════════════════════════════════
// PATIENTS
// ═════════════════════════════════════════════════════════════════════
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
    $fn  = trim($b['first_name'] ?? '');
    $ln  = trim($b['last_name']  ?? '');
    $dob = trim($b['dob']        ?? '');
    $gen = trim($b['gender']     ?? '');
    if (!$fn || !$ln || !$dob || !$gen) {
        echo json_encode(['success'=>false,'message'=>'first_name, last_name, dob, gender are required']); return;
    }
    $colors = ['#1a73e8','#00b4a6','#8b5cf6','#ec4899','#f59e0b',
               '#ef4444','#10b981','#06b6d4','#0b3c7a','#6366f1'];
    $conn = getConn();
    // Build next patient_id
    $res  = $conn->query("SELECT patient_id FROM patients ORDER BY id DESC LIMIT 1");
    $row  = $res->fetch_assoc();
    $max  = $row ? (int)substr($row['patient_id'], 2) : 0;
    $pid  = 'P-' . str_pad($max + 1, 5, '0', STR_PAD_LEFT);
    // Pick avatar color
    $cnt  = (int)$conn->query("SELECT COUNT(*) AS c FROM patients")->fetch_assoc()['c'];
    $clr  = $colors[$cnt % count($colors)];

    $bld  = $b['blood_type'] ?? '';
    $ph   = $b['phone']      ?? '';
    $em   = $b['email']      ?? '';
    $ins  = $b['insurance']  ?? '';
    $doc  = $b['physician']  ?? '';
    $alg  = $b['allergies']  ?? '';
    $cnd  = $b['conditions'] ?? '';
    $smk  = $b['smoking']    ?? '';
    $alc  = $b['alcohol']    ?? '';
    $pmh  = $b['pmh']        ?? '';
    $fmh  = $b['fmh']        ?? '';
    $surg = $b['surgical']   ?? '';
    $vac  = $b['vaccines']   ?? '';

    $stmt = $conn->prepare(
        "INSERT INTO patients
         (patient_id,first_name,last_name,dob,gender,blood_type,phone,email,
          insurance,physician,allergies,conditions,smoking,alcohol,
          pmh,fmh,surgical,vaccines,avatar_color,status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active')"
    );
    $stmt->bind_param(
        'sssssssssssssssssss',
        $pid,$fn,$ln,$dob,$gen,$bld,$ph,$em,
        $ins,$doc,$alg,$cnd,$smk,$alc,
        $pmh,$fmh,$surg,$vac,$clr
    );
    if (!$stmt->execute()) {
        $err = $conn->error; $stmt->close(); $conn->close();
        echo json_encode(['success'=>false,'message'=>$err]); return;
    }
    $stmt->close();

    // Auto-add registration timeline entry
    $today = date('d M Y');
    $text  = '<strong>Patient Registration</strong> — New patient registered in the EHR system.';
    $ts = $conn->prepare(
        "INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,'$today','ok',?)"
    );
    $ts->bind_param('ss', $pid, $text); $ts->execute(); $ts->close();

    $conn->close();
    echo json_encode(['success'=>true,'patient_id'=>$pid,
                      'message'=>"Patient $fn $ln registered ($pid)"]);
}

function updatePatient($b) {
    $pid = $b['patient_id'] ?? '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $allowed = ['first_name','last_name','phone','email','insurance','physician',
                'blood_type','allergies','conditions','smoking','alcohol',
                'pmh','fmh','surgical','vaccines','last_visit','next_appt','status'];
    $sets=[]; $vals=[]; $types='';
    foreach ($allowed as $f) {
        if (array_key_exists($f, $b)) { $sets[] = "$f=?"; $vals[] = $b[$f]; $types .= 's'; }
    }
    if (!$sets) { echo json_encode(['success'=>false,'message'=>'Nothing to update']); return; }
    $vals[] = $pid; $types .= 's';
    $conn = getConn();
    $stmt = $conn->prepare("UPDATE patients SET ".implode(',',$sets)." WHERE patient_id=?");
    $stmt->bind_param($types, ...$vals);
    $ok = $stmt->execute();
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>$ok]);
}

function deletePatient($b) {
    $pid = $b['patient_id'] ?? '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("DELETE FROM patients WHERE patient_id=?");
    $stmt->bind_param('s',$pid); $ok=$stmt->execute();
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>$ok]);
}

// ═════════════════════════════════════════════════════════════════════
// TIMELINE
// ═════════════════════════════════════════════════════════════════════
function getTimeline() {
    $pid = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("SELECT * FROM timeline WHERE patient_id=? ORDER BY id DESC");
    $stmt->bind_param('s',$pid); $stmt->execute();
    $rows = []; $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>true,'data'=>$rows]);
}

function addTimeline($b) {
    $pid  = $b['patient_id']  ?? '';
    $date = $b['entry_date']  ?? date('d M Y');
    $dot  = $b['dot_type']    ?? '';
    $text = trim($b['entry_text'] ?? '');
    if (!$pid || !$text) { echo json_encode(['success'=>false,'message'=>'patient_id and entry_text required']); return; }
    if (!in_array($dot, ['','ok','warn','red'])) $dot = '';
    $conn = getConn();
    $stmt = $conn->prepare("INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,?,?,?)");
    $stmt->bind_param('ssss',$pid,$date,$dot,$text);
    $ok=$stmt->execute(); $id=$conn->insert_id;
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>$ok,'id'=>$id]);
}

function deleteTimeline($b) {
    $id = (int)($b['id']??0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("DELETE FROM timeline WHERE id=?");
    $stmt->bind_param('i',$id); $ok=$stmt->execute();
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>$ok]);
}

// ═════════════════════════════════════════════════════════════════════
// VITALS
// ═════════════════════════════════════════════════════════════════════
function getVitals() {
    $pid = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn = getConn();
    $stmt = $conn->prepare("SELECT * FROM vitals WHERE patient_id=? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s',$pid); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close(); $conn->close();
    echo json_encode(['success'=>true,'data'=>$row]);
}

function saveVitals($b) {
    $pid=$b['patient_id']??'';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
    $conn=getConn();
    $ra=$b['recorded_at']??date('d M Y, h:i A');
    $nu=$b['nurse']??'—';$bp=$b['bp']??'—';$bs=$b['bp_status']??'ok';
    $hr=$b['hr']??'—';$tp=$b['temp']??'—';$sp=$b['spo2']??'—';
    $rr=$b['rr']??'—';$bm=$b['bmi']??'—';$nt=$b['note']??'';
    $stmt=$conn->prepare("INSERT INTO vitals (patient_id,recorded_at,nurse,bp,bp_status,hr,temp,spo2,rr,bmi,note) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssssssss',$pid,$ra,$nu,$bp,$bs,$hr,$tp,$sp,$rr,$bm,$nt);
    $ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}

// ═════════════════════════════════════════════════════════════════════
// MEDICATIONS
// ═════════════════════════════════════════════════════════════════════
function getMeds() {
    $pid=isset($_GET['patient_id'])?trim($_GET['patient_id']):'';
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'patient_id required']); return; }
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
    if (!$pid||!$nm||!$dose||!$by){echo json_encode(['success'=>false,'message'=>'Required fields missing']);return;}
    $conn=getConn();
    $sd=$b['start_date']??date('d M Y');$st=$b['status']??'active';
    $stmt=$conn->prepare("INSERT INTO medications (patient_id,med_name,dose,prescribed_by,start_date,status) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('ssssss',$pid,$nm,$dose,$by,$sd,$st);
    $ok=$stmt->execute();$nid=$conn->insert_id;
    $stmt->close();
    // Log to timeline
    $tl=$conn->prepare("INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,?,'ok',?)");
    $txt="<strong>E-Prescription: $nm</strong> — $dose prescribed by $by.";
    $tl->bind_param('sss',$pid,$sd,$txt);$tl->execute();$tl->close();
    $conn->close();
    echo json_encode(['success'=>$ok,'id'=>$nid]);
}

function deleteMed($b) {
    $id=(int)($b['id']??0);
    if (!$id){echo json_encode(['success'=>false,'message'=>'id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("DELETE FROM medications WHERE id=?");
    $stmt->bind_param('i',$id);$ok=$stmt->execute();
    $stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}

function requestRefill($b) {
    $pid=trim($b['patient_id']??'');$nm=trim($b['med_name']??'');
    $ph=trim($b['pharmacy']??'HealthCare Hub Pharmacy');$qty=trim($b['qty']??'30 days');
    if (!$pid||!$nm){echo json_encode(['success'=>false,'message'=>'patient_id and med_name required']);return;}
    $conn=getConn();$today=date('d M Y');
    $txt="<strong>Refill Request: $nm</strong> — $qty supply sent to $ph.";
    $stmt=$conn->prepare("INSERT INTO timeline (patient_id,entry_date,dot_type,entry_text) VALUES (?,'$today','ok',?)");
    $stmt->bind_param('ss',$pid,$txt);$ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}

// ═════════════════════════════════════════════════════════════════════
// LAB RESULTS
// ═════════════════════════════════════════════════════════════════════
function getLabs() {
    $pid=isset($_GET['patient_id'])?trim($_GET['patient_id']):'';
    if (!$pid){echo json_encode(['success'=>false,'message'=>'patient_id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("SELECT * FROM lab_results WHERE patient_id=? ORDER BY id DESC");
    $stmt->bind_param('s',$pid);$stmt->execute();
    $rows=[];$res=$stmt->get_result();
    while($r=$res->fetch_assoc())$rows[]=$r;
    $stmt->close();$conn->close();
    echo json_encode(['success'=>true,'data'=>$rows]);
}

function addLab($b) {
    $pid=trim($b['patient_id']??'');$nm=trim($b['test_name']??'');
    $val=trim($b['test_value']??'');$ref=trim($b['ref_range']??'');$lbl=trim($b['label']??'');
    if (!$pid||!$nm||!$val||!$ref||!$lbl){echo json_encode(['success'=>false,'message'=>'Required fields missing']);return;}
    $conn=getConn();
    $pd=$b['panel_date']??date('d M Y');
    $pct=min(100,max(0,(int)($b['pct']??50)));
    $cls=in_array($b['cls']??'',['ok','med','hi'])?$b['cls']:'ok';
    $clrMap=['ok'=>'success','med'=>'accent2','hi'=>'danger'];$clr=$clrMap[$cls];
    $stmt=$conn->prepare("INSERT INTO lab_results (patient_id,panel_date,test_name,test_value,ref_range,pct,cls,label,color_type) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssisss',$pid,$pd,$nm,$val,$ref,$pct,$cls,$lbl,$clr);
    $ok=$stmt->execute();$nid=$conn->insert_id;$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok,'id'=>$nid]);
}

function updateLab($b) {
    $id=(int)($b['id']??0);
    if (!$id){echo json_encode(['success'=>false,'message'=>'id required']);return;}
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
    $id=(int)($b['id']??0);
    if (!$id){echo json_encode(['success'=>false,'message'=>'id required']);return;}
    $conn=getConn();
    $stmt=$conn->prepare("DELETE FROM lab_results WHERE id=?");
    $stmt->bind_param('i',$id);$ok=$stmt->execute();$stmt->close();$conn->close();
    echo json_encode(['success'=>$ok]);
}
