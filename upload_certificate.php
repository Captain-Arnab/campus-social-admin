<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}
if (!has_priv('certificates')) {
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
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
require_once __DIR__ . '/event_date_range_schema.php';
$ev = $conn->query('SELECT event_date, event_end_date FROM events WHERE id = ' . (int) $event_id)->fetch_assoc();
if (!$ev || !events_row_is_fully_past($ev)) {
    echo json_encode(['status' => 'error', 'message' => 'E-certificates can only be uploaded for past events']);
    exit();
}

const MAX_SIZE = 5 * 1024 * 1024; // 5 MB
$allowed = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];

/**
 * Many shared hosts disable ext-fileinfo; avoid fatal error and still validate uploads.
 */
function certificate_detect_mime(string $tmp): ?string
{
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $m = $fi->file($tmp);
        if (is_string($m) && $m !== '') {
            return $m;
        }
    }
    if (function_exists('mime_content_type')) {
        $m = @mime_content_type($tmp);
        if (is_string($m) && $m !== '') {
            return $m;
        }
    }
    $head = @file_get_contents($tmp, false, null, 0, 12);
    if ($head !== false && strlen($head) >= 4 && strncmp($head, '%PDF', 4) === 0) {
        return 'application/pdf';
    }
    if (function_exists('getimagesize')) {
        $info = @getimagesize($tmp);
        if ($info !== false && !empty($info['mime'])) {
            return (string) $info['mime'];
        }
    }
    return null;
}

if (!isset($_FILES['certificate']) || $_FILES['certificate']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['certificate']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = 'No file or upload error';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        $msg = 'Upload rejected by server size limit (check PHP upload_max_filesize / post_max_size)';
    } elseif ($err === UPLOAD_ERR_PARTIAL) {
        $msg = 'File was only partially uploaded';
    } elseif ($err === UPLOAD_ERR_NO_TMP_DIR) {
        $msg = 'Server missing temporary folder for uploads';
    } elseif ($err === UPLOAD_ERR_CANT_WRITE) {
        $msg = 'Server could not write file to disk';
    }
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit();
}

$file = $_FILES['certificate'];
if ($file['size'] > MAX_SIZE) {
    echo json_encode(['status' => 'error', 'message' => 'File must be 5 MB or less']);
    exit();
}

$mime = certificate_detect_mime($file['tmp_name']);
if ($mime === null) {
    echo json_encode(['status' => 'error', 'message' => 'Could not detect file type. Use PDF or JPEG/PNG/GIF/WebP, or enable the PHP fileinfo extension on the server.']);
    exit();
}
if ($mime === 'image/jpg') {
    $mime = 'image/jpeg';
}
if (!in_array($mime, $allowed, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Only PDF and images (JPEG, PNG, GIF, WebP) allowed']);
    exit();
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
if (!preg_match('/^[a-z0-9]+$/i', $ext)) {
    $ext = $mime === 'application/pdf' ? 'pdf' : 'jpg';
}
$dir = __DIR__ . '/uploads/certificates';
if (!is_dir($dir)) {
    if (!@mkdir($dir, 0755, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Could not create uploads folder (check permissions on admin/uploads).']);
        exit();
    }
}
if (!is_writable($dir)) {
    echo json_encode(['status' => 'error', 'message' => 'Upload folder is not writable by the web server (chmod admin/uploads/certificates).']);
    exit();
}
$filename = 'cert_' . $event_id . '_' . $user_id . '_' . $type . '_' . time() . '.' . strtolower($ext);
$path = $dir . '/' . $filename;
$relative_path = 'uploads/certificates/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $path)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save file (check disk space and open_basedir restrictions).']);
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
