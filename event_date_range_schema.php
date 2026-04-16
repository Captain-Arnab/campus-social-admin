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
