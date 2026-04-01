<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}
if (!has_priv('events')) {
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit();
}

$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($event_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid event']);
    exit();
}

// Get organizer_id and existing editor ids for this event
$ev = $conn->query("SELECT organizer_id FROM events WHERE id = $event_id")->fetch_assoc();
if (!$ev) {
    echo json_encode(['status' => 'error', 'message' => 'Event not found']);
    exit();
}
$organizer_id = (int) $ev['organizer_id'];

$exclude_ids = [$organizer_id];
$editors_result = @$conn->query("SELECT user_id FROM event_editors WHERE event_id = $event_id");
if ($editors_result) {
    while ($row = $editors_result->fetch_assoc()) {
        $exclude_ids[] = (int) $row['user_id'];
    }
}
$exclude_ids = array_unique($exclude_ids);
$exclude_sql = implode(',', array_map('intval', $exclude_ids));

$sql = "SELECT id, full_name, email FROM users WHERE status = 'active' AND id NOT IN ($exclude_sql)";
if ($search !== '') {
    $search_esc = $conn->real_escape_string($search);
    $sql .= " AND (full_name LIKE '%$search_esc%' OR email LIKE '%$search_esc%')";
}
$sql .= " ORDER BY full_name ASC LIMIT 50";

$result = $conn->query($sql);
$users = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = ['id' => (int) $row['id'], 'full_name' => $row['full_name'], 'email' => $row['email']];
    }
}
echo json_encode(['status' => 'success', 'data' => $users]);
