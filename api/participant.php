<?php
// participant.php - Handle participant registrations
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
        // Block leave if already recorded as a winner for this event.
        $win = @$conn->query("SELECT 1 FROM event_winners WHERE event_id = $event_id AND user_id = $user_id LIMIT 1");
        if ($win && $win->num_rows > 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Cannot leave: you are recorded as a winner for this event"]);
            exit();
        }

        $chk = $conn->prepare("SELECT id FROM participant WHERE user_id = ? AND event_id = ? AND status = 'active' LIMIT 1");
        $chk->bind_param("ii", $user_id, $event_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "You are not a participant for this event"]);
            exit();
        }
        $chk->close();

        // Hard delete so unique (event_id, user_id) allows rejoin later.
        $del = $conn->prepare("DELETE FROM participant WHERE user_id = ? AND event_id = ? AND status = 'active'");
        $del->bind_param("ii", $user_id, $event_id);
        if (!$del->execute() || $del->affected_rows < 1) {
            $del->close();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Failed to leave as participant"]);
            exit();
        }
        $del->close();

        registration_log_action($conn, $event_id, $user_id, 'participating', 'left', 'User left as participant');
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

    if (!isset($data['event_id'], $data['user_id'], $data['department_class'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "event_id, user_id, and department_class are required"]);
        exit();
    }

    $event_id = intval($data['event_id']);
    $user_id = intval($data['user_id']);
    $department_class = trim((string) $data['department_class']);
    if ($event_id <= 0 || $user_id <= 0 || $department_class === '') {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid event_id, user_id, or empty department_class"]);
        exit();
    }
    
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
        echo json_encode(["status" => "error", "message" => "Registration closed for this event"]);
        exit();
    }
    
    // Check eligibility: participant can only join if their role matches organizer's role
    // Students can participate in student events, Faculty can participate in faculty events
    if ($user_is_student != $organizer_is_student) {
        $user_role_name = $user_is_student ? "student" : "faculty";
        $organizer_role_name = $organizer_is_student ? "student" : "faculty";
        http_response_code(403);
        echo json_encode([
            "status" => "error", 
            "message" => "As a $user_role_name, you can only be an attendee for $organizer_role_name events. Participation is restricted to same role members."
        ]);
        exit();
    }
    
    // Check if user is already an active participant for this event
    $check_query = "SELECT id FROM participant WHERE user_id = ? AND event_id = ? AND status = 'active'";
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
        echo json_encode(["status" => "error", "message" => "You are already a participant for this event"]);
        $stmt->close();
        exit();
    }
    $stmt->close();

    $vol_check = $conn->prepare(
        "SELECT id FROM volunteers WHERE user_id = ? AND event_id = ? AND status = 'active' LIMIT 1"
    );
    $vol_check->bind_param('ii', $user_id, $event_id);
    $vol_check->execute();
    if ($vol_check->get_result()->num_rows > 0) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'You are registered as a volunteer. Use switch_staff_role to change to participant.',
        ]);
        $vol_check->close();
        exit();
    }
    $vol_check->close();

    // Sync department/class on profile (student_faculty)
    $dept_esc = $conn->real_escape_string($department_class);
    $conn->query("UPDATE student_faculty SET department_class = '$dept_esc' WHERE user_id = $user_id");

    // Insert new participant record (stores dept at registration time)
    $insert_query = "INSERT INTO participant (event_id, user_id, status, department_class) 
                     VALUES (?, ?, 'active', ?)";
    $insert_stmt = $conn->prepare($insert_query);

    if (!$insert_stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }

    $insert_stmt->bind_param("iis", $event_id, $user_id, $department_class);
    
    if ($insert_stmt->execute()) {
        $new_participant_id = $insert_stmt->insert_id;
        // Participant and attendee are mutually exclusive, so the join counts stay in sync.
        $att_del = $conn->prepare("DELETE FROM attendees WHERE event_id = ? AND user_id = ?");
        if ($att_del) {
            $att_del->bind_param("ii", $event_id, $user_id);
            $att_del->execute();
            $att_del->close();
        }
        require_once __DIR__ . '/registration_leave_helper.php';
        $counts = registration_event_counts($conn, $event_id);
        http_response_code(200);
        echo json_encode([
            "status" => "success", 
            "message" => "You have been registered as a participant",
            "participant_id" => $new_participant_id,
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