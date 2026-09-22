<?php
/**
 * Mobile faculty/subadmin event approval API.
 *
 * Auth: app user_id must have users.linked_subadmin_id → active subadmin with privileges.
 *
 * GET  ?action=list_pending&user_id=
 * GET  ?action=list_pending_edits&user_id=
 * POST ?action=approve|reject|hold|reschedule  { user_id, event_id, reason?, reschedule_date?, new_date? }
 * POST ?action=approve_edit|reject_edit       { user_id, event_id }
 *
 * Privilege gate: approve_events (same as desktop approve.php).
 * Edit of event details remains on desktop edit_event.php for now — flag if app needs parity.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../admin_priv.php';
require_once __DIR__ . '/../event_delete_helper.php';
require_once __DIR__ . '/app_inbox_notifications_helper.php';
require_once __DIR__ . '/background_jobs_helper.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$data = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    if (is_array($json)) {
        $data = $json;
    }
    if (!empty($_POST)) {
        $data = array_merge($data, $_POST);
    }
} else {
    $data = $_GET;
}
$action = strtolower(trim((string) ($data['action'] ?? $_GET['action'] ?? '')));

/**
 * Resolve linked active subadmin + privileges for an app user.
 * @return array{ok:bool,message?:string,subadmin_id?:int,username?:string,privileges?:list<string>}
 */
function admin_events_resolve_actor(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'message' => 'user_id is required'];
    }
    $col = @$conn->query("SHOW COLUMNS FROM users LIKE 'linked_subadmin_id'");
    if (!$col || $col->num_rows === 0) {
        return ['ok' => false, 'message' => 'linked_subadmin_id not installed. Run uploads/migrations/006_faculty_linked_subadmin.sql'];
    }
    $u = $conn->query(
        "SELECT u.id, u.linked_subadmin_id, u.full_name, u.is_student, s.id AS sid, s.username, s.status AS sstatus
         FROM users u
         LEFT JOIN subadmins s ON s.id = u.linked_subadmin_id
         WHERE u.id = $userId LIMIT 1"
    );
    if (!$u || !($row = $u->fetch_assoc())) {
        return ['ok' => false, 'message' => 'User not found'];
    }
    $sid = (int) ($row['linked_subadmin_id'] ?? 0);
    if ($sid <= 0 || (int) ($row['sid'] ?? 0) <= 0) {
        return ['ok' => false, 'message' => 'This account is not linked to a sub-admin'];
    }
    if (($row['sstatus'] ?? '') !== 'active') {
        return ['ok' => false, 'message' => 'Linked sub-admin account is inactive'];
    }
    $privs = [];
    $pr = $conn->query("SELECT privilege FROM subadmin_privileges WHERE subadmin_id = $sid");
    if ($pr) {
        while ($p = $pr->fetch_assoc()) {
            $privs[] = $p['privilege'];
        }
    }
    if ($privs === []) {
        $privs = array_keys(subadmin_privilege_definitions());
    }
    return [
        'ok' => true,
        'subadmin_id' => $sid,
        'username' => (string) $row['username'],
        'full_name' => (string) $row['full_name'],
        'privileges' => $privs,
    ];
}

function admin_events_has_priv(array $actor, string $key): bool
{
    return in_array($key, $actor['privileges'] ?? [], true);
}

if ($action === 'list_pending' || ($method === 'GET' && $action === '')) {
    $userId = (int) ($data['user_id'] ?? $_GET['user_id'] ?? 0);
    $actor = admin_events_resolve_actor($conn, $userId);
    if (!$actor['ok']) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => $actor['message']]);
        exit();
    }
    if (!admin_events_has_priv($actor, 'approve_events') && !admin_events_has_priv($actor, 'events')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Missing events privilege']);
        exit();
    }
    $sql = "SELECT e.id, e.title, e.category, e.venue, e.event_date, e.event_end_date, e.registration_deadline,
                   e.status, e.description, e.organizer_id, u.full_name AS organizer_name,
                   e.participate_mode, e.participate_fee, e.attend_mode, e.attend_fee, e.volunteer_mode
            FROM events e
            JOIN users u ON u.id = e.organizer_id
            WHERE e.status IN ('pending','hold')
            ORDER BY e.event_date ASC";
    // Graceful if fee columns missing
    if (!@$conn->query("SHOW COLUMNS FROM events LIKE 'participate_mode'")->num_rows) {
        $sql = "SELECT e.id, e.title, e.category, e.venue, e.event_date, e.status, e.description,
                       e.organizer_id, u.full_name AS organizer_name
                FROM events e JOIN users u ON u.id = e.organizer_id
                WHERE e.status IN ('pending','hold') ORDER BY e.event_date ASC";
    }
    $res = $conn->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['id'] = (int) $r['id'];
            $r['organizer_id'] = (int) $r['organizer_id'];
            $rows[] = $r;
        }
    }
    echo json_encode([
        'status' => 'success',
        'count' => count($rows),
        'can_approve_events' => admin_events_has_priv($actor, 'approve_events'),
        'privileges' => $actor['privileges'],
        'data' => $rows,
    ]);
    exit();
}

