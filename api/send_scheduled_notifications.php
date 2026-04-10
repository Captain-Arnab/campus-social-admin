<?php
/**
 * send_scheduled_notifications.php
 * Cron script — processes scheduled notifications and celebration-day greetings.
 *
 * Run via cron every 15 minutes (recommended for scheduled_notifications precision):
 *   IST  → UTC offset is +5:30
 *   Example (runs at :00 and :15 and :30 and :45 every hour):
 *     *\/15 * * * * cd /path/to/api && php send_scheduled_notifications.php >> /var/log/micampus_notif.log 2>&1
 *
 * For greeting-only (daily 10 AM IST = 04:30 UTC):
 *     30 4 * * * cd /path/to/api && php send_scheduled_notifications.php >> /var/log/micampus_notif.log 2>&1
 *
 * Calendar broadcast rule:
 *  - Date-based pushes to all users run ONLY for rows in `celebration_days` where occasion_date = today.
 *  - The `notification_dates` table is not used by this cron (manage days via admin → Celebration days).
 *
 * What changed from original:
 *  - Adds deduplication: uses notification_log to skip already-sent notifications
 *  - Processes scheduled_notifications table (new) with per-minute precision
 *  - Logs every send to notification_log
 *  - Updates scheduled_notifications.status to 'sent' after processing
 *  - Fetches only is_active tokens when column exists
 *  - Clean structured output (timestamp + level + message)
 */
// if (php_sapi_name() !== 'cli') {
//     // Allow HTTP call for testing, but protect with a secret token
//     $secret = getenv('CRON_SECRET') ?: 'changeme_in_production';
//     $given  = $_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
//     if (!hash_equals($secret, $given)) {
//         http_response_code(403);
//         header('Content-Type: application/json');
//         echo json_encode(['error' => 'Forbidden']);
//         exit();
//     }
//     header('Content-Type: application/json');
// }

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/fcm_helper.php';
require_once __DIR__ . '/db.php';

if (!isset($conn) || !$conn) {
    cron_log('ERROR', 'Database connection failed');
    exit(1);
}

$today   = date('Y-m-d');
$nowDt   = date('Y-m-d H:i:s');   // current IST datetime (for scheduled_notifications)
$results = [];

// ─── Helper: check is_active column ──────────────────────────────────────────
$hasActive  = false;
$colCheck   = $conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
if ($colCheck && $colCheck->num_rows > 0) {
    $hasActive = true;
}
$activeFilter = $hasActive ? " AND (is_active = 1 OR is_active IS NULL)" : "";

// ─── Fetch ALL active FCM tokens (for broadcast notifications) ────────────────
$tres      = $conn->query(
    "SELECT fcm_token FROM user_fcm_tokens WHERE 1=1 {$activeFilter}"
);
$allTokens = [];
if ($tres) {
    while ($tr = $tres->fetch_assoc()) {
        $allTokens[] = $tr['fcm_token'];
    }
}
$allTokens = array_values(array_unique(array_filter($allTokens)));
$tokenCount = count($allTokens);
cron_log('INFO', "Loaded {$tokenCount} active FCM tokens");

if (empty($allTokens)) {
    cron_log('WARN', 'No FCM tokens found — no greetings can be sent');
    // Still continue to process scheduled_notifications (they may target subsets)
}

$celebrationHasPush = celebration_days_has_push_columns($conn);

// =============================================================================
// SECTION 1: Process celebration_days (only calendar days listed in DB)
// =============================================================================
$celebrations = [];
$tableCheck   = $conn->query("SHOW TABLES LIKE 'celebration_days'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $selectCols = $celebrationHasPush
        ? 'id, occasion_name, push_title, push_message'
        : 'id, occasion_name';
    $cStmt = $conn->prepare(
        "SELECT {$selectCols} FROM celebration_days WHERE occasion_date = ? ORDER BY id"
    );
    $cStmt->bind_param('s', $today);
    $cStmt->execute();
    $celebrations = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cStmt->close();
}

foreach ($celebrations as $cel) {
    $ref_id = (int)$cel['id'];

    // --- Deduplication ---
    if (already_sent('celebration', $ref_id, $today, $conn)) {
        cron_log('INFO', "SKIP celebration id={$ref_id} '{$cel['occasion_name']}' (already sent)");
        continue;
    }

    $title = 'MiCampus';
    $body  = 'Happy ' . $cel['occasion_name'] . '! 🎉';
    if ($celebrationHasPush) {
        if (!empty(trim((string)($cel['push_title'] ?? '')))) {
            $title = trim((string)$cel['push_title']);
        }
        if (!empty(trim((string)($cel['push_message'] ?? '')))) {
            $body = trim((string)$cel['push_message']);
        }
    }
    $fcmData = ['type' => 'greeting', 'occasion' => $cel['occasion_name']];

    if (empty($allTokens)) {
        cron_log('WARN', "celebration id={$ref_id}: no tokens, skipping send");
        continue;
    }

    $out    = fcm_send_to_tokens($allTokens, $title, $body, $fcmData);
    $status = determine_status($out['success'], $out['failed']);
    $errSum = !empty($out['errors']) ? implode(' | ', array_slice($out['errors'], 0, 5)) : '';

    fcm_log_notification([
        'type'            => 'celebration',
        'ref_id'          => $ref_id,
        'ref_date'        => $today,
        'title'           => $title,
        'body'            => $body,
        'recipient_type'  => 'all',
        'tokens_targeted' => $tokenCount,
        'tokens_sent'     => $out['success'],
        'tokens_failed'   => $out['failed'],
        'status'          => $status,
        'error_message'   => $errSum,
    ]);

    $results[] = "celebration '{$cel['occasion_name']}': sent={$out['success']}, failed={$out['failed']}";
    cron_log('INFO', end($results));
}

