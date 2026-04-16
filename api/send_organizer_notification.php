<?php
/**
 * send_organizer_notification.php
 * Organizer sends a push notification to volunteers and/or participants of an event.
 *
 * POST body (JSON):
 *   event_id, organizer_id, message, recipient_type (volunteers|participants|both)
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

require_once __DIR__ . '/fcm_helper.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app_inbox_notifications_helper.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

try {
    $input = file_get_contents('php://input');
    $data  = json_decode($input, true) ?: $_POST;

    $event_id       = isset($data['event_id']) ? (int) $data['event_id'] : 0;
    $organizer_id   = isset($data['organizer_id']) ? (int) $data['organizer_id'] : 0;
    $message        = isset($data['message']) ? trim((string) $data['message']) : '';
    $recipient_type = isset($data['recipient_type']) ? trim((string) $data['recipient_type']) : 'both';

    if ($event_id <= 0 || $organizer_id <= 0 || $message === '') {
        http_response_code(400);
        echo json_encode([
            'status'  => 'error',
            'message' => 'event_id, organizer_id and message are required',
        ]);
        exit();
    }

    if (!in_array($recipient_type, ['volunteers', 'participants', 'both'], true)) {
        $recipient_type = 'both';
    }

    $check = $conn->prepare(
        'SELECT id, title FROM events WHERE id = ? AND organizer_id = ?'
    );
    $check->bind_param('ii', $event_id, $organizer_id);
    $check->execute();
    $ev = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$ev) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not the organizer of this event']);
        exit();
    }

    $today    = date('Y-m-d');
    $rate_res = $conn->prepare(
        "SELECT COUNT(*) AS c FROM organizer_notifications
          WHERE event_id = ? AND organizer_id = ?
            AND DATE(sent_at) = ?"
    );
    $rate_res->bind_param('iis', $event_id, $organizer_id, $today);
    $rate_res->execute();
    $rate_count = (int) $rate_res->get_result()->fetch_assoc()['c'];
    $rate_res->close();

    if ($rate_count >= 10) {
        http_response_code(429);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Daily limit reached: you can send at most 10 notifications per event per day.',
        ]);
        exit();
    }

    $user_ids = [];

    if ($recipient_type === 'volunteers' || $recipient_type === 'both') {
        $st = $conn->prepare(
            "SELECT user_id FROM volunteers WHERE event_id = ? AND status = 'active'"
        );
        $st->bind_param('i', $event_id);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $user_ids[(int) $row['user_id']] = true;
        }
        $st->close();
    }

    if ($recipient_type === 'participants' || $recipient_type === 'both') {
        $st = $conn->prepare(
            "SELECT user_id FROM participant WHERE event_id = ? AND status = 'active'"
        );
        $st->bind_param('i', $event_id);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $user_ids[(int) $row['user_id']] = true;
        }
        $st->close();
    }

    if (empty($user_ids)) {
        $conn->query(
            "INSERT INTO organizer_notifications (event_id, organizer_id, message, recipient_type)
             VALUES ({$event_id}, {$organizer_id}, '" . $conn->real_escape_string($message) . "', '{$recipient_type}')"
        );

        echo json_encode([
            'status'            => 'success',
            'message'           => 'No active volunteers/participants found for this event',
            'users_targeted'    => 0,
            'push_sent'         => 0,
            'push_failed'       => 0,
            'recipient_count'   => 0,
        ]);
        exit();
    }

    $ids          = array_keys($user_ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $hasActive = false;
    $colCheck  = $conn->query("SHOW COLUMNS FROM user_fcm_tokens LIKE 'is_active'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $hasActive = true;
    }
    $activeFilter = $hasActive ? ' AND (is_active = 1 OR is_active IS NULL)' : '';

    $stmt = $conn->prepare(
        "SELECT fcm_token FROM user_fcm_tokens
          WHERE user_id IN ({$placeholders}){$activeFilter}"
    );
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $tres   = $stmt->get_result();
    $tokens = [];
    while ($tr = $tres->fetch_assoc()) {
        $tokens[] = $tr['fcm_token'];
    }
    $stmt->close();

    if (empty($tokens)) {
        $msg_esc = $conn->real_escape_string($message);
        $conn->query(
            "INSERT INTO organizer_notifications (event_id, organizer_id, message, recipient_type)
             VALUES ({$event_id}, {$organizer_id}, '{$msg_esc}', '{$recipient_type}')"
        );
        $org_notif_id = (int) $conn->insert_id;
        try {
            campus_inbox_organizer_broadcast_recipients(
                $conn,
                $ids,
                $event_id,
                (string) $ev['title'],
                $message,
                $org_notif_id > 0 ? $org_notif_id : null
            );
        } catch (Throwable $e) {
            error_log('[send_organizer_notification] inbox: ' . $e->getMessage());
        }

        $n = count($ids);
        echo json_encode([
            'status'            => 'success',
            'message'           => "No active push tokens for {$n} volunteer(s)/participant(s). They must open the app and allow notifications.",
            'users_targeted'    => $n,
            'push_sent'         => 0,
            'push_failed'       => 0,
            'recipient_count'   => 0,
        ]);
        exit();
    }

    $notification_title = 'Event: ' . $ev['title'];
    $notification_body  = $message;
    $fcm_data           = [
        'type'                => 'organizer_message',
        'notification_type'   => 'organizer_message',
        'event_id'            => (string) $event_id,
    ];

    $out            = fcm_send_to_tokens($tokens, $notification_title, $notification_body, $fcm_data);
    $sent           = $out['success'];
    $failed         = $out['failed'];
    $total_tokens   = count($tokens);
    $errorSummary   = !empty($out['errors']) ? implode(' | ', array_slice($out['errors'], 0, 5)) : '';

    $msg_esc = $conn->real_escape_string($message);
    $conn->query(
        "INSERT INTO organizer_notifications (event_id, organizer_id, message, recipient_type)
         VALUES ({$event_id}, {$organizer_id}, '{$msg_esc}', '{$recipient_type}')"
    );
    $org_notif_id = (int) $conn->insert_id;

    try {
        campus_inbox_organizer_broadcast_recipients(
            $conn,
            $ids,
            $event_id,
            (string) $ev['title'],
            $message,
            $org_notif_id > 0 ? $org_notif_id : null
        );
    } catch (Throwable $e) {
        error_log('[send_organizer_notification] inbox: ' . $e->getMessage());
    }

    $log_status = ($failed === 0) ? 'sent' : (($sent === 0) ? 'failed' : 'partial');

    fcm_log_notification([
        'type'            => 'organizer',
        'ref_id'          => $org_notif_id,
        'ref_date'        => $today,
        'title'           => $notification_title,
        'body'            => $notification_body,
        'recipient_type'  => $recipient_type,
        'event_id'        => $event_id,
        'tokens_targeted' => $total_tokens,
        'tokens_sent'     => $sent,
        'tokens_failed'   => $failed,
        'status'          => $log_status,
        'error_message'   => $errorSummary,
    ]);

    echo json_encode([
        'status'                => 'success',
        'message'               => 'Notification sent',
        'users_targeted'        => count($ids),
        'push_sent'             => $sent,
        'push_failed'           => $failed,
        'recipient_count'       => $sent,
        'daily_sends_used'      => $rate_count + 1,
        'daily_sends_remaining' => max(0, 10 - $rate_count - 1),
        'error_details'         => $errorSummary ?: null,
    ]);
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    error_log(sprintf(
        '[send_organizer_notification] %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        @$conn->close();
    }
}