if ($action === 'list_pending_edits') {
    $userId = (int) ($data['user_id'] ?? 0);
    $actor = admin_events_resolve_actor($conn, $userId);
    if (!$actor['ok'] || !admin_events_has_priv($actor, 'approve_events')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => $actor['message'] ?? 'Forbidden']);
        exit();
    }
    $res = $conn->query(
        "SELECT p.*, e.title AS live_title, e.status AS event_status, u.full_name AS submitted_by_name
         FROM event_pending_edits p
         JOIN events e ON e.id = p.event_id
         LEFT JOIN users u ON u.id = p.submitted_by_user_id
         ORDER BY p.submitted_at DESC"
    );
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
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

$userId = (int) ($data['user_id'] ?? 0);
$eventId = (int) ($data['event_id'] ?? $data['id'] ?? 0);
$actor = admin_events_resolve_actor($conn, $userId);
if (!$actor['ok']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => $actor['message']]);
    exit();
}
if (!admin_events_has_priv($actor, 'approve_events')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Missing approve_events privilege']);
    exit();
}
if ($eventId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'event_id is required']);
    exit();
}

$username = $actor['username'];
$user_type = 'subadmin';

if (in_array($action, ['approve', 'reject', 'hold', 'reschedule'], true)) {
    $current_event = $conn->query("SELECT status, event_date, organizer_id, title FROM events WHERE id=$eventId")->fetch_assoc();
    if (!$current_event) {
        echo json_encode(['status' => 'error', 'message' => 'Event not found']);
        exit();
    }
    $old_status = $current_event['status'];

    if ($action === 'reject') {
        $rejection_reason = trim((string) ($data['reason'] ?? 'No reason provided'));
        if (function_exists('mb_substr')) {
            $rejection_reason = mb_substr($rejection_reason, 0, 2000, 'UTF-8');
        } else {
            $rejection_reason = substr($rejection_reason, 0, 2000);
        }
        try {
            campus_inbox_after_status_change($conn, $eventId, 'rejected', $old_status, $rejection_reason);
        } catch (Throwable $e) {
            error_log('[admin_events.php] inbox reject: ' . $e->getMessage());
        }
        $conn->begin_transaction();
        try {
            if (!event_hard_delete($conn, $eventId, 'rejected')) {
                throw new Exception('Event hard delete failed');
            }
            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Event rejected and removed', 'action' => 'reject']);
        } catch (Throwable $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => 'Reject failed']);
        }
        exit();
    }

    $new_status = $old_status;
    $remarks = '';
    $hold_reason = null;
    $reschedule_date = null;
    $new_event_date = null;

    if ($action === 'approve') {
        $new_status = 'approved';
        $remarks = 'Event approved and published (app)';
        $conn->query("UPDATE events SET status = 'approved', hold_reason = NULL, rejection_reason = NULL WHERE id = $eventId");
    } elseif ($action === 'hold') {
        $new_status = 'hold';
        $hold_reason = trim((string) ($data['reason'] ?? 'No reason provided'));
        $reschedule_date = !empty($data['reschedule_date']) ? trim((string) $data['reschedule_date']) : null;
        $remarks = 'Event put on hold (app): ' . $hold_reason;
        $hr = $conn->real_escape_string($hold_reason);
        $rdSql = $reschedule_date !== null ? "'" . $conn->real_escape_string($reschedule_date) . "'" : 'NULL';
        $conn->query("UPDATE events SET status = 'hold', hold_reason = '$hr', reschedule_date = $rdSql WHERE id = $eventId");
    } elseif ($action === 'reschedule') {
        $new_status = 'approved';
        $new_event_date = trim((string) ($data['new_date'] ?? ''));
        if ($new_event_date === '') {
            echo json_encode(['status' => 'error', 'message' => 'new_date is required for reschedule']);
            exit();
        }
        $reason = trim((string) ($data['reason'] ?? 'No reason provided'));
        $remarks = 'Event rescheduled (app): ' . $reason;
        $nd = $conn->real_escape_string($new_event_date);
        $conn->query("UPDATE events SET status = 'approved', event_date = '$nd' WHERE id = $eventId");
    }

    $log = $conn->prepare(
        "INSERT INTO event_status_log (event_id, admin_type, admin_username, old_status, new_status, remarks) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $log->bind_param('isssss', $eventId, $user_type, $username, $old_status, $new_status, $remarks);
    $log->execute();
    $log->close();

    try {
        campus_inbox_after_status_change($conn, $eventId, $new_status, $old_status, (string) ($hold_reason ?? ''));
    } catch (Throwable $e) {
        error_log('[admin_events.php] inbox: ' . $e->getMessage());
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Event ' . $action . 'd',
        'action' => $action,
        'event_id' => $eventId,
        'new_status' => $new_status,
    ]);
    exit();
}

