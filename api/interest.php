<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $event_id = intval($data['event_id']);
    $action = isset($data['action']) ? $data['action'] : 'like'; // 'like' or 'unlike'

    // NOTE: In a complex app, you would have a separate 'likes' table to track WHO liked what.
    // For this simple schema, we just increment/decrement the count on the event table.

    if ($action == 'like') {
        $sql = "UPDATE events SET interest_count = interest_count + 1 WHERE id = $event_id";
    } else {
        $sql = "UPDATE events SET interest_count = GREATEST(interest_count - 1, 0) WHERE id = $event_id";
    }

    if ($conn->query($sql)) {
        // Fetch new count
        $new_count = $conn->query("SELECT interest_count FROM events WHERE id = $event_id")->fetch_assoc()['interest_count'];
        echo json_encode(["status" => "success", "new_count" => $new_count]);
    } else {
        echo json_encode(["status" => "error", "message" => $conn->error]);
    }
}
?>