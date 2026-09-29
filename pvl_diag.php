<?php
/**
 * TEMPORARY diagnostic for password_view_log.php — delete from the server after use.
 * Only works for a logged-in main admin.
 */
session_start();
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    exit('Sign in as the main admin first, then reload this page.');
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=UTF-8');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo '<pre style="background:#fee;border:2px solid #c00;padding:12px;white-space:pre-wrap">FATAL: '
            . htmlspecialchars($e['message']) . "\nin " . htmlspecialchars($e['file']) . ' line ' . (int) $e['line'] . '</pre>';
    }
});

function diag_row(string $label, bool $ok, string $detail = ''): void
{
    echo '<tr><td>' . ($ok ? '✅' : '❌') . '</td><td>' . htmlspecialchars($label) . '</td><td><code>' . htmlspecialchars($detail) . '</code></td></tr>';
}

echo '<h2 style="font-family:sans-serif">password_view_log diagnostics</h2><table border="1" cellpadding="6" style="border-collapse:collapse;font-family:sans-serif;font-size:14px">';
diag_row('PHP version', PHP_VERSION_ID >= 70400, PHP_VERSION);
diag_row('openssl extension', extension_loaded('openssl'), extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : 'missing');
diag_row('mysqli extension', extension_loaded('mysqli'));
diag_row('PHP error log location', true, (string) ini_get('error_log') ?: '(server default)');

$files = [
    'db.php' => null,
    'admin_priv.php' => 'subadmin_default_privilege_keys',
    'password_vault.php' => 'password_vault_ensure_schema_inner',
    'password_view_log.php' => 'pvl_page_url',
    'sidebar.php' => 'password_view_log.php',
    'portal_auth.php' => 'portal_verify_password',
    'api/password_vault_config.local.php' => null,
];
foreach ($files as $f => $needle) {
    $path = __DIR__ . '/' . $f;
    if (!is_file($path)) {
        diag_row("file $f", false, 'NOT FOUND on server');
        continue;
    }
    $src = (string) @file_get_contents($path);
    $info = filesize($path) . ' bytes, modified ' . date('Y-m-d H:i', filemtime($path)) . ', perms ' . substr(sprintf('%o', fileperms($path)), -4);
    $upToDate = $needle === null || strpos($src, $needle) !== false;
    diag_row("file $f", $upToDate, $upToDate ? $info : "OLD VERSION (missing '$needle') — $info");
    if (strncmp($src, "\xEF\xBB\xBF", 3) === 0) {
        diag_row("file $f encoding", false, 'starts with a UTF-8 BOM');
    }
}

try {
    require __DIR__ . '/db.php';
    diag_row('DB connection', isset($conn) && $conn instanceof mysqli, isset($conn) ? $conn->server_info : 'no $conn');
    $r = $conn->query("SHOW COLUMNS FROM users LIKE 'password_encrypted'");
    diag_row('users.password_encrypted column', $r && $r->num_rows > 0, $r && $r->num_rows > 0 ? 'present' : 'missing');
    $r = $conn->query("SHOW TABLES LIKE 'password_view_log'");
    diag_row('password_view_log table', $r && $r->num_rows > 0, $r && $r->num_rows > 0 ? 'present' : 'missing');
    $g = $conn->query('SHOW GRANTS');
    $grants = [];
    while ($g && ($row = $g->fetch_row())) {
        $grants[] = $row[0];
    }
    $all = implode(' | ', $grants);
    diag_row('DB user can ALTER/CREATE', stripos($all, 'ALL PRIVILEGES') !== false || (stripos($all, 'ALTER') !== false && stripos($all, 'CREATE') !== false), $all);
} catch (Throwable $e) {
    diag_row('DB checks', false, get_class($e) . ': ' . $e->getMessage());
}

if (is_file(__DIR__ . '/password_vault.php')) {
    try {
        require_once __DIR__ . '/password_vault.php';
        diag_row('encryption key configured', password_vault_is_configured(), password_vault_is_configured() ? 'yes' : 'no (page still loads, shows a warning)');
        diag_row('schema ensure', password_vault_ensure_schema($conn), 'see error log line "[password_vault]" if false');
    } catch (Throwable $e) {
        diag_row('password_vault.php', false, get_class($e) . ': ' . $e->getMessage() . ' @ line ' . $e->getLine());
    }
}
echo '</table>';

echo '<h3 style="font-family:sans-serif">Rendering password_view_log.php with errors shown:</h3><div style="border:2px dashed #999;padding:8px">';
try {
    require __DIR__ . '/password_view_log.php';
} catch (Throwable $e) {
    echo '<pre style="background:#fee;border:2px solid #c00;padding:12px;white-space:pre-wrap">EXCEPTION: ' . htmlspecialchars(get_class($e) . ': ' . $e->getMessage())
        . "\nin " . htmlspecialchars($e->getFile()) . ' line ' . $e->getLine() . "\n\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}
echo '</div>';
