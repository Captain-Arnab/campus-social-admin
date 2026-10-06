<?php
/**
 * GET api/winner_photos.php?limit=20[&scope=ended|closed][&require_photo=0|1]
 * Recent winners for the app "Recent Winners" section — one request, newest events first.
 *
 * scope=ended (default) : closed events + approved events that have already ended
 *                         (most events are never formally closed by the organizer)
 * scope=closed          : status = 'closed' only
 * require_photo=1       : only winners with an uploaded photo (default 0; photo_url is null when missing)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_public_url.php';
require_once __DIR__ . '/../event_date_range_schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
$limit = max(1, min(100, $limit));
$scope = (($_GET['scope'] ?? 'ended') === 'closed') ? 'closed' : 'ended';
$requirePhoto = filter_var($_GET['require_photo'] ?? false, FILTER_VALIDATE_BOOLEAN);

$pc = @$conn->query("SHOW COLUMNS FROM event_winners LIKE 'photo_path'");
$hasPhoto = $pc && $pc->num_rows > 0;
$hasClosedAt = schema_events_has_closed_status($conn);
$hasEndDate = schema_events_has_event_end_date($conn);

if ($requirePhoto && !$hasPhoto) {
    echo json_encode(['status' => 'success', 'count' => 0, 'data' => [], 'message' => 'photo_path not migrated yet']);
    exit();
}

$where = ($scope === 'closed')
    ? "e.status = 'closed'"
    : "(e.status = 'closed' OR (e.status = 'approved' AND " . events_sql_past($conn, 'e') . '))';
if ($requirePhoto) {
    $where .= " AND w.photo_path IS NOT NULL AND w.photo_path != ''";
}
$endedAt = $hasEndDate ? 'COALESCE(e.event_end_date, e.event_date)' : 'e.event_date';

$sql = "SELECT w.user_id, w.position, w.created_at,
               " . ($hasPhoto ? 'w.photo_path' : 'NULL AS photo_path') . ",
               u.full_name AS winner_name, u.profile_pic AS winner_avatar,
               e.id AS event_id, e.title AS event_name, e.status AS event_status, e.event_date,
               " . ($hasClosedAt ? 'e.closed_at' : 'NULL AS closed_at') . "
        FROM event_winners w
        INNER JOIN users u ON u.id = w.user_id
        INNER JOIN events e ON e.id = w.event_id
        WHERE $where
          AND u.status = 'active'
        ORDER BY $endedAt DESC, e.id DESC, w.position ASC
        LIMIT $limit";

$res = $conn->query($sql);
if (!$res) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Query failed']);
    exit();
}

$data = [];
while ($row = $res->fetch_assoc()) {
    $photoPath = ($row['photo_path'] ?? '') !== '' ? (string) $row['photo_path'] : null;
    $data[] = [
        'user_id' => (int) $row['user_id'],
        'winner_name' => $row['winner_name'],
        'winner_avatar' => $row['winner_avatar'],
        'position' => (int) $row['position'],
        'event_id' => (int) $row['event_id'],
        'event_name' => $row['event_name'],
        'event_status' => $row['event_status'],
        'event_date' => $row['event_date'],
        'photo_path' => $photoPath,
        'photo_url' => $photoPath !== null ? admin_public_file_url($photoPath) : null,
        'has_photo' => $photoPath !== null,
        'created_at' => $row['created_at'],
        'closed_at' => $row['closed_at'],
    ];
}

echo json_encode(['status' => 'success', 'count' => count($data), 'data' => $data]);