// =============================================================================
// SECTION 2: Process scheduled_notifications (admin-scheduled with datetime)
// =============================================================================
$schedCheck = $conn->query("SHOW TABLES LIKE 'scheduled_notifications'");
if ($schedCheck && $schedCheck->num_rows > 0) {

    // Fetch notifications due in the last 16 minutes (handles up to 15-min cron gap)
    $windowStart = date('Y-m-d H:i:s', strtotime('-16 minutes'));
    $snStmt = $conn->prepare(
        "SELECT * FROM scheduled_notifications
          WHERE status = 'pending'
            AND scheduled_at BETWEEN ? AND ?
          ORDER BY scheduled_at ASC"
    );
    $snStmt->bind_param('ss', $windowStart, $nowDt);
    $snStmt->execute();
    $scheduledRows = $snStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $snStmt->close();

    foreach ($scheduledRows as $sn) {
        $sn_id          = (int)$sn['id'];
        $sn_title       = $sn['title'];
        $sn_body        = $sn['body'];
        $sn_rtype       = $sn['recipient_type'];
        $sn_event_id    = $sn['event_id'] ? (int)$sn['event_id'] : null;
        $sn_topic       = $sn['topic'];
        $sn_data        = $sn['data_payload'] ? json_decode($sn['data_payload'], true) : [];
        $sn_data        = is_array($sn_data) ? $sn_data : [];
        $sn_data['type'] = $sn_data['type'] ?? 'scheduled';

        // Mark as sent immediately (optimistic lock — prevents re-processing on overlap)
        $conn->query(
            "UPDATE scheduled_notifications
                SET status = 'sent', sent_at = NOW()
              WHERE id = {$sn_id} AND status = 'pending'"
        );
        if ($conn->affected_rows === 0) {
            // Another process already picked it up
            cron_log('INFO', "SKIP scheduled_notification id={$sn_id} (already processed)");
            continue;
        }

        $out = ['success' => 0, 'failed' => 0];

        // --- Determine target tokens based on recipient_type ---
        if ($sn_rtype === 'topic' && $sn_topic) {
            // Topic send — one HTTP call, no token list needed
            $res = fcm_send_to_topic($sn_topic, $sn_title, $sn_body, $sn_data);
            if ($res['ok']) {
                $out['success'] = 1;
            } else {
                $out['failed']  = 1;
                cron_log('ERROR', "Topic send failed for scheduled_notification id={$sn_id}: {$res['response']}");
            }
            $tokensForLog = 1; // topic = 1 request
        } else {
            // Token-based send
            $targetTokens = get_tokens_for_recipient($sn_rtype, $sn_event_id, $conn, $activeFilter);

            if (empty($targetTokens)) {
                cron_log('WARN', "scheduled_notification id={$sn_id}: no tokens for type={$sn_rtype}");
                $conn->query(
                    "UPDATE scheduled_notifications SET status = 'failed' WHERE id = {$sn_id}"
                );
                continue;
            }

            $out        = fcm_send_to_tokens($targetTokens, $sn_title, $sn_body, $sn_data);
            $tokensForLog = count($targetTokens);
        }

        $status  = determine_status($out['success'], $out['failed']);
        $snErrSum = !empty($out['errors']) ? implode(' | ', array_slice($out['errors'], 0, 5)) : '';
        $log_id  = fcm_log_notification([
            'type'            => 'scheduled',
            'ref_id'          => $sn_id,
            'ref_date'        => $today,
            'title'           => $sn_title,
            'body'            => $sn_body,
            'recipient_type'  => $sn_rtype,
            'event_id'        => $sn_event_id,
            'tokens_targeted' => $tokensForLog,
            'tokens_sent'     => $out['success'],
            'tokens_failed'   => $out['failed'],
            'status'          => $status,
            'error_message'   => $snErrSum,
        ]);

        // Link log entry back to the scheduled_notification row
        if ($log_id) {
            $conn->query(
                "UPDATE scheduled_notifications SET log_id = {$log_id} WHERE id = {$sn_id}"
            );
        }

        if ($status === 'failed') {
            $conn->query(
                "UPDATE scheduled_notifications SET status = 'failed' WHERE id = {$sn_id}"
            );
        }

        $results[] = "scheduled_notification id={$sn_id} [{$sn_rtype}]: sent={$out['success']}, failed={$out['failed']}";
        cron_log('INFO', end($results));
    }
}

$conn->close();

