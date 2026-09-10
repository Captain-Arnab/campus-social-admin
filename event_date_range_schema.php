<?php
/**
 * Multi-day events: `event_date` = start (from), optional `event_end_date` = end (to).
 * After migrations/add_event_end_date.sql, range filters apply; before migration, SQL falls back to `event_date` only.
 */

/**
 * @param mysqli $conn
 */
function schema_events_has_event_end_date($conn): bool {
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $r = @$conn->query("SHOW COLUMNS FROM events LIKE 'event_end_date'");
    $v = ($r && $r->num_rows > 0);
    return $v;
}

/**
 * @param mysqli $conn
 */
function schema_event_pending_edits_has_event_end_date($conn): bool {
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $r = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'event_end_date'");
    $v = ($r && $r->num_rows > 0);
    return $v;
}

function events_normalize_dt(?string $raw): ?string {
    if ($raw === null) {
        return null;
    }
    $v = trim(str_replace('T', ' ', $raw));
    if ($v === '') {
        return null;
    }
    if (strlen($v) === 16) {
        $v .= ':00';
    }
    return $v;
}

/**
 * @param array<string,mixed> $src
 */
function events_parse_end_from_request(array $src): ?string {
    foreach (['event_end_date', 'event_date_to', 'event_date_end'] as $k) {
        if (!empty($src[$k])) {
            return events_normalize_dt((string) $src[$k]);
        }
    }
    return null;
}

/**
 * @param array<string,mixed> $src
 */
function events_parse_start_from_request(array $src, string $primaryKey = 'event_date'): ?string {
    foreach ([$primaryKey, 'event_date_from', 'event_start_date', 'event_start'] as $k) {
        if (!empty($src[$k])) {
            return events_normalize_dt((string) $src[$k]);
        }
    }
    return null;
}

/**
 * @param mysqli $conn
 */
function events_sql_not_past($conn, string $alias = 'e'): string {
    if (!schema_events_has_event_end_date($conn)) {
        return "{$alias}.event_date >= NOW()";
    }
    return "(({$alias}.event_end_date IS NOT NULL AND {$alias}.event_end_date >= NOW()) OR ({$alias}.event_end_date IS NULL AND {$alias}.event_date >= NOW()))";
}

/**
 * @param mysqli $conn
 */
function events_sql_past($conn, string $alias = 'e'): string {
    if (!schema_events_has_event_end_date($conn)) {
        return "{$alias}.event_date < NOW()";
    }
    return "(({$alias}.event_end_date IS NOT NULL AND {$alias}.event_end_date < NOW()) OR ({$alias}.event_end_date IS NULL AND {$alias}.event_date < NOW()))";
}

/** Same as events_sql_not_past without table alias (single table `events` in FROM). */
function events_sql_not_past_naked($conn): string {
    if (!schema_events_has_event_end_date($conn)) {
        return 'event_date >= NOW()';
    }
    return '((event_end_date IS NOT NULL AND event_end_date >= NOW()) OR (event_end_date IS NULL AND event_date >= NOW()))';
}

/** Past filter without alias. */
function events_sql_past_naked($conn): string {
    if (!schema_events_has_event_end_date($conn)) {
        return 'event_date < NOW()';
    }
    return '((event_end_date IS NOT NULL AND event_end_date < NOW()) OR (event_end_date IS NULL AND event_date < NOW()))';
}

function events_validate_end_after_start(string $start, ?string $end): bool {
    if ($end === null || $end === '') {
        return true;
    }
    $tsStart = strtotime($start);
    $tsEnd    = strtotime($end);
    if ($tsStart === false || $tsEnd === false) {
        return false;
    }
    return $tsEnd >= $tsStart;
}

/**
 * Effective "has ended" for PHP checks (certificates, upload, etc.).
 *
 * @param array<string,mixed> $eventRow
 */
