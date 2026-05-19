<?php
/**
 * Switch between volunteer and participant for an event (app users).
 *
 * POST JSON:
 *   event_id, user_id, to_role ("volunteer"|"participant")
 *   role — required when to_role is volunteer
 *   department_class — required when to_role is participant (falls back to profile if omitted)
 *
 * Same action via volunteers.php or participant.php:
 *   { "action": "switch_staff_role", ... }
 */
header('Content-Type: application/json');

try {
    include 'db.php';
    require_once __DIR__ . '/event_staff_switch_lib.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
        exit();
    }

    event_staff_switch_role($conn, $data);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
