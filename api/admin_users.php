<?php
/**
 * Admin-panel user actions (session-authenticated; NOT for the mobile app).
 *
 * POST ?action=reveal_password
 *   JSON { admin_id, admin_password, target_user_id, csrf_token }
 *   - admin_id must match the logged-in admin session (session is the source of truth)
 *   - admin_password is re-verified against the logged-in admin's own hash
 *   - requires privilege view_user_passwords (main admin always; sub-admin only if explicitly granted)
 *   - every successful reveal is written to password_view_log
 */
session_start();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// Admin DB config (no wildcard CORS headers, unlike api/db.php).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../admin_priv.php';
require_once __DIR__ . '/../portal_auth.php';
require_once __DIR__ . '/../password_vault.php';

const REVEAL_MAX_FAILED_REAUTH = 5;
const REVEAL_LOCKOUT_SECONDS = 900;

function admin_users_json(int $http, array $body): void
{
    http_response_code($http);
    echo json_encode($body);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_users_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    admin_users_json(401, ['status' => 'error', 'message' => 'Your admin session has expired. Please sign in again.']);
}

$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) {
    admin_users_json(400, ['status' => 'error', 'message' => 'Invalid JSON body']);
}
$action = (string) ($_GET['action'] ?? ($data['action'] ?? ''));

/**
 * Logged-in portal account, re-read from the DB (not trusted from session privileges).
 * @return array{type:string,id:int,password:string,can_view:bool}|null
 */
function admin_users_resolve_actor(mysqli $conn): ?array
{
    if (is_main_admin()) {
        $u = (string) $_SESSION['admin'];
        $stmt = $conn->prepare('SELECT id, password FROM admins WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $u);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? ['type' => 'admin', 'id' => (int) $row['id'], 'password' => (string) $row['password'], 'can_view' => true] : null;
    }
    $sid = (int) ($_SESSION['subadmin_id'] ?? 0);
    if ($sid <= 0) {
        return null;
    }
    $stmt = $conn->prepare("SELECT id, password FROM subadmins WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param('i', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $priv = 'view_user_passwords';
    $stmt = $conn->prepare('SELECT 1 FROM subadmin_privileges WHERE subadmin_id = ? AND privilege = ? LIMIT 1');
    $stmt->bind_param('is', $sid, $priv);
    $stmt->execute();
    $granted = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return ['type' => 'subadmin', 'id' => (int) $row['id'], 'password' => (string) $row['password'], 'can_view' => $granted];
}

if ($action === 'reveal_password') {
    $csrf = (string) ($data['csrf_token'] ?? '');
    if (empty($_SESSION['pw_reveal_csrf']) || !hash_equals((string) $_SESSION['pw_reveal_csrf'], $csrf)) {
        admin_users_json(403, ['status' => 'error', 'message' => 'Security token mismatch. Reload the page and try again.']);
    }

    $lock = $_SESSION['pw_reveal_lock'] ?? ['fails' => 0, 'until' => 0];
    if (($lock['until'] ?? 0) > time()) {
        $mins = (int) ceil(($lock['until'] - time()) / 60);
        admin_users_json(429, ['status' => 'error', 'code' => 'locked', 'message' => "Too many incorrect password attempts. Try again in $mins minute(s)."]);
    }

    $actor = admin_users_resolve_actor($conn);
    if ($actor === null) {
        admin_users_json(401, ['status' => 'error', 'message' => 'Your admin session is no longer valid. Please sign in again.']);
    }
    if (!$actor['can_view']) {
        admin_users_json(403, ['status' => 'error', 'code' => 'forbidden', 'message' => 'You do not have permission to view user passwords.']);
    }

    $bodyAdminId = isset($data['admin_id']) ? (int) $data['admin_id'] : 0;
    if ($bodyAdminId !== $actor['id']) {
        admin_users_json(403, ['status' => 'error', 'message' => 'Admin identity does not match the current session.']);
    }

    $adminPassword = (string) ($data['admin_password'] ?? '');
    if ($adminPassword === '' || !portal_verify_password($actor['password'], $adminPassword)) {
        $fails = (int) ($lock['fails'] ?? 0) + 1;
        $_SESSION['pw_reveal_lock'] = $fails >= REVEAL_MAX_FAILED_REAUTH
            ? ['fails' => 0, 'until' => time() + REVEAL_LOCKOUT_SECONDS]
            : ['fails' => $fails, 'until' => 0];
        $left = REVEAL_MAX_FAILED_REAUTH - $fails;
        admin_users_json(401, [
            'status' => 'error',
            'code' => 'bad_admin_password',
            'message' => $left > 0 ? "Incorrect admin password. $left attempt(s) left." : 'Too many incorrect attempts. Reveal is locked for 15 minutes.',
        ]);
    }
    unset($_SESSION['pw_reveal_lock']);

    $targetId = (int) ($data['target_user_id'] ?? 0);
    if ($targetId <= 0) {
        admin_users_json(400, ['status' => 'error', 'message' => 'target_user_id is required']);
    }
    if (!password_vault_ensure_schema($conn)) {
        admin_users_json(500, ['status' => 'error', 'message' => 'Password storage is not installed. Run uploads/migrations/009_user_password_vault.sql.']);
    }

    $stmt = $conn->prepare('SELECT id, password_encrypted FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$target) {
        admin_users_json(404, ['status' => 'error', 'message' => 'User not found']);
    }
    if ($target['password_encrypted'] === null || $target['password_encrypted'] === '') {
        admin_users_json(404, [
            'status' => 'error',
            'code' => 'not_available',
            'message' => 'Not available — this password was set before password viewing was enabled. Ask the user to reset their password.',
        ]);
    }
    if (!password_vault_is_configured()) {
        admin_users_json(500, ['status' => 'error', 'message' => 'Encryption key is not configured on the server (USER_PASSWORD_ENC_KEY).']);
    }

    $plain = password_vault_decrypt_for_user($targetId, (string) $target['password_encrypted']);
    if ($plain === null) {
        error_log('[admin_users reveal_password] decrypt failed for user ' . $targetId);
        admin_users_json(500, [
            'status' => 'error',
            'code' => 'decrypt_failed',
            'message' => 'Could not decrypt this password (the server key may have changed). Ask the user to reset their password.',
        ]);
    }

    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $stmt = $conn->prepare('INSERT INTO password_view_log (admin_id, admin_type, target_user_id, ip_address) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isis', $actor['id'], $actor['type'], $targetId, $ip);
    $logged = $stmt->execute();
    $stmt->close();
    if (!$logged) {
        error_log('[admin_users reveal_password] audit insert failed: ' . $conn->error);
        admin_users_json(500, ['status' => 'error', 'message' => 'Could not write the audit log, so the password was not revealed.']);
    }

    admin_users_json(200, ['status' => 'success', 'password' => $plain]);
}

admin_users_json(400, ['status' => 'error', 'message' => 'Unknown action']);
