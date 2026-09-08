<?php
/**
 * Meeting minutes workflow — consolidated into event_pending_edits reapproval.
 *
 * POST ?action=submit|approve|reject
 * GET  ?action=list|get
 *
 * Host submit stages minutes onto event_pending_edits and flips the event to
 * pending (same C2 pipeline as other post-approval edits). Live minutes rows
 * are written only when admin approves the pending edit (or legacy approve here).
 *
 * Host = organizer_id + event_editors.
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
require_once __DIR__ . '/background_jobs_helper.php';
require_once __DIR__ . '/admin_public_url.php';
require_once __DIR__ . '/../event_pending_edits_helper.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

function mm_ensure_table($conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $sql = "CREATE TABLE IF NOT EXISTS `meeting_minutes` (
      `id` int NOT NULL AUTO_INCREMENT,
      `event_id` int NOT NULL,
      `content` text NOT NULL,
      `file_path` varchar(255) DEFAULT NULL,
      `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      `submitted_by` int NOT NULL,
      `reviewed_by` int DEFAULT NULL,
      `reviewed_at` datetime DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_mm_event` (`event_id`),
      KEY `idx_mm_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $ok = (bool) @$conn->query($sql);
    return $ok;
}

function mm_is_host($conn, int $event_id, int $user_id): bool
{
    $r = @$conn->query("SELECT organizer_id FROM events WHERE id = $event_id LIMIT 1");
    if (!$r || !($row = $r->fetch_assoc())) {
        return false;
    }
    if ((int) $row['organizer_id'] === $user_id) {
        return true;
    }
    $e = @$conn->query("SELECT 1 FROM event_editors WHERE event_id = $event_id AND user_id = $user_id LIMIT 1");
    return $e && $e->num_rows > 0;
}

function mm_row_public(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['event_id'] = (int) $row['event_id'];
    $row['submitted_by'] = (int) $row['submitted_by'];
    $row['reviewed_by'] = isset($row['reviewed_by']) ? (int) $row['reviewed_by'] : null;
    $row['file_url'] = !empty($row['file_path']) ? admin_public_file_url($row['file_path']) : '';
    return $row;
}

/** Resolve event_id from POST body, JSON, multipart form, or query string. */
function mm_resolve_int(array $data, string $key): int
{
    if (isset($data[$key]) && $data[$key] !== '' && $data[$key] !== null) {
        return (int) $data[$key];
    }
    if (isset($_POST[$key]) && $_POST[$key] !== '') {
        return (int) $_POST[$key];
    }
    if (isset($_GET[$key]) && $_GET[$key] !== '') {
        return (int) $_GET[$key];
    }
    return 0;
}

if (!mm_ensure_table($conn)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not prepare meeting_minutes table']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if ($method === 'POST' && (stripos($content_type, 'multipart/form-data') !== false || !empty($_POST))) {
    $data = $_POST;
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }
} else {
    $data = $_GET;
}

$action = $data['action'] ?? ($_GET['action'] ?? '');

if ($method === 'GET' || $action === 'list' || $action === 'get') {
    if ($action === 'get' || isset($_GET['id'])) {
        $id = (int) ($data['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'id required']);
            exit();
        }
        $r = $conn->query("SELECT * FROM meeting_minutes WHERE id = $id LIMIT 1");
        if (!$r || !($row = $r->fetch_assoc())) {
            echo json_encode(['status' => 'error', 'message' => 'Not found']);
            exit();
        }
        echo json_encode(['status' => 'success', 'data' => mm_row_public($row)]);
        exit();
    }

    $event_id = mm_resolve_int($data, 'event_id');
    $statusFilter = trim((string) ($data['status'] ?? $_GET['status'] ?? ''));
    $sql = "SELECT * FROM meeting_minutes WHERE 1=1";
    if ($event_id > 0) {
        $sql .= " AND event_id = $event_id";
    }
    if (in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
        $sql .= " AND status = '" . $conn->real_escape_string($statusFilter) . "'";
    }
    $sql .= " ORDER BY created_at DESC";
    $res = $conn->query($sql);
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = mm_row_public($row);
        }
    }
    echo json_encode(['status' => 'success', 'count' => count($rows), 'data' => $rows]);
    exit();
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

