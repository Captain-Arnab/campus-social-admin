<?php
/**
 * User social / portfolio links (max 5 per user).
 *
 * POST ?action=add    { user_id, url, label? }
 * POST ?action=delete { id, user_id }
 * GET  ?action=list&user_id=
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

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

$chk = @$conn->query("SHOW TABLES LIKE 'user_links'");
if (!$chk || $chk->num_rows === 0) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'user_links not installed. Run uploads/migrations/004_user_links.sql']);
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

$action = strtolower(trim((string) ($data['action'] ?? $_GET['action'] ?? 'list')));

function user_links_fetch(mysqli $conn, int $userId): array
{
    $out = [];
    $stmt = $conn->prepare(
        'SELECT id, user_id, url, label, sort_order, created_at FROM user_links WHERE user_id = ? ORDER BY sort_order ASC, id ASC'
    );
    if (!$stmt) {
        return $out;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'url' => $row['url'],
            'label' => $row['label'],
            'sort_order' => (int) $row['sort_order'],
            'created_at' => $row['created_at'],
        ];
    }
    $stmt->close();
    return $out;
}

if ($method === 'GET' || $action === 'list') {
    $userId = (int) ($data['user_id'] ?? $_GET['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'user_id is required']);
        exit();
    }
    $links = user_links_fetch($conn, $userId);
    echo json_encode(['status' => 'success', 'count' => count($links), 'links' => $links]);
    exit();
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

if ($action === 'add') {
    $userId = (int) ($data['user_id'] ?? 0);
    $url = trim((string) ($data['url'] ?? ''));
    $label = trim((string) ($data['label'] ?? ''));
    if ($userId <= 0 || $url === '') {
        echo json_encode(['status' => 'error', 'message' => 'user_id and url are required']);
        exit();
    }
    if (!preg_match('#^https?://#i', $url)) {
        // Allow bare domains by prefixing https
        if (preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/.*)?$#i', $url)) {
            $url = 'https://' . $url;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Enter a valid URL']);
            exit();
        }
    }
    if (strlen($url) > 500) {
        echo json_encode(['status' => 'error', 'message' => 'URL is too long']);
        exit();
    }
    $cnt = $conn->query('SELECT COUNT(*) AS c FROM user_links WHERE user_id = ' . $userId);
    $n = $cnt ? (int) $cnt->fetch_assoc()['c'] : 0;
    if ($n >= 5) {
        echo json_encode(['status' => 'error', 'message' => 'Maximum 5 links allowed']);
        exit();
    }
    $labelVal = $label !== '' ? $label : null;
    $sort = $n;
    $stmt = $conn->prepare('INSERT INTO user_links (user_id, url, label, sort_order) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('issi', $userId, $url, $labelVal, $sort);
    // mysqli may not accept null label well — use empty string
    if ($labelVal === null) {
        $stmt->close();
        $empty = '';
        $stmt = $conn->prepare('INSERT INTO user_links (user_id, url, label, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('issi', $userId, $url, $empty, $sort);
    }
    if (!$stmt->execute()) {
        echo json_encode(['status' => 'error', 'message' => 'Could not add link']);
        exit();
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();
    echo json_encode([
        'status' => 'success',
        'message' => 'Link added',
        'link' => [
            'id' => $id,
            'user_id' => $userId,
            'url' => $url,
            'label' => $label !== '' ? $label : '',
            'sort_order' => $sort,
        ],
        'links' => user_links_fetch($conn, $userId),
    ]);
    exit();
}

if ($action === 'delete') {
    $id = (int) ($data['id'] ?? 0);
    $userId = (int) ($data['user_id'] ?? 0);
    if ($id <= 0 || $userId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'id and user_id are required']);
        exit();
    }
    $stmt = $conn->prepare('DELETE FROM user_links WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$ok) {
        echo json_encode(['status' => 'error', 'message' => 'Link not found']);
        exit();
    }
    echo json_encode([
        'status' => 'success',
        'message' => 'Link deleted',
        'links' => user_links_fetch($conn, $userId),
    ]);
    exit();
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
