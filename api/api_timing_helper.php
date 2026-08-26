<?php
/**
 * Lightweight response-time logging for auth and other hot API paths.
 * Table is created on first use (safe if migration already ran).
 */

/**
 * @param mysqli $conn
 */
function api_timing_ensure_table($conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $sql = "CREATE TABLE IF NOT EXISTS `api_request_log` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `endpoint` VARCHAR(128) NOT NULL,
      `action` VARCHAR(64) NOT NULL DEFAULT '',
      `duration_ms` INT UNSIGNED NOT NULL,
      `http_status` SMALLINT UNSIGNED NOT NULL DEFAULT 200,
      `outcome` VARCHAR(32) NOT NULL DEFAULT 'ok',
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_endpoint_action_time` (`endpoint`(64), `action`, `created_at`),
      KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $ok = (bool) @$conn->query($sql);
    if (!$ok) {
        error_log('[api_timing] ensure_table failed: ' . $conn->error);
    }
    return $ok;
}

function api_timing_start(): float
{
    return microtime(true);
}

/**
 * Log one request timing row (non-blocking best effort).
 *
 * @param mysqli $conn
 */
function api_timing_log($conn, string $endpoint, string $action, float $t0, int $httpStatus = 200, string $outcome = 'ok'): void
{
    if (!api_timing_ensure_table($conn)) {
        return;
    }
    $ms = (int) round(max(0, (microtime(true) - $t0) * 1000));
    $endpoint = substr(trim($endpoint), 0, 128);
    $action = substr(trim($action), 0, 64);
    $outcome = substr(trim($outcome), 0, 32);
    if ($outcome === '') {
        $outcome = 'ok';
    }
    $stmt = $conn->prepare(
        'INSERT INTO api_request_log (endpoint, action, duration_ms, http_status, outcome)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('[api_timing] prepare failed: ' . $conn->error);
        return;
    }
    $stmt->bind_param('ssiis', $endpoint, $action, $ms, $httpStatus, $outcome);
    if (!$stmt->execute()) {
        error_log('[api_timing] insert failed: ' . $stmt->error);
    }
    $stmt->close();
    error_log(sprintf('[api_timing] %s action=%s %dms outcome=%s', $endpoint, $action, $ms, $outcome));
}

/**
 * Register shutdown handler so all exit()/die paths are timed.
 *
 * @param mysqli $conn
 */
function api_timing_register_shutdown($conn, string $endpoint, string $action, float $t0): void
{
    register_shutdown_function(function () use ($conn, $endpoint, $action, $t0) {
        $code = http_response_code();
        if ($code === false) {
            $code = 200;
        }
        api_timing_log($conn, $endpoint, $action, $t0, (int) $code);
    });
}

/**
 * Percentile report for an endpoint/action over the last N days.
 *
 * @param mysqli $conn
 * @return array{p50: ?float, p95: ?float, p99: ?float, count: int, window_days: int}|null
 */
function api_timing_percentiles($conn, string $endpoint, string $action, int $days = 7): ?array
{
    if (!api_timing_ensure_table($conn)) {
        return null;
    }
    $days = max(1, min(90, $days));
    $endpoint = substr(trim($endpoint), 0, 128);
    $action = substr(trim($action), 0, 64);

    $countStmt = $conn->prepare(
        'SELECT COUNT(*) AS c FROM api_request_log
          WHERE endpoint = ? AND action = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if (!$countStmt) {
        return null;
    }
    $countStmt->bind_param('ssi', $endpoint, $action, $days);
    $countStmt->execute();
    $count = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $countStmt->close();

    if ($count === 0) {
        return ['p50' => null, 'p95' => null, 'p99' => null, 'count' => 0, 'window_days' => $days];
    }

    $pct = function (float $p) use ($conn, $endpoint, $action, $days, $count): ?float {
        $offset = (int) max(0, min($count - 1, (int) ceil($p * $count) - 1));
        $stmt = $conn->prepare(
            'SELECT duration_ms FROM api_request_log
              WHERE endpoint = ? AND action = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              ORDER BY duration_ms ASC
              LIMIT 1 OFFSET ?'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ssii', $endpoint, $action, $days, $offset);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (float) $row['duration_ms'] : null;
    };

    return [
        'p50'         => $pct(0.50),
        'p95'         => $pct(0.95),
        'p99'         => $pct(0.99),
        'count'         => $count,
        'window_days'   => $days,
    ];
}
