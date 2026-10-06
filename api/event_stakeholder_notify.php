<?php
/**
 * Fan-out inbox + FCM to attendees, participants, volunteers, organizer, editors.
 * Used by background jobs: event_approved_notify, minutes_approved_notify, new_event_published (all active users).
 */
require_once __DIR__ . '/app_inbox_notifications_helper.php';
require_once __DIR__ . '/fcm_helper.php';

/**
 * Collect unique user IDs: attendees + active participants + active volunteers + organizer + editors.
 * @return int[]
 */
function campus_event_stakeholder_user_ids($conn, int $event_id): array
{
    $ids = [];
    $add = function ($uid) use (&$ids) {
        $uid = (int) $uid;
        if ($uid > 0) {
            $ids[$uid] = true;
        }
    };

    $r = @$conn->query("SELECT organizer_id FROM events WHERE id = " . (int) $event_id . " LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) {
        $add($row['organizer_id']);
    }
    $r = @$conn->query("SELECT user_id FROM event_editors WHERE event_id = " . (int) $event_id);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $add($row['user_id']);
        }
    }
    $r = @$conn->query("SELECT user_id FROM attendees WHERE event_id = " . (int) $event_id);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $add($row['user_id']);
        }
    }
    $r = @$conn->query("SELECT user_id FROM participant WHERE event_id = " . (int) $event_id . " AND status = 'active'");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $add($row['user_id']);
        }
    }
    $r = @$conn->query("SELECT user_id FROM volunteers WHERE event_id = " . (int) $event_id . " AND status = 'active'");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $add($row['user_id']);
        }
    }
    return array_map('intval', array_keys($ids));
}

/**
 * @param array<string,mixed> $extraData
 */
function campus_notify_event_stakeholders(
    $conn,
    int $event_id,
    string $notification_type,
    string $title,
    string $body,
    array $extraData = []
): void {
    if ($event_id <= 0) {
        return;
    }
    $userIds = campus_event_stakeholder_user_ids($conn, $event_id);
    if ($userIds === []) {
        return;
    }
    $data = array_merge([
        'type' => $notification_type,
        'event_id' => $event_id,
        'notification_type' => $notification_type,
    ], $extraData);

    foreach ($userIds as $uid) {
        campus_inbox_insert($conn, $uid, $notification_type, $title, $body, $event_id, $data);
    }

    if (!function_exists('fcm_send_to_tokens')) {
        return;
    }
    $in = implode(',', $userIds);
    $activeFilter = '';
    $colCheck = @$conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $activeFilter = ' AND (is_active = 1 OR is_active IS NULL)';
    }
    $tokRes = @$conn->query("SELECT DISTINCT fcm_token FROM user_fcm_tokens WHERE user_id IN ($in) AND fcm_token IS NOT NULL AND fcm_token != ''{$activeFilter}");
    $tokens = [];
    if ($tokRes) {
        while ($t = $tokRes->fetch_assoc()) {
            $tok = trim((string) ($t['fcm_token'] ?? ''));
            if ($tok !== '') {
                $tokens[] = $tok;
            }
        }
    }
    if ($tokens !== []) {
        try {
            fcm_send_to_tokens($tokens, $title, $body, $data);
        } catch (Throwable $e) {
            error_log('[campus_notify_event_stakeholders] FCM: ' . $e->getMessage());
        }
    }
}

function process_job_event_approved_notify($conn, array $payload): void
{
    $eventId = (int) ($payload['event_id'] ?? 0);
    if ($eventId <= 0) {
        throw new InvalidArgumentException('event_id required');
    }
    $titlePlain = (string) ($payload['title'] ?? 'Event');
    campus_notify_event_stakeholders(
        $conn,
        $eventId,
        'event_edit_approved',
        'Event update approved',
        'Updates to "' . $titlePlain . '" are now live.',
        ['kind' => 'event_approved_notify']
    );
}

/**
 * First publish of an event → inbox + push to EVERY active user (not just stakeholders).
 * Enqueued once by campus_event_enqueue_first_publish_broadcast().
 * Inbox insert is set-based and skips users who already have the row, so a retried
 * job never duplicates inbox entries.
 */
