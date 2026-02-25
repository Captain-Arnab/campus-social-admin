<?php
include 'db.php';
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit();
}

$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
if ($event_id <= 0) {
    echo json_encode(["status" => "error", "message" => "event_id required"]);
    exit();
}

$sql = "SELECT w.id, w.event_id, w.user_id, w.position, u.full_name, u.email 
        FROM event_winners w 
        JOIN users u ON w.user_id = u.id 
        WHERE w.event_id = $event_id ORDER BY w.position ASC";
$result = $conn->query($sql);
$list = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $list[] = [
            'id' => (int) $row['id'],
            'event_id' => (int) $row['event_id'],
            'user_id' => (int) $row['user_id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'position' => (int) $row['position']
        ];
    }
}
echo json_encode(["status" => "success", "count" => count($list), "data" => $list]);
?>
