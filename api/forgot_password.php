<?php
/**
 * Password reset — 3-step OTP flow.
 *
 * POST ?action=request_otp  { identifier }
 * POST ?action=verify_otp   { identifier, otp }
 * POST ?action=reset        { reset_token, password, password_confirm }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include __DIR__ . '/db.php';
require_once __DIR__ . '/sms_helper.php';
require_once __DIR__ . '/background_jobs_helper.php';
require_once __DIR__ . '/smtp_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
    exit();
}

$action = $_GET['action'] ?? ($data['action'] ?? '');

function forgot_ensure_table($conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $sql = "CREATE TABLE IF NOT EXISTS `password_reset_otps` (
      `user_id` int NOT NULL,
      `channel` enum('sms','email') NOT NULL,
      `destination` varchar(255) NOT NULL,
      `otp_hash` varchar(255) NOT NULL,
      `expires_at` datetime NOT NULL,
      `failed_attempts` tinyint unsigned NOT NULL DEFAULT 0,
      `last_sent_at` datetime DEFAULT NULL,
      `reset_token_hash` varchar(255) DEFAULT NULL,
      `reset_token_expires_at` datetime DEFAULT NULL,
      `verified_at` datetime DEFAULT NULL,
      PRIMARY KEY (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $ok = (bool) @$conn->query($sql);
    return $ok;
}

function forgot_mask_destination(string $channel, string $dest): string
{
    if ($channel === 'email') {
        $at = strpos($dest, '@');
        if ($at === false) {
            return '******';
        }
        $local = substr($dest, 0, $at);
        $domain = substr($dest, $at);
        $keep = min(2, max(0, strlen($local) - 1));
        return str_repeat('*', max(4, strlen($local) - $keep)) . substr($local, -$keep) . $domain;
    }
    $digits = preg_replace('/\D/', '', $dest);
    $last4 = substr($digits, -4);
    return '******' . $last4;
}

/**
 * Resolve identifier → user row.
 * Order: email (@), phone (digits-only), roll_number, emp_number.
 * @return array{user:array,channel:string,destination:string}|null
 */
function forgot_resolve_user($conn, string $identifier)
{
    $id = trim($identifier);
    if ($id === '') {
        return null;
    }

    if (strpos($id, '@') !== false) {
        $email = strtolower($id);
        $esc = $conn->real_escape_string($email);
        $r = $conn->query("SELECT id, full_name, email, phone, status FROM users WHERE LOWER(TRIM(email)) = '$esc' LIMIT 1");
        if ($r && ($u = $r->fetch_assoc())) {
            return ['user' => $u, 'channel' => 'email', 'destination' => (string) $u['email']];
        }
        return null;
    }

    $digits = preg_replace('/\D/', '', $id);
    if ($digits !== '' && strlen($digits) >= 10) {
        $all = $conn->query("SELECT id, full_name, email, phone, status FROM users WHERE phone IS NOT NULL AND phone != ''");
        if ($all) {
            while ($u = $all->fetch_assoc()) {
                if (sms_phones_match_loose($id, $u['phone']) || sms_phones_match_loose($digits, $u['phone'])) {
                    $norm = sms_normalize_india_mobile($u['phone']);
                    return [
                        'user' => $u,
                        'channel' => 'sms',
                        'destination' => $norm ?: (string) $u['phone'],
                    ];
                }
            }
        }
        // Fall through to roll/emp if phone not found (e.g. numeric roll numbers)
    }

    $esc = $conn->real_escape_string($id);
    $sf = $conn->query("SELECT user_id FROM student_faculty WHERE roll_number = '$esc' LIMIT 1");
    if (!$sf || $sf->num_rows === 0) {
        $sf = $conn->query("SELECT user_id FROM student_faculty WHERE emp_number = '$esc' LIMIT 1");
    }
    if (!$sf || $sf->num_rows === 0) {
        return null;
    }
    $uid = (int) $sf->fetch_assoc()['user_id'];
    $r = $conn->query("SELECT id, full_name, email, phone, status FROM users WHERE id = $uid LIMIT 1");
    if (!$r || !($u = $r->fetch_assoc())) {
        return null;
    }
    // Prefer SMS when mobile is valid; otherwise email.
    $norm = sms_normalize_india_mobile($u['phone'] ?? '');
    if ($norm !== null) {
        return ['user' => $u, 'channel' => 'sms', 'destination' => $norm];
    }
    $email = trim((string) ($u['email'] ?? ''));
    if ($email !== '' && strpos($email, '@') !== false) {
        return ['user' => $u, 'channel' => 'email', 'destination' => $email];
    }
    return null;
}

