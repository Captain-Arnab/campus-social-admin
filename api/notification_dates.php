<?php
/**
 * notification_dates.php — List dates when the app/calendar may show notifications.
 * Data comes from celebration_days (same rows the cron uses for broadcast greetings).
 * Optional: include_events=1 merges approved event start dates (informational only; cron does not use them).
 */
header('Content-Type: application/json');

try {
    include 'db.php';

    if (!$conn) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit();
    }

    $celebration_check = $conn->query("SHOW TABLES LIKE 'celebration_days'");
    if (!$celebration_check || $celebration_check->num_rows === 0) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'celebration_days table missing. Import college schema or create celebration_days.',
        ]);
        exit();
    }

    $has_push = false;
    $pc = @$conn->query("SHOW COLUMNS FROM `celebration_days` LIKE 'push_title'");
    if ($pc && $pc->num_rows > 0) {
        $has_push = true;
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $include_events = isset($_GET['include_events']) && $_GET['include_events'] !== '0';
        $from = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-d');
        $to = isset($_GET['to']) ? trim($_GET['to']) : '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-d');
        }
        if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = '';
        }

        $dates = [];

        $select = $has_push
            ? 'id, occasion_name, occasion_date, is_fixed, is_tentative, sort_order, push_title, push_message, created_at'
            : 'id, occasion_name, occasion_date, is_fixed, is_tentative, sort_order, created_at';

        if ($to !== '') {
            $stmt = $conn->prepare(
                "SELECT {$select} FROM celebration_days WHERE occasion_date >= ? AND occasion_date <= ? ORDER BY occasion_date ASC, sort_order ASC, id ASC"
            );
            $stmt->bind_param('ss', $from, $to);
        } else {
            $stmt = $conn->prepare(
                "SELECT {$select} FROM celebration_days WHERE occasion_date >= ? ORDER BY occasion_date ASC, sort_order ASC, id ASC"
            );
            $stmt->bind_param('s', $from);
        }

        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $pushTitle = $has_push ? trim((string)($row['push_title'] ?? '')) : '';
                $pushMsg = $has_push ? trim((string)($row['push_message'] ?? '')) : '';
                $occ = $row['occasion_name'];
                $dates[] = [
                    'id' => (int) $row['id'],
                    'event_id' => null,
                    'notify_date' => $row['occasion_date'],
                    'title' => $pushTitle !== '' ? $pushTitle : $occ,
                    'message' => $pushMsg !== '' ? $pushMsg : ('Happy ' . $occ . '!'),
                    'event_title' => null,
                    'source' => 'celebration',
                    'occasion_name' => $occ,
                    'is_fixed' => (bool) (int) $row['is_fixed'],
                    'is_tentative' => (bool) (int) $row['is_tentative'],
                    'sort_order' => $row['sort_order'] !== null ? (int) $row['sort_order'] : null,
                ];
            }
            $stmt->close();
        }

        if ($include_events) {
            require_once __DIR__ . '/../event_date_range_schema.php';
            if (schema_events_has_event_end_date($conn)) {
                $estmt = $conn->prepare(
                    'SELECT id, title, event_date, event_end_date, venue FROM events WHERE status = \'approved\' AND (DATE(event_date) >= ? OR (event_end_date IS NOT NULL AND DATE(event_end_date) >= ?)) ORDER BY event_date ASC LIMIT 100'
                );
                if ($estmt) {
                    $estmt->bind_param('ss', $from, $from);
                }
            } else {
                $estmt = $conn->prepare(
                    'SELECT id, title, event_date, venue FROM events WHERE status = \'approved\' AND DATE(event_date) >= ? ORDER BY event_date ASC LIMIT 100'
                );
                if ($estmt) {
                    $estmt->bind_param('s', $from);
                }
            }
            if ($estmt) {
                $estmt->execute();
                $eres = $estmt->get_result();
                while ($erow = $eres->fetch_assoc()) {
                    $ed = date('Y-m-d', strtotime($erow['event_date']));
                    if ($to !== '' && strcmp($ed, $to) > 0) {
                        continue;
                    }
                    $rowOut = [
                        'id' => 'e' . $erow['id'],
                        'event_id' => (int) $erow['id'],
                        'notify_date' => $ed,
                        'title' => $erow['title'],
                        'message' => 'Event: ' . $erow['title'] . ' at ' . $erow['venue'],
                        'event_title' => $erow['title'],
                        'source' => 'event',
                    ];
                    if (!empty($erow['event_end_date']) && ($erow['event_end_date'] ?? '') !== '0000-00-00 00:00:00') {
                        $rowOut['event_end_date'] = date('Y-m-d', strtotime($erow['event_end_date']));
                    }
                    $dates[] = $rowOut;
                }
                $estmt->close();
            }
            usort($dates, function ($a, $b) {
                return strcmp($a['notify_date'], $b['notify_date']);
            });
        }

        echo json_encode(['status' => 'success', 'count' => count($dates), 'data' => $dates]);
        exit();
    }

    if ($method === 'POST') {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Add or edit celebration days from the admin panel (Celebration days).',
        ]);
        exit();
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
