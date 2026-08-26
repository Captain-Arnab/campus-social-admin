<?php
/**
 * Lightweight DB-backed job queue for Core PHP (no framework).
 * Used to defer slow side-effects (SMS, inbox fan-out, FCM) off the request path.
 */

/**
 * Ensure background_jobs table exists (safe if migration already ran).
 *
 * @param mysqli $conn
 */
function bg_jobs_ensure_table($conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $sql = "CREATE TABLE IF NOT EXISTS `background_jobs` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `job_type` VARCHAR(64) NOT NULL,
      `payload_json` JSON NOT NULL,
      `status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
      `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
      `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
      `last_error` TEXT NULL,
      `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `locked_at` DATETIME NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_pickup` (`status`, `available_at`, `id`),
      KEY `idx_job_type_status` (`job_type`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $ok = (bool) @$conn->query($sql);
    if (!$ok) {
        error_log('[bg_jobs] ensure_table failed: ' . $conn->error);
    }
    return $ok;
}

/**
 * Enqueue a job. Returns job id or 0 on failure.
 *
 * @param mysqli $conn
 * @param array<string,mixed> $payload
 */
function bg_jobs_enqueue($conn, string $job_type, array $payload, int $delaySeconds = 0, int $maxAttempts = 5): int
{
    if (!bg_jobs_ensure_table($conn)) {
        return 0;
    }
    $job_type = trim($job_type);
    if ($job_type === '') {
        return 0;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        error_log('[bg_jobs] payload json_encode failed');
        return 0;
    }
    $availableAt = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));
    $stmt = $conn->prepare(
        'INSERT INTO background_jobs (job_type, payload_json, max_attempts, available_at)
         VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('[bg_jobs] prepare failed: ' . $conn->error);
        return 0;
    }
    $stmt->bind_param('ssis', $job_type, $json, $maxAttempts, $availableAt);
    $ok = $stmt->execute();
    $id = $ok ? (int) $conn->insert_id : 0;
    if (!$ok) {
        error_log('[bg_jobs] insert failed: ' . $stmt->error);
    }
    $stmt->close();
    return $id;
}

/**
 * Claim up to $limit pending jobs (atomic per-row claim).
 *
 * @param mysqli $conn
 * @return list<array{id:int,job_type:string,payload_json:string,attempts:int,max_attempts:int}>
 */
function bg_jobs_claim($conn, int $limit = 10): array
{
    if (!bg_jobs_ensure_table($conn)) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $now = date('Y-m-d H:i:s');
    $sel = $conn->prepare(
        "SELECT id FROM background_jobs
          WHERE status = 'pending' AND available_at <= ?
          ORDER BY id ASC
          LIMIT {$limit}"
    );
    if (!$sel) {
        return [];
    }
    $sel->bind_param('s', $now);
    $sel->execute();
    $res = $sel->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
    $sel->close();

    $claimed = [];
    foreach ($ids as $id) {
        $upd = $conn->prepare(
            "UPDATE background_jobs
                SET status = 'processing', locked_at = ?, attempts = attempts + 1
              WHERE id = ? AND status = 'pending'"
        );
        if (!$upd) {
            continue;
        }
        $upd->bind_param('si', $now, $id);
        $upd->execute();
        $affected = $upd->affected_rows;
        $upd->close();
        if ($affected !== 1) {
            continue;
        }
        $get = $conn->prepare(
            'SELECT id, job_type, payload_json, attempts, max_attempts
               FROM background_jobs WHERE id = ?'
        );
        if (!$get) {
            continue;
        }
        $get->bind_param('i', $id);
        $get->execute();
        $job = $get->get_result()->fetch_assoc();
        $get->close();
        if ($job) {
            $claimed[] = $job;
        }
    }
    return $claimed;
}

/**
 * Claim a specific pending job by id (for inline OTP processing).
 *
 * @param mysqli $conn
 * @return array{id:int,job_type:string,payload_json:string,attempts:int,max_attempts:int}|null
 */
function bg_jobs_claim_id($conn, int $id): ?array
{
    if ($id <= 0 || !bg_jobs_ensure_table($conn)) {
        return null;
    }
    $now = date('Y-m-d H:i:s');
    $upd = $conn->prepare(
        "UPDATE background_jobs
            SET status = 'processing', locked_at = ?, attempts = attempts + 1
          WHERE id = ? AND status = 'pending'"
    );
    if (!$upd) {
        return null;
    }
    $upd->bind_param('si', $now, $id);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();
    if ($affected !== 1) {
        return null;
    }
    $get = $conn->prepare(
        'SELECT id, job_type, payload_json, attempts, max_attempts
           FROM background_jobs WHERE id = ?'
    );
    if (!$get) {
        return null;
    }
    $get->bind_param('i', $id);
    $get->execute();
    $job = $get->get_result()->fetch_assoc();
    $get->close();
    return $job ?: null;
}

/**
 * Force-fail a job (no retry) — used when OTP must fail loudly to the client.
 *
 * @param mysqli $conn
 */
function bg_jobs_mark_failed_final($conn, int $id, string $error): void
{
    if (function_exists('mb_substr')) {
        $error = mb_substr($error, 0, 2000);
    } else {
        $error = substr($error, 0, 2000);
    }
    $stmt = $conn->prepare(
        "UPDATE background_jobs
            SET status = 'failed', last_error = ?, locked_at = NULL
          WHERE id = ?"
    );
    if ($stmt) {
        $stmt->bind_param('si', $error, $id);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * @param mysqli $conn
 */
function bg_jobs_mark_done($conn, int $id): void
{
    $stmt = $conn->prepare(
        "UPDATE background_jobs SET status = 'done', last_error = NULL, locked_at = NULL WHERE id = ?"
    );
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Mark failed or re-queue with backoff when attempts remain.
 *
 * @param mysqli $conn
 */
function bg_jobs_mark_failed($conn, int $id, string $error, int $attempts, int $maxAttempts): void
{
    if (function_exists('mb_substr')) {
        $error = mb_substr($error, 0, 2000);
    } else {
        $error = substr($error, 0, 2000);
    }
    if ($attempts < $maxAttempts) {
        $backoff = min(900, 30 * (2 ** max(0, $attempts - 1))); // 30s, 60s, 120s...
        $availableAt = date('Y-m-d H:i:s', time() + $backoff);
        $stmt = $conn->prepare(
            "UPDATE background_jobs
                SET status = 'pending', last_error = ?, available_at = ?, locked_at = NULL
              WHERE id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('ssi', $error, $availableAt, $id);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }
    $stmt = $conn->prepare(
        "UPDATE background_jobs
            SET status = 'failed', last_error = ?, locked_at = NULL
          WHERE id = ?"
    );
    if ($stmt) {
        $stmt->bind_param('si', $error, $id);
        $stmt->execute();
        $stmt->close();
    }
}