if (!forgot_ensure_table($conn)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not prepare password reset storage']);
    exit();
}

// ——— request_otp ———
if ($action === 'request_otp') {
    $identifier = trim((string) ($data['identifier'] ?? ''));
    $resolved = forgot_resolve_user($conn, $identifier);
    if ($resolved === null) {
        echo json_encode(['status' => 'error', 'message' => 'No account found for that identifier']);
        exit();
    }
    $user = $resolved['user'];
    if (($user['status'] ?? '') === 'blocked') {
        echo json_encode(['status' => 'error', 'message' => 'Account blocked']);
        exit();
    }
    $user_id = (int) $user['id'];
    $channel = $resolved['channel'];
    $dest = $resolved['destination'];

    $throttle = $conn->query("SELECT last_sent_at FROM password_reset_otps WHERE user_id = $user_id");
    if ($throttle && $throttle->num_rows > 0) {
        $row = $throttle->fetch_assoc();
        if (!empty($row['last_sent_at'])) {
            $last = strtotime($row['last_sent_at']);
            if ($last !== false && (time() - $last) < 60) {
                echo json_encode(['status' => 'error', 'message' => 'Please wait a minute before requesting another OTP']);
                exit();
            }
        }
    }

    $otp = (string) random_int(100000, 999999);
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $dest_esc = $conn->real_escape_string($dest);
    $hash_esc = $conn->real_escape_string($hash);
    $channel_esc = $conn->real_escape_string($channel);

    $sql = "INSERT INTO password_reset_otps
              (user_id, channel, destination, otp_hash, expires_at, failed_attempts, last_sent_at, reset_token_hash, reset_token_expires_at, verified_at)
            VALUES ($user_id, '$channel_esc', '$dest_esc', '$hash_esc', DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, NOW(), NULL, NULL, NULL)
            ON DUPLICATE KEY UPDATE
              channel = VALUES(channel),
              destination = VALUES(destination),
              otp_hash = VALUES(otp_hash),
              expires_at = VALUES(expires_at),
              failed_attempts = 0,
              last_sent_at = NOW(),
              reset_token_hash = NULL,
              reset_token_expires_at = NULL,
              verified_at = NULL";
    if (!$conn->query($sql)) {
        echo json_encode(['status' => 'error', 'message' => 'Could not create OTP', 'detail' => $conn->error]);
        exit();
    }

    if ($channel === 'sms') {
        $message = 'Your OTP for MiCampus password reset is ' . $otp . '. Do not share this code. Valid for 10 minutes. Micampus.co.in';
        $jobId = bg_jobs_enqueue($conn, 'login_otp_sms', [
            'user_id'     => $user_id,
            'destination' => $dest,
            'message'     => $message,
        ], 0, 3);
        if ($jobId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Could not queue OTP SMS']);
            exit();
        }
        $claimed = bg_jobs_claim_id($conn, $jobId);
        if ($claimed === null) {
            echo json_encode(['status' => 'error', 'message' => 'Could not start OTP SMS']);
            exit();
        }
        try {
            process_job_login_otp_sms([
                'user_id'     => $user_id,
                'destination' => $dest,
                'message'     => $message,
                'job_id'      => $jobId,
            ], $conn, 10);
            bg_jobs_mark_done($conn, $jobId);
        } catch (Throwable $e) {
            bg_jobs_mark_failed_final($conn, $jobId, $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Failed to send OTP SMS', 'detail' => $e->getMessage()]);
            exit();
        }
    } else {
        $html = "<h2>Password Reset</h2><p>Your verification code is: <strong style='font-size:24px;color:#FF5F15;'>"
            . htmlspecialchars($otp) . "</strong></p><p>This code will expire in 10 minutes.</p>";
        $sent = smtp_send_mail($dest, 'MiCampus Password Reset Code', $html);
        if (!$sent['ok']) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to send OTP email', 'detail' => $sent['error'] ?? '']);
            exit();
        }
    }

    echo json_encode([
        'status'  => 'success',
        'message' => 'OTP sent',
        'channel' => $channel,
        'masked'  => forgot_mask_destination($channel, $dest),
        'user_id' => $user_id,
    ]);
    exit();
}

