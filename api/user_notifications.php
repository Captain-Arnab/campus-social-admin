<?php
/**
 * user_notifications.php — In-app notification feed for the mobile app (bell icon).
 *
 * GET  ?user_id=123&hours=24
 *      Returns notifications from the last `hours` (default 24, max 168).
 *
 * POST JSON:
 *   { "user_id": 123, "action": "mark_read", "notification_ids": [1, 2, 3] }
 *   { "user_id": 123, "action": "mark_all_read", "hours": 24 }
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app_inbox_notifications_helper.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

if (!campus_inbox_table_exists($conn)) {
    http_response_code(503);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Inbox not installed. Run migrations/add_user_inbox_notifications.sql on the database.',
    ]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ─── GET: list notifications ─────────────────────────────────────────────────
if ($method === 'GET') {
    $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
    if ($user_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'user_id is required']);
        exit();
    }

    $hours = isset($_GET['hours']) ? (int) $_GET['hours'] : 24;
    if ($hours < 1) {
        $hours = 24;
    }
    if ($hours > 168) {
        $hours = 168;
    }

    $chk = $conn->prepare('SELECT 1 FROM users WHERE id = ? AND status = \'active\' LIMIT 1');
    $chk->bind_param('i', $user_id);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $chk->close();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found or inactive']);
        exit();
    }
    $chk->close();

    $stmt = $conn->prepare(
        'SELECT id, notification_type, title, body, event_id, data_json, is_read, created_at
           FROM user_inbox_notifications
          WHERE user_id = ?
            AND created_at >= (NOW() - INTERVAL ? HOUR)
          ORDER BY created_at DESC
          LIMIT 500'
    );
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Query failed']);
        exit();
    }
    $stmt->bind_param('ii', $user_id, $hours);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['id']                = (int) $row['id'];
        $row['event_id']          = $row['event_id'] !== null ? (int) $row['event_id'] : null;
        $row['is_read']           = (int) $row['is_read'];
        $row['data']              = null;
        if (!empty($row['data_json'])) {
            $decoded = json_decode((string) $row['data_json'], true);
            $row['data'] = is_array($decoded) ? $decoded : null;
        }
        unset($row['data_json']);
        $rows[] = $row;
    }
    $stmt->close();

    $cntStmt = $conn->prepare(
        'SELECT COUNT(*) AS c FROM user_inbox_notifications
          WHERE user_id = ? AND is_read = 0 AND created_at >= (NOW() - INTERVAL ? HOUR)'
    );
    $unread = 0;
    if ($cntStmt) {
        $cntStmt->bind_param('ii', $user_id, $hours);
        $cntStmt->execute();
        $unread = (int) ($cntStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $cntStmt->close();
    }

    echo json_encode([
        'status'       => 'success',
        'hours_window' => $hours,
        'unread_count' => $unread,
        'count'        => count($rows),
        'data'         => $rows,
    ]);
    exit();
}

// ─── POST: mark read ─────────────────────────────────────────────────────────
if ($method === 'POST') {
    $input   = json_decode(file_get_contents('php://input'), true) ?: [];
    $user_id = isset($input['user_id']) ? (int) $input['user_id'] : 0;
    $action  = isset($input['action']) ? trim((string) $input['action']) : '';

    if ($user_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'user_id is required']);
        exit();
    }

    $chk = $conn->prepare('SELECT 1 FROM users WHERE id = ? AND status = \'active\' LIMIT 1');
    $chk->bind_param('i', $user_id);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $chk->close();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found or inactive']);
        exit();
    }
    $chk->close();

    if ($action === 'mark_read') {
        $ids = isset($input['notification_ids']) && is_array($input['notification_ids'])
            ? array_filter(array_map('intval', $input['notification_ids']))
            : [];
        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'notification_ids array required']);
            exit();
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types        = str_repeat('i', count($ids) + 1);
        $sql          = "UPDATE user_inbox_notifications SET is_read = 1
                          WHERE user_id = ? AND id IN ($placeholders)";
        $stmt         = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Update failed']);
            exit();
        }
        $params = array_merge([$user_id], $ids);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        echo json_encode(['status' => 'success', 'message' => 'Updated', 'updated' => $affected]);
        exit();
    }

    if ($action === 'mark_all_read') {
        $hours = isset($input['hours']) ? (int) $input['hours'] : 24;
        if ($hours < 1) {
            $hours = 24;
        }
        if ($hours > 168) {
            $hours = 168;
        }
        $stmt = $conn->prepare(
            'UPDATE user_inbox_notifications SET is_read = 1
              WHERE user_id = ?
                AND is_read = 0
                AND created_at >= (NOW() - INTERVAL ? HOUR)'
        );
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Update failed']);
            exit();
        }
        $stmt->bind_param('ii', $user_id, $hours);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        echo json_encode(['status' => 'success', 'message' => 'Updated', 'updated' => $affected]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action. Use mark_read or mark_all_read.']);
    exit();
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
