<?php
/**
 * Institutions API — list active institutions for app registration / signup.
 *
 * GET ?action=list
 * Returns: { "status": "success", "institutions": [ { id, name, short_code, logo_url }, ... ] }
 */
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$action = $_GET['action'] ?? 'list';

if ($action !== 'list') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit();
}

$table_check = $conn->query("SHOW TABLES LIKE 'institutions'");
if (!$table_check || $table_check->num_rows === 0) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Institutions not installed. Run uploads/migrations/002_institutions_multi_support.sql',
    ]);
    exit();
}

$result = $conn->query(
    "SELECT id, name, short_code, logo_url
     FROM institutions
     WHERE status = 'active'
     ORDER BY name ASC"
);

$institutions = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $institutions[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'short_code' => $row['short_code'],
            'logo_url' => $row['logo_url'],
        ];
    }
}

echo json_encode([
    'status' => 'success',
    'institutions' => $institutions,
]);
