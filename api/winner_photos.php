<?php
/**
 * GET api/winner_photos.php?limit=20
 * Recent winners with photos from closed events.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_public_url.php';

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

$hasPhoto = @$conn->query("SHOW COLUMNS FROM event_winners LIKE 'photo_path'");
if (!$hasPhoto || $hasPhoto->num_rows === 0) {
    echo json_encode(['status' => 'success', 'count' => 0, 'data' => [], 'message' => 'photo_path not migrated yet']);
    exit();
}

$sql = "SELECT w.user_id, w.position, w.photo_path, w.created_at,
               u.full_name AS winner_name,
               e.id AS event_id, e.title AS event_name, e.closed_at
        FROM event_winners w
        INNER JOIN users u ON u.id = w.user_id
        INNER JOIN events e ON e.id = w.event_id
        WHERE e.status = 'closed'
          AND w.photo_path IS NOT NULL
          AND w.photo_path != ''
        ORDER BY COALESCE(e.closed_at, w.created_at) DESC, w.position ASC
        LIMIT $limit";

$res = @$conn->query($sql);
if (!$res) {
    // closed_at may be missing
    $sql = "SELECT w.user_id, w.position, w.photo_path, w.created_at,
                   u.full_name AS winner_name,
                   e.id AS event_id, e.title AS event_name
            FROM event_winners w
            INNER JOIN users u ON u.id = w.user_id
            INNER JOIN events e ON e.id = w.event_id
            WHERE e.status = 'closed'
              AND w.photo_path IS NOT NULL
              AND w.photo_path != ''
            ORDER BY w.created_at DESC, w.position ASC
            LIMIT $limit";
    $res = $conn->query($sql);
}

$data = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $data[] = [
            'user_id' => (int) $row['user_id'],
            'winner_name' => $row['winner_name'],
            'position' => (int) $row['position'],
            'event_id' => (int) $row['event_id'],
            'event_name' => $row['event_name'],
            'photo_path' => $row['photo_path'],
            'photo_url' => admin_public_file_url($row['photo_path']),
            'created_at' => $row['created_at'],
            'closed_at' => $row['closed_at'] ?? null,
        ];
    }
}

echo json_encode(['status' => 'success', 'count' => count($data), 'data' => $data]);
