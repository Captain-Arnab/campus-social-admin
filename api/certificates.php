<?php
include 'db.php';
require_once __DIR__ . '/../event_date_range_schema.php';

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

/**
 * Forced-download link. Clients should use this instead of the raw file URL so
 * the download reliably completes (proper Content-Disposition + Content-Length).
 */
function certificate_download_url($cert_id) {
    $base = certificates_admin_base_url();
    $path = 'api/download_certificate.php?id=' . (int) $cert_id;
    return $base === '' ? $path : ($base . '/' . $path);
}

/** True when the stored certificate file actually exists on disk. */
function certificate_file_exists($file_path) {
    if ($file_path === null || $file_path === '') {
        return false;
    }
    $rel = str_replace('\\', '/', trim($file_path));
    if (preg_match('#^https?://#i', $rel)) {
        return true; // external URL — assume reachable
    }
    $rel = ltrim($rel, '/');
    if (strpos($rel, '..') !== false) {
        return false;
    }
    return is_file(__DIR__ . '/../' . $rel);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Generate All is admin-panel only (browser-based rendering). Not for Flutter.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    require_once __DIR__ . '/../admin_priv.php';
    if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized — admin session required']);
        exit();
    }
    if (!has_priv('certificates')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
        exit();
    }

    require_once __DIR__ . '/background_jobs_helper.php';
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    $action = $data['action'] ?? ($_GET['action'] ?? '');
    if ($action === 'generate_all') {
        $eid = (int) ($data['event_id'] ?? 0);
        if ($eid <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'event_id required']);
            exit();
        }
        $jobId = bg_jobs_enqueue($conn, 'generate_event_certificates', ['event_id' => $eid]);
        echo json_encode([
            'status' => 'success',
            'message' => 'Certificate generation queued (pending rows for browser Generate All)',
            'job_id' => $jobId,
        ]);
        exit();
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit();
}

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
    $evCols = 'e.title AS event_title, e.event_date';
    if (schema_events_has_event_end_date($conn)) {
        $evCols .= ', e.event_end_date';
    }
    $stmt = $conn->prepare(
        "SELECT c.id, c.event_id, c.type, c.file_path, c.uploaded_at, $evCols
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
        $item = [
            'id' => (int) $row['id'],
            'event_id' => (int) $row['event_id'],
            'event_title' => $row['event_title'],
            'event_date' => $row['event_date'],
            'type' => $row['type'],
            'file_path' => $rel,
            'certificate_url' => $abs,
            'url' => $abs,
            'download_url' => certificate_download_url($row['id']),
            'file_exists' => certificate_file_exists($rel),
            'uploaded_at' => $row['uploaded_at'],
        ];
        if (!empty($row['event_end_date']) && ($row['event_end_date'] ?? '') !== '0000-00-00 00:00:00') {
            $item['event_end_date'] = $row['event_end_date'];
        }
        $list[] = $item;
    }
    $stmt->close();
    echo json_encode(["status" => "success", "count" => count($list), "data" => $list]);
    exit();
}

if ($event_id > 0) {
    $hasStatus = false;
    $chk = @$conn->query("SHOW COLUMNS FROM event_certificates LIKE 'status'");
    $hasStatus = ($chk && $chk->num_rows > 0);
    $statusCol = $hasStatus ? ', c.status' : '';

    $stmt = $conn->prepare(
        "SELECT c.id, c.event_id, c.user_id, c.type, c.file_path, c.uploaded_at{$statusCol}, u.full_name
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
        $ready = $hasStatus
            ? (($row['status'] ?? '') === 'ready' && certificate_file_exists($rel))
            : certificate_file_exists($rel);
        $status = $ready ? 'ready' : 'pending';
        $abs = $ready ? certificate_public_url($rel) : '';
        $list[] = [
            'id' => (int) $row['id'],
            'event_id' => (int) $row['event_id'],
            'user_id' => (int) $row['user_id'],
            'full_name' => $row['full_name'],
            'type' => $row['type'],
            'status' => $status,
            'file_path' => $rel,
            'certificate_url' => $abs,
            'url' => $abs,
            'download_url' => $ready ? certificate_download_url($row['id']) : '',
            'file_exists' => $ready,
            'uploaded_at' => $row['uploaded_at'],
        ];
    }
    $stmt->close();
    echo json_encode(["status" => "success", "count" => count($list), "data" => $list]);
    exit();
}

echo json_encode(["status" => "error", "message" => "Provide user_id (or id) or event_id"]);
