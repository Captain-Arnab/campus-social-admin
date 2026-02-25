<?php
include 'db.php';
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit();
}

// GET: list e-certificates for a user (app: "my certificates") or for an event
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;

if ($user_id > 0) {
    $sql = "SELECT c.id, c.event_id, c.type, c.file_path, c.uploaded_at, e.title as event_title, e.event_date 
            FROM event_certificates c 
            JOIN events e ON c.event_id = e.id 
            WHERE c.user_id = $user_id ORDER BY c.uploaded_at DESC";
    $result = $conn->query($sql);
    $list = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $list[] = [
                'id' => (int) $row['id'],
                'event_id' => (int) $row['event_id'],
                'event_title' => $row['event_title'],
                'event_date' => $row['event_date'],
                'type' => $row['type'],
                'file_path' => $row['file_path'],
                'uploaded_at' => $row['uploaded_at']
            ];
        }
    }
    echo json_encode(["status" => "success", "count" => count($list), "data" => $list]);
    exit();
}

if ($event_id > 0) {
    $sql = "SELECT c.id, c.event_id, c.user_id, c.type, c.file_path, c.uploaded_at, u.full_name 
            FROM event_certificates c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.event_id = $event_id ORDER BY c.type, u.full_name";
    $result = $conn->query($sql);
    $list = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $list[] = [
                'id' => (int) $row['id'],
                'event_id' => (int) $row['event_id'],
                'user_id' => (int) $row['user_id'],
                'full_name' => $row['full_name'],
                'type' => $row['type'],
                'file_path' => $row['file_path'],
                'uploaded_at' => $row['uploaded_at']
            ];
        }
    }
    echo json_encode(["status" => "success", "count" => count($list), "data" => $list]);
    exit();
}

echo json_encode(["status" => "error", "message" => "Provide user_id or event_id"]);
?>
