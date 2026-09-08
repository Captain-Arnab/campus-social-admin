<?php
/**
 * Schema helpers for event_pending_edits extras (minutes, editors, meeting updates).
 * Runtime ALTER keeps older installs working; prefer migrations/2026_pending_edits_extras.sql.
 */

/**
 * @param mysqli $conn
 */
function schema_event_pending_edits_ensure_extras($conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $cols = [
        'minutes_content' => "ALTER TABLE `event_pending_edits` ADD COLUMN `minutes_content` text NULL AFTER `rules`",
        'minutes_file_path' => "ALTER TABLE `event_pending_edits` ADD COLUMN `minutes_file_path` varchar(255) NULL AFTER `minutes_content`",
        'editors_json' => "ALTER TABLE `event_pending_edits` ADD COLUMN `editors_json` text NULL AFTER `minutes_file_path`",
        'meeting_update_message' => "ALTER TABLE `event_pending_edits` ADD COLUMN `meeting_update_message` text NULL AFTER `editors_json`",
        'meeting_update_recipient_type' => "ALTER TABLE `event_pending_edits` ADD COLUMN `meeting_update_recipient_type` varchar(32) NULL AFTER `meeting_update_message`",
    ];
    foreach ($cols as $name => $sql) {
        $r = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE '" . $conn->real_escape_string($name) . "'");
        if (!$r || $r->num_rows === 0) {
            @$conn->query($sql);
        }
    }
    $done = true;
}

/**
 * @param mysqli $conn
 */
function schema_event_pending_edits_has_column($conn, string $column): bool
{
    schema_event_pending_edits_ensure_extras($conn);
    $r = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE '" . $conn->real_escape_string($column) . "'");
    return $r && $r->num_rows > 0;
}

/**
 * Upsert a pending edit row, copying live event fields when not overridden.
 * Returns true on success.
 *
 * @param mysqli $conn
 * @param array<string,mixed> $overrides keys: title, description, venue, event_date, event_end_date,
 *   category, banners, rules, minutes_content, minutes_file_path, editors_json,
 *   meeting_update_message, meeting_update_recipient_type
 */
