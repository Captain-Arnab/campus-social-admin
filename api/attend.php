<?php
// attend.php - Handle attendee registrations (open to both students and faculty)
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
    $required = ['event_id', 'user_id'];
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
    
    // Validate database connection
    if (!$conn) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database connection failed"]);
        exit();
    }
    
    // Check if user exists
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
    
    // Check if event exists
    $event_check = $conn->prepare("SELECT id FROM events WHERE id = ?");
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
    $event_check->close();
    
    // Check if user is already an attendee for this event
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
    
    // Insert new attendee record
    // Note: attendees can be anyone - no restrictions based on student/faculty status
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
        http_response_code(200);
        echo json_encode([
            "status" => "success", 
            "message" => "You have been registered as an attendee",
            "attendee_id" => $insert_stmt->insert_id
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