// ─── Summary ─────────────────────────────────────────────────────────────────
if (empty($results)) {
    cron_log('INFO', "Nothing to send for {$today} (no celebration rows for today and no due scheduled_notifications)");
} else {
    cron_log('INFO', 'Done. Processed ' . count($results) . ' notification batch(es).');
}

if (php_sapi_name() !== 'cli') {
    echo json_encode(['status' => 'ok', 'processed' => count($results), 'results' => $results]);
}
exit(0);

// =============================================================================
// Helper functions
// =============================================================================

function celebration_days_has_push_columns(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = @$conn->query("SHOW COLUMNS FROM `celebration_days` LIKE 'push_title'");
    $cache = ($r && $r->num_rows > 0);
    return $cache;
}

/**
 * Check notification_log to see if a given notification was already sent today.
 */
function already_sent(string $type, int $ref_id, string $ref_date, mysqli $conn): bool {
    $check = $conn->query("SHOW TABLES LIKE 'notification_log'");
    if (!$check || $check->num_rows === 0) {
        return false; // table not created yet → allow send
    }

    $stmt = $conn->prepare(
        "SELECT id FROM notification_log
          WHERE type = ? AND ref_id = ? AND ref_date = ?
            AND status IN ('sent', 'partial')
          LIMIT 1"
    );
    $stmt->bind_param('sis', $type, $ref_id, $ref_date);
    $stmt->execute();
    $found = ($stmt->get_result()->num_rows > 0);
    $stmt->close();
    return $found;
}

/**
 * Get FCM tokens based on recipient_type (and optional event_id).
 *
 * @param string   $recipient_type  all|students|faculty|event_participants|event_volunteers|event_both
 * @param int|null $event_id
 * @param mysqli   $conn
 * @param string   $activeFilter    SQL fragment excluding is_active = 0 only
 * @return string[]
 */
function get_tokens_for_recipient(
    string $recipient_type,
    ?int   $event_id,
    mysqli $conn,
    string $activeFilter
): array {
    $tokens = [];

    switch ($recipient_type) {
        case 'all':
            $res = $conn->query(
                "SELECT fcm_token FROM user_fcm_tokens WHERE 1=1 {$activeFilter}"
            );
            break;

        case 'students':
            $res = $conn->query(
                "SELECT t.fcm_token FROM user_fcm_tokens t
                   JOIN users u ON u.id = t.user_id
                  WHERE u.is_student = 1 AND u.status = 'active' {$activeFilter}"
            );
            break;

        case 'faculty':
            $res = $conn->query(
                "SELECT t.fcm_token FROM user_fcm_tokens t
                   JOIN users u ON u.id = t.user_id
                  WHERE u.is_student = 0 AND u.status = 'active' {$activeFilter}"
            );
            break;

        case 'event_participants':
        case 'event_volunteers':
        case 'event_both':
            if (!$event_id) {
                return [];
            }
            $user_ids = [];

            if ($recipient_type !== 'event_volunteers') {
                // participants
                $st = $conn->prepare(
                    "SELECT user_id FROM participant WHERE event_id = ? AND status = 'active'"
                );
                $st->bind_param('i', $event_id);
                $st->execute();
                $r = $st->get_result();
                while ($row = $r->fetch_assoc()) {
                    $user_ids[(int)$row['user_id']] = true;
                }
                $st->close();
            }

            if ($recipient_type !== 'event_participants') {
                // volunteers
                $st = $conn->prepare(
                    "SELECT user_id FROM volunteers WHERE event_id = ? AND status = 'active'"
                );
                $st->bind_param('i', $event_id);
                $st->execute();
                $r = $st->get_result();
                while ($row = $r->fetch_assoc()) {
                    $user_ids[(int)$row['user_id']] = true;
                }
                $st->close();
            }

            if (empty($user_ids)) {
                return [];
            }

            $ids          = array_keys($user_ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $st           = $conn->prepare(
                "SELECT fcm_token FROM user_fcm_tokens
                  WHERE user_id IN ({$placeholders}) {$activeFilter}"
            );
            $st->bind_param(str_repeat('i', count($ids)), ...$ids);
            $st->execute();
            $r = $st->get_result();
            while ($row = $r->fetch_assoc()) {
                $tokens[] = $row['fcm_token'];
            }
            $st->close();
            return array_values(array_unique(array_filter($tokens)));

        default:
            return [];
    }

    if (isset($res) && $res) {
        while ($row = $res->fetch_assoc()) {
            $tokens[] = $row['fcm_token'];
        }
    }

    return array_values(array_unique(array_filter($tokens)));
}

/**
 * Determine log status from sent/failed counts.
 */
function determine_status(int $sent, int $failed): string {
    if ($failed === 0)   return 'sent';
    if ($sent  === 0)    return 'failed';
    return 'partial';
}

/**
 * Print a timestamped log line (CLI) or accumulate for JSON (HTTP).
 */
function cron_log(string $level, string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $msg;
    if (php_sapi_name() === 'cli') {
        echo $line . PHP_EOL;
    }
    // Also write to PHP error_log for server-side visibility
    error_log('[MiCampus-Cron] ' . $line);
}