function event_pending_edits_stage($conn, int $eventId, int $submittedBy, array $overrides = []): bool
{
    require_once __DIR__ . '/event_date_range_schema.php';
    schema_event_pending_edits_ensure_extras($conn);

    $evCols = 'id, title, description, venue, event_date, category, banners, rules';
    if (schema_events_has_event_end_date($conn)) {
        $evCols .= ', event_end_date';
    }
    $r = @$conn->query("SELECT $evCols FROM events WHERE id = " . (int) $eventId . " LIMIT 1");
    if (!$r || !($evt = $r->fetch_assoc())) {
        return false;
    }

    // Seed from existing pending row so partial updates (minutes-only) keep prior staged fields.
    $existing = @$conn->query("SELECT * FROM event_pending_edits WHERE event_id = " . (int) $eventId . " LIMIT 1");
    $base = ($existing && ($er = $existing->fetch_assoc())) ? $er : $evt;

    $title = array_key_exists('title', $overrides) ? (string) $overrides['title'] : (string) ($base['title'] ?? $evt['title']);
    $desc = array_key_exists('description', $overrides) ? (string) $overrides['description'] : (string) ($base['description'] ?? $evt['description'] ?? '');
    $venue = array_key_exists('venue', $overrides) ? (string) $overrides['venue'] : (string) ($base['venue'] ?? $evt['venue']);
    $eventDate = array_key_exists('event_date', $overrides) ? $overrides['event_date'] : ($base['event_date'] ?? $evt['event_date']);
    $category = array_key_exists('category', $overrides) ? (string) $overrides['category'] : (string) ($base['category'] ?? $evt['category'] ?? '');
    $banners = array_key_exists('banners', $overrides) ? (string) $overrides['banners'] : (string) ($base['banners'] ?? $evt['banners'] ?? '[]');
    $rules = array_key_exists('rules', $overrides) ? (string) $overrides['rules'] : (string) ($base['rules'] ?? $evt['rules'] ?? '');
    $endDate = null;
    if (schema_events_has_event_end_date($conn)) {
        if (array_key_exists('event_end_date', $overrides)) {
            $endDate = $overrides['event_end_date'];
        } else {
            $endDate = $base['event_end_date'] ?? $evt['event_end_date'] ?? null;
        }
        if ($endDate === '' || $endDate === '0000-00-00 00:00:00') {
            $endDate = null;
        }
    }

    $minutesContent = array_key_exists('minutes_content', $overrides)
        ? $overrides['minutes_content']
        : ($base['minutes_content'] ?? null);
    $minutesFile = array_key_exists('minutes_file_path', $overrides)
        ? $overrides['minutes_file_path']
        : ($base['minutes_file_path'] ?? null);
    $editorsJson = array_key_exists('editors_json', $overrides)
        ? $overrides['editors_json']
        : ($base['editors_json'] ?? null);
    $meetingMsg = array_key_exists('meeting_update_message', $overrides)
        ? $overrides['meeting_update_message']
        : ($base['meeting_update_message'] ?? null);
    $meetingRecip = array_key_exists('meeting_update_recipient_type', $overrides)
        ? $overrides['meeting_update_recipient_type']
        : ($base['meeting_update_recipient_type'] ?? null);

    $hasEnd = schema_event_pending_edits_has_event_end_date($conn);

    if ($hasEnd) {
        $stmt = $conn->prepare(
            "INSERT INTO event_pending_edits
                (event_id, title, description, venue, event_date, event_end_date, category, banners, rules,
                 minutes_content, minutes_file_path, editors_json, meeting_update_message, meeting_update_recipient_type,
                 submitted_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title=VALUES(title), description=VALUES(description), venue=VALUES(venue),
                event_date=VALUES(event_date), event_end_date=VALUES(event_end_date), category=VALUES(category),
                banners=VALUES(banners), rules=VALUES(rules),
                minutes_content=VALUES(minutes_content), minutes_file_path=VALUES(minutes_file_path),
                editors_json=VALUES(editors_json),
                meeting_update_message=VALUES(meeting_update_message),
                meeting_update_recipient_type=VALUES(meeting_update_recipient_type),
                submitted_by_user_id=VALUES(submitted_by_user_id),
                submitted_at=CURRENT_TIMESTAMP"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'isssssssssssssi',
            $eventId,
            $title,
            $desc,
            $venue,
            $eventDate,
            $endDate,
            $category,
            $banners,
            $rules,
            $minutesContent,
            $minutesFile,
            $editorsJson,
            $meetingMsg,
            $meetingRecip,
            $submittedBy
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO event_pending_edits
                (event_id, title, description, venue, event_date, category, banners, rules,
                 minutes_content, minutes_file_path, editors_json, meeting_update_message, meeting_update_recipient_type,
                 submitted_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title=VALUES(title), description=VALUES(description), venue=VALUES(venue),
                event_date=VALUES(event_date), category=VALUES(category),
                banners=VALUES(banners), rules=VALUES(rules),
                minutes_content=VALUES(minutes_content), minutes_file_path=VALUES(minutes_file_path),
                editors_json=VALUES(editors_json),
                meeting_update_message=VALUES(meeting_update_message),
                meeting_update_recipient_type=VALUES(meeting_update_recipient_type),
                submitted_by_user_id=VALUES(submitted_by_user_id),
                submitted_at=CURRENT_TIMESTAMP"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'issssssssssssi',
            $eventId,
            $title,
            $desc,
            $venue,
            $eventDate,
            $category,
            $banners,
            $rules,
            $minutesContent,
            $minutesFile,
            $editorsJson,
            $meetingMsg,
            $meetingRecip,
            $submittedBy
        );
    }

    $ok = $stmt->execute();
    $stmt->close();
    return (bool) $ok;
}
