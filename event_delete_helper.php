<?php
/**
 * Event hard-delete helpers.
 *
 * Cascades related rows + removes uploaded files from disk.
 * event_hard_delete() also writes deleted_events_log + text log (rejected / expired_pending).
 */

/**
 * Ensure deleted_events_log exists (idempotent).
 */
function schema_ensure_deleted_events_log(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    @$conn->query(
        "CREATE TABLE IF NOT EXISTS `deleted_events_log` (
          `id` int NOT NULL AUTO_INCREMENT,
          `event_id` int NOT NULL,
          `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
          `organizer_id` int DEFAULT NULL,
          `reason` enum('rejected','expired_pending') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
          `deleted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_del_events_reason` (`reason`),
          KEY `idx_del_events_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $done = true;
}

/**
 * Resolve a stored relative/absolute path to a real file under the admin tree.
 */
function event_delete_resolve_file_path(string $path): ?string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || strpos($path, '..') !== false) {
        return null;
    }
    $adminRoot = __DIR__;

    $candidates = [];
    if (preg_match('#^(uploads/|assets/)#', $path)) {
        $candidates[] = $adminRoot . '/' . $path;
    } elseif (strpos($path, '/') === false && strpos($path, '\\') === false) {
        // Bare banner filename
        $candidates[] = $adminRoot . '/uploads/events/' . $path;
    } else {
        $candidates[] = $adminRoot . '/' . ltrim($path, '/');
        if (is_file($path)) {
            $candidates[] = $path;
        }
    }

    foreach ($candidates as $cand) {
        $real = realpath($cand);
        if ($real !== false && is_file($real)) {
            // Must stay under admin root
            $rootReal = realpath($adminRoot);
            if ($rootReal !== false && strpos($real, $rootReal) === 0) {
                return $real;
            }
        }
    }
    return null;
}

/**
 * Collect on-disk file paths tied to an event (before DB rows are removed).
 *
 * @return list<string> absolute paths that exist
 */
function event_collect_files_for_delete(mysqli $conn, int $event_id): array
{
    $files = [];
    $add = static function (?string $p) use (&$files): void {
        if ($p === null || trim($p) === '') {
            return;
        }
        $resolved = event_delete_resolve_file_path($p);
        if ($resolved !== null) {
            $files[$resolved] = true;
        }
    };

    $ev = @$conn->query('SELECT banners FROM events WHERE id = ' . (int) $event_id . ' LIMIT 1');
    if ($ev && ($row = $ev->fetch_assoc())) {
        $banners = json_decode((string) ($row['banners'] ?? '[]'), true);
        if (is_array($banners)) {
            foreach ($banners as $b) {
                $add(is_string($b) ? $b : null);
            }
        }
    }

    $eid = (int) $event_id;
    $tables = [
        "SELECT file_path AS p FROM event_review_files WHERE event_id = $eid",
        "SELECT file_path AS p FROM event_certificates WHERE event_id = $eid",
        "SELECT file_path AS p FROM meeting_minutes WHERE event_id = $eid AND file_path IS NOT NULL AND file_path != ''",
        "SELECT minutes_file_path AS p FROM event_pending_edits WHERE event_id = $eid AND minutes_file_path IS NOT NULL AND minutes_file_path != ''",
        "SELECT photo_path AS p FROM event_winners WHERE event_id = $eid AND photo_path IS NOT NULL AND photo_path != ''",
    ];
    foreach ($tables as $sql) {
        $r = @$conn->query($sql);
        if (!$r) {
            continue;
        }
        while ($row = $r->fetch_assoc()) {
            $add($row['p'] ?? null);
        }
    }

    return array_keys($files);
}

/**
 * @param list<string> $absolutePaths
 */
function event_delete_uploaded_files(array $absolutePaths): int
{
    $n = 0;
    foreach ($absolutePaths as $path) {
        if (is_string($path) && is_file($path) && @unlink($path)) {
            $n++;
        }
    }
    return $n;
}

/**
 * Remove dependent rows for an event so the event can be deleted
 * even when FKs lack ON DELETE CASCADE.
 */
