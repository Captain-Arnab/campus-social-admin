<?php
/**
 * Shared helpers for leave/cancel registration and response counts.
 *
 * Leave policy: leaving is ALLOWED after the registration deadline.
 * Joining remains blocked once the deadline (or event_date fallback) has passed.
 * Rationale: deadline protects headcount/planning for new joins; users who already
 * registered should still be able to cancel so organizers see accurate intent.
 */

require_once __DIR__ . '/../event_date_range_schema.php';

/** ISO 8601 server time in Asia/Kolkata (e.g. 2026-09-02T15:49:00+05:30). */
function api_server_time_iso(): string
{
    try {
        $dt = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
        return $dt->format('c');
    } catch (Throwable $e) {
        return date('c');
    }
}

/**
 * @return array{attendee_count:int,volunteer_count:int,participant_count:int,viewer_count:int}
 */
function registration_event_counts(mysqli $conn, int $event_id): array
{
    $att = 0;
    $vol = 0;
    $part = 0;
    $r = @$conn->query('SELECT COUNT(*) AS c FROM attendees WHERE event_id = ' . (int) $event_id);
    if ($r && ($row = $r->fetch_assoc())) {
        $att = (int) $row['c'];
    }
    $r = @$conn->query("SELECT COUNT(*) AS c FROM volunteers WHERE event_id = " . (int) $event_id . " AND status = 'active'");
    if ($r && ($row = $r->fetch_assoc())) {
        $vol = (int) $row['c'];
    }
    $r = @$conn->query("SELECT COUNT(*) AS c FROM participant WHERE event_id = " . (int) $event_id . " AND status = 'active'");
    if ($r && ($row = $r->fetch_assoc())) {
        $part = (int) $row['c'];
    }
    return [
        'attendee_count' => $att,
        'volunteer_count' => $vol,
        'participant_count' => $part,
        'viewer_count' => $att,
    ];
}

/**
 * Audit leave/join-style actions via event_status_log (app actor).
 * old_status / new_status store role labels (e.g. attending → left), not event.status.
 */
function registration_log_action(
    mysqli $conn,
    int $event_id,
    int $user_id,
    string $oldLabel,
    string $newLabel,
    string $remarks
): void {
    $adminType = 'app';
    $adminUser = 'user_' . $user_id;
    $stmt = $conn->prepare(
        'INSERT INTO event_status_log (event_id, admin_type, admin_username, old_status, new_status, remarks)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isssss', $event_id, $adminType, $adminUser, $oldLabel, $newLabel, $remarks);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return array{ok:bool,message?:string,http?:int}
 */
function registration_leave_validate_user_event(mysqli $conn, int $event_id, int $user_id): array
{
    if ($event_id <= 0 || $user_id <= 0) {
        return ['ok' => false, 'message' => 'event_id and user_id are required', 'http' => 400];
    }
    $u = $conn->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    if (!$u) {
        return ['ok' => false, 'message' => 'Database error', 'http' => 500];
    }
    $u->bind_param('i', $user_id);
    $u->execute();
    if ($u->get_result()->num_rows === 0) {
        $u->close();
        return ['ok' => false, 'message' => 'User not found', 'http' => 404];
    }
    $u->close();

    $e = $conn->prepare('SELECT id FROM events WHERE id = ? LIMIT 1');
    if (!$e) {
        return ['ok' => false, 'message' => 'Database error', 'http' => 500];
    }
    $e->bind_param('i', $event_id);
    $e->execute();
    if ($e->get_result()->num_rows === 0) {
        $e->close();
        return ['ok' => false, 'message' => 'Event not found', 'http' => 404];
    }
    $e->close();
    return ['ok' => true];
}
