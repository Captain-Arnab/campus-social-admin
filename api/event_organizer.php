<?php
/**
 * Organizer-only: post-event review and attendance marking.
 * Attendance and review allowed only when CURDATE() >= DATE(event_date).
 */
header('Content-Type: application/json');
include 'db.php';
require_once __DIR__ . '/../event_date_range_schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($content_type, 'multipart/form-data') !== false || !empty($_POST)) {
    $data = $_POST;
} else {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
}
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit();
}

$action = $data['action'] ?? '';
$event_id = isset($data['event_id']) ? (int) $data['event_id'] : 0;
$organizer_id = isset($data['organizer_id']) ? (int) $data['organizer_id'] : 0;

if ($event_id <= 0 || $organizer_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'event_id and organizer_id are required']);
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
    echo json_encode(['status' => 'error', 'message' => 'Review and attendance are only allowed on or after the event start date']);
    exit();
}

if ($action === 'set_review') {
    $review = isset($data['organizer_review']) ? trim((string) $data['organizer_review']) : '';
    if ($review === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'organizer_review text is required']);
        exit();
    }
    $st = $conn->prepare('UPDATE events SET organizer_review = ?, organizer_review_at = NOW() WHERE id = ?');
    if (!$st) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
        exit();
    }
    $st->bind_param('si', $review, $event_id);
    if ($st->execute()) {
        $response = ['status' => 'success', 'message' => 'Review saved'];

        $review_files = [];
        $files_key = isset($_FILES['review_files']) ? 'review_files' : (isset($_FILES['review_file']) ? 'review_file' : null);
        if ($files_key) {
            $upload_dir = dirname(__DIR__) . '/uploads/review_files/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
            $fd = $_FILES[$files_key];
            $is_multi = is_array($fd['tmp_name']);
            $fc = $is_multi ? count($fd['tmp_name']) : 1;

            for ($fi = 0; $fi < $fc; $fi++) {
                $ftmp = $is_multi ? $fd['tmp_name'][$fi] : $fd['tmp_name'];
                $ferr = $is_multi ? $fd['error'][$fi] : $fd['error'];
                $fname = $is_multi ? $fd['name'][$fi] : $fd['name'];
                $ftype = $is_multi ? $fd['type'][$fi] : $fd['type'];
                if ($ferr !== UPLOAD_ERR_OK || !in_array($ftype, $allowed)) continue;

                $ext = pathinfo($fname, PATHINFO_EXTENSION);
                $fn = 'review_' . $event_id . '_' . time() . '_' . $fi . '.' . $ext;
                if (move_uploaded_file($ftmp, $upload_dir . $fn)) {
                    $path = 'uploads/review_files/' . $fn;
                    $ins = $conn->prepare("INSERT INTO event_review_files (event_id, file_path, file_type, original_name, uploaded_by) VALUES (?, ?, ?, ?, ?)");
                    $ins->bind_param('isssi', $event_id, $path, $ftype, $fname, $organizer_id);
                    $ins->execute();
                    $review_files[] = ['id' => (int)$conn->insert_id, 'file_path' => $path, 'original_name' => $fname];
                    $ins->close();
                }
            }
            $response['review_files_uploaded'] = count($review_files);
            $response['review_files'] = $review_files;
        }

        echo json_encode($response);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $st->error]);
    }
    $st->close();
    exit();
}

if ($action === 'set_attendance') {
    $role = $data['role'] ?? '';
    if (!in_array($role, ['volunteer', 'participant'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'role must be volunteer or participant']);
        exit();
    }
    $items = $data['attendance'] ?? null;
    if (!is_array($items) || $items === []) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'attendance must be a non-empty array of {user_id, present}']);
        exit();
    }

    $table = $role === 'volunteer' ? 'volunteers' : 'participant';
    $stmt = $conn->prepare("UPDATE $table SET attended = ?, attendance_marked_at = NOW() WHERE event_id = ? AND user_id = ? AND status = 'active'");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
        exit();
    }

    $updated = 0;
    foreach ($items as $row) {
        if (!is_array($row) || !isset($row['user_id'])) {
            continue;
        }
        $uid = (int) $row['user_id'];
        $present = !empty($row['present']) ? 1 : 0;
        if ($uid <= 0) {
            continue;
        }
        $stmt->bind_param('iii', $present, $event_id, $uid);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $updated++;
        }
    }
    $stmt->close();
    echo json_encode(['status' => 'success', 'message' => 'Attendance updated', 'rows_updated' => $updated]);
    exit();
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action; use set_review or set_attendance']);
