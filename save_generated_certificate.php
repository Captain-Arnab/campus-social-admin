<?php
/**
 * Save a client-rendered certificate image (PNG) to event_certificates.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$event_id = (int) ($_POST['event_id'] ?? 0);
$user_id = (int) ($_POST['user_id'] ?? 0);
$type = (string) ($_POST['type'] ?? '');

if ($event_id <= 0 || $user_id <= 0 || !in_array($type, ['participant', 'volunteer'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'event_id, user_id, and type (participant|volunteer) are required']);
    exit;
}

$ev = $conn->query('SELECT event_date, event_end_date FROM events WHERE id = ' . (int) $event_id)->fetch_assoc();
if (!$ev || !events_row_is_fully_past($ev)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Certificates can only be saved for past events']);
    exit;
}

$image_data = (string) ($_POST['image_data'] ?? '');
if ($image_data === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'image_data is required']);
    exit;
}

if (preg_match('#^data:image/(png|jpeg);base64,#i', $image_data, $m)) {
    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : 'png';
    $raw = base64_decode(preg_replace('#^data:image/(png|jpeg);base64,#i', '', $image_data), true);
} else {
    $ext = 'png';
    $raw = base64_decode($image_data, true);
}

if ($raw === false || strlen($raw) < 100) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid image data']);
    exit;
}

if (strlen($raw) > 8 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Image must be 8 MB or less']);
    exit;
}

$dir = __DIR__ . '/uploads/certificates';
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not create uploads folder']);
    exit;
}
if (!is_writable($dir)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Upload folder is not writable']);
    exit;
}

$filename = 'cert_gen_' . $event_id . '_' . $user_id . '_' . $type . '_' . time() . '.' . $ext;
$path = $dir . '/' . $filename;
$relative_path = 'uploads/certificates/' . $filename;

if (file_put_contents($path, $raw) === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to save file']);
    exit;
}

$hasStatus = false;
$chk = @$conn->query("SHOW COLUMNS FROM event_certificates LIKE 'status'");
$hasStatus = ($chk && $chk->num_rows > 0);

if ($hasStatus) {
    $stmt = $conn->prepare(
        "INSERT INTO event_certificates (event_id, user_id, type, status, file_path)
         VALUES (?, ?, ?, 'ready', ?)
         ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), status = 'ready', uploaded_at = CURRENT_TIMESTAMP"
    );
} else {
    $stmt = $conn->prepare(
        'INSERT INTO event_certificates (event_id, user_id, type, file_path)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), uploaded_at = CURRENT_TIMESTAMP'
    );
}
if (!$stmt) {
    @unlink($path);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
    exit;
}
$stmt->bind_param('iiss', $event_id, $user_id, $type, $relative_path);
if ($stmt->execute()) {
    $stmt->close();
    echo json_encode([
        'status' => 'success',
        'message' => 'Certificate saved and linked to the user',
        'file_path' => $relative_path,
    ]);
} else {
    $stmt->close();
    @unlink($path);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
}
