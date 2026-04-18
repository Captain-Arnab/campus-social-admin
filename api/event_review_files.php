<?php
/**
 * Event review file uploads/listing.
 *
 * POST: Upload file(s) for an event review (organizer only, after event date).
 *   Form fields: event_id, organizer_id, review_file (single file) OR review_files[] (multiple)
 *
 * GET:  List review files for an event.
 *   Query: event_id
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../event_date_range_schema.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
    if ($event_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'event_id required']);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, file_path, file_type, original_name, uploaded_at FROM event_review_files WHERE event_id = ? ORDER BY uploaded_at ASC");
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $files = [];
    while ($row = $res->fetch_assoc()) {
        $files[] = $row;
    }
    $stmt->close();

    echo json_encode(['status' => 'success', 'data' => $files]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_id = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
    $organizer_id = isset($_POST['organizer_id']) ? (int) $_POST['organizer_id'] : 0;

    if ($event_id <= 0 || $organizer_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'event_id and organizer_id required']);
        exit();
    }

    $evCols = 'SELECT id, organizer_id, event_date';
    if (schema_events_has_event_end_date($conn)) {
        $evCols .= ', event_end_date';
    }
    $evCols .= ' FROM events WHERE id = ?';
    $ev = $conn->prepare($evCols);
    $ev->bind_param('i', $event_id);
    $ev->execute();
    $er = $ev->get_result()->fetch_assoc();
    $ev->close();

    if (!$er || (int) $er['organizer_id'] !== $organizer_id) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not the organizer of this event']);
        exit();
    }

    if (!events_row_organizer_actions_allowed($er)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'File uploads are only allowed on or after the event start date']);
        exit();
    }

    $upload_dir = dirname(__DIR__) . '/uploads/review_files/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $allowed_types = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf'
    ];
    $max_size = 10 * 1024 * 1024;

    $files_key = isset($_FILES['review_files']) ? 'review_files' : (isset($_FILES['review_file']) ? 'review_file' : null);
    if (!$files_key) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No file provided (use review_file or review_files[])']);
        exit();
    }

    $uploaded = [];
    $errors = [];

    $file_data = $_FILES[$files_key];
    $is_multi = is_array($file_data['tmp_name']);
    $count = $is_multi ? count($file_data['tmp_name']) : 1;

    for ($i = 0; $i < $count; $i++) {
        $tmp = $is_multi ? $file_data['tmp_name'][$i] : $file_data['tmp_name'];
        $err = $is_multi ? $file_data['error'][$i] : $file_data['error'];
        $name = $is_multi ? $file_data['name'][$i] : $file_data['name'];
        $type = $is_multi ? $file_data['type'][$i] : $file_data['type'];
        $size = $is_multi ? $file_data['size'][$i] : $file_data['size'];

        if ($err !== UPLOAD_ERR_OK) {
            $errors[] = "Upload error for $name";
            continue;
        }
        if (!in_array($type, $allowed_types)) {
            $errors[] = "$name: unsupported type ($type)";
            continue;
        }
        if ($size > $max_size) {
            $errors[] = "$name: exceeds 10 MB limit";
            continue;
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $filename = 'review_' . $event_id . '_' . time() . '_' . $i . '.' . $ext;
        $dest = $upload_dir . $filename;

        if (move_uploaded_file($tmp, $dest)) {
            $path = 'uploads/review_files/' . $filename;
            $stmt = $conn->prepare("INSERT INTO event_review_files (event_id, file_path, file_type, original_name, uploaded_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('isssi', $event_id, $path, $type, $name, $organizer_id);
            $stmt->execute();
            $uploaded[] = [
                'id' => (int) $conn->insert_id,
                'file_path' => $path,
                'file_type' => $type,
                'original_name' => $name,
            ];
            $stmt->close();
        } else {
            $errors[] = "$name: failed to save";
        }
    }

    echo json_encode([
        'status' => empty($uploaded) ? 'error' : 'success',
        'message' => count($uploaded) . ' file(s) uploaded' . (empty($errors) ? '' : '; ' . count($errors) . ' error(s)'),
        'uploaded' => $uploaded,
        'errors' => $errors,
    ]);
    exit();
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
