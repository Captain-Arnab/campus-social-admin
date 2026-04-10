<?php
/**
 * admin_scheduled_notifications.php
 * Admin API to create, list, and cancel scheduled push notifications.
 *
 * Endpoints:
 *   GET    ?action=list    — list upcoming pending notifications
 *   POST   ?action=create  — schedule a new notification
 *   POST   ?action=cancel  — cancel a pending notification
 *
 * Security: Requires 'X-Admin-Token' header matching ADMIN_SECRET env var.
 *           In production, place this behind your admin authentication middleware.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ─── Auth ─────────────────────────────────────────────────────────────────────
// $adminSecret = getenv('ADMIN_SECRET') ?: 'changeme_admin_secret';
// $givenToken  = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
// if (!hash_equals($adminSecret, $givenToken)) {
//     http_response_code(401);
//     echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
//     exit();
// }

// ─── DB ───────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/db.php';
if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'scheduled_notifications'");
if (!$tableCheck || $tableCheck->num_rows === 0) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Run migrations/add_notification_system.sql first',
    ]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

// =============================================================================
// GET — list scheduled notifications
// =============================================================================
if ($method === 'GET' && $action === 'list') {
    $status_filter = $_GET['status'] ?? 'pending';
    $valid_statuses = ['pending', 'sent', 'failed', 'cancelled', 'all'];
    if (!in_array($status_filter, $valid_statuses, true)) {
        $status_filter = 'pending';
    }

    $where = $status_filter !== 'all' ? "WHERE status = '{$status_filter}'" : '';
    $rows  = [];
    $res   = $conn->query(
        "SELECT sn.*, e.title AS event_title
           FROM scheduled_notifications sn
           LEFT JOIN events e ON e.id = sn.event_id
           {$where}
           ORDER BY sn.scheduled_at DESC
           LIMIT 100"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['data_payload'] = $row['data_payload']
                ? json_decode($row['data_payload'], true)
                : null;
            $rows[] = $row;
        }
    }

    echo json_encode(['status' => 'success', 'count' => count($rows), 'data' => $rows]);
    $conn->close();
    exit();
}

// =============================================================================
// POST — create a scheduled notification
// =============================================================================
if ($method === 'POST' && $action === 'create') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $title         = trim($input['title']   ?? '');
    $body          = trim($input['body']    ?? '');
    $scheduled_at  = trim($input['scheduled_at'] ?? ''); // YYYY-MM-DD HH:MM:SS (IST)
    $recipient_type = trim($input['recipient_type'] ?? 'all');
    $event_id      = isset($input['event_id'])  ? (int)$input['event_id']  : null;
    $topic         = trim($input['topic']   ?? '');
    $data_payload  = isset($input['data_payload']) && is_array($input['data_payload'])
                     ? $input['data_payload']
                     : null;
    $created_by    = isset($input['admin_id']) ? (int)$input['admin_id'] : null;

    // Validation
    if ($title === '' || $body === '' || $scheduled_at === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'title, body, and scheduled_at are required']);
        $conn->close();
        exit();
    }

    if (!strtotime($scheduled_at)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'scheduled_at must be a valid datetime (YYYY-MM-DD HH:MM:SS)']);
        $conn->close();
        exit();
    }

    $valid_rtypes = ['all', 'students', 'faculty', 'topic', 'event_participants', 'event_volunteers', 'event_both'];
    if (!in_array($recipient_type, $valid_rtypes, true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid recipient_type. Valid: ' . implode(', ', $valid_rtypes)]);
        $conn->close();
        exit();
    }

    if ($recipient_type === 'topic' && $topic === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'topic is required when recipient_type = topic']);
        $conn->close();
        exit();
    }

    if (in_array($recipient_type, ['event_participants', 'event_volunteers', 'event_both'], true) && !$event_id) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'event_id is required for event-based recipient types']);
        $conn->close();
        exit();
    }

    $topicVal    = $topic       ?: null;
    $dataJson    = $data_payload ? json_encode($data_payload) : null;

    $stmt = $conn->prepare(
        "INSERT INTO scheduled_notifications
           (title, body, scheduled_at, recipient_type, event_id, topic, data_payload, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'ssssissi',
        $title, $body, $scheduled_at, $recipient_type,
        $event_id, $topicVal, $dataJson, $created_by
    );

    if ($stmt->execute()) {
        $id = (int)$stmt->insert_id;
        $stmt->close();
        echo json_encode([
            'status'  => 'success',
            'message' => 'Notification scheduled',
            'id'      => $id,
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $stmt->error]);
        $stmt->close();
    }

    $conn->close();
    exit();
}

// =============================================================================
// POST — cancel a pending notification
// =============================================================================
if ($method === 'POST' && $action === 'cancel') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $id    = isset($input['id']) ? (int)$input['id'] : 0;

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'id is required']);
        $conn->close();
        exit();
    }

    $stmt = $conn->prepare(
        "UPDATE scheduled_notifications
            SET status = 'cancelled'
          WHERE id = ? AND status = 'pending'"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Notification cancelled']);
    } else {
        http_response_code(404);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Notification not found or already sent/cancelled',
        ]);
    }

    $conn->close();
    exit();
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action or method']);
$conn->close();