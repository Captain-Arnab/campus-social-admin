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

$event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($event_id <= 0 || $user_id <= 0 || !in_array($action, ['add', 'remove'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit();
}

// Verify event exists and user is not organizer
$ev = $conn->query("SELECT organizer_id FROM events WHERE id = $event_id")->fetch_assoc();
if (!$ev) {
    echo json_encode(['status' => 'error', 'message' => 'Event not found']);
    exit();
}
if ((int) $ev['organizer_id'] === $user_id) {
    echo json_encode(['status' => 'error', 'message' => 'Organizer is already an editor']);
    exit();
}

if ($action === 'add') {
    $stmt = $conn->prepare("INSERT IGNORE INTO event_editors (event_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $event_id, $user_id);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['status' => 'success', 'message' => 'Editor added']);
    } else {
        $stmt->close();
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
} else {
    $stmt = $conn->prepare("DELETE FROM event_editors WHERE event_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $event_id, $user_id);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['status' => 'success', 'message' => 'Editor removed']);
    } else {
        $stmt->close();
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
}