// ——— verify_otp ———
if ($action === 'verify_otp') {
    $identifier = trim((string) ($data['identifier'] ?? ''));
    $otp = trim((string) ($data['otp'] ?? ''));
    if ($identifier === '' || $otp === '') {
        echo json_encode(['status' => 'error', 'message' => 'identifier and otp are required']);
        exit();
    }
    $resolved = forgot_resolve_user($conn, $identifier);
    if ($resolved === null) {
        echo json_encode(['status' => 'error', 'message' => 'No account found for that identifier']);
        exit();
    }
    $user_id = (int) $resolved['user']['id'];
    $row = $conn->query("SELECT * FROM password_reset_otps WHERE user_id = $user_id LIMIT 1");
    if (!$row || $row->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'No OTP requested']);
        exit();
    }
    $otpRow = $row->fetch_assoc();
    if ((int) $otpRow['failed_attempts'] >= 5) {
        echo json_encode(['status' => 'error', 'message' => 'Too many failed attempts. Request a new OTP.']);
        exit();
    }
    if (strtotime($otpRow['expires_at']) < time()) {
        echo json_encode(['status' => 'error', 'message' => 'OTP expired. Request a new one.']);
        exit();
    }
    if (!password_verify($otp, $otpRow['otp_hash'])) {
        $conn->query("UPDATE password_reset_otps SET failed_attempts = failed_attempts + 1 WHERE user_id = $user_id");
        echo json_encode(['status' => 'error', 'message' => 'Invalid OTP']);
        exit();
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    $th = $conn->real_escape_string($tokenHash);
    $conn->query("UPDATE password_reset_otps
                  SET verified_at = NOW(),
                      reset_token_hash = '$th',
                      reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                      failed_attempts = 0
                  WHERE user_id = $user_id");

    echo json_encode(['status' => 'success', 'reset_token' => $token]);
    exit();
}

// ——— reset ———
if ($action === 'reset') {
    $token = trim((string) ($data['reset_token'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $confirm = (string) ($data['password_confirm'] ?? '');
    if ($token === '' || $password === '') {
        echo json_encode(['status' => 'error', 'message' => 'reset_token and password are required']);
        exit();
    }
    if ($password !== $confirm) {
        echo json_encode(['status' => 'error', 'message' => 'Passwords do not match']);
        exit();
    }
    if (strlen($password) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters']);
        exit();
    }

    $candidates = $conn->query("SELECT user_id, reset_token_hash, reset_token_expires_at, verified_at
                                FROM password_reset_otps
                                WHERE reset_token_hash IS NOT NULL AND verified_at IS NOT NULL");
    $matchedUser = 0;
    if ($candidates) {
        while ($c = $candidates->fetch_assoc()) {
            if (empty($c['reset_token_expires_at']) || strtotime($c['reset_token_expires_at']) < time()) {
                continue;
            }
            if (password_verify($token, $c['reset_token_hash'])) {
                $matchedUser = (int) $c['user_id'];
                break;
            }
        }
    }
    if ($matchedUser <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired reset token. Verify OTP again.']);
        exit();
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $h = $conn->real_escape_string($hash);
    if (!$conn->query("UPDATE users SET password = '$h' WHERE id = $matchedUser")) {
        echo json_encode(['status' => 'error', 'message' => 'Update failed']);
        exit();
    }
    $conn->query("DELETE FROM password_reset_otps WHERE user_id = $matchedUser");
    echo json_encode(['status' => 'success', 'message' => 'Password changed successfully']);
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action. Use request_otp, verify_otp, or reset']);
