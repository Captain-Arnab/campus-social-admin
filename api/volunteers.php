<?php
// volunteers.php - Improved version with better error handling and role restrictions
header('Content-Type: application/json');

try {
    include 'db.php';
    
    // Only handle POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit();
    }
    
    // Get and decode JSON input
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid JSON body"]);
        exit();
    }

    if (($data['action'] ?? '') === 'switch_staff_role') {
        require_once __DIR__ . '/event_staff_switch_lib.php';
        event_staff_switch_role($conn, $data);
        exit();
    }

    $action = strtolower(trim((string) ($data['action'] ?? ($_GET['action'] ?? ''))));
    if ($action === 'leave' || $action === 'cancel') {
        require_once __DIR__ . '/registration_leave_helper.php';
        $event_id = (int) ($data['event_id'] ?? 0);
        $user_id = (int) ($data['user_id'] ?? 0);
        $v = registration_leave_validate_user_event($conn, $event_id, $user_id);
        if (!$v['ok']) {
            http_response_code((int) ($v['http'] ?? 400));
            echo json_encode(["status" => "error", "message" => $v['message']]);
            exit();
        }

        // Leave allowed after registration deadline (join is not).
        $chk = $conn->prepare("SELECT id FROM volunteers WHERE user_id = ? AND event_id = ? AND status = 'active' LIMIT 1");
        $chk->bind_param("ii", $user_id, $event_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "You are not volunteering for this event"]);
            exit();
        }
        $chk->close();

        $del = $conn->prepare("DELETE FROM volunteers WHERE user_id = ? AND event_id = ? AND status = 'active'");
        $del->bind_param("ii", $user_id, $event_id);
        if (!$del->execute() || $del->affected_rows < 1) {
            $del->close();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Failed to leave as volunteer"]);
            exit();
        }
        $del->close();

        registration_log_action($conn, $event_id, $user_id, 'volunteering', 'left', 'User left as volunteer');
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
    
    // Validate required parameters
    $required = ['event_id', 'user_id', 'role'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing parameter: $field"]);
            exit();
        }
    }
    
    // Sanitize inputs
    $event_id = intval($data['event_id']);
    $user_id = intval($data['user_id']);
    $role = $conn->real_escape_string(trim($data['role']));
    
    // Validate database connection
    if (!$conn) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database connection failed"]);
        exit();
    }
    
    // Get user's is_student status
    $user_query = "SELECT is_student FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_query);
    
    if (!$user_stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }
    
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    
    if ($user_result->num_rows == 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "User not found"]);
        $user_stmt->close();
        exit();
    }
    
    $user_data = $user_result->fetch_assoc();
    $user_is_student = intval($user_data['is_student']);
    $user_stmt->close();
    
    // Get event organizer's is_student status + registration deadline fields
    require_once __DIR__ . '/../event_date_range_schema.php';
    require_once __DIR__ . '/registration_leave_helper.php';
    $event_query = "SELECT u.is_student as organizer_is_student, e.event_date";
    if (schema_events_has_registration_deadline($conn)) {
        $event_query .= ", e.registration_deadline";
    }
    $event_query .= " FROM events e 
                    JOIN users u ON e.organizer_id = u.id 
                    WHERE e.id = ?";
    $event_stmt = $conn->prepare($event_query);
    
    if (!$event_stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }
    
    $event_stmt->bind_param("i", $event_id);
    $event_stmt->execute();
    $event_result = $event_stmt->get_result();
    
    if ($event_result->num_rows == 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Event not found"]);
        $event_stmt->close();
        exit();
    }
    
    $event_data = $event_result->fetch_assoc();
    $organizer_is_student = intval($event_data['organizer_is_student']);
    $event_stmt->close();

    if (events_row_registration_closed($event_data)) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "Registration closed for this event",
            "registration_closed" => true,
            "registration_deadline" => events_row_registration_deadline_value($event_data),
            "server_time" => api_server_time_iso(),
        ]);
        exit();
    }
    
    // Check eligibility: volunteer can only join if their role matches organizer's role
    // Students can volunteer for student events, Faculty can volunteer for faculty events
    if ($user_is_student != $organizer_is_student) {
        $user_role_name = $user_is_student ? "student" : "faculty";
        $organizer_role_name = $organizer_is_student ? "student" : "faculty";
        http_response_code(403);
        echo json_encode([
            "status" => "error", 
            "message" => "As a $user_role_name, you can only be an attendee for $organizer_role_name events. Volunteering is restricted to same role members."
        ]);
        exit();
    }

    require_once __DIR__ . '/event_staff_switch_lib.php';
    require_once __DIR__ . '/registration_leave_helper.php';

    // Already volunteering, or registered as participant/attendee → switch/update in place.
    $check_query = "SELECT id FROM volunteers WHERE user_id = ? AND event_id = ? AND status = 'active'";
    $stmt = $conn->prepare($check_query);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }
    $stmt->bind_param("ii", $user_id, $event_id);
    $stmt->execute();
    $already_vol = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    $part_check = $conn->prepare(
        "SELECT id FROM participant WHERE user_id = ? AND event_id = ? AND status = 'active' LIMIT 1"
    );
    $part_check->bind_param('ii', $user_id, $event_id);
    $part_check->execute();
    $has_part = $part_check->get_result()->num_rows > 0;
    $part_check->close();

    $att_check = $conn->prepare('SELECT id FROM attendees WHERE user_id = ? AND event_id = ? LIMIT 1');
    $att_check->bind_param('ii', $user_id, $event_id);
    $att_check->execute();
    $has_att = $att_check->get_result()->num_rows > 0;
    $att_check->close();

    if ($already_vol || $has_part || $has_att) {
        event_staff_switch_role($conn, [
            'event_id' => $event_id,
            'user_id' => $user_id,
            'to_role' => 'volunteer',
            'role' => $role,
        ]);
        exit();
    }
    
    // Insert new volunteer record
    $insert_query = "INSERT INTO volunteers (event_id, user_id, role, status) 
                     VALUES (?, ?, ?, 'active')";
    $insert_stmt = $conn->prepare($insert_query);
    
    if (!$insert_stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }
    
    $insert_stmt->bind_param("iis", $event_id, $user_id, $role);
    
    if ($insert_stmt->execute()) {
        $new_volunteer_id = $insert_stmt->insert_id;
        $counts = registration_event_counts($conn, $event_id);
        http_response_code(200);
        echo json_encode([
            "status" => "success", 
            "message" => "You have been registered as a volunteer",
            "volunteer_id" => $new_volunteer_id,
            "from_role" => "none",
            "to_role" => "volunteer",
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