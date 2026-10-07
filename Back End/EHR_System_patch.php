<?php
/**
 * EHR_System_patch.php
 * ════════════════════════════════════════════════════════════════════════
 * Patches for Admin Dashboard — Reset Password (with email) + Resend Credentials
 *
 * STEP 1 — In your switch($action) block in EHR_System.php, add:
 *
 *     case 'reset_password':      handleResetPassword();      break;
 *     case 'resend_credentials':  handleResendCredentials();  break;
 *
 * NOTE: If reset_password already exists in your switch, REPLACE its
 * handler call with handleResetPassword() — the new version sends an
 * email after saving the hash.
 *
 * STEP 2 — Paste the functions below anywhere in EHR_System.php.
 * ════════════════════════════════════════════════════════════════════════
 */

// ── Helper ─────────────────────────────────────────────────────────────────
function sendCredentialsEmail(string $toEmail, string $fullName, string $username, string $plainPassword, string $hospital, string $role): bool {
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/mailer_config.php';

    $roleIcon = $role === 'doctor' ? '🩺' : '💊';
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
  ⚠️ <strong>Security reminder:</strong> Please change your password after your first login.
  Do not share these credentials with anyone.
</div>
";

    try {
        $mail = createMailer();
        $mail->addAddress($toEmail);
        $mail->Subject = "🏥 Your Pharos HIS Login Credentials — {$hospital}";
        $mail->Body    = emailWrapper("Your Updated Login Credentials", $emailBody);
        $mail->AltBody = "Hello {$fullName},\n\nYour credentials have been reset by an admin at {$hospital}.\n\n"
                       . "Username: {$username}\nNew Password: {$plainPassword}\n\n"
                       . "Please change your password after first login.";
        $mail->send();
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

// ══════════════════════════════════════════════════════════════════════════
// ACTION: reset_password
// POST body: { id: <user_id>, new_password: <plain_text> }
// Saves the hashed password, then emails the new credentials to the
// staff member directly. The admin never sees the password.
// ══════════════════════════════════════════════════════════════════════════
function handleResetPassword(): void {
    requireRole(['admin']);

    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $id          = (int)($body['id'] ?? 0);
    $newPassword = trim($body['new_password'] ?? '');

    if (!$id || strlen($newPassword) < 8) {
        echo json_encode(['success' => false, 'message' => 'id and new_password (min 8 chars) required']);
        return;
    }

    $conn = getConn();
    $stmt = $conn->prepare(
        "SELECT id, username, email, full_name, role, hospital FROM users
         WHERE id = ? AND role IN ('doctor','nurse','manager') LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Staff member not found.']);
        return;
    }

    // Hospital ownership check
    if ($user['hospital'] !== ($_SESSION['hospital'] ?? '')) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Access denied — different hospital.']);
        return;
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $upd  = $conn->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
    $upd->bind_param('si', $hash, $id);
    $upd->execute();
    $upd->close();
    $conn->close();

    // Email new credentials directly to the staff member
    $sent = sendCredentialsEmail(
        $user['email'],
        $user['full_name'] ?: $user['username'],
        $user['username'],
        $newPassword,
        $user['hospital'],
        $user['role']
    );

    echo json_encode([
        'success' => true,
        'message' => $sent
            ? "Password reset and emailed to {$user['email']}."
            : "Password reset successfully, but email could not be sent to {$user['email']}.",
        'email_sent' => $sent,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════
// ACTION: resend_credentials
// POST body: { id: <user_id> }
// Generates a NEW password, saves the hash, emails username + password
// to the staff member. Admin never sees the password.
// ══════════════════════════════════════════════════════════════════════════
function handleResendCredentials(): void {
    requireRole(['admin']);

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'User ID is required.']);
        return;
    }

    $conn = getConn();
    $stmt = $conn->prepare(
        "SELECT id, username, email, full_name, role, hospital FROM users
         WHERE id = ? AND role IN ('doctor','nurse','manager') LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Staff member not found.']);
        return;
    }

    if ($user['hospital'] !== ($_SESSION['hospital'] ?? '')) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Access denied — different hospital.']);
        return;
    }

    // Generate new password
    $chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$';
    $newPass  = '';
    for ($i = 0; $i < 12; $i++) $newPass .= $chars[random_int(0, strlen($chars) - 1)];

    $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
    $upd  = $conn->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
    $upd->bind_param('si', $hash, $id);
    $upd->execute();
    $upd->close();
    $conn->close();

    $sent = sendCredentialsEmail(
        $user['email'],
        $user['full_name'] ?: $user['username'],
        $user['username'],
        $newPass,
        $user['hospital'],
        $user['role']
    );

    echo json_encode([
        'success'    => $sent,
        'message'    => $sent
            ? "Credentials emailed to {$user['email']} successfully."
            : "Password was reset but email failed for {$user['email']}.",
        'email_sent' => $sent,
    ]);
}
