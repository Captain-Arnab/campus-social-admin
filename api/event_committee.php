<?php
/**
 * Host-side committee / faculty coordinator (event_editors) changes.
 * Post-approval changes stage into event_pending_edits for admin reapproval.
 *
 * POST JSON: { event_id, user_id (actor), editors: [user_id, ...] }
 *   or { event_id, user_id, action: add|remove, editor_user_id }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../event_pending_edits_helper.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$event_id = (int) ($data['event_id'] ?? 0);
$actor_id = (int) ($data['user_id'] ?? $data['organizer_id'] ?? 0);
if ($event_id <= 0 || $actor_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'event_id and user_id are required']);
    exit();
}

$ev = @$conn->query("SELECT id, organizer_id, status FROM events WHERE id = $event_id LIMIT 1");
if (!$ev || !($evt = $ev->fetch_assoc())) {
    echo json_encode(['status' => 'error', 'message' => 'Event not found']);
    exit();
}

$is_host = ((int) $evt['organizer_id'] === $actor_id);
if (!$is_host) {
    $ed = @$conn->query("SELECT 1 FROM event_editors WHERE event_id = $event_id AND user_id = $actor_id LIMIT 1");
    $is_host = $ed && $ed->num_rows > 0;
}
if (!$is_host) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only organizer or editors can update committee']);
    exit();
}

// Build proposed editors list
$current = [];
$cr = @$conn->query("SELECT user_id FROM event_editors WHERE event_id = $event_id");
if ($cr) {
    while ($row = $cr->fetch_assoc()) {
        $current[] = (int) $row['user_id'];
    }
}

if (isset($data['editors']) && is_array($data['editors'])) {
    $proposed = array_values(array_unique(array_map('intval', $data['editors'])));
} else {
    $action = strtolower(trim((string) ($data['action'] ?? '')));
    $editor_user_id = (int) ($data['editor_user_id'] ?? $data['editor_id'] ?? 0);
    $proposed = $current;
    if ($action === 'add' && $editor_user_id > 0) {
        $proposed[] = $editor_user_id;
        $proposed = array_values(array_unique($proposed));
    } elseif ($action === 'remove' && $editor_user_id > 0) {
        $proposed = array_values(array_filter($proposed, static fn ($id) => $id !== $editor_user_id));
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Provide editors[] or action=add|remove with editor_user_id']);
        exit();
    }
}

// Never include organizer as editor
$orgId = (int) $evt['organizer_id'];
$proposed = array_values(array_filter($proposed, static fn ($id) => $id > 0 && $id !== $orgId));

$needsApproval = ($evt['status'] === 'approved')
    || ($evt['status'] === 'pending' && ($pe = @$conn->query("SELECT 1 FROM event_pending_edits WHERE event_id = $event_id LIMIT 1")) && $pe->num_rows > 0);

if ($needsApproval) {
    $ok = event_pending_edits_stage($conn, $event_id, $actor_id, [
        'editors_json' => json_encode($proposed),
    ]);
    if (!$ok) {
        echo json_encode(['status' => 'error', 'message' => 'Could not stage committee changes']);
        exit();
    }
    @$conn->query("UPDATE events SET status = 'pending' WHERE id = $event_id");
    echo json_encode([
        'status' => 'success',
        'message' => 'Committee constitution changes submitted for admin approval',
        'pending_approval' => true,
        'editors' => $proposed,
    ]);
    exit();
}

// Pre-approval: apply live
$conn->query("DELETE FROM event_editors WHERE event_id = $event_id");
foreach ($proposed as $uid) {
    @$conn->query("INSERT IGNORE INTO event_editors (event_id, user_id) VALUES ($event_id, $uid)");
}

echo json_encode([
    'status' => 'success',
    'message' => 'Committee updated',
    'editors' => $proposed,
]);
