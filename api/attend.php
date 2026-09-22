<?php
// attend.php - Join / leave / paid confirm_intent + verify_payment as attendee
header('Content-Type: application/json');

try {
    include 'db.php';
    require_once __DIR__ . '/../event_date_range_schema.php';
    require_once __DIR__ . '/registration_leave_helper.php';
    require_once __DIR__ . '/event_payment_helper.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit();
    }

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid JSON body"]);
        exit();
    }

    $action = strtolower(trim((string) ($data['action'] ?? ($_GET['action'] ?? 'join'))));

    if ($action === 'confirm_intent') {
        $event_id = (int) ($data['event_id'] ?? 0);
        $user_id = (int) ($data['user_id'] ?? 0);
        if ($event_id <= 0 || $user_id <= 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "event_id and user_id are required"]);
            exit();
        }
        $evCols = 'id, event_date, status';
        if (schema_events_has_registration_deadline($conn)) {
            $evCols .= ', registration_deadline';
        }
        if (schema_events_has_fee_modes($conn)) {
            $evCols .= ', attend_mode, attend_fee, participate_mode, participate_fee, volunteer_mode';
        }
        $er = $conn->query("SELECT $evCols FROM events WHERE id = $event_id LIMIT 1");
        $event_row = $er ? $er->fetch_assoc() : null;
        if (!$event_row) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Event not found"]);
            exit();
        }
        if (events_row_registration_closed($event_row)) {
            http_response_code(400);
            echo json_encode([
                "status" => "error",
                "message" => "Registration closed for this event",
                "registration_closed" => true,
            ]);
            exit();
        }
        $res = event_payment_confirm_intent($conn, $event_id, $user_id, 'attendee', []);
        if (($res['status'] ?? '') !== 'success') {
            http_response_code((int) ($res['http'] ?? 400));
        }
        echo json_encode($res);
        exit();
    }

    if ($action === 'verify_payment') {
        require_once __DIR__ . '/event_staff_switch_lib.php';
        $res = event_payment_verify($conn, array_merge($data, ['role' => 'attendee']), function (array $paymentRow, array $meta) use ($conn) {
            $event_id = (int) $paymentRow['event_id'];
            $user_id = (int) $paymentRow['user_id'];

            // Clear other roles then insert attendee as paid
            @$conn->query("DELETE FROM volunteers WHERE user_id = $user_id AND event_id = $event_id");
            @$conn->query("DELETE FROM participant WHERE user_id = $user_id AND event_id = $event_id");
            $exists = $conn->query("SELECT id FROM attendees WHERE user_id = $user_id AND event_id = $event_id LIMIT 1");
            if ($exists && $exists->num_rows > 0) {
                if (schema_join_has_payment_status($conn, 'attendees')) {
                    @$conn->query("UPDATE attendees SET payment_status = 'paid' WHERE user_id = $user_id AND event_id = $event_id");
                }
            } else {
                if (schema_join_has_payment_status($conn, 'attendees')) {
                    $ins = $conn->prepare("INSERT INTO attendees (event_id, user_id, joined_at, payment_status) VALUES (?, ?, NOW(), 'paid')");
                    $ins->bind_param('ii', $event_id, $user_id);
                } else {
                    $ins = $conn->prepare("INSERT INTO attendees (event_id, user_id, joined_at) VALUES (?, ?, NOW())");
                    $ins->bind_param('ii', $event_id, $user_id);
                }
                if (!$ins || !$ins->execute()) {
                    return ['status' => 'error', 'message' => 'Payment confirmed but registration failed'];
                }
                $ins->close();
            }
            $counts = registration_event_counts($conn, $event_id);
            return [
                'status' => 'success',
                'message' => 'Payment verified. You are registered as an attendee',
                'to_role' => 'attendee',
                'payment_status' => 'paid',
                'server_time' => api_server_time_iso(),
                'attendee_count' => $counts['attendee_count'],
                'volunteer_count' => $counts['volunteer_count'],
                'participant_count' => $counts['participant_count'],
                'viewer_count' => $counts['viewer_count'],
            ];
        });
        if (($res['status'] ?? '') !== 'success') {
            http_response_code(400);
        }
        echo json_encode($res);
        exit();
    }

    // ——— leave ———
    if ($action === 'leave' || $action === 'cancel') {
        $event_id = (int) ($data['event_id'] ?? 0);
        $user_id = (int) ($data['user_id'] ?? 0);
        $v = registration_leave_validate_user_event($conn, $event_id, $user_id);
        if (!$v['ok']) {
            http_response_code((int) ($v['http'] ?? 400));
            echo json_encode(["status" => "error", "message" => $v['message']]);
            exit();
        }
        if (event_user_has_paid_lock($conn, $event_id, $user_id)) {
            http_response_code(400);
            echo json_encode(event_paid_lock_error());
            exit();
        }

        $chk = $conn->prepare("SELECT id FROM attendees WHERE user_id = ? AND event_id = ? LIMIT 1");
        $chk->bind_param("ii", $user_id, $event_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "You are not attending this event"]);
            exit();
        }
        $chk->close();

        $del = $conn->prepare("DELETE FROM attendees WHERE user_id = ? AND event_id = ?");
        $del->bind_param("ii", $user_id, $event_id);
        if (!$del->execute() || $del->affected_rows < 1) {
            $del->close();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Failed to leave event"]);
            exit();
        }
        $del->close();

        registration_log_action($conn, $event_id, $user_id, 'attending', 'left', 'User left as attendee');
        $counts = registration_event_counts($conn, $event_id);
        echo json_encode([
            "status" => "success",
            "message" => "Left event",
            "event_id" => $event_id,
            "server_time" => api_server_time_iso(),
            "attendee_count" => $counts['attendee_count'],
            "volunteer_count" => $counts['volunteer_count'],
            "participant_count" => $counts['participant_count'],
            "viewer_count" => $counts['viewer_count'],
            "event" => array_merge(['id' => $event_id], $counts),
        ]);
        exit();
    }

    // ——— join (default) ———
    $required = ['event_id', 'user_id'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing parameter: $field"]);
            exit();
        }
    }

    $event_id = intval($data['event_id']);
    $user_id = intval($data['user_id']);

    if (!$conn) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database connection failed"]);
        exit();
    }

    $user_check = $conn->prepare("SELECT id FROM users WHERE id = ?");
    if (!$user_check) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }
    $user_check->bind_param("i", $user_id);
    $user_check->execute();
    if ($user_check->get_result()->num_rows == 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "User not found"]);
        $user_check->close();
        exit();
    }
    $user_check->close();

    $evCols = 'id, event_date, status';
    if (schema_events_has_registration_deadline($conn)) {
        $evCols .= ', registration_deadline';
    }
    if (schema_events_has_fee_modes($conn)) {
        $evCols .= ', attend_mode, attend_fee, participate_mode, participate_fee, volunteer_mode';
    }
    $event_check = $conn->prepare("SELECT $evCols FROM events WHERE id = ?");
    if (!$event_check) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }
    $event_check->bind_param("i", $event_id);
    $event_check->execute();
    $event_result = $event_check->get_result();
    if ($event_result->num_rows == 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Event not found"]);
        $event_check->close();
        exit();
    }
    $event_row = $event_result->fetch_assoc();
    $event_check->close();

    if (events_row_registration_closed($event_row)) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "Registration closed for this event",
            "registration_closed" => true,
            "registration_deadline" => events_row_registration_deadline_iso($event_row),
            "server_time" => api_server_time_iso(),
        ]);
        exit();
    }

    $modes = event_fee_modes_from_row($event_row);
    $mf = event_role_mode_fee($modes, 'attendee');
    if ($mf['mode'] === 'disabled') {
        http_response_code(400);
        echo json_encode(event_join_disabled_message());
        exit();
    }
    if ($mf['mode'] === 'with_fee') {
        http_response_code(402);
        echo json_encode([
            "status" => "error",
            "message" => "Payment required. Call action=confirm_intent then complete payment (mock Simulate Payment or live checkout).",
            "payment_required" => true,
            "fee" => $mf['fee'],
            "role" => "attendee",
            "mock_gateway" => payment_gateway_is_mock(),
        ]);
        exit();
    }

    if (event_user_has_paid_lock($conn, $event_id, $user_id)) {
        http_response_code(400);
        echo json_encode(event_paid_lock_error());
        exit();
    }

    require_once __DIR__ . '/event_staff_switch_lib.php';
    $vol_chk = $conn->prepare("SELECT id FROM volunteers WHERE user_id = ? AND event_id = ? AND status = 'active' LIMIT 1");
    $vol_chk->bind_param('ii', $user_id, $event_id);
    $vol_chk->execute();
    $has_vol = $vol_chk->get_result()->num_rows > 0;
    $vol_chk->close();
    $part_chk = $conn->prepare("SELECT id FROM participant WHERE user_id = ? AND event_id = ? AND status = 'active' LIMIT 1");
    $part_chk->bind_param('ii', $user_id, $event_id);
    $part_chk->execute();
    $has_part = $part_chk->get_result()->num_rows > 0;
    $part_chk->close();
    $att_chk = $conn->prepare("SELECT id FROM attendees WHERE user_id = ? AND event_id = ? LIMIT 1");
    $att_chk->bind_param('ii', $user_id, $event_id);
    $att_chk->execute();
    $has_att = $att_chk->get_result()->num_rows > 0;
    $att_chk->close();

    if ($has_att && !$has_vol && !$has_part) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "You are already attending this event"]);
        exit();
    }
    if ($has_vol || $has_part || $has_att) {
        event_staff_switch_role($conn, [
            'event_id' => $event_id,
            'user_id' => $user_id,
            'to_role' => 'attendee',
        ]);
        exit();
    }

    if (schema_join_has_payment_status($conn, 'attendees')) {
        $insert_stmt = $conn->prepare("INSERT INTO attendees (event_id, user_id, joined_at, payment_status) VALUES (?, ?, NOW(), 'n/a')");
    } else {
        $insert_stmt = $conn->prepare("INSERT INTO attendees (event_id, user_id, joined_at) VALUES (?, ?, NOW())");
    }
    if (!$insert_stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }
    $insert_stmt->bind_param("ii", $event_id, $user_id);

    if ($insert_stmt->execute()) {
        $counts = registration_event_counts($conn, $event_id);
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "You have been registered as an attendee",
            "attendee_id" => $insert_stmt->insert_id,
            "from_role" => "none",
            "to_role" => "attendee",
            "payment_status" => "n/a",
            "server_time" => api_server_time_iso(),
            "attendee_count" => $counts['attendee_count'],
            "volunteer_count" => $counts['volunteer_count'],
            "participant_count" => $counts['participant_count'],
            "viewer_count" => $counts['viewer_count'],
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to register: " . $insert_stmt->error]);
    }
    $insert_stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Server error: " . $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
?>
