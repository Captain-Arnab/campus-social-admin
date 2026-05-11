<?php
/**
 * Admin: update volunteer role or participant department/class on an event roster.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

include __DIR__ . '/db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}
if (!has_priv('events')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to update roster details.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$event_id = (int) ($_POST['event_id'] ?? 0);
if ($event_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid event.']);
    exit;
}

$kind = (string) ($_POST['kind'] ?? '');

if ($kind === 'volunteer_role') {
    $vid = (int) ($_POST['volunteer_id'] ?? 0);
    $role = trim((string) ($_POST['role'] ?? ''));
    if ($vid <= 0 || $role === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Volunteer and role are required.']);
        exit;
    }
    if (strlen($role) > 191) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Role is too long (max 191 characters).']);
        exit;
    }
    $st = $conn->prepare('UPDATE volunteers SET role = ? WHERE id = ? AND event_id = ?');
    if (!$st) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        exit;
    }
    $st->bind_param('sii', $role, $vid, $event_id);
    $ok = $st->execute();
    $st->close();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Could not update role.']);
        exit;
    }
    $chk = $conn->prepare('SELECT id FROM volunteers WHERE id = ? AND event_id = ? LIMIT 1');
    $chk->bind_param('ii', $vid, $event_id);
    $chk->execute();
    $exists = $chk->get_result()->num_rows > 0;
    $chk->close();
    if (!$exists) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Volunteer not found for this event.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'message' => 'Volunteer role updated.', 'role' => $role]);
    exit;
}

if ($kind === 'participant_dept') {
    $pid = (int) ($_POST['participant_id'] ?? 0);
    $dept = trim((string) ($_POST['department_class'] ?? ''));
    if ($pid <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Participant is required.']);
        exit;
    }
    if (strlen($dept) > 191) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Value is too long (max 191 characters).']);
        exit;
    }
    $st = $conn->prepare('UPDATE participant SET department_class = ? WHERE id = ? AND event_id = ?');
    if (!$st) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        exit;
    }
    $st->bind_param('sii', $dept, $pid, $event_id);
    $ok = $st->execute();
    $st->close();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Could not update participant details.']);
        exit;
    }
    $chk = $conn->prepare('SELECT id FROM participant WHERE id = ? AND event_id = ? LIMIT 1');
    $chk->bind_param('ii', $pid, $event_id);
    $chk->execute();
    $exists = $chk->get_result()->num_rows > 0;
    $chk->close();
    if (!$exists) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Participant not found for this event.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'message' => 'Participant details updated.', 'department_class' => $dept]);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
