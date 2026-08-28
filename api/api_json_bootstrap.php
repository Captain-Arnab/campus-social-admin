<?php
/**
 * Ensures API responses stay valid JSON (no PHP notices/warnings/fatal HTML).
 * Included from db.php for all HTTP API requests.
 */
if (php_sapi_name() === 'cli') {
    return;
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    error_log(sprintf('[api] PHP error (%d): %s in %s:%d', $severity, $message, $file, $line));
    return true;
});

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        '[api] Uncaught %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
        'status'  => 'error',
        'message' => 'Internal server error',
    ]);
    exit();
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    error_log(sprintf('[api] Fatal shutdown: %s in %s:%d', $err['message'], $err['file'], $err['line']));
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status'  => 'error',
        'message' => 'Internal server error',
    ]);
});
