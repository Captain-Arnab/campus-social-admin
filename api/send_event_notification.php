<?php
// send_event_notification.php — Organizer sends a text message as push notification to volunteers and/or participants
header('Content-Type: application/json');

define('FCM_HELPER_LOADED', true);

try {
    include 'db.php';
    require_once __DIR__ . '/fcm_helper.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit();
    }

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    $required = ['event_id', 'organizer_id', 'message'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing parameter: $field"]);
            exit();
        }
    }

    $event_id = intval($data['event_id']);
    $organizer_id = intval($data['organizer_id']);
    $message = trim($data['message']);
    $recipient_type = isset($data['recipient_type']) ? strtolower(trim($data['recipient_type'])) : 'both';
    if (!in_array($recipient_type, ['volunteers', 'participants', 'both'])) {
        $recipient_type = 'both';
    }

    if (strlen($message) === 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Message cannot be empty"]);
        exit();
    }

    if (!$conn) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database connection failed"]);
        exit();
    }

    $table_check = $conn->query("SHOW TABLES LIKE 'user_fcm_tokens'");
    if (!$table_check || $table_check->num_rows === 0) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Notification tables not installed. Run api/migrations/add_notification_tables.sql"]);
        exit();
    }

    // Verify event exists and user is the organizer
    $evt_stmt = $conn->prepare("SELECT id, title, organizer_id FROM events WHERE id = ?");
    $evt_stmt->bind_param("i", $event_id);
    $evt_stmt->execute();
    $evt = $evt_stmt->get_result()->fetch_assoc();
    $evt_stmt->close();
    if (!$evt) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Event not found"]);
        exit();
    }
    if ((int) $evt['organizer_id'] !== $organizer_id) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Only the event organizer can send notifications"]);
        exit();
    }

    $event_title = $evt['title'];
    $user_ids = [];

    if ($recipient_type === 'volunteers' || $recipient_type === 'both') {
        $v_stmt = $conn->prepare("SELECT user_id FROM volunteers WHERE event_id = ? AND status = 'active'");
        $v_stmt->bind_param("i", $event_id);
        $v_stmt->execute();
        $vr = $v_stmt->get_result();
        while ($row = $vr->fetch_assoc()) {
            $user_ids[(int) $row['user_id']] = true;
        }
        $v_stmt->close();
    }
    if ($recipient_type === 'participants' || $recipient_type === 'both') {
        $p_stmt = $conn->prepare("SELECT user_id FROM participant WHERE event_id = ? AND status = 'active'");
        $p_stmt->bind_param("i", $event_id);
        $p_stmt->execute();
        $pr = $p_stmt->get_result();
        while ($row = $pr->fetch_assoc()) {
            $user_ids[(int) $row['user_id']] = true;
        }
        $p_stmt->close();
    }

    $user_ids = array_keys($user_ids);
    if (empty($user_ids)) {
        echo json_encode([
            "status" => "success",
            "message" => "No volunteers or participants to notify",
            "recipients_count" => 0,
            "push_sent" => 0
        ]);
        exit();
    }

    // Fetch FCM tokens for these users
    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    $t_stmt = $conn->prepare("SELECT fcm_token FROM user_fcm_tokens WHERE user_id IN ($placeholders)");
    $t_stmt->bind_param(str_repeat('i', count($user_ids)), ...$user_ids);
    $t_stmt->execute();
    $tr = $t_stmt->get_result();
    $tokens = [];
    while ($row = $tr->fetch_assoc()) {
        $tokens[] = $row['fcm_token'];
    }
    $t_stmt->close();

    $title = "Meeting update: " . $event_title;
    $data_payload = [
        'type' => 'organizer_message',
        'event_id' => (string) $event_id,
        'message' => $message
    ];
    $push_result = ['success' => 0, 'failed' => 0];
    if (!empty($tokens)) {
        $push_result = fcm_send_to_tokens($tokens, $title, $message, $data_payload);
    }

    // Log in organizer_notifications
    $log_table = $conn->query("SHOW TABLES LIKE 'organizer_notifications'");
    if ($log_table && $log_table->num_rows > 0) {
        $log_stmt = $conn->prepare("INSERT INTO organizer_notifications (event_id, organizer_id, message, recipient_type) VALUES (?, ?, ?, ?)");
        if ($log_stmt) {
            $log_stmt->bind_param("iiss", $event_id, $organizer_id, $message, $recipient_type);
            $log_stmt->execute();
            $log_stmt->close();
        }
    }

    echo json_encode([
        "status" => "success",
        "message" => "Notification sent",
        "recipients_count" => count($user_ids),
        "push_sent" => $push_result['success'],
        "push_failed" => $push_result['failed'],
        "recipient_type" => $recipient_type
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
