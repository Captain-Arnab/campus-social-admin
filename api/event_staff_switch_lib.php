<?php
/**
 * Shared logic: set a user's role (volunteer or participant) for one event.
 * Used by event_staff_switch.php, volunteers.php, and participant.php (action=switch_staff_role).
 *
 * Supports every starting state:
 *   - attendee  -> participant / volunteer (the user "upgrades" their join)
 *   - volunteer <-> participant            (swap staff role)
 *   - volunteer -> volunteer               (change the assigned volunteer role)
 *   - participant -> participant           (update the department/class)
 *
 * Roles are mutually exclusive: becoming a participant/volunteer removes the
 * attendee row and the opposite staff role, so per-event counts never double up.
 */

function event_staff_switch_role(mysqli $conn, array $data): void
{
    if (!isset($data['event_id'], $data['user_id'], $data['to_role'])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'event_id, user_id, and to_role (volunteer|participant) are required',
        ]);
        return;
    }

    $event_id = (int) $data['event_id'];
    $user_id = (int) $data['user_id'];
    $to_role = strtolower(trim((string) $data['to_role']));

    if ($event_id <= 0 || $user_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid event_id or user_id']);
        return;
    }

    if (!in_array($to_role, ['volunteer', 'participant'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'to_role must be volunteer or participant']);
        return;
    }

    $user_stmt = $conn->prepare('SELECT id, is_student, status FROM users WHERE id = ? LIMIT 1');
    if (!$user_stmt) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error']);
        return;
    }
    $user_stmt->bind_param('i', $user_id);
    $user_stmt->execute();
    $user_row = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();

    if (!$user_row) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        return;
    }
    if (($user_row['status'] ?? '') === 'blocked') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Account blocked']);
        return;
    }

    $user_is_student = (int) $user_row['is_student'];

    $event_stmt = $conn->prepare(
        'SELECT e.id, e.status, u.is_student AS organizer_is_student
         FROM events e
         JOIN users u ON e.organizer_id = u.id
         WHERE e.id = ? LIMIT 1'
    );
    if (!$event_stmt) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error']);
        return;
    }
    $event_stmt->bind_param('i', $event_id);
    $event_stmt->execute();
    $event_row = $event_stmt->get_result()->fetch_assoc();
    $event_stmt->close();

    if (!$event_row) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Event not found']);
        return;
    }

    if (($event_row['status'] ?? '') !== 'approved') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Role switch is only allowed for approved events']);
        return;
    }

    $organizer_is_student = (int) $event_row['organizer_is_student'];
    if ($user_is_student !== $organizer_is_student) {
        $user_role_name = $user_is_student ? 'student' : 'faculty';
        $organizer_role_name = $organizer_is_student ? 'student' : 'faculty';
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => "As a $user_role_name, you can only join $organizer_role_name events.",
        ]);
        return;
    }

    // Current role for this event (active rows only). A user can hold at most one
    // staff role at a time; attendee is a separate "joined" state that we treat as
    // mutually exclusive with participant/volunteer so the counts stay in sync.
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

    // Human-readable label for the role the user is coming from (for the response).
    if ($active_vol) {
        $from_role = 'volunteer';
    } elseif ($active_part) {
        $from_role = 'participant';
    } elseif ($is_attendee) {
        $from_role = 'attendee';
    } else {
        $from_role = 'none';
    }

    if ($to_role === 'participant') {
        $department_class = isset($data['department_class'])
            ? trim((string) $data['department_class'])
            : '';
        if ($department_class === '' && $active_part) {
            // Pure role refresh: keep the dept already on the participant record.
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
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'department_class is required when switching to participant',
            ]);
            return;
        }

        $conn->begin_transaction();
        try {
            // Drop any active volunteer role for this event (volunteer -> participant).
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

            // Remove the attendee row so the same user is never counted twice.
            $att_del = $conn->prepare('DELETE FROM attendees WHERE event_id = ? AND user_id = ?');
            $att_del->bind_param('ii', $event_id, $user_id);
            $att_del->execute();
            $att_del->close();

            $conn->commit();

            if ($from_role === 'participant') {
                $message = 'Participant details updated';
            } else {
                $message = 'Switched from ' . $from_role . ' to participant';
            }
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => $message,
                'from_role' => $from_role,
                'to_role' => 'participant',
                'participant_id' => $participant_id,
                'department_class' => $department_class,
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Could not switch to participant']);
        }
        return;
    }

    // Switch to volunteer (also handles changing an existing volunteer's role).
    $volunteer_role = isset($data['role']) ? trim((string) $data['role']) : '';
    if ($volunteer_role === '' && $active_vol) {
        // Keep the existing role when none supplied (e.g. re-confirming the role).
        $volunteer_role = trim((string) ($active_vol['role'] ?? ''));
    }
    if ($volunteer_role === '') {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'role is required when switching to volunteer',
        ]);
        return;
    }
    if (strlen($volunteer_role) > 100) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'role must be at most 100 characters']);
        return;
    }

    $conn->begin_transaction();
    try {
        // Drop any active participant record for this event (participant -> volunteer).
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

        // Remove the attendee row so the same user is never counted twice.
        $att_del = $conn->prepare('DELETE FROM attendees WHERE event_id = ? AND user_id = ?');
        $att_del->bind_param('ii', $event_id, $user_id);
        $att_del->execute();
        $att_del->close();

        $conn->commit();

        if ($from_role === 'volunteer') {
            $message = 'Volunteer role updated';
        } else {
            $message = 'Switched from ' . $from_role . ' to volunteer';
        }
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => $message,
            'from_role' => $from_role,
            'to_role' => 'volunteer',
            'volunteer_id' => $volunteer_id,
            'role' => $volunteer_role,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Could not switch to volunteer']);
    }
}
