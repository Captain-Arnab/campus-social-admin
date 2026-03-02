<?php
// notification_dates.php — Get list of dates for notifications (and optionally manage them)
header('Content-Type: application/json');

try {
    include 'db.php';

    if (!$conn) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database connection failed"]);
        exit();
    }

    $table_check = $conn->query("SHOW TABLES LIKE 'notification_dates'");
    if (!$table_check || $table_check->num_rows === 0) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Notification tables not installed. Run api/migrations/add_notification_tables.sql"]);
        exit();
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // GET — return list of notification dates (notification_dates, optional celebration_days, optional event dates)
    if ($method === 'GET') {
        $include_events = isset($_GET['include_events']) && $_GET['include_events'] !== '0';
        $include_celebrations = isset($_GET['include_celebrations']) && $_GET['include_celebrations'] !== '0';
        $from = isset($_GET['from']) ? $conn->real_escape_string($_GET['from']) : date('Y-m-d');
        $to = isset($_GET['to']) ? $conn->real_escape_string($_GET['to']) : null;

        $dates = [];

        // From notification_dates table
        $sql = "SELECT nd.id, nd.event_id, nd.notify_date, nd.title, nd.message, nd.created_at, e.title as event_title
                FROM notification_dates nd
                LEFT JOIN events e ON e.id = nd.event_id
                WHERE nd.notify_date >= ?
                ORDER BY nd.notify_date ASC";
        if ($to) {
            $sql = "SELECT nd.id, nd.event_id, nd.notify_date, nd.title, nd.message, nd.created_at, e.title as event_title
                    FROM notification_dates nd
                    LEFT JOIN events e ON e.id = nd.event_id
                    WHERE nd.notify_date >= ? AND nd.notify_date <= ?
                    ORDER BY nd.notify_date ASC";
        }
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($to) {
                $stmt->bind_param("ss", $from, $to);
            } else {
                $stmt->bind_param("s", $from);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $dates[] = [
                    'id' => (int) $row['id'],
                    'event_id' => $row['event_id'] ? (int) $row['event_id'] : null,
                    'notify_date' => $row['notify_date'],
                    'title' => $row['title'],
                    'message' => $row['message'],
                    'event_title' => $row['event_title'],
                    'source' => 'schedule'
                ];
            }
            $stmt->close();
        }

        // Optionally include Celebration Days (e.g. New Year, Republic Day, Diwali — 2026/2027)
        if ($include_celebrations) {
            $celebrations_check = $conn->query("SHOW TABLES LIKE 'celebration_days'");
            if ($celebrations_check && $celebrations_check->num_rows > 0) {
                $c_sql = "SELECT id, occasion_name, occasion_date, is_fixed, is_tentative FROM celebration_days WHERE occasion_date >= ?";
                $c_params = [$from];
                $c_types = "s";
                if ($to) {
                    $c_sql .= " AND occasion_date <= ?";
                    $c_params[] = $to;
                    $c_types .= "s";
                }
                $c_sql .= " ORDER BY occasion_date ASC";
                $c_stmt = $conn->prepare($c_sql);
                if ($c_stmt) {
                    if ($to) {
                        $c_stmt->bind_param("ss", $from, $to);
                    } else {
                        $c_stmt->bind_param("s", $from);
                    }
                    $c_stmt->execute();
                    $c_res = $c_stmt->get_result();
                    while ($c_row = $c_res->fetch_assoc()) {
                        $dates[] = [
                            'id' => 'c' . $c_row['id'],
                            'event_id' => null,
                            'notify_date' => $c_row['occasion_date'],
                            'title' => $c_row['occasion_name'],
                            'message' => $c_row['occasion_name'],
                            'event_title' => null,
                            'source' => 'celebration',
                            'is_fixed' => (bool) $c_row['is_fixed'],
                            'is_tentative' => (bool) $c_row['is_tentative']
                        ];
                    }
                    $c_stmt->close();
                }
            }
        }

        // Optionally include upcoming event dates as notification dates
        if ($include_events) {
            $events_sql = "SELECT id, title, event_date, venue FROM events WHERE status = 'approved' AND DATE(event_date) >= ? ORDER BY event_date ASC LIMIT 100";
            $estmt = $conn->prepare($events_sql);
            if ($estmt) {
                $estmt->bind_param("s", $from);
                $estmt->execute();
                $eres = $estmt->get_result();
                while ($erow = $eres->fetch_assoc()) {
                    $dates[] = [
                        'id' => 'e' . $erow['id'],
                        'event_id' => (int) $erow['id'],
                        'notify_date' => date('Y-m-d', strtotime($erow['event_date'])),
                        'title' => $erow['title'],
                        'message' => 'Event: ' . $erow['title'] . ' at ' . $erow['venue'],
                        'event_title' => $erow['title'],
                        'source' => 'event'
                    ];
                }
                $estmt->close();
            }
        }

        usort($dates, function ($a, $b) {
            return strcmp($a['notify_date'], $b['notify_date']);
        });

        echo json_encode(["status" => "success", "count" => count($dates), "data" => $dates]);
        exit();
    }

    // POST — add a notification date (e.g. for admin or organizer)
    if ($method === 'POST') {
        $input = file_get_contents("php://input");
        $data = json_decode($input, true);

        $notify_date = isset($data['notify_date']) ? trim($data['notify_date']) : null;
        $title = isset($data['title']) ? $conn->real_escape_string(trim($data['title'])) : '';
        $message = isset($data['message']) ? $conn->real_escape_string(trim($data['message'])) : '';
        $event_id = isset($data['event_id']) ? intval($data['event_id']) : null;

        if (!$notify_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $notify_date)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Valid notify_date (YYYY-MM-DD) required"]);
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO notification_dates (event_id, notify_date, title, message) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            exit();
        }
        $stmt->bind_param("isss", $event_id, $notify_date, $title, $message);
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Notification date added", "id" => $stmt->insert_id]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $stmt->error]);
        }
        $stmt->close();
        exit();
    }

    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
