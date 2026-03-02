<?php
// celebration_dates.php — Get list of Celebration Days for app notifications (2026, 2027)
header('Content-Type: application/json');

try {
    include 'db.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit();
    }

    if (!$conn) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database connection failed"]);
        exit();
    }

    $table_check = $conn->query("SHOW TABLES LIKE 'celebration_days'");
    if (!$table_check || $table_check->num_rows === 0) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Celebration days not installed. Run api/migrations/add_celebration_days.sql"]);
        exit();
    }

    $from = isset($_GET['from']) ? $conn->real_escape_string($_GET['from']) : date('Y-m-d');
    $to = isset($_GET['to']) ? $conn->real_escape_string($_GET['to']) : null;
    $year = isset($_GET['year']) ? intval($_GET['year']) : null;

    $sql = "SELECT id, occasion_name, occasion_date, is_fixed, is_tentative, sort_order
            FROM celebration_days
            WHERE occasion_date >= ?";
    $params = [$from];
    $types = "s";

    if ($to) {
        $sql .= " AND occasion_date <= ?";
        $params[] = $to;
        $types .= "s";
    }
    if ($year >= 2000 && $year <= 2100) {
        $sql .= " AND YEAR(occasion_date) = ?";
        $params[] = $year;
        $types .= "i";
    }

    $sql .= " ORDER BY occasion_date ASC, sort_order ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        exit();
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = [
            'id' => (int) $row['id'],
            'occasion_name' => $row['occasion_name'],
            'occasion_date' => $row['occasion_date'],
            'is_fixed' => (bool) $row['is_fixed'],
            'is_tentative' => (bool) $row['is_tentative'],
            'sort_order' => $row['sort_order'] !== null ? (int) $row['sort_order'] : null
        ];
    }
    $stmt->close();

    echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