function admin_delete_event_dependents(mysqli $conn, int $event_id): void
{
    if ($event_id <= 0) {
        return;
    }

    $queries = [
        'DELETE FROM favorites WHERE event_id = ?',
        'DELETE FROM attendees WHERE event_id = ?',
        'DELETE FROM volunteers WHERE event_id = ?',
        'DELETE FROM participant WHERE event_id = ?',
        'DELETE FROM event_status_log WHERE event_id = ?',
        'DELETE FROM event_certificates WHERE event_id = ?',
        'DELETE FROM event_editors WHERE event_id = ?',
        'DELETE FROM event_winners WHERE event_id = ?',
        'DELETE FROM event_pending_edits WHERE event_id = ?',
        'DELETE FROM event_review_files WHERE event_id = ?',
        'DELETE FROM notification_dates WHERE event_id = ?',
        'DELETE FROM organizer_notifications WHERE event_id = ?',
        'DELETE FROM meeting_minutes WHERE event_id = ?',
        'DELETE FROM event_payments WHERE event_id = ?',
        'DELETE FROM scheduled_notifications WHERE event_id = ?',
        'DELETE FROM user_inbox_notifications WHERE event_id = ?',
    ];

    foreach ($queries as $sql) {
        $st = @$conn->prepare($sql);
        if ($st) {
            $st->bind_param('i', $event_id);
            $st->execute();
            $st->close();
        } else {
            // Table may not exist on older installs — best-effort via plain query
            @$conn->query(str_replace('?', (string) (int) $event_id, $sql));
        }
    }
}

/**
 * Append a line to api/logs/deleted_events.log and PHP error_log.
 */
function event_hard_delete_text_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    error_log('[MiCampus-EventHardDelete] ' . $message);

    $dir = __DIR__ . '/api/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($dir . '/deleted_events.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Hard-delete an event: audit log → cascade DB rows → delete event → unlink files.
 *
 * @param string $reason 'rejected'|'expired_pending'
 * @return bool true when the events row was removed
 */
function event_hard_delete(mysqli $conn, int $event_id, string $reason): bool
{
    if ($event_id <= 0) {
        return false;
    }
    if (!in_array($reason, ['rejected', 'expired_pending'], true)) {
        $reason = 'rejected';
    }

    schema_ensure_deleted_events_log($conn);

    $ev = @$conn->query(
        'SELECT id, title, organizer_id, status FROM events WHERE id = ' . (int) $event_id . ' LIMIT 1'
    );
    if (!$ev || !($event = $ev->fetch_assoc())) {
        return false;
    }

    $title = (string) ($event['title'] ?? '');
    $organizerId = isset($event['organizer_id']) ? (int) $event['organizer_id'] : null;
    $files = event_collect_files_for_delete($conn, $event_id);

    // Audit row BEFORE cascade (event_id is historical only)
    $titleEsc = $conn->real_escape_string(
        function_exists('mb_substr') ? mb_substr($title, 0, 100, 'UTF-8') : substr($title, 0, 100)
    );
    $orgSql = $organizerId && $organizerId > 0 ? (string) $organizerId : 'NULL';
    $reasonEsc = $conn->real_escape_string($reason);
    @$conn->query(
        "INSERT INTO deleted_events_log (event_id, title, organizer_id, reason)
         VALUES ($event_id, '$titleEsc', $orgSql, '$reasonEsc')"
    );

    admin_delete_event_dependents($conn, $event_id);

    $del = $conn->prepare('DELETE FROM events WHERE id = ?');
    if (!$del) {
        return false;
    }
    $del->bind_param('i', $event_id);
    $del->execute();
    $ok = $del->affected_rows === 1;
    $del->close();

    if ($ok) {
        $removedFiles = event_delete_uploaded_files($files);
        event_hard_delete_text_log(
            "event_id={$event_id} title=\"{$title}\" organizer_id=" . ($organizerId ?? 'null')
            . " reason={$reason} files_removed={$removedFiles}"
        );
    }

    return $ok;
}

/**
 * Permanently delete one event and its dependents (no deleted_events_log).
 * Used by user-admin cleanup paths; prefer event_hard_delete() for reject/expiry.
 *
 * @return bool true when the events row was removed
 */
function admin_delete_event_permanently(mysqli $conn, int $event_id): bool
{
    if ($event_id <= 0) {
        return false;
    }

    $files = event_collect_files_for_delete($conn, $event_id);
    admin_delete_event_dependents($conn, $event_id);

    $del = $conn->prepare('DELETE FROM events WHERE id = ?');
    if (!$del) {
        return false;
    }
    $del->bind_param('i', $event_id);
    $del->execute();
    $ok = $del->affected_rows === 1;
    $del->close();

    if ($ok) {
        event_delete_uploaded_files($files);
    }
    return $ok;
}