if ($action === 'submit') {
    $event_id = mm_resolve_int($data, 'event_id');
    $user_id = mm_resolve_int($data, 'user_id');
    if ($user_id <= 0) {
        $user_id = mm_resolve_int($data, 'submitted_by');
    }
    $content = trim((string) ($data['content'] ?? $data['minutes'] ?? $_POST['content'] ?? ''));

    // Attachment may arrive as attachment, picture, file, or minutes_file
    $fileKey = null;
    foreach (['attachment', 'picture', 'file', 'minutes_file', 'minutes_picture'] as $k) {
        if (!empty($_FILES[$k]['tmp_name']) && ($_FILES[$k]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $fileKey = $k;
            break;
        }
    }

    if ($event_id <= 0 || $user_id <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'event_id and user_id are required',
            'hint' => 'Send event_id in form body (multipart/JSON) or as ?event_id= query param',
        ]);
        exit();
    }
    if ($content === '' && $fileKey === null) {
        echo json_encode(['status' => 'error', 'message' => 'content or attachment (picture) is required']);
        exit();
    }
    if (!mm_is_host($conn, $event_id, $user_id)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Only organizer or editors can submit minutes']);
        exit();
    }

    $ev = @$conn->query("SELECT id, status, title FROM events WHERE id = $event_id LIMIT 1");
    if (!$ev || !($evt = $ev->fetch_assoc())) {
        echo json_encode(['status' => 'error', 'message' => 'Event not found']);
        exit();
    }

    $file_path = null;
    if ($fileKey !== null) {
        $dir = dirname(__DIR__) . '/uploads/meeting_minutes/';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $ext = pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION);
        $fn = 'mm_' . $event_id . '_' . time() . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $dir . $fn)) {
            $file_path = 'uploads/meeting_minutes/' . $fn;
        }
    }

    // Stage via event_pending_edits (single reapproval workflow).
    $stageOk = event_pending_edits_stage($conn, $event_id, $user_id, [
        'minutes_content' => $content !== '' ? $content : '(See attached minutes file)',
        'minutes_file_path' => $file_path,
    ]);
    if (!$stageOk) {
        echo json_encode(['status' => 'error', 'message' => 'Could not stage minutes for approval']);
        exit();
    }

    // Flip approved (or already-pending-with-edit) events to pending for admin review.
    if (in_array($evt['status'], ['approved', 'pending'], true)) {
        @$conn->query("UPDATE events SET status = 'pending' WHERE id = $event_id");
        $remarks = 'Minutes of meeting submitted for admin reapproval by user_' . $user_id;
        @$conn->query(
            "INSERT INTO event_status_log (event_id, admin_type, admin_username, old_status, new_status, remarks)
             VALUES ($event_id, 'app', 'user_$user_id', '" . $conn->real_escape_string($evt['status']) . "', 'pending', '" . $conn->real_escape_string($remarks) . "')"
        );
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Minutes submitted for admin approval',
        'pending_approval' => true,
        'event_id' => $event_id,
        'file_path' => $file_path,
        'file_url' => $file_path ? admin_public_file_url($file_path) : '',
    ]);
    exit();
}

if ($action === 'approve' || $action === 'reject') {
    // Legacy path: approve a meeting_minutes row directly (kept for admin API clients).
    // Prefer approving via approve_event_edit.php when minutes are staged on pending edits.
    $id = (int) ($data['id'] ?? $data['minutes_id'] ?? $_GET['id'] ?? 0);
    $reviewed_by = (int) ($data['reviewed_by'] ?? $data['admin_id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'id required']);
        exit();
    }
    $r = $conn->query("SELECT * FROM meeting_minutes WHERE id = $id LIMIT 1");
    if (!$r || !($row = $r->fetch_assoc())) {
        echo json_encode(['status' => 'error', 'message' => 'Not found']);
        exit();
    }
    if ($row['status'] !== 'pending') {
        echo json_encode(['status' => 'error', 'message' => 'Already reviewed']);
        exit();
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $stmt = $conn->prepare("UPDATE meeting_minutes SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
    $stmt->bind_param('sii', $newStatus, $reviewed_by, $id);
    $stmt->execute();
    $stmt->close();

    if ($action === 'approve') {
        $eventId = (int) $row['event_id'];
        $ev = @$conn->query("SELECT title FROM events WHERE id = $eventId LIMIT 1");
        $title = ($ev && ($er = $ev->fetch_assoc())) ? (string) $er['title'] : 'Event';
        bg_jobs_enqueue($conn, 'minutes_approved_notify', [
            'event_id' => $eventId,
            'minutes_id' => $id,
            'title' => $title,
        ]);
    }

    echo json_encode(['status' => 'success', 'message' => 'Minutes ' . $newStatus]);
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
