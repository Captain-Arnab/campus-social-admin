<?php
include 'db.php';

// Endpoint: favorites.php
// Methods: POST (Toggle Like), GET (List Favorites)

$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'POST') {
    // TOGGLE FAVORITE (Like/Unlike)
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($data['user_id']) || !isset($data['event_id'])) {
        echo json_encode(["status" => "error", "message" => "Missing parameters"]);
        exit();
    }

    $user_id = intval($data['user_id']);
    $event_id = intval($data['event_id']);

    // Check if already favorited
    $check = $conn->query("SELECT id FROM favorites WHERE user_id = $user_id AND event_id = $event_id");
    
    if ($check->num_rows > 0) {
        // Already liked -> Remove it (Unlike)
        $sql = "DELETE FROM favorites WHERE user_id = $user_id AND event_id = $event_id";
        if ($conn->query($sql)) {
            echo json_encode(["status" => "success", "action" => "removed", "message" => "Removed from favorites"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Database error"]);
        }
    } else {
        // Not liked -> Add it (Like)
        $sql = "INSERT INTO favorites (user_id, event_id) VALUES ($user_id, $event_id)";
        if ($conn->query($sql)) {
            echo json_encode(["status" => "success", "action" => "added", "message" => "Added to favorites"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Database error"]);
        }
    }
} 
elseif ($method == 'GET') {
    // GET USER FAVORITES
    if (!isset($_GET['user_id'])) {
        echo json_encode(["status" => "error", "message" => "User ID required"]);
        exit();
    }

    $user_id = intval($_GET['user_id']);
    
    // Join with events table to get event details for the favorites list
    $sql = "SELECT e.*, u.full_name as organizer_name, u.profile_pic as organizer_avatar,
            (SELECT COUNT(*) FROM volunteers v WHERE v.event_id = e.id AND v.status = 'active') as volunteer_count,
            (SELECT COUNT(*) FROM attendees a WHERE a.event_id = e.id) as attendee_count,
            f.created_at as fav_date 
            FROM favorites f 
            JOIN events e ON f.event_id = e.id 
            JOIN users u ON e.organizer_id = u.id
            WHERE f.user_id = $user_id 
            ORDER BY f.created_at DESC";
            
    $result = $conn->query($sql);
    $favorites = [];
    
    while ($row = $result->fetch_assoc()) {
        $row['banners'] = json_decode($row['banners'] ?? '[]');
        $favorites[] = $row;
    }
    
    echo json_encode(["status" => "success", "data" => $favorites]);
}
?>