function process_job_new_event_published($conn, array $payload): void
{
    $eventId = (int) ($payload['event_id'] ?? 0);
    if ($eventId <= 0) {
        throw new InvalidArgumentException('event_id required');
    }
    @set_time_limit(0);

    $r = $conn->query("SELECT id, title, venue, event_date, category FROM events WHERE id = $eventId LIMIT 1");
    $ev = $r ? $r->fetch_assoc() : null;
    if (!$ev) {
        return;
    }

    $type       = 'new_event_published';
    $titlePlain = (string) $ev['title'];
    $ts         = strtotime((string) $ev['event_date']);
    $dateFmt    = $ts ? date('D, M j, Y', $ts) : (string) $ev['event_date'];
    $title      = 'New event: ' . $titlePlain;
    $body       = 'Check out ' . $titlePlain . ', happening on ' . $dateFmt . ' at ' . (string) $ev['venue'] . '.';
    $data       = [
        'type'              => $type,
        'event_id'          => $eventId,
        'notification_type' => $type,
    ];

    if (campus_inbox_table_exists($conn)) {
        $dataStr = json_encode($data, JSON_UNESCAPED_UNICODE);
        $st = $conn->prepare(
            "INSERT INTO user_inbox_notifications (user_id, notification_type, title, body, event_id, data_json)
             SELECT u.id, ?, ?, ?, ?, ?
               FROM users u
              WHERE u.status = 'active'
                AND NOT EXISTS (
                  SELECT 1 FROM user_inbox_notifications n
                   WHERE n.event_id = ? AND n.notification_type = ? AND n.user_id = u.id
                )"
        );
        if (!$st) {
            throw new RuntimeException('inbox prepare failed: ' . $conn->error);
        }
        $st->bind_param('sssisis', $type, $title, $body, $eventId, $dataStr, $eventId, $type);
        if (!$st->execute()) {
            $err = $st->error;
            $st->close();
            throw new RuntimeException('inbox insert failed: ' . $err);
        }
        $st->close();
    }

    $activeFilter = '';
    $colCheck = @$conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $activeFilter = ' AND (t.is_active = 1 OR t.is_active IS NULL)';
    }
    $tr = $conn->query(
        "SELECT DISTINCT t.fcm_token
           FROM user_fcm_tokens t
           INNER JOIN users u ON u.id = t.user_id
          WHERE u.status = 'active' AND t.fcm_token IS NOT NULL AND t.fcm_token != ''{$activeFilter}"
    );
    $tokens = [];
    if ($tr) {
        while ($row = $tr->fetch_assoc()) {
            $tokens[] = (string) $row['fcm_token'];
        }
    }
    if ($tokens === []) {
        return;
    }

    // Push is best-effort: never throw past this point, or a retry would re-push everyone.
    try {
        $out = fcm_send_to_tokens_batched($tokens, $title, $body, $data);
    } catch (Throwable $e) {
        error_log('[new_event_published] FCM: ' . $e->getMessage());
        $out = ['success' => 0, 'failed' => count($tokens), 'errors' => [$e->getMessage()]];
    }
    $sent   = (int) $out['success'];
    $failed = (int) $out['failed'];

    fcm_log_notification([
        'type'            => $type,
        'ref_id'          => $eventId,
        'ref_date'        => date('Y-m-d'),
        'title'           => $title,
        'body'            => $body,
        'recipient_type'  => 'all',
        'event_id'        => $eventId,
        'tokens_targeted' => count($tokens),
        'tokens_sent'     => $sent,
        'tokens_failed'   => $failed,
        'status'          => ($failed === 0) ? 'sent' : (($sent === 0) ? 'failed' : 'partial'),
        'error_message'   => !empty($out['errors']) ? implode(' | ', array_slice($out['errors'], 0, 5)) : '',
    ]);
}

