<?php
/**
 * Fan-out inbox + FCM to attendees, participants, volunteers, organizer, editors.
 * Used by background jobs: event_approved_notify, minutes_approved_notify.
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

function process_job_minutes_approved_notify($conn, array $payload): void
{
    $eventId = (int) ($payload['event_id'] ?? 0);
    $minutesId = (int) ($payload['minutes_id'] ?? 0);
    if ($eventId <= 0) {
        throw new InvalidArgumentException('event_id required');
    }
    $titlePlain = (string) ($payload['title'] ?? 'Event');
    campus_notify_event_stakeholders(
        $conn,
        $eventId,
        'minutes_approved',
        'Minutes of meeting approved',
        'Meeting minutes for "' . $titlePlain . '" have been approved.',
        ['minutes_id' => $minutesId, 'kind' => 'minutes_approved_notify']
    );
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
