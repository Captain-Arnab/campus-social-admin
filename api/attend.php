<?php
// attend.php - Join or leave as attendee (open to both students and faculty)
header('Content-Type: application/json');

try {
    include 'db.php';
    require_once __DIR__ . '/../event_date_range_schema.php';
    require_once __DIR__ . '/registration_leave_helper.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit();
    }

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid JSON body"]);
        exit();
    }

    $action = strtolower(trim((string) ($data['action'] ?? ($_GET['action'] ?? 'join'))));
    // Default (no action / join) keeps backward-compatible join behavior.

    // ——— leave ———
    if ($action === 'leave' || $action === 'cancel') {
        $event_id = (int) ($data['event_id'] ?? 0);
        $user_id = (int) ($data['user_id'] ?? 0);
        $v = registration_leave_validate_user_event($conn, $event_id, $user_id);
        if (!$v['ok']) {
            http_response_code((int) ($v['http'] ?? 400));
            echo json_encode(["status" => "error", "message" => $v['message']]);
            exit();
        }

        // Leave is allowed after registration deadline (join is not).
        $chk = $conn->prepare("SELECT id FROM attendees WHERE user_id = ? AND event_id = ? LIMIT 1");
        $chk->bind_param("ii", $user_id, $event_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "You are not attending this event"]);
            exit();
        }
        $chk->close();

        $del = $conn->prepare("DELETE FROM attendees WHERE user_id = ? AND event_id = ?");
        $del->bind_param("ii", $user_id, $event_id);
        if (!$del->execute() || $del->affected_rows < 1) {
            $del->close();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Failed to leave event"]);
            exit();
        }
        $del->close();

        registration_log_action(
            $conn,
            $event_id,
            $user_id,
            'attending',
            'left',
            'User left as attendee'
        );

        $counts = registration_event_counts($conn, $event_id);
        echo json_encode([
            "status" => "success",
            "message" => "Left event",
            "event_id" => $event_id,
            "server_time" => api_server_time_iso(),
            "attendee_count" => $counts['attendee_count'],
            "volunteer_count" => $counts['volunteer_count'],
            "participant_count" => $counts['participant_count'],
            "viewer_count" => $counts['viewer_count'],
            "event" => array_merge(['id' => $event_id], $counts),
        ]);
        exit();
    }

    // ——— join (default) ———
    $required = ['event_id', 'user_id'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing parameter: $field"]);
            exit();
        }
    }

    $event_id = intval($data['event_id']);
    $user_id = intval($data['user_id']);

    if (!$conn) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database connection failed"]);
        exit();
    }

    $user_check = $conn->prepare("SELECT id FROM users WHERE id = ?");
    if (!$user_check) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }

    $user_check->bind_param("i", $user_id);
    $user_check->execute();
    $user_result = $user_check->get_result();

    if ($user_result->num_rows == 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "User not found"]);
        $user_check->close();
        exit();
    }
    $user_check->close();

    $evCols = 'id, event_date';
    if (schema_events_has_registration_deadline($conn)) {
        $evCols .= ', registration_deadline';
    }
    $event_check = $conn->prepare("SELECT $evCols FROM events WHERE id = ?");
    if (!$event_check) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }

    $event_check->bind_param("i", $event_id);
    $event_check->execute();
    $event_result = $event_check->get_result();

    if ($event_result->num_rows == 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Event not found"]);
        $event_check->close();
        exit();
    }
    $event_row = $event_result->fetch_assoc();
    $event_check->close();

    if (events_row_registration_closed($event_row)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Registration closed for this event"]);
        exit();
    }

    $check_query = "SELECT id FROM attendees WHERE user_id = ? AND event_id = ?";
    $stmt = $conn->prepare($check_query);

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }

    $stmt->bind_param("ii", $user_id, $event_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "You are already attending this event"]);
        $stmt->close();
        exit();
    }

    $stmt->close();

    $insert_query = "INSERT INTO attendees (event_id, user_id, joined_at)
                     VALUES (?, ?, NOW())";
    $insert_stmt = $conn->prepare($insert_query);

    if (!$insert_stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }

    $insert_stmt->bind_param("ii", $event_id, $user_id);

    if ($insert_stmt->execute()) {
        $counts = registration_event_counts($conn, $event_id);
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "You have been registered as an attendee",
            "attendee_id" => $insert_stmt->insert_id,
            "server_time" => api_server_time_iso(),
            "attendee_count" => $counts['attendee_count'],
            "volunteer_count" => $counts['volunteer_count'],
            "participant_count" => $counts['participant_count'],
            "viewer_count" => $counts['viewer_count'],
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to register: " . $insert_stmt->error]);
    }

    $insert_stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Server error: " . $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
?>