function process_job_minutes_approved_notify($conn, array $payload): void
{
    $eventId = (int) ($payload['event_id'] ?? 0);
    $minutesId = (int) ($payload['minutes_id'] ?? 0);
    if ($eventId <= 0) {
        throw new InvalidArgumentException('event_id required');
    }
    $titlePlain = (string) ($payload['title'] ?? 'Event');
    $content = trim((string) ($payload['content'] ?? ''));
    if ($content === '' && $minutesId > 0) {
        $r = @$conn->query('SELECT content FROM meeting_minutes WHERE id = ' . (int) $minutesId . ' LIMIT 1');
        if ($r && ($row = $r->fetch_assoc())) {
            $content = trim((string) ($row['content'] ?? ''));
        }
    }

    $placeholder = (strcasecmp($content, '(See attached minutes file)') === 0);
    if ($content === '' || $placeholder) {
        $body = 'Meeting minutes for "' . $titlePlain . '" are now available.'
            . ($placeholder ? ' See the attached minutes file in the event.' : '');
    } else {
        // Keep push/inbox body readable; full text also goes in data payload.
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            $body = mb_strlen($content) > 500 ? (mb_substr($content, 0, 497) . '…') : $content;
        } else {
            $body = strlen($content) > 500 ? (substr($content, 0, 497) . '…') : $content;
        }
    }

    $auto = !empty($payload['auto_published']);
    $notifTitle = $auto
        ? ('Meeting minutes: ' . $titlePlain)
        : ('Minutes of meeting: ' . $titlePlain);

    campus_notify_event_stakeholders(
        $conn,
        $eventId,
        'minutes_approved',
        $notifTitle,
        $body,
        [
            'minutes_id' => $minutesId,
            'kind' => 'minutes_approved_notify',
            'content' => $content,
            'minutes_content' => $content,
            'auto_published' => $auto ? '1' : '0',
        ]
    );
}

/**
 * After admin approves a staged meeting update, deliver push/inbox to recipients.
 */
