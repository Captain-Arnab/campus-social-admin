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
    
    // Get event organizer's is_student status
    $event_query = "SELECT u.is_student as organizer_is_student 
                    FROM events e 
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
    
    // Check if user is already a volunteer for this event
    $check_query = "SELECT id FROM volunteers WHERE user_id = ? AND event_id = ?";
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
        echo json_encode(["status" => "error", "message" => "You are already volunteering for this event"]);
        $stmt->close();
        exit();
    }
    
    $stmt->close();
    
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
        http_response_code(200);
        echo json_encode([
            "status" => "success", 
            "message" => "You have been registered as a volunteer",
            "volunteer_id" => $insert_stmt->insert_id
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