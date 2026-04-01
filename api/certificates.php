<?php
include 'db.php';

/**
 * Public URL prefix for files under the admin folder (uploads/certificates/...).
 * Derived from this script's URL so it works behind subfolders and common proxies.
 */
function certificates_admin_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $script = str_replace('\\', '/', $script);
    // .../admin/api/certificates.php -> .../admin
    $adminBase = dirname(dirname($script));
    if ($adminBase === '/' || $adminBase === '.' || $adminBase === '') {
        $adminBase = '';
    }
    return $scheme . '://' . $host . rtrim($adminBase, '/');
}

function certificate_public_url($file_path) {
    if ($file_path === null || $file_path === '') {
        return '';
    }
    $file_path = str_replace('\\', '/', trim($file_path));
    if (preg_match('#^https?://#i', $file_path)) {
        return $file_path;
    }
    $file_path = ltrim($file_path, '/');
    $base = certificates_admin_base_url();
    return $base === '' ? $file_path : ($base . '/' . $file_path);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit();
}

// App may use user_id (same as other endpoints) or id (same as users.php profile)
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
if ($user_id <= 0 && isset($_GET['id'])) {
    $user_id = intval($_GET['id']);
}
$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;

if ($user_id > 0) {
    $stmt = $conn->prepare(
        "SELECT c.id, c.event_id, c.type, c.file_path, c.uploaded_at, e.title AS event_title, e.event_date
         FROM event_certificates c
         INNER JOIN events e ON c.event_id = e.id
         WHERE c.user_id = ?
         ORDER BY c.uploaded_at DESC"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $list = [];
    while ($row = $result->fetch_assoc()) {
        $rel = $row['file_path'];
        $abs = certificate_public_url($rel);
        $list[] = [
            'id' => (int) $row['id'],
            'event_id' => (int) $row['event_id'],
            'event_title' => $row['event_title'],
            'event_date' => $row['event_date'],
            'type' => $row['type'],
            'file_path' => $rel,
            'certificate_url' => $abs,
            'url' => $abs,
            'uploaded_at' => $row['uploaded_at'],
        ];
    }
    $stmt->close();
    echo json_encode(["status" => "success", "count" => count($list), "data" => $list]);
    exit();
}

if ($event_id > 0) {
    $stmt = $conn->prepare(
        "SELECT c.id, c.event_id, c.user_id, c.type, c.file_path, c.uploaded_at, u.full_name
         FROM event_certificates c
         INNER JOIN users u ON c.user_id = u.id
         WHERE c.event_id = ?
         ORDER BY c.type, u.full_name"
    );
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $list = [];
    while ($row = $result->fetch_assoc()) {
        $rel = $row['file_path'];
        $abs = certificate_public_url($rel);
        $list[] = [
            'id' => (int) $row['id'],
            'event_id' => (int) $row['event_id'],
            'user_id' => (int) $row['user_id'],
            'full_name' => $row['full_name'],
            'type' => $row['type'],
            'file_path' => $rel,
            'certificate_url' => $abs,
            'url' => $abs,
            'uploaded_at' => $row['uploaded_at'],
        ];
    }
    $stmt->close();
    echo json_encode(["status" => "success", "count" => count($list), "data" => $list]);
    exit();
}

echo json_encode(["status" => "error", "message" => "Provide user_id (or id) or event_id"]);
