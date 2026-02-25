<?php
session_start();
include 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
$type = isset($_POST['type']) ? $_POST['type'] : '';

if ($event_id <= 0 || $user_id <= 0 || !in_array($type, ['participant', 'volunteer'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit();
}

// E-certificates only for past events
$ev = $conn->query("SELECT event_date FROM events WHERE id = $event_id")->fetch_assoc();
if (!$ev || strtotime($ev['event_date']) >= time()) {
    echo json_encode(['status' => 'error', 'message' => 'E-certificates can only be uploaded for past events']);
    exit();
}

const MAX_SIZE = 5 * 1024 * 1024; // 5 MB
$allowed = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];

if (!isset($_FILES['certificate']) || $_FILES['certificate']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'No file or upload error']);
    exit();
}

$file = $_FILES['certificate'];
if ($file['size'] > MAX_SIZE) {
    echo json_encode(['status' => 'error', 'message' => 'File must be 5 MB or less']);
    exit();
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if (!in_array($mime, $allowed)) {
    echo json_encode(['status' => 'error', 'message' => 'Only PDF and images (JPEG, PNG, GIF, WebP) allowed']);
    exit();
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
if (!preg_match('/^[a-z0-9]+$/i', $ext)) $ext = 'pdf';
$dir = __DIR__ . '/uploads/certificates';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$filename = 'cert_' . $event_id . '_' . $user_id . '_' . $type . '_' . time() . '.' . strtolower($ext);
$path = $dir . '/' . $filename;
$relative_path = 'uploads/certificates/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $path)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save file']);
    exit();
}

// Upsert: replace existing certificate for this event+user+type
$stmt = $conn->prepare("INSERT INTO event_certificates (event_id, user_id, type, file_path) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), uploaded_at = CURRENT_TIMESTAMP");
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
    exit();
}
$stmt->bind_param("iiss", $event_id, $user_id, $type, $relative_path);
if ($stmt->execute()) {
    $stmt->close();
    echo json_encode(['status' => 'success', 'message' => 'Certificate uploaded', 'file_path' => $relative_path]);
} else {
    $stmt->close();
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
}
