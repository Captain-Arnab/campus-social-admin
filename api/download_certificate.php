<?php
/**
 * Stream a saved e-certificate as a forced download.
 *
 * Why this exists: opening the raw file URL inside the app's in-app browser can
 * spin forever (no Content-Disposition / Content-Length, so the client never
 * knows the response finished). This endpoint always sends the right headers so
 * the download completes and is saved to the device.
 *
 * GET params:
 *   id       certificate id (event_certificates.id)   — preferred
 *   or event_id + user_id [+ type]                     — fallback lookup
 *   inline=1 to display in the browser instead of forcing a download
 */

include 'db.php';

/** Emit a JSON error and stop (only used before any file bytes are sent). */
function cert_dl_fail(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    cert_dl_fail(405, 'Method not allowed');
}

$cert_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$type = isset($_GET['type']) && in_array($_GET['type'], ['participant', 'volunteer'], true)
    ? $_GET['type']
    : '';

$row = null;
if ($cert_id > 0) {
    $stmt = $conn->prepare(
        'SELECT c.id, c.file_path, c.type, e.title AS event_title, u.full_name
         FROM event_certificates c
         INNER JOIN events e ON c.event_id = e.id
         INNER JOIN users u ON c.user_id = u.id
         WHERE c.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $cert_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} elseif ($event_id > 0 && $user_id > 0) {
    $sql = 'SELECT c.id, c.file_path, c.type, e.title AS event_title, u.full_name
            FROM event_certificates c
            INNER JOIN events e ON c.event_id = e.id
            INNER JOIN users u ON c.user_id = u.id
            WHERE c.event_id = ? AND c.user_id = ?';
    if ($type !== '') {
        $sql .= " AND c.type = '" . $conn->real_escape_string($type) . "'";
    }
    $sql .= ' ORDER BY c.uploaded_at DESC LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $event_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    cert_dl_fail(400, 'Provide id, or event_id and user_id');
}

if (!$row) {
    cert_dl_fail(404, 'Certificate not found');
}

// file_path is stored relative to the admin folder (e.g. uploads/certificates/x.png).
$rel = str_replace('\\', '/', ltrim((string) $row['file_path'], '/'));
// Never allow traversal out of the admin folder.
if (strpos($rel, '..') !== false) {
    cert_dl_fail(400, 'Invalid file path');
}
$abs = realpath(__DIR__ . '/../' . $rel);
$base = realpath(__DIR__ . '/../');
if ($abs === false || $base === false || strncmp($abs, $base, strlen($base)) !== 0 || !is_file($abs)) {
    cert_dl_fail(404, 'Certificate file is missing on the server');
}

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime_map = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

// Build a friendly download filename: Certificate_<Event>_<type>.<ext>
$safe = function (string $s): string {
    $s = preg_replace('/[^A-Za-z0-9]+/', '_', $s);
    return trim((string) $s, '_');
};
$name_parts = array_filter([
    'Certificate',
    $safe((string) ($row['event_title'] ?? '')),
    $safe((string) ($row['type'] ?? '')),
]);
$download_name = (implode('_', $name_parts) ?: 'certificate') . '.' . ($ext !== '' ? $ext : 'pdf');

$inline = isset($_GET['inline']) && $_GET['inline'] == '1';
$disposition = $inline ? 'inline' : 'attachment';

// Clear any buffered output so the binary is not corrupted.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $download_name . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($abs);
exit();
