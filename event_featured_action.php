<?php
/**
 * POST { event_id, is_featured: true|false|1|0 } — set events.is_featured.
 * Independent of event status / approval; no other side effects.
 */
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/event_date_range_schema.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}
if (!has_priv('events')) {
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}
if (!schema_events_has_is_featured($conn)) {
    echo json_encode(['status' => 'error', 'message' => 'is_featured column missing — run uploads/migrations/010_event_featured_first_published.sql']);
    exit();
}

$data = $_POST;
$json = json_decode((string) file_get_contents('php://input'), true);
if (is_array($json)) {
    $data = array_merge($json, $data);
}

$event_id = isset($data['event_id']) ? intval($data['event_id']) : 0;
$raw = $data['is_featured'] ?? null;
$is_featured = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

if ($event_id <= 0 || $is_featured === null) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit();
}

$flag = $is_featured ? 1 : 0;
$stmt = $conn->prepare('UPDATE events SET is_featured = ? WHERE id = ?');
$stmt->bind_param('ii', $flag, $event_id);
if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    echo json_encode(['status' => 'error', 'message' => $err]);
    exit();
}
$stmt->close();

$chk = $conn->query("SELECT is_featured FROM events WHERE id = $event_id");
$row = $chk ? $chk->fetch_assoc() : null;
if (!$row) {
    echo json_encode(['status' => 'error', 'message' => 'Event not found']);
    exit();
}

echo json_encode([
    'status' => 'success',
    'message' => ((int) $row['is_featured'] === 1) ? 'Event marked as featured' : 'Event removed from featured',
    'event_id' => $event_id,
    'is_featured' => (int) $row['is_featured'] === 1,
]);
