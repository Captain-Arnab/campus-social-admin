<?php
/**
 * Organizer sends a push notification to volunteers and/or participants of an event.
 * Called by the app from event detail screen (Send notification).
 *
 * POST: event_id, organizer_id, message, recipient_type (volunteers|participants|both)
 * Header: Authorization: Bearer <token>
 *
 * Uses same db.php as the rest of the API (must use database micampus_college_event_db
 * so user_fcm_tokens is the same as register_fcm_token.php).
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

define('FCM_HELPER_LOADED', true);
require_once __DIR__ . '/fcm_helper.php';
require_once __DIR__ . '/db.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

$input = file_get_contents('php://input');
$data = json_decode($input, true) ?: $_POST;

$event_id = isset($data['event_id']) ? (int) $data['event_id'] : 0;
$organizer_id = isset($data['organizer_id']) ? (int) $data['organizer_id'] : 0;
$message = isset($data['message']) ? trim($data['message']) : '';
$recipient_type = isset($data['recipient_type']) ? trim($data['recipient_type']) : 'both';

if ($event_id <= 0 || $organizer_id <= 0 || $message === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'event_id, organizer_id and message are required']);
    exit();
}

if (!in_array($recipient_type, ['volunteers', 'participants', 'both'])) {
    $recipient_type = 'both';
}

// Verify this user is the organizer of the event
$check = $conn->prepare("SELECT id, title FROM events WHERE id = ? AND organizer_id = ?");
$check->bind_param("ii", $event_id, $organizer_id);
$check->execute();
$ev = $check->get_result()->fetch_assoc();
$check->close();
if (!$ev) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not the organizer of this event']);
    exit();
}

$user_ids = [];

if ($recipient_type === 'volunteers' || $recipient_type === 'both') {
    $st = $conn->prepare("SELECT user_id FROM volunteers WHERE event_id = ? AND status = 'active'");
    $st->bind_param("i", $event_id);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $user_ids[(int)$row['user_id']] = true;
    }
    $st->close();
}

if ($recipient_type === 'participants' || $recipient_type === 'both') {
    $st = $conn->prepare("SELECT user_id FROM participant WHERE event_id = ? AND status = 'active'");
    $st->bind_param("i", $event_id);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $user_ids[(int)$row['user_id']] = true;
    }
    $st->close();
}

if (empty($user_ids)) {
    echo json_encode(['status' => 'success', 'message' => 'No volunteers/participants for this event', 'push_sent' => 0]);
    exit();
}

$ids = array_keys($user_ids);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $conn->prepare("SELECT fcm_token FROM user_fcm_tokens WHERE user_id IN ($placeholders)");
$stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
$stmt->execute();
$tres = $stmt->get_result();
$tokens = [];
while ($tr = $tres->fetch_assoc()) {
    $tokens[] = $tr['fcm_token'];
}
$stmt->close();
$conn->close();

if (empty($tokens)) {
    echo json_encode(['status' => 'success', 'message' => 'No FCM tokens found for volunteers/participants', 'push_sent' => 0]);
    exit();
}

$title = 'Event: ' . $ev['title'];
$body = $message;
$fcm_data = ['type' => 'organizer_message', 'event_id' => (string)$event_id];
$out = fcm_send_to_tokens($tokens, $title, $body, $fcm_data);
$sent = $out['success'];

echo json_encode(['status' => 'success', 'message' => 'Notification sent', 'push_sent' => $sent]);
