<?php
/**
 * In-app notification inbox + optional FCM for campus events.
 * Requires migrations/add_user_inbox_notifications.sql applied.
 */

/**
 * @param mysqli $conn
 */
function campus_inbox_table_exists($conn): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = @$conn->query("SHOW TABLES LIKE 'user_inbox_notifications'");
    $cache = ($r && $r->num_rows > 0);
    return $cache;
}

/**
 * Insert one inbox row (best-effort; ignores failures).
 */
function campus_inbox_insert(
    $conn,
    int $user_id,
    string $notification_type,
    string $title,
    string $body,
    ?int $event_id,
    ?array $data_json
): void {
    if (!campus_inbox_table_exists($conn) || $user_id <= 0) {
        return;
    }
    $dataStr = $data_json !== null ? json_encode($data_json, JSON_UNESCAPED_UNICODE) : null;
    $eid      = $event_id;

    if ($dataStr === null) {
        $stmt = $conn->prepare(
            'INSERT INTO user_inbox_notifications
               (user_id, notification_type, title, body, event_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isssi', $user_id, $notification_type, $title, $body, $eid);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO user_inbox_notifications
               (user_id, notification_type, title, body, event_id, data_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isssis', $user_id, $notification_type, $title, $body, $eid, $dataStr);
    }
    $stmt->execute();
    $stmt->close();
}

/**
 * After a new event is submitted (pending): all active users get inbox + push.
 */
function campus_inbox_after_event_created(
    $conn,
    int $event_id,
    string $title_plain,
    string $category_plain,
    string $venue_plain
): void {
    if (!campus_inbox_table_exists($conn) || $event_id <= 0) {
        return;
    }

    $sql = 'INSERT INTO user_inbox_notifications
              (user_id, notification_type, title, body, event_id, data_json)
            SELECT u.id, \'event_created\',
                   CONCAT(\'New event: \', e.title),
                   CONCAT(IFNULL(NULLIF(e.category, \'\'), \'Event\'), \' · \', IFNULL(e.venue, \'\')),
                   e.id,
                   JSON_OBJECT(
                     \'type\', \'event_created\',
                     \'event_id\', e.id,
                     \'notification_type\', \'event_created\'
                   )
            FROM users u
            INNER JOIN events e ON e.id = ?
            WHERE u.status = \'active\'';

    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param('i', $event_id);
        $st->execute();
        $st->close();
    }

    if (!file_exists(__DIR__ . '/fcm_helper.php')) {
        return;
    }
    require_once __DIR__ . '/fcm_helper.php';

    $pushTitle = 'New campus event';
    $pushBody  = $title_plain;
    if ($category_plain !== '' || $venue_plain !== '') {
        $pushBody .= "\n" . trim($category_plain . ($category_plain && $venue_plain ? ' · ' : '') . $venue_plain);
    }

    $fcm_data = [
        'type'               => 'event_created',
        'event_id'           => (string) $event_id,
        'notification_type'  => 'event_created',
    ];

    $hasActive = false;
    $colCheck  = @$conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $hasActive = true;
    }
    $activeFilter = $hasActive ? ' AND (t.is_active = 1 OR t.is_active IS NULL)' : '';

    $tr = $conn->query(
        "SELECT DISTINCT t.fcm_token
           FROM user_fcm_tokens t
           INNER JOIN users u ON u.id = t.user_id
          WHERE u.status = 'active'{$activeFilter}"
    );
    $tokens = [];
    if ($tr) {
        while ($row = $tr->fetch_assoc()) {
            $tokens[] = $row['fcm_token'];
        }
    }

    if (empty($tokens)) {
        return;
    }

    $out          = fcm_send_to_tokens($tokens, $pushTitle, $pushBody, $fcm_data);
    $sent         = (int) $out['success'];
    $failed       = (int) $out['failed'];
    $total        = count($tokens);
    $errorSummary = !empty($out['errors']) ? implode(' | ', array_slice($out['errors'], 0, 5)) : '';
    $log_status   = ($failed === 0) ? 'sent' : (($sent === 0) ? 'failed' : 'partial');

    fcm_log_notification([
        'type'            => 'event_created',
        'ref_id'          => $event_id,
        'ref_date'        => date('Y-m-d'),
        'title'           => $pushTitle,
        'body'            => $pushBody,
        'recipient_type'  => 'all',
        'event_id'        => $event_id,
        'tokens_targeted' => $total,
        'tokens_sent'     => $sent,
        'tokens_failed'   => $failed,
        'status'          => $log_status,
        'error_message'   => $errorSummary,
    ]);
}

/**
 * Inbox + FCM + notification_log for the event organizer (single user).
 *
 * @param array $fcm_data string values for FCM data payload (merged with event_id, notification_type)
 */
function campus_inbox_deliver_to_organizer(
    $conn,
    int $organizer_id,
    int $event_id,
    string $notification_type,
    string $title,
    string $body,
    array $fcm_data
): void {
    if ($organizer_id <= 0 || $event_id <= 0) {
        return;
    }

    $inboxData = array_merge(
        [
            'type'              => $notification_type,
            'event_id'          => $event_id,
            'notification_type' => $notification_type,
        ],
        $fcm_data
    );

    campus_inbox_insert($conn, $organizer_id, $notification_type, $title, $body, $event_id, $inboxData);

    if (!file_exists(__DIR__ . '/fcm_helper.php')) {
        return;
    }
    require_once __DIR__ . '/fcm_helper.php';

    $hasActive = false;
    $colCheck  = @$conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $hasActive = true;
    }
    $activeFilter = $hasActive ? ' AND (is_active = 1 OR is_active IS NULL)' : '';

    $st = $conn->prepare(
        "SELECT fcm_token FROM user_fcm_tokens WHERE user_id = ?{$activeFilter}"
    );
    if (!$st) {
        return;
    }
    $st->bind_param('i', $organizer_id);
    $st->execute();
    $res    = $st->get_result();
    $tokens = [];
    while ($row = $res->fetch_assoc()) {
        $tokens[] = $row['fcm_token'];
    }
    $st->close();

    $fcm_send = [];
    foreach ($inboxData as $k => $v) {
        if (!is_string($k)) {
            continue;
        }
        $fcm_send[$k] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    if (empty($tokens)) {
        return;
    }

    $out          = fcm_send_to_tokens($tokens, $title, $body, $fcm_send);
    $sent         = (int) $out['success'];
    $failed       = (int) $out['failed'];
    $total        = count($tokens);
    $errorSummary = !empty($out['errors']) ? implode(' | ', array_slice($out['errors'], 0, 5)) : '';
    $log_status   = ($failed === 0) ? 'sent' : (($sent === 0) ? 'failed' : 'partial');

    fcm_log_notification([
        'type'            => $notification_type,
        'ref_id'          => $event_id,
        'ref_date'        => date('Y-m-d'),
        'title'           => $title,
        'body'            => $body,
        'recipient_type'  => 'topic',
        'event_id'        => $event_id,
        'tokens_targeted' => $total,
        'tokens_sent'     => $sent,
        'tokens_failed'   => $failed,
        'status'          => $log_status,
        'error_message'   => $errorSummary,
    ]);
}

/**
 * Admin approved or rejected an event — notify organizer in inbox + push.
 *
 * @param 'approve'|'reject' $action
 */
function campus_inbox_after_admin_approve_or_reject(
    $conn,
    int $event_id,
    string $action,
    int $organizer_id,
    string $event_title_plain
): void {
    if ($organizer_id <= 0) {
        return;
    }

    $isApprove = ($action === 'approve');
    $type      = $isApprove ? 'event_approved' : 'event_rejected';
    $title     = $isApprove ? 'Event approved' : 'Event rejected';
    $body      = $isApprove
        ? ('Your event "' . $event_title_plain . '" is approved and published.')
        : ('Your event "' . $event_title_plain . '" was rejected by admin.');

    campus_inbox_deliver_to_organizer($conn, $organizer_id, $event_id, $type, $title, $body, []);
}

/**
 * Admin put the event on hold — notify organizer.
 */
function campus_inbox_after_admin_hold(
    $conn,
    int $event_id,
    int $organizer_id,
    string $event_title_plain,
    string $hold_reason_plain,
    ?string $reschedule_date_plain
): void {
    if ($organizer_id <= 0) {
        return;
    }

    $type  = 'event_hold';
    $title = 'Event on hold';
    $body  = 'Your event "' . $event_title_plain . '" was put on hold by admin.';
    if ($hold_reason_plain !== '') {
        $body .= ' Reason: ' . $hold_reason_plain;
    }
    if ($reschedule_date_plain !== null && $reschedule_date_plain !== '') {
        $body .= ' Tentative date: ' . $reschedule_date_plain;
    }

    $extra = [];
    if ($reschedule_date_plain !== null && $reschedule_date_plain !== '') {
        $extra['reschedule_date'] = $reschedule_date_plain;
    }
    if ($hold_reason_plain !== '') {
        $extra['hold_reason'] = $hold_reason_plain;
    }

    campus_inbox_deliver_to_organizer($conn, $organizer_id, $event_id, $type, $title, $body, $extra);
}

/**
 * Admin rescheduled an approved event — notify organizer.
 */
function campus_inbox_after_admin_reschedule(
    $conn,
    int $event_id,
    int $organizer_id,
    string $event_title_plain,
    string $old_event_datetime,
    string $new_event_datetime,
    string $reason_plain
): void {
    if ($organizer_id <= 0) {
        return;
    }

    $type  = 'event_rescheduled';
    $title = 'Event rescheduled';
    $oldTs = strtotime($old_event_datetime);
    $newTs = strtotime($new_event_datetime);
    $oldFmt = $oldTs ? date('M j, Y g:i A', $oldTs) : $old_event_datetime;
    $newFmt = $newTs ? date('M j, Y g:i A', $newTs) : $new_event_datetime;
    $body  = 'Admin rescheduled "' . $event_title_plain . '". Was: ' . $oldFmt . '. New: ' . $newFmt . '.';
    if ($reason_plain !== '') {
        $body .= ' Note: ' . $reason_plain;
    }

    campus_inbox_deliver_to_organizer($conn, $organizer_id, $event_id, $type, $title, $body, [
        'old_event_date' => $old_event_datetime,
        'new_event_date' => $new_event_datetime,
    ]);
}

/**
 * Organizer push to volunteers/participants — persist one inbox row per recipient.
 *
 * @param int[] $recipient_user_ids
 */
function campus_inbox_organizer_broadcast_recipients(
    $conn,
    array $recipient_user_ids,
    int $event_id,
    string $event_title_plain,
    string $message_plain,
    ?int $organizer_notification_id
): void {
    if (!campus_inbox_table_exists($conn) || $event_id <= 0 || empty($recipient_user_ids)) {
        return;
    }
    $notifTitle = 'Event: ' . $event_title_plain;
    foreach (array_unique(array_map('intval', $recipient_user_ids)) as $uid) {
        if ($uid <= 0) {
            continue;
        }
        $data = [
            'type'               => 'organizer_message',
            'event_id'           => $event_id,
            'notification_type'  => 'organizer_message',
        ];
        if ($organizer_notification_id !== null && $organizer_notification_id > 0) {
            $data['organizer_notification_id'] = $organizer_notification_id;
        }
        campus_inbox_insert($conn, $uid, 'organizer_message', $notifTitle, $message_plain, $event_id, $data);
    }
}

/**
 * Notify the event organizer when admin changes event status
 * (approve, reject, hold, reschedule, etc.)
 *
 * Call this from the admin panel after updating event status.
 */
function campus_inbox_after_status_change($conn, $event_id, $new_status, $old_status = null, $admin_note = '') {
    if (!campus_inbox_table_exists($conn)) return;

    $stmt = $conn->prepare('SELECT organizer_id, title FROM events WHERE id = ?');
    if (!$stmt) return;
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return;

    $organizer_id = (int) $row['organizer_id'];
    $event_title  = (string) $row['title'];
    if ($organizer_id <= 0) return;

    switch (strtolower($new_status)) {
        case 'approved':
            $notif_type = 'event_approved';
            $title      = 'Event Approved!';
            $body       = "Your event \"{$event_title}\" has been approved by the admin.";
            break;
        case 'rejected':
            $notif_type = 'event_rejected';
            $title      = 'Event Rejected';
            $body       = "Your event \"{$event_title}\" has been rejected.";
            if ($admin_note !== '') $body .= " Reason: {$admin_note}";
            break;
        case 'hold':
            $notif_type = 'event_hold';
            $title      = 'Event On Hold';
            $body       = "Your event \"{$event_title}\" has been put on hold by the admin.";
            if ($admin_note !== '') $body .= " Note: {$admin_note}";
            break;
        case 'rescheduled':
            $notif_type = 'event_rescheduled';
            $title      = 'Event Rescheduled';
            $body       = "Your event \"{$event_title}\" has been rescheduled by the admin.";
            if ($admin_note !== '') $body .= " Note: {$admin_note}";
            break;
        default:
            $notif_type = 'status_change';
            $title      = 'Event Status Updated';
            $body       = "Your event \"{$event_title}\" status changed to {$new_status}.";
            break;
    }

    $data_json = json_encode([
        'event_id'   => $event_id,
        'old_status' => $old_status,
        'new_status' => $new_status,
    ]);

    $ins = $conn->prepare(
        'INSERT INTO user_inbox_notifications (user_id, notification_type, title, body, event_id, data_json, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, NOW())'
    );
    if ($ins) {
        $ins->bind_param('isssis', $organizer_id, $notif_type, $title, $body, $event_id, $data_json);
        $ins->execute();
        $ins->close();
    }

    if (function_exists('fcm_send_to_tokens')) {
        $hasActive = false;
        $colCheck  = @$conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
        if ($colCheck && $colCheck->num_rows > 0) $hasActive = true;
        $activeFilter = $hasActive ? " AND (is_active = 1 OR is_active IS NULL)" : '';

        $tkStmt = $conn->prepare(
            "SELECT fcm_token FROM user_fcm_tokens WHERE user_id = ?{$activeFilter}"
        );
        if ($tkStmt) {
            $tkStmt->bind_param('i', $organizer_id);
            $tkStmt->execute();
            $tkRes  = $tkStmt->get_result();
            $tokens = [];
            while ($tr = $tkRes->fetch_assoc()) {
                $tokens[] = $tr['fcm_token'];
            }
            $tkStmt->close();

            if (!empty($tokens)) {
                fcm_send_to_tokens($tokens, $title, $body, [
                    'type'              => $notif_type,
                    'notification_type' => $notif_type,
                    'event_id'          => (string) $event_id,
                ]);
            }
        }
    }
}
