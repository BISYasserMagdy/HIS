<?php
// ════════════════════════════════════════════════════════════════════════════
// auth.php  —  Med-Alex HIS  |  Session-based RBAC helpers
//
// Place this file in the same directory as EHR_System.php (the "Back End/"
// folder). It is loaded via require_once at the top of EHR_System.php.
//
// Provides:
//   - session_start() (guarded)
//   - loginUser($body)   → action=login   (POST {username, password})
//   - logoutUser()       → action=logout
//   - requireRole($roles) → call inside any handler that needs RBAC
// ════════════════════════════════════════════════════════════════════════════

if (session_status() === PHP_SESSION_NONE) {
    // Pin the session cookie to the server root so it is sent to ALL PHP
    // scripts regardless of which subdirectory they live in.
    // Without this, EHR_System.php and Online_Consultation_API.php each get
    // a different cookie path and can never see the same session.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',          // same host
        'secure'   => false,       // set true if serving over HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ─────────────────────────────────────────────────────────────────────────────
// requireRole($roles)
//   Ensures the current session belongs to a logged-in user whose role is in
//   $roles. If not, sends a 401 JSON response with status "Unauthorized access"
//   and terminates the script immediately.
// ─────────────────────────────────────────────────────────────────────────────
function requireRole(array $roles) {
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'status'  => 'Unauthorized access',
            'message' => 'You must be logged in to perform this action.'
        ]);
        exit;
    }

    if (!in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'status'  => 'Unauthorized access',
            'message' => 'Your role (' . $_SESSION['role'] . ') does not have permission for this action.'
        ]);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// loginUser($body)
//   Verifies username/password against the `users` table (bcrypt hash via
//   password_verify). On success, stores user_id/username/role/full_name in
//   the PHP session and returns { success: true, name, role }.
// ─────────────────────────────────────────────────────────────────────────────
function loginUser($body) {
    $username = trim($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');

    if ($username === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
        return;
    }

    $conn = getConn();

    $stmt = $conn->prepare(
        "SELECT id, username, password_hash, role, full_name, hospital, is_active
         FROM users WHERE username = ? LIMIT 1"
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        return;
    }

    if ((int)$user['is_active'] !== 1) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'This account has been deactivated.']);
        return;
    }

    // ── Regenerate session ID on privilege change to prevent session fixation ──
    session_regenerate_id(true);

    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['hospital']  = $user['hospital'];

    // ── Audit log: record login event ──
    $token = hash('sha256', session_id());
    $ip    = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua    = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $audit = $conn->prepare(
        "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, action)
         VALUES (?, ?, ?, ?, 'login')"
    );
    $audit->bind_param('isss', $user['id'], $token, $ip, $ua);
    $audit->execute();
    $audit->close();
    $conn->close();

    echo json_encode([
        'success'  => true,
        'name'     => $user['full_name'] ?: $user['username'],
        'role'     => $user['role'],
        'hospital' => $user['hospital'],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// logoutUser()
//   Records a logout audit event (if logged in) and destroys the session.
// ─────────────────────────────────────────────────────────────────────────────
function logoutUser() {
    if (!empty($_SESSION['user_id'])) {
        $conn  = getConn();
        $token = hash('sha256', session_id());
        $audit = $conn->prepare(
            "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, action)
             VALUES (?, ?, ?, ?, 'logout')"
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $audit->bind_param('isss', $_SESSION['user_id'], $token, $ip, $ua);
        $audit->execute();
        $audit->close();
        $conn->close();
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie('PHPSESSID', '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();

    echo json_encode(['success' => true, 'message' => 'Logged out.']);
}