function events_row_is_fully_past(array $eventRow): bool {
    $end = $eventRow['event_end_date'] ?? null;
    if ($end !== null && $end !== '' && $end !== '0000-00-00 00:00:00') {
        return strtotime((string) $end) < time();
    }
    return strtotime((string) ($eventRow['event_date'] ?? '')) < time();
}

/**
 * Organizer may act on/after first day of event (same as previous DATE(event_date) rule, extended for multi-day).
 *
 * @param array<string,mixed> $eventRow
 */
function events_row_organizer_actions_allowed(array $eventRow): bool {
    return date('Y-m-d') >= date('Y-m-d', strtotime((string) ($eventRow['event_date'] ?? 'now')));
}

/**
 * @param mysqli $conn
 */
function schema_events_has_registration_deadline($conn): bool {
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $r = @$conn->query("SHOW COLUMNS FROM events LIKE 'registration_deadline'");
    $v = ($r && $r->num_rows > 0);
    return $v;
}

/**
 * @param mysqli $conn
 */
function schema_events_has_closed_status($conn): bool {
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $r = @$conn->query("SHOW COLUMNS FROM events LIKE 'closed_at'");
    $v = ($r && $r->num_rows > 0);
    return $v;
}

/**
 * App/campus wall-clock timezone. Deadlines are stored as naive datetimes in this zone
 * (MySQL DATETIME has no offset). Never use PHP's default php.ini timezone for compares.
 */
function events_app_timezone(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone('Asia/Kolkata');
    }
    return $tz;
}

/**
 * Normalized registration_deadline (MySQL DATETIME string) or null when unset.
 *
 * @param array<string,mixed> $eventRow
 */
function events_row_registration_deadline_value(array $eventRow): ?string {
    $deadline = $eventRow['registration_deadline'] ?? null;
    if ($deadline === null || $deadline === '' || $deadline === '0000-00-00 00:00:00') {
        return null;
    }
    return (string) $deadline;
}

/**
 * registration_deadline as ISO-8601 with IST offset (e.g. 2026-09-10T07:00:00+05:30).
 * Use in API responses alongside server_time.
 */
function events_row_registration_deadline_iso(array $eventRow): ?string
{
    $deadline = events_row_registration_deadline_value($eventRow);
    if ($deadline === null) {
        return null;
    }
    try {
        // Stored value is a naive IST wall clock — attach Asia/Kolkata explicitly.
        $dt = new DateTime(substr($deadline, 0, 19), events_app_timezone());
        return $dt->format('c');
    } catch (Throwable $e) {
        return $deadline;
    }
}

/**
 * True when registration join/leave/role-switch should be rejected.
 * Strict rule: closed only when registration_deadline is set AND now(IST) >= deadline(IST).
 * Do NOT infer from event_date — missing deadline means registration stays open.
 *
 * Compares in Asia/Kolkata so php.ini date.timezone (e.g. UTC / Europe/Berlin) cannot
 * shift a naive "07:00:00" deadline by several hours.
 *
 * @param array<string,mixed> $eventRow registration_deadline optional
 */
function events_row_registration_closed(array $eventRow): bool {
    $deadline = events_row_registration_deadline_value($eventRow);
    if ($deadline === null) {
        return false;
    }
    try {
        $tz = events_app_timezone();
        $dl = new DateTime(substr($deadline, 0, 19), $tz);
        $now = new DateTime('now', $tz);
        return $now >= $dl;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @param array<string,mixed> $eventRow
 * @return array{can_close:bool,close_blockers:string[]}
 */
function events_row_close_info(array $eventRow): array {
    $blockers = [];
    if (($eventRow['status'] ?? '') === 'closed') {
        return ['can_close' => false, 'close_blockers' => ['Event is already closed']];
    }
    if (!events_row_is_fully_past($eventRow)) {
        $blockers[] = 'Event date has not passed yet';
    }
    $review = trim((string) ($eventRow['organizer_review'] ?? ''));
    if ($review === '') {
        $blockers[] = 'Organizer report (text) is required';
    }
    return ['can_close' => $blockers === [], 'close_blockers' => $blockers];
}
