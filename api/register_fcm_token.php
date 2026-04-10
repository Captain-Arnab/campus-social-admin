<?php
/**
 * register_fcm_token.php
 * Register or update FCM token for a user (push notifications).
 *
 * Changes from original:
 *  - If device_id is provided, removes any OLD token for that device before inserting
 *    (prevents token drift: same device should only have one active token)
 *  - Sets is_active = 1 on upsert (re-activates if previously invalidated)
 *  - Returns token_count so Flutter knows how many devices the user has registered
 */
header('Content-Type: application/json');

try {
    include 'db.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        exit();
    }

    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);

    foreach (['user_id', 'fcm_token'] as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Missing parameter: $field"]);
            exit();
        }
    }

    $user_id   = (int)$data['user_id'];
    $fcm_token = trim($data['fcm_token']);
    $device_id = isset($data['device_id']) ? trim($data['device_id']) : null;

    // Reject tokens that are clearly not valid FCM tokens.
    // Real FCM v1 tokens look like "xxxx:APA91bXXX..." (contain colon, 100+ chars).
    // 32-char hex strings are device fingerprints / APNs tokens — not FCM.
    if (strlen($fcm_token) < 50 || strpos($fcm_token, ':') === false) {
        http_response_code(400);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Invalid FCM token format. Token must be a Firebase Cloud Messaging device token.',
        ]);
        exit();
    }

    if (!$conn) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit();
    }

    // --- Check migration has been run ---
    $table_check = $conn->query("SHOW TABLES LIKE 'user_fcm_tokens'");
    if (!$table_check || $table_check->num_rows === 0) {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Notification tables not installed. Run migrations/add_notification_system.sql',
        ]);
        exit();
    }

    // --- Validate user exists and is active ---
    $user_check = $conn->prepare(
        "SELECT id FROM users WHERE id = ? AND status = 'active'"
    );
    $user_check->bind_param('i', $user_id);
    $user_check->execute();
    if ($user_check->get_result()->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found or inactive']);
        $user_check->close();
        exit();
    }
    $user_check->close();

    // --- If device_id provided, deactivate ALL old tokens for this device ---
    // Covers: (a) same user rotating token, (b) different user logging in on same device.
    // A physical device can only hold one valid FCM token at a time.
    if ($device_id !== null && $device_id !== '') {
        $hasActivePre = false;
        $colPre = $conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
        if ($colPre && $colPre->num_rows > 0) {
            $hasActivePre = true;
        }

        if ($hasActivePre) {
            $del = $conn->prepare(
                "UPDATE user_fcm_tokens SET is_active = 0
                  WHERE device_id = ? AND fcm_token != ?"
            );
            $del->bind_param('ss', $device_id, $fcm_token);
        } else {
            $del = $conn->prepare(
                "DELETE FROM user_fcm_tokens
                  WHERE device_id = ? AND fcm_token != ?"
            );
            $del->bind_param('ss', $device_id, $fcm_token);
        }
        $del->execute();
        $del->close();
    }

    // --- Check if is_active column exists (post-migration) ---
    $hasActive = false;
    $colCheck  = $conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $hasActive = true;
    }

    // --- Upsert: insert or update timestamp + device_id + re-activate token ---
    if ($hasActive) {
        $stmt = $conn->prepare(
            "INSERT INTO user_fcm_tokens (user_id, fcm_token, device_id, is_active)
               VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
               updated_at = CURRENT_TIMESTAMP,
               device_id  = COALESCE(VALUES(device_id), device_id),
               is_active  = 1"
        );
        $stmt->bind_param('iss', $user_id, $fcm_token, $device_id);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO user_fcm_tokens (user_id, fcm_token, device_id)
               VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
               updated_at = CURRENT_TIMESTAMP,
               device_id  = COALESCE(VALUES(device_id), device_id)"
        );
        $stmt->bind_param('iss', $user_id, $fcm_token, $device_id);
    }

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        exit();
    }

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to register token: ' . $stmt->error]);
        $stmt->close();
        exit();
    }
    $stmt->close();

    // --- Return how many active devices this user has ---
    $activeCol   = $hasActive ? "AND (is_active = 1 OR is_active IS NULL)" : "";
    $count_res   = $conn->query(
        "SELECT COUNT(*) as c FROM user_fcm_tokens WHERE user_id = {$user_id} {$activeCol}"
    );
    $token_count = $count_res ? (int)$count_res->fetch_assoc()['c'] : 1;

    echo json_encode([
        'status'      => 'success',
        'message'     => 'FCM token registered',
        'token_count' => $token_count,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}