if ($action === 'approve_edit' || $action === 'reject_edit') {
    // Delegate to shared logic by including approve_event_edit patterns — call via HTTP redirect is awkward;
    // replicate minimal apply/reject for pending edits.
    $pending = $conn->query("SELECT * FROM event_pending_edits WHERE event_id = $eventId LIMIT 1")->fetch_assoc();
    if (!$pending) {
        echo json_encode(['status' => 'error', 'message' => 'No pending edit for this event']);
        exit();
    }
    if ($action === 'reject_edit') {
        $conn->query("DELETE FROM event_pending_edits WHERE event_id = $eventId");
        $conn->query("UPDATE events SET status = 'approved' WHERE id = $eventId AND status = 'pending'");
        $remarks = 'Pending edit rejected (app) by ' . $username;
        $old = 'pending';
        $new = 'approved';
        $log = $conn->prepare(
            "INSERT INTO event_status_log (event_id, admin_type, admin_username, old_status, new_status, remarks) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $log->bind_param('isssss', $eventId, $user_type, $username, $old, $new, $remarks);
        $log->execute();
        $log->close();
        echo json_encode(['status' => 'success', 'message' => 'Pending edit rejected', 'action' => 'reject_edit']);
        exit();
    }

    // approve_edit — apply core fields then minutes (reuse approve_event_edit.php via internal require is heavy;
    // redirect caller to use desktop for complex edits OR include the file logic).
    // For parity, shell out by simulating GET is wrong. Include apply path:
    $_GET['id'] = $eventId;
    $_GET['action'] = 'approve';
    // Cannot easily include approve_event_edit.php (session-based). Apply essentials:
    $title = $conn->real_escape_string((string) $pending['title']);
    $desc = $conn->real_escape_string((string) ($pending['description'] ?? ''));
    $venue = $conn->real_escape_string((string) $pending['venue']);
    $edate = $conn->real_escape_string((string) $pending['event_date']);
    $cat = $conn->real_escape_string((string) ($pending['category'] ?? ''));
    $rules = $conn->real_escape_string((string) ($pending['rules'] ?? ''));
    $conn->query(
        "UPDATE events SET title='$title', description='$desc', venue='$venue', event_date='$edate',
         category='$cat', rules='$rules', status='approved' WHERE id = $eventId"
    );

    $minutesContent = trim((string) ($pending['minutes_content'] ?? ''));
    $minutesFile = trim((string) ($pending['minutes_file_path'] ?? ''));
    if ($minutesContent !== '' || $minutesFile !== '') {
        if ($minutesContent === '') {
            $minutesContent = '(See attached minutes file)';
        }
        $submittedBy = (int) ($pending['submitted_by_user_id'] ?? 0);
        $fp = $minutesFile !== '' ? $minutesFile : null;
        $promo = trim((string) ($pending['promotional_link'] ?? ''));
        $live = trim((string) ($pending['live_stream_link'] ?? ''));
        $promoSql = $promo !== '' ? "'" . $conn->real_escape_string($promo) . "'" : 'NULL';
        $liveSql = $live !== '' ? "'" . $conn->real_escape_string($live) . "'" : 'NULL';
        $fpSql = $fp !== null ? "'" . $conn->real_escape_string($fp) . "'" : 'NULL';
        $mc = $conn->real_escape_string($minutesContent);
        @$conn->query(
            "INSERT INTO meeting_minutes (event_id, content, file_path, promotional_link, live_stream_link, status, submitted_by, reviewed_at)
             VALUES ($eventId, '$mc', $fpSql, $promoSql, $liveSql, 'approved', $submittedBy, NOW())"
        );
    }

    $conn->query("DELETE FROM event_pending_edits WHERE event_id = $eventId");
    $remarks = 'Pending edit approved (app) by ' . $username;
    $old = 'pending';
    $new = 'approved';
    $log = $conn->prepare(
        "INSERT INTO event_status_log (event_id, admin_type, admin_username, old_status, new_status, remarks) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $log->bind_param('isssss', $eventId, $user_type, $username, $old, $new, $remarks);
    $log->execute();
    $log->close();

    echo json_encode(['status' => 'success', 'message' => 'Pending edit approved', 'action' => 'approve_edit']);
    exit();
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action. Use list_pending, approve, reject, hold, reschedule, approve_edit, reject_edit']);
