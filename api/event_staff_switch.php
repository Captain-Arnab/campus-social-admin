<?php
/**
 * Set a user's role for an event (app users). Works from any starting state:
 * none, attendee, volunteer, or participant — including switching back to attendee.
 *
 * POST JSON:
 *   event_id, user_id, to_role ("attend"|"attendee"|"volunteer"|"participant")
 *   role — required when to_role is volunteer (kept as-is on a pure re-confirm)
 *   department_class — required when to_role is participant (falls back to the
 *                      existing record / profile if omitted)
 *
 * Same action via volunteers.php or participant.php:
 *   { "action": "switch_staff_role", ... }
 * Or join-as-attendee via attend.php when already volunteer/participant.
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
