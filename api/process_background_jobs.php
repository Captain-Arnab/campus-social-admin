<?php
/**
 * process_background_jobs.php
 * Cron worker — drains background_jobs (event_created side-effects, etc.).
 *
 * Recommended cron (every minute):
 *   * * * * * cd /path/to/admin/api && php process_background_jobs.php >> /var/log/micampus_jobs.log 2>&1
 *
 * HTTP (dev/testing) — protect with CRON_SECRET:
 *   GET /admin/api/process_background_jobs.php?secret=YOUR_SECRET&limit=10
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
require_once __DIR__ . '/background_jobs_helper.php';
require_once __DIR__ . '/sms_helper.php';

if (!isset($conn) || !$conn) {
    jobs_log('ERROR', 'Database connection failed');
    exit(1);
}

$limit = 10;
if (php_sapi_name() === 'cli') {
    foreach ($argv as $arg) {
        if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
            $limit = (int) $m[1];
        }
    }
} else {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
}
$limit = max(1, min(50, $limit));

$jobs = bg_jobs_claim($conn, $limit);
$summary = [
    'claimed' => count($jobs),
    'done'    => 0,
    'failed'  => 0,
    'results' => [],
];

foreach ($jobs as $job) {
    $id       = (int) $job['id'];
    $type     = (string) $job['job_type'];
    $attempts = (int) $job['attempts'];
    $maxAtt   = (int) $job['max_attempts'];
    $payload  = json_decode((string) $job['payload_json'], true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $t0 = microtime(true);
    try {
        switch ($type) {
            case 'event_created_notify':
                process_job_event_created_notify($conn, $payload);
                break;
            case 'event_banners_finalize':
                process_job_event_banners_finalize($conn, $payload);
                break;
            case 'login_otp_sms':
                $payload['job_id'] = $id;
                process_job_login_otp_sms($payload, $conn, 15);
                break;
            default:
                throw new RuntimeException('Unknown job_type: ' . $type);
        }
        bg_jobs_mark_done($conn, $id);
        $summary['done']++;
        $ms = round((microtime(true) - $t0) * 1000, 1);
        jobs_log('INFO', "DONE job={$id} type={$type} in {$ms}ms");
        $summary['results'][] = ['id' => $id, 'type' => $type, 'status' => 'done', 'ms' => $ms];
    } catch (Throwable $e) {
        bg_jobs_mark_failed($conn, $id, $e->getMessage(), $attempts, $maxAtt);
        $summary['failed']++;
        jobs_log('ERROR', "FAIL job={$id} type={$type} attempt={$attempts}/{$maxAtt}: " . $e->getMessage());
        $summary['results'][] = [
            'id'     => $id,
            'type'   => $type,
            'status' => 'failed',
            'error'  => $e->getMessage(),
        ];
    }
}

if (php_sapi_name() !== 'cli') {
    echo json_encode(['status' => 'ok', 'summary' => $summary], JSON_UNESCAPED_UNICODE);
} else {
    jobs_log('INFO', 'Batch complete claimed=' . $summary['claimed']
        . ' done=' . $summary['done'] . ' failed=' . $summary['failed']);
}

// ─── Job handlers ────────────────────────────────────────────────────────────

/**
 * Slow path moved off POST /events.php create:
 *  - admin SMS
 *  - inbox row per active user
 *  - FCM to all active tokens (per-token HTTP — can take many seconds)
 *
 * @param mysqli $conn
 * @param array<string,mixed> $payload
 */
function process_job_event_created_notify($conn, array $payload): void
{
    $eventId  = (int) ($payload['event_id'] ?? 0);
    $title    = (string) ($payload['title'] ?? '');
    $category = (string) ($payload['category'] ?? '');
    $venue    = (string) ($payload['venue'] ?? '');

    if ($eventId <= 0) {
        throw new InvalidArgumentException('event_id required');
    }

    $t = microtime(true);
    if ($title !== '') {
        sms_notify_admins_event_created($conn, $title);
    }
    jobs_log('INFO', sprintf('SMS block %.1fms event_id=%d', (microtime(true) - $t) * 1000, $eventId));

    $t = microtime(true);
    $inboxHelper = __DIR__ . '/app_inbox_notifications_helper.php';
    if (is_readable($inboxHelper)) {
        require_once $inboxHelper;
        campus_inbox_after_event_created($conn, $eventId, $title, $category, $venue);
    }
    jobs_log('INFO', sprintf('Inbox+FCM block %.1fms event_id=%d', (microtime(true) - $t) * 1000, $eventId));
}

/**
 * Optional banner finalize: moves staging files into uploads/events/ and
 * updates events.banners. Create path already move_uploaded_file()'s to final
 * names when possible; this handles staged filenames if present.
 *
 * Payload:
 *  - event_id (int)
 *  - staged_files (string[]) relative basenames under uploads/events/_staging/
 *
 * @param mysqli $conn
 * @param array<string,mixed> $payload
 */
function process_job_event_banners_finalize($conn, array $payload): void
{
    $eventId = (int) ($payload['event_id'] ?? 0);
    $staged  = $payload['staged_files'] ?? [];
    if ($eventId <= 0 || !is_array($staged) || $staged === []) {
        throw new InvalidArgumentException('event_id and staged_files required');
    }

    $stagingDir = realpath(__DIR__ . '/../uploads/events/_staging');
    $finalDir   = realpath(__DIR__ . '/../uploads/events');
    if ($stagingDir === false || $finalDir === false) {
        throw new RuntimeException('uploads/events directories missing');
    }

    $finalNames = [];
    foreach ($staged as $name) {
        $base = basename((string) $name);
        if ($base === '' || $base === '.' || $base === '..') {
            continue;
        }
        $from = $stagingDir . DIRECTORY_SEPARATOR . $base;
        $to   = $finalDir . DIRECTORY_SEPARATOR . $base;
        if (!is_file($from)) {
            // Already moved or missing — keep name if final exists
            if (is_file($to)) {
                $finalNames[] = $base;
            }
            continue;
        }
        if (!@rename($from, $to)) {
            if (!@copy($from, $to)) {
                throw new RuntimeException('Failed to promote banner: ' . $base);
            }
            @unlink($from);
        }
        $finalNames[] = $base;
    }

    $json = json_encode($finalNames, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare('UPDATE events SET banners = ? WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('si', $json, $eventId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('update banners failed: ' . $err);
    }
    $stmt->close();
}

function jobs_log(string $level, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$msg}";
    error_log($line);
    if (php_sapi_name() === 'cli') {
        echo $line . PHP_EOL;
    }
}
