<?php
/**
 * GET: Fetch active advertisement posts for app home screen.
 * Returns JSON array of active ads sorted by sort_order.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

$result = $conn->query("SELECT id, title, media_type, media_url, link_url, sort_order FROM ad_posts WHERE is_active = 1 ORDER BY sort_order ASC, created_at DESC");

$posts = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $posts[] = $row;
    }
}

echo json_encode(['status' => 'success', 'count' => count($posts), 'data' => $posts]);
