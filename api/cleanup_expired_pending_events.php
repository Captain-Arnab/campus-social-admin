<?php
/**
 * cleanup_expired_pending_events.php
 *
 * Hard-deletes events that are still status=pending after their registration
 * deadline (or event_date when registration_deadline is NULL) has passed.
 *
 * Recommended cron (hourly — less urgent than background jobs / FCM):
 *   0 * * * * cd /path/to/admin/api && php cleanup_expired_pending_events.php >> /var/log/micampus_cleanup.log 2>&1
 *
 * HTTP (dev/testing) — protect with CRON_SECRET:
 *   GET /admin/api/cleanup_expired_pending_events.php?secret=YOUR_SECRET
 *
 * Scope: status='pending' only. Does NOT touch approved / hold / closed / rejected.
 */

date_default_timezone_set('Asia/Kolkata');

if (php_sapi_name() !== 'cli') {
    $secret = getenv('CRON_SECRET') ?: 'changeme_in_production';
    $given  = $_GET['secret'] ?? ($_SERVER['HTTP_X_CRON_SECRET'] ?? '');
    if (!hash_equals($secret, (string) $given)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden']);
        exit();
    }
    header('Content-Type: application/json');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../event_delete_helper.php';
require_once __DIR__ . '/../event_date_range_schema.php';

function cleanup_log(string $level, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $msg;
    if (php_sapi_name() === 'cli') {
        echo $line . PHP_EOL;
    }
    error_log('[MiCampus-CleanupPending] ' . $msg);

    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($dir . '/cleanup_expired_pending.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

if (!isset($conn) || !$conn) {
    cleanup_log('ERROR', 'Database connection failed');
    exit(1);
}

@$conn->query("SET time_zone = '+05:30'");

$hasDeadline = schema_events_has_registration_deadline($conn);

if ($hasDeadline) {
    // registration_deadline set and past → delete
    // registration_deadline NULL/empty → fall back to event_date past (per product rule for this cleanup)
    $sql = "SELECT id, title, organizer_id, event_date, registration_deadline, status
            FROM events
            WHERE status = 'pending'
              AND (
                    (registration_deadline IS NOT NULL
                     AND registration_deadline != '0000-00-00 00:00:00'
                     AND registration_deadline < NOW())
                 OR ((registration_deadline IS NULL
                      OR registration_deadline = '0000-00-00 00:00:00')
                     AND event_date < NOW())
              )
            ORDER BY id ASC
            LIMIT 200";
} else {
    $sql = "SELECT id, title, organizer_id, event_date, status
            FROM events
            WHERE status = 'pending' AND event_date < NOW()
            ORDER BY id ASC
            LIMIT 200";
}

$res = $conn->query($sql);
if (!$res) {
    cleanup_log('ERROR', 'Query failed: ' . $conn->error);
    exit(1);
}

$candidates = [];
while ($row = $res->fetch_assoc()) {
    $candidates[] = $row;
}

$summary = [
    'scanned' => count($candidates),
    'deleted' => 0,
    'failed' => 0,
    'events' => [],
];

cleanup_log('INFO', 'Starting cleanup; candidates=' . count($candidates));

foreach ($candidates as $row) {
    $eid = (int) $row['id'];
    $title = (string) ($row['title'] ?? '');
    try {
        $conn->begin_transaction();
        $ok = event_hard_delete($conn, $eid, 'expired_pending');
        if (!$ok) {
            throw new RuntimeException('event_hard_delete returned false');
        }
        $conn->commit();
        $summary['deleted']++;
        $summary['events'][] = ['id' => $eid, 'title' => $title, 'reason' => 'expired_pending'];
        cleanup_log('INFO', "DELETED event_id={$eid} title=\"{$title}\" reason=expired_pending");
    } catch (Throwable $e) {
        $conn->rollback();
        $summary['failed']++;
        cleanup_log('ERROR', "FAILED event_id={$eid} title=\"{$title}\": " . $e->getMessage());
    }
}

cleanup_log('INFO', 'Done. deleted=' . $summary['deleted'] . ' failed=' . $summary['failed']);

if (php_sapi_name() !== 'cli') {
    echo json_encode(['status' => 'success', 'summary' => $summary]);
}

exit($summary['failed'] > 0 ? 1 : 0);
