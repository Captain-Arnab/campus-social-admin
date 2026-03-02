<?php
// register_fcm_token.php — Register or update FCM token for a user (for push notifications)
header('Content-Type: application/json');

try {
    include 'db.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit();
    }

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    $required = ['user_id', 'fcm_token'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing parameter: $field"]);
            exit();
        }
    }

    $user_id = intval($data['user_id']);
    $fcm_token = $conn->real_escape_string(trim($data['fcm_token']));
    $device_id = isset($data['device_id']) ? $conn->real_escape_string(trim($data['device_id'])) : null;

    if (!$conn) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database connection failed"]);
        exit();
    }

    // Ensure table exists (migration may not have been run)
    $table_check = $conn->query("SHOW TABLES LIKE 'user_fcm_tokens'");
    if (!$table_check || $table_check->num_rows === 0) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Notification tables not installed. Run api/migrations/add_notification_tables.sql"]);
        exit();
    }

    // Check user exists
    $user_check = $conn->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
    $user_check->bind_param("i", $user_id);
    $user_check->execute();
    if ($user_check->get_result()->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "User not found or inactive"]);
        $user_check->close();
        exit();
    }
    $user_check->close();

    // Upsert: insert or update on duplicate (user_id, fcm_token)
    $stmt = $conn->prepare("INSERT INTO user_fcm_tokens (user_id, fcm_token, device_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP, device_id = COALESCE(VALUES(device_id), device_id)");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }
    $stmt->bind_param("iss", $user_id, $fcm_token, $device_id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "FCM token registered"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to register token: " . $stmt->error]);
    }
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
