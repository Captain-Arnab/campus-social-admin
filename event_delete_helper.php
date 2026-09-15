<?php
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
    ];

    foreach ($queries as $sql) {
        $st = @$conn->prepare($sql);
        if ($st) {
            $st->bind_param('i', $event_id);
            $st->execute();
            $st->close();
        }
    }
}

/**
 * Permanently delete one event and its dependents.
 * @return bool true when the events row was removed
 */
function admin_delete_event_permanently(mysqli $conn, int $event_id): bool
{
    if ($event_id <= 0) {
        return false;
    }

    admin_delete_event_dependents($conn, $event_id);

    $del = $conn->prepare('DELETE FROM events WHERE id = ?');
    if (!$del) {
        return false;
    }
    $del->bind_param('i', $event_id);
    $del->execute();
    $ok = $del->affected_rows === 1;
    $del->close();
    return $ok;
}
