<?php
/**
 * JSON lookup for certificate generator: events search, event meta, staff lists.
 */
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/event_date_range_schema.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
if (!has_priv('certificates')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$action = (string) ($_GET['action'] ?? '');

if ($action === 'events') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $past_sql = events_sql_past_naked($conn);
    $sql = "SELECT e.id, e.title, e.event_date, e.category, u.full_name AS organizer_name
            FROM events e
            JOIN users u ON e.organizer_id = u.id
            WHERE e.status = 'approved' AND ($past_sql)";
    $params = [];
    $types = '';
    if ($q !== '') {
        $sql .= ' AND (e.title LIKE ? OR e.category LIKE ? OR u.full_name LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like];
        $types = 'sss';
    }
    $sql .= ' ORDER BY e.event_date DESC LIMIT 80';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Query failed']);
        exit;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $id = (int) $row['id'];
        $date = $row['event_date'] ? date('M d, Y', strtotime($row['event_date'])) : '';
        $cat = trim((string) ($row['category'] ?? ''));
        $org = trim((string) ($row['organizer_name'] ?? ''));
        $organised = $cat !== '' && $org !== '' ? $cat . ' — ' . $org : ($cat !== '' ? $cat : $org);
        $items[] = [
            'id' => $id,
            'title' => (string) $row['title'],
            'event_date' => $date,
            'organised_by' => $organised,
            'text' => (string) $row['title'] . ($date !== '' ? ' (' . $date . ')' : ''),
        ];
    }
    $stmt->close();
    echo json_encode(['status' => 'success', 'results' => $items]);
    exit;
}

if ($action === 'event') {
    $event_id = (int) ($_GET['event_id'] ?? 0);
    if ($event_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'event_id required']);
        exit;
    }
    $past_sql = events_sql_past_naked($conn);
    $stmt = $conn->prepare(
        "SELECT e.id, e.title, e.event_date, e.category, u.full_name AS organizer_name
         FROM events e
         JOIN users u ON e.organizer_id = u.id
         WHERE e.id = ? AND e.status = 'approved' AND ($past_sql)
         LIMIT 1"
    );
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Event not found']);
        exit;
    }
    $cat = trim((string) ($row['category'] ?? ''));
    $org = trim((string) ($row['organizer_name'] ?? ''));
    $organised = $cat !== '' && $org !== '' ? $cat . ' — ' . $org : ($cat !== '' ? $cat : $org);
    echo json_encode([
        'status' => 'success',
        'event' => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'event_date' => $row['event_date'] ? date('M d, Y', strtotime($row['event_date'])) : '',
            'organised_by' => $organised,
        ],
    ]);
    exit;
}

if ($action === 'staff') {
    $event_id = (int) ($_GET['event_id'] ?? 0);
    $type = (string) ($_GET['type'] ?? 'participant');
    if ($event_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'event_id required']);
        exit;
    }
    if (!in_array($type, ['participant', 'volunteer'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid type']);
        exit;
    }

    $past_sql = events_sql_past_naked($conn);
    $chk = $conn->prepare(
        "SELECT id FROM events WHERE id = ? AND status = 'approved' AND ($past_sql) LIMIT 1"
    );
    $chk->bind_param('i', $event_id);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        $chk->close();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Event not found']);
        exit;
    }
    $chk->close();

    $items = [];
    if ($type === 'volunteer') {
        $stmt = $conn->prepare(
            "SELECT u.id AS user_id, u.full_name, v.role
             FROM volunteers v
             JOIN users u ON v.user_id = u.id
             WHERE v.event_id = ? AND v.status = 'active'
             ORDER BY u.full_name ASC"
        );
    } else {
        $stmt = $conn->prepare(
            "SELECT u.id AS user_id, u.full_name, p.department_class
             FROM participant p
             JOIN users u ON p.user_id = u.id
             WHERE p.event_id = ? AND p.status = 'active'
             ORDER BY u.full_name ASC"
        );
    }
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $uid = (int) $row['user_id'];
        $name = (string) $row['full_name'];
        $extra = $type === 'volunteer'
            ? trim((string) ($row['role'] ?? ''))
            : trim((string) ($row['department_class'] ?? ''));
        $position = 0;
        $w = $conn->prepare(
            'SELECT position FROM event_winners WHERE event_id = ? AND user_id = ? LIMIT 1'
        );
        if ($w) {
            $w->bind_param('ii', $event_id, $uid);
            $w->execute();
            $wr = $w->get_result()->fetch_assoc();
            if ($wr) {
                $position = (int) $wr['position'];
            }
            $w->close();
        }
        $label = $name;
        if ($extra !== '') {
            $label .= ' — ' . $extra;
        }
        $items[] = [
            'id' => $uid,
            'full_name' => $name,
            'text' => $label,
            'position' => $position,
        ];
    }
    $stmt->close();
    echo json_encode(['status' => 'success', 'results' => $items]);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