function process_job_send_staged_meeting_update($conn, array $payload): void
{
    $eventId = (int) ($payload['event_id'] ?? 0);
    $organizerId = (int) ($payload['organizer_id'] ?? 0);
    $message = trim((string) ($payload['message'] ?? ''));
    $recipientType = trim((string) ($payload['recipient_type'] ?? 'both'));
    if ($eventId <= 0 || $message === '') {
        throw new InvalidArgumentException('event_id and message required');
    }
    if (!in_array($recipientType, ['volunteers', 'participants', 'both', 'all'], true)) {
        $recipientType = 'both';
    }

    $ev = @$conn->query("SELECT title FROM events WHERE id = $eventId LIMIT 1");
    $title = ($ev && ($er = $ev->fetch_assoc())) ? (string) $er['title'] : 'Event';

    $user_ids = [];
    if ($recipientType === 'all') {
        $st = $conn->query("SELECT id FROM users WHERE status = 'active'");
        if ($st) {
            while ($row = $st->fetch_assoc()) {
                $user_ids[(int) $row['id']] = true;
            }
        }
    } else {
        if ($recipientType === 'volunteers' || $recipientType === 'both') {
            $st = @$conn->query("SELECT user_id FROM volunteers WHERE event_id = $eventId AND status = 'active'");
            if ($st) {
                while ($row = $st->fetch_assoc()) {
                    $user_ids[(int) $row['user_id']] = true;
                }
            }
        }
        if ($recipientType === 'participants' || $recipientType === 'both') {
            $st = @$conn->query("SELECT user_id FROM participant WHERE event_id = $eventId AND status = 'active'");
            if ($st) {
                while ($row = $st->fetch_assoc()) {
                    $user_ids[(int) $row['user_id']] = true;
                }
            }
        }
    }

    $msg_esc = $conn->real_escape_string($message);
    $rt_esc = $conn->real_escape_string($recipientType);
    @$conn->query(
        "INSERT INTO organizer_notifications (event_id, organizer_id, message, recipient_type)
         VALUES ($eventId, $organizerId, '$msg_esc', '$rt_esc')"
    );
    $org_notif_id = (int) $conn->insert_id;

    $ids = array_keys($user_ids);
    if ($ids === []) {
        return;
    }

    try {
        if ($recipientType === 'all') {
            campus_inbox_organizer_broadcast_all($conn, $eventId, $title, $message, $org_notif_id > 0 ? $org_notif_id : null);
        } else {
            campus_inbox_organizer_broadcast_recipients($conn, $ids, $eventId, $title, $message, $org_notif_id > 0 ? $org_notif_id : null);
        }
    } catch (Throwable $e) {
        error_log('[send_staged_meeting_update] inbox: ' . $e->getMessage());
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $hasActive = false;
    $colCheck = @$conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $hasActive = true;
    }
    $activeFilter = $hasActive ? ' AND (is_active = 1 OR is_active IS NULL)' : '';
    $stmt = $conn->prepare(
        "SELECT fcm_token FROM user_fcm_tokens WHERE user_id IN ($placeholders)$activeFilter"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $tres = $stmt->get_result();
    $tokens = [];
    while ($tr = $tres->fetch_assoc()) {
        $tokens[] = $tr['fcm_token'];
    }
    $stmt->close();
    if ($tokens !== [] && function_exists('fcm_send_multicast')) {
        try {
            fcm_send_multicast($tokens, 'Meeting update: ' . $title, $message, [
                'type' => 'meeting_update',
                'event_id' => (string) $eventId,
            ]);
        } catch (Throwable $e) {
            error_log('[send_staged_meeting_update] fcm: ' . $e->getMessage());
        }
    }
}

/**
 * G3: enqueue pending certificate rows for browser-based generation later.
 * Does not render images server-side.
 */
function process_job_generate_event_certificates($conn, array $payload): void
{
    $eventId = (int) ($payload['event_id'] ?? 0);
    if ($eventId <= 0) {
        throw new InvalidArgumentException('event_id required');
    }
    $recipients = $payload['recipients'] ?? [];
    if (!is_array($recipients) || $recipients === []) {
        // Default: active participants + volunteers
        $recipients = [];
        $r = @$conn->query("SELECT user_id FROM participant WHERE event_id = $eventId AND status = 'active'");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $recipients[] = ['user_id' => (int) $row['user_id'], 'type' => 'participant'];
            }
        }
        $r = @$conn->query("SELECT user_id FROM volunteers WHERE event_id = $eventId AND status = 'active'");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $recipients[] = ['user_id' => (int) $row['user_id'], 'type' => 'volunteer'];
            }
        }
    }

    $hasStatus = false;
    $chk = @$conn->query("SHOW COLUMNS FROM event_certificates LIKE 'status'");
    $hasStatus = ($chk && $chk->num_rows > 0);

    foreach ($recipients as $rec) {
        $uid = (int) ($rec['user_id'] ?? 0);
        $type = (($rec['type'] ?? 'participant') === 'volunteer') ? 'volunteer' : 'participant';
        if ($uid <= 0) {
            continue;
        }
        // Skip if already ready with a file
        $ex = @$conn->query("SELECT id, file_path" . ($hasStatus ? ", status" : "") . " FROM event_certificates WHERE event_id = $eventId AND user_id = $uid AND type = '$type' LIMIT 1");
        if ($ex && ($row = $ex->fetch_assoc())) {
            if (!empty($row['file_path']) && (!$hasStatus || ($row['status'] ?? '') === 'ready')) {
                continue;
            }
            if ($hasStatus) {
                @$conn->query("UPDATE event_certificates SET status = 'pending' WHERE id = " . (int) $row['id']);
            }
            continue;
        }
        if ($hasStatus) {
            $stmt = $conn->prepare("INSERT INTO event_certificates (event_id, user_id, type, status, file_path) VALUES (?, ?, ?, 'pending', NULL)");
            if ($stmt) {
                $stmt->bind_param('iis', $eventId, $uid, $type);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            // Schema without status: insert placeholder empty path only if unique allows empty
            @$conn->query("INSERT IGNORE INTO event_certificates (event_id, user_id, type, file_path) VALUES ($eventId, $uid, '$type', '')");
        }
    }
}
