<?php
/**
 * Save the certificate template default seal / star graphic.
 */
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
if (!has_priv('certificates')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

const MAX_SIZE = 2 * 1024 * 1024;
$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

if (!isset($_FILES['seal']) || $_FILES['seal']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No seal image uploaded']);
    exit;
}

$file = $_FILES['seal'];
if ($file['size'] > MAX_SIZE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Seal image must be 2 MB or less']);
    exit;
}

$mime = null;
if (class_exists('finfo')) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($file['tmp_name']);
}
if ($mime === null && function_exists('getimagesize')) {
    $info = @getimagesize($file['tmp_name']);
    if ($info && !empty($info['mime'])) {
        $mime = (string) $info['mime'];
    }
}
if ($mime === 'image/jpg') {
    $mime = 'image/jpeg';
}
if ($mime === null || !in_array($mime, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Only JPEG, PNG, GIF, WebP, or SVG allowed']);
    exit;
}

$ext_map = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/svg+xml' => 'svg',
];
$ext = $ext_map[$mime];

$dir = __DIR__ . '/uploads/certificates';
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not create upload folder']);
    exit;
}

foreach (glob($dir . '/brand_seal.*') ?: [] as $old) {
    @unlink($old);
}

$relative = 'uploads/certificates/brand_seal.' . $ext;
$path = __DIR__ . '/' . $relative;

if (!move_uploaded_file($file['tmp_name'], $path)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to save seal']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => 'Default certificate seal saved',
    'seal_url' => $relative . '?v=' . time(),
]);
