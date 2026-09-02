<?php
/**
 * Shared public URL builder for files under the admin folder.
 * Same pattern as certificates_admin_base_url() in certificates.php.
 */

function admin_public_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $script = str_replace('\\', '/', $script);
    // .../admin/api/*.php -> .../admin
    $adminBase = dirname(dirname($script));
    if ($adminBase === '/' || $adminBase === '.' || $adminBase === '') {
        $adminBase = '';
    }
    return $scheme . '://' . $host . rtrim($adminBase, '/');
}

function admin_public_file_url(?string $file_path): string
{
    if ($file_path === null || $file_path === '') {
        return '';
    }
    $file_path = str_replace('\\', '/', trim($file_path));
    if (preg_match('#^https?://#i', $file_path)) {
        return $file_path;
    }
    $file_path = ltrim($file_path, '/');
    $base = admin_public_base_url();
    return $base === '' ? $file_path : ($base . '/' . $file_path);
}
