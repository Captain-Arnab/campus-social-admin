<?php
/**
 * Shared logic: set a user's role (attendee, volunteer, or participant) for one event.
 * Used by event_staff_switch.php, volunteers.php, and participant.php (action=switch_staff_role).
 * Join endpoints also call this when a user already holds a different role (update in place).
 *
 * Supports:
 *   - attendee  <-> volunteer / participant
 *   - volunteer <-> participant
 *   - same-role refresh (volunteer role text / participant department)
 *
 * Roles are mutually exclusive so per-event counts never double up.
 * Blocked when registration_deadline has passed (strict deadline vs server time).
 */

require_once __DIR__ . '/../event_date_range_schema.php';
require_once __DIR__ . '/registration_leave_helper.php';

/**
 * @param array<string,mixed> $data
 * @param bool $echoJson when false, returns the payload array instead of echoing/exiting
 * @return array<string,mixed>|null
 */
function event_staff_switch_role(mysqli $conn, array $data, bool $echoJson = true): ?array
{
    $respond = static function (int $http, array $payload) use ($echoJson): ?array {
        if ($echoJson) {
            http_response_code($http);
            echo json_encode($payload);
            return null;
        }
        $payload['_http'] = $http;
        return $payload;
    };

    if (!isset($data['event_id'], $data['user_id'], $data['to_role'])) {
        return $respond(400, [
            'status' => 'error',
            'message' => 'event_id, user_id, and to_role (attend|attendee|volunteer|participant) are required',
        ]);
    }

    $event_id = (int) $data['event_id'];
    $user_id = (int) $data['user_id'];
    $to_role = strtolower(trim((string) $data['to_role']));
    if ($to_role === 'attend') {
        $to_role = 'attendee';
    }

    if ($event_id <= 0 || $user_id <= 0) {
        return $respond(400, ['status' => 'error', 'message' => 'Invalid event_id or user_id']);
    }

    if (!in_array($to_role, ['attendee', 'volunteer', 'participant'], true)) {
        return $respond(400, [
            'status' => 'error',
            'message' => 'to_role must be attend/attendee, volunteer, or participant',
        ]);
    }

    $user_stmt = $conn->prepare('SELECT id, is_student, status FROM users WHERE id = ? LIMIT 1');
    if (!$user_stmt) {
        return $respond(500, ['status' => 'error', 'message' => 'Database error']);
    }
    $user_stmt->bind_param('i', $user_id);
    $user_stmt->execute();
    $user_row = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();

    if (!$user_row) {
        return $respond(404, ['status' => 'error', 'message' => 'User not found']);
    }
    if (($user_row['status'] ?? '') === 'blocked') {
        return $respond(403, ['status' => 'error', 'message' => 'Account blocked']);
    }

    $user_is_student = (int) $user_row['is_student'];

    $evCols = 'e.id, e.status, e.event_date, u.is_student AS organizer_is_student';
    if (schema_events_has_registration_deadline($conn)) {
        $evCols .= ', e.registration_deadline';
    }
    $event_stmt = $conn->prepare(
        "SELECT $evCols
         FROM events e
         JOIN users u ON e.organizer_id = u.id
         WHERE e.id = ? LIMIT 1"
    );
    if (!$event_stmt) {
        return $respond(500, ['status' => 'error', 'message' => 'Database error']);
    }
    $event_stmt->bind_param('i', $event_id);
    $event_stmt->execute();
    $event_row = $event_stmt->get_result()->fetch_assoc();
    $event_stmt->close();

    if (!$event_row) {
        return $respond(404, ['status' => 'error', 'message' => 'Event not found']);
    }

    if (($event_row['status'] ?? '') !== 'approved') {
        return $respond(400, ['status' => 'error', 'message' => 'Role switch is only allowed for approved events']);
    }

    if (events_row_registration_closed($event_row)) {
        return $respond(400, [
            'status' => 'error',
            'message' => 'Registration closed for this event',
            'registration_closed' => true,
            'registration_deadline' => events_row_registration_deadline_value($event_row),
            'server_time' => api_server_time_iso(),
        ]);
    }

    $organizer_is_student = (int) $event_row['organizer_is_student'];
    if ($to_role !== 'attendee' && $user_is_student !== $organizer_is_student) {
        $user_role_name = $user_is_student ? 'student' : 'faculty';
        $organizer_role_name = $organizer_is_student ? 'student' : 'faculty';
        return $respond(403, [
            'status' => 'error',
            'message' => "As a $user_role_name, you can only join $organizer_role_name events as volunteer/participant.",
        ]);
    }

    $vol_stmt = $conn->prepare(
        "SELECT id, role FROM volunteers WHERE event_id = ? AND user_id = ? AND status = 'active' LIMIT 1"
    );
    $vol_stmt->bind_param('ii', $event_id, $user_id);
    $vol_stmt->execute();
    $active_vol = $vol_stmt->get_result()->fetch_assoc();
    $vol_stmt->close();

    $part_stmt = $conn->prepare(
        "SELECT id, department_class FROM participant WHERE event_id = ? AND user_id = ? AND status = 'active' LIMIT 1"
    );
    $part_stmt->bind_param('ii', $event_id, $user_id);
    $part_stmt->execute();
    $active_part = $part_stmt->get_result()->fetch_assoc();
    $part_stmt->close();

    $att_stmt = $conn->prepare(
        'SELECT id FROM attendees WHERE event_id = ? AND user_id = ? LIMIT 1'
    );
    $att_stmt->bind_param('ii', $event_id, $user_id);
    $att_stmt->execute();
    $is_attendee = $att_stmt->get_result()->num_rows > 0;
    $att_stmt->close();

    if ($active_vol) {
        $from_role = 'volunteer';
    } elseif ($active_part) {
        $from_role = 'participant';
    } elseif ($is_attendee) {
        $from_role = 'attendee';
    } else {
        $from_role = 'none';
    }

    // ——— switch / set attendee ———
    if ($to_role === 'attendee') {
        $conn->begin_transaction();
        try {
            if ($active_vol) {
                $del = $conn->prepare('DELETE FROM volunteers WHERE id = ? AND event_id = ? AND user_id = ?');
                $del->bind_param('iii', $active_vol['id'], $event_id, $user_id);
                $del->execute();
                $del->close();
            }
            if ($active_part) {
                $del = $conn->prepare('DELETE FROM participant WHERE id = ? AND event_id = ? AND user_id = ?');
                $del->bind_param('iii', $active_part['id'], $event_id, $user_id);
                $del->execute();
                $del->close();
            }

            $existing = $conn->prepare('SELECT id FROM attendees WHERE event_id = ? AND user_id = ? LIMIT 1');
            $existing->bind_param('ii', $event_id, $user_id);
            $existing->execute();
            $existing_row = $existing->get_result()->fetch_assoc();
            $existing->close();

            if ($existing_row) {
                $attendee_id = (int) $existing_row['id'];
            } else {
                $ins = $conn->prepare('INSERT INTO attendees (event_id, user_id, joined_at) VALUES (?, ?, NOW())');
                $ins->bind_param('ii', $event_id, $user_id);
                $ins->execute();
                $attendee_id = (int) $ins->insert_id;
                $ins->close();
            }

            $conn->commit();
            $counts = registration_event_counts($conn, $event_id);
            $message = ($from_role === 'attendee' || $from_role === 'none')
                ? 'Registered as attendee'
                : ('Switched from ' . $from_role . ' to attendee');
            return $respond(200, array_merge([
                'status' => 'success',
                'message' => $message,
                'from_role' => $from_role,
                'to_role' => 'attendee',
                'attendee_id' => $attendee_id,
                'server_time' => api_server_time_iso(),
            ], $counts, ['viewer_count' => $counts['attendee_count']]));
        } catch (Throwable $e) {
            $conn->rollback();
            return $respond(500, ['status' => 'error', 'message' => 'Could not switch to attendee']);
        }
    }

    if ($to_role === 'participant') {
        $department_class = isset($data['department_class'])
            ? trim((string) $data['department_class'])
            : '';
        if ($department_class === '' && $active_part) {
            $department_class = trim((string) ($active_part['department_class'] ?? ''));
        }
        if ($department_class === '') {
            $sf = $conn->prepare(
                'SELECT department_class FROM student_faculty WHERE user_id = ? LIMIT 1'
            );
            $sf->bind_param('i', $user_id);
            $sf->execute();
            $sf_row = $sf->get_result()->fetch_assoc();
            $sf->close();
            $department_class = trim((string) ($sf_row['department_class'] ?? ''));
        }
        if ($department_class === '') {
            return $respond(400, [
                'status' => 'error',
                'message' => 'department_class is required when switching to participant',
            ]);
        }

        $conn->begin_transaction();
        try {
            if ($active_vol) {
                $del = $conn->prepare('DELETE FROM volunteers WHERE id = ? AND event_id = ? AND user_id = ?');
                $del->bind_param('iii', $active_vol['id'], $event_id, $user_id);
                $del->execute();
                $del->close();
            }

            $dept_esc = $conn->real_escape_string($department_class);
            $conn->query(
                "UPDATE student_faculty SET department_class = '$dept_esc' WHERE user_id = $user_id"
            );

            $existing = $conn->prepare(
                'SELECT id FROM participant WHERE event_id = ? AND user_id = ? LIMIT 1'
            );
            $existing->bind_param('ii', $event_id, $user_id);
            $existing->execute();
            $existing_row = $existing->get_result()->fetch_assoc();
            $existing->close();

            if ($existing_row) {
                $react = $conn->prepare(
                    "UPDATE participant SET status = 'active', department_class = ? WHERE id = ?"
                );
                $react->bind_param('si', $department_class, $existing_row['id']);
                $react->execute();
                $participant_id = (int) $existing_row['id'];
                $react->close();
            } else {
                $ins = $conn->prepare(
                    "INSERT INTO participant (event_id, user_id, status, department_class)
                     VALUES (?, ?, 'active', ?)"
                );
                $ins->bind_param('iis', $event_id, $user_id, $department_class);
                $ins->execute();
                $participant_id = (int) $ins->insert_id;
                $ins->close();
            }

            $att_del = $conn->prepare('DELETE FROM attendees WHERE event_id = ? AND user_id = ?');
            $att_del->bind_param('ii', $event_id, $user_id);
            $att_del->execute();
            $att_del->close();

            $conn->commit();
            $counts = registration_event_counts($conn, $event_id);
            $message = ($from_role === 'participant')
                ? 'Participant details updated'
                : ('Switched from ' . $from_role . ' to participant');
            return $respond(200, array_merge([
                'status' => 'success',
                'message' => $message,
                'from_role' => $from_role,
                'to_role' => 'participant',
                'participant_id' => $participant_id,
                'department_class' => $department_class,
                'server_time' => api_server_time_iso(),
            ], $counts, ['viewer_count' => $counts['attendee_count']]));
        } catch (Throwable $e) {
            $conn->rollback();
            return $respond(500, ['status' => 'error', 'message' => 'Could not switch to participant']);
        }
    }

    // Switch to volunteer
    $volunteer_role = isset($data['role']) ? trim((string) $data['role']) : '';
    if ($volunteer_role === '' && $active_vol) {
        $volunteer_role = trim((string) ($active_vol['role'] ?? ''));
    }
    if ($volunteer_role === '') {
        return $respond(400, [
            'status' => 'error',
            'message' => 'role is required when switching to volunteer',
        ]);
    }
    if (strlen($volunteer_role) > 100) {
        return $respond(400, ['status' => 'error', 'message' => 'role must be at most 100 characters']);
    }

    $conn->begin_transaction();
    try {
        if ($active_part) {
            $del = $conn->prepare('DELETE FROM participant WHERE id = ? AND event_id = ? AND user_id = ?');
            $del->bind_param('iii', $active_part['id'], $event_id, $user_id);
            $del->execute();
            $del->close();
        }

        $existing = $conn->prepare(
            'SELECT id FROM volunteers WHERE event_id = ? AND user_id = ? LIMIT 1'
        );
        $existing->bind_param('ii', $event_id, $user_id);
        $existing->execute();
        $existing_row = $existing->get_result()->fetch_assoc();
        $existing->close();

        if ($existing_row) {
            $react = $conn->prepare(
                "UPDATE volunteers SET status = 'active', role = ? WHERE id = ?"
            );
            $react->bind_param('si', $volunteer_role, $existing_row['id']);
            $react->execute();
            $volunteer_id = (int) $existing_row['id'];
            $react->close();
        } else {
            $ins = $conn->prepare(
                "INSERT INTO volunteers (event_id, user_id, role, status) VALUES (?, ?, ?, 'active')"
            );
            $ins->bind_param('iis', $event_id, $user_id, $volunteer_role);
            $ins->execute();
            $volunteer_id = (int) $ins->insert_id;
            $ins->close();
        }

        $att_del = $conn->prepare('DELETE FROM attendees WHERE event_id = ? AND user_id = ?');
        $att_del->bind_param('ii', $event_id, $user_id);
        $att_del->execute();
        $att_del->close();

        $conn->commit();
        $counts = registration_event_counts($conn, $event_id);
        $message = ($from_role === 'volunteer')
            ? 'Volunteer role updated'
            : ('Switched from ' . $from_role . ' to volunteer');
        return $respond(200, array_merge([
            'status' => 'success',
            'message' => $message,
            'from_role' => $from_role,
            'to_role' => 'volunteer',
            'volunteer_id' => $volunteer_id,
            'role' => $volunteer_role,
            'server_time' => api_server_time_iso(),
        ], $counts, ['viewer_count' => $counts['attendee_count']]));
    } catch (Throwable $e) {
        $conn->rollback();
        return $respond(500, ['status' => 'error', 'message' => 'Could not switch to volunteer']);
    }
}
