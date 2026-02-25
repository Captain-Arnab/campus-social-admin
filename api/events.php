<?php
include 'db.php';
$method = $_SERVER['REQUEST_METHOD'];

// --- 1. GET EVENTS ---
if ($method == 'GET') {
    $view = isset($_GET['type']) ? $_GET['type'] : 'live';
    $search_query = isset($_GET['search']) ? $_GET['search'] : '';
    $category_filter = isset($_GET['category']) ? $_GET['category'] : '';
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    // Base query organizer_id JOIN 
    $sql = "SELECT e.*, u.full_name as organizer_name, u.profile_pic as organizer_avatar, u.is_student as organizer_is_student,
            (SELECT COUNT(*) FROM volunteers v WHERE v.event_id = e.id AND v.status = 'active') as volunteer_count,
            (SELECT COUNT(*) FROM attendees a WHERE a.event_id = e.id) as attendee_count,
            (SELECT COUNT(*) FROM participant p WHERE p.event_id = e.id AND p.status = 'active') as participant_count
            FROM events e 
            JOIN users u ON e.organizer_id = u.id 
            WHERE 1=1";

    if ($id > 0) {
        // Get specific event by ID
        $sql .= " AND e.id = $id";
    } elseif ($view == 'attending' && $user_id > 0) {
        // Get events the user is attending as an attendee
        $sql = "SELECT e.*, u.full_name as organizer_name, u.profile_pic as organizer_avatar, u.is_student as organizer_is_student,
                (SELECT COUNT(*) FROM volunteers v WHERE v.event_id = e.id AND v.status = 'active') as volunteer_count,
                (SELECT COUNT(*) FROM attendees a WHERE a.event_id = e.id) as attendee_count,
                (SELECT COUNT(*) FROM participant p WHERE p.event_id = e.id AND p.status = 'active') as participant_count,
                'attendee' as user_role
                FROM events e 
                JOIN users u ON e.organizer_id = u.id 
                JOIN attendees a ON e.id = a.event_id
                WHERE a.user_id = $user_id AND e.status = 'approved'
                ORDER BY e.event_date ASC";
        
        $result = $conn->query($sql);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $row['banners'] = json_decode($row['banners'] ?? '[]');
            $data[] = $row;
        }
        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        exit();
    } elseif ($view == 'volunteering' && $user_id > 0) {
        // Get events the user is volunteering for
        $sql = "SELECT e.*, u.full_name as organizer_name, u.profile_pic as organizer_avatar, u.is_student as organizer_is_student,
                (SELECT COUNT(*) FROM volunteers v WHERE v.event_id = e.id AND v.status = 'active') as volunteer_count,
                (SELECT COUNT(*) FROM attendees a WHERE a.event_id = e.id) as attendee_count,
                (SELECT COUNT(*) FROM participant p WHERE p.event_id = e.id AND p.status = 'active') as participant_count,
                v.role as user_role
                FROM events e 
                JOIN users u ON e.organizer_id = u.id 
                JOIN volunteers v ON e.id = v.event_id
                WHERE v.user_id = $user_id AND v.status = 'active' AND e.status = 'approved'
                ORDER BY e.event_date ASC";
        
        $result = $conn->query($sql);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $row['banners'] = json_decode($row['banners'] ?? '[]');
            $data[] = $row;
        }
        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        exit();
    } elseif ($view == 'participating' && $user_id > 0) {
        // Get events the user is participating in
        $sql = "SELECT e.*, u.full_name as organizer_name, u.profile_pic as organizer_avatar, u.is_student as organizer_is_student,
                (SELECT COUNT(*) FROM volunteers v WHERE v.event_id = e.id AND v.status = 'active') as volunteer_count,
                (SELECT COUNT(*) FROM attendees a WHERE a.event_id = e.id) as attendee_count,
                (SELECT COUNT(*) FROM participant p WHERE p.event_id = e.id AND p.status = 'active') as participant_count,
                'participant' as user_role
                FROM events e 
                JOIN users u ON e.organizer_id = u.id 
                JOIN participant p ON e.id = p.event_id
                WHERE p.user_id = $user_id AND p.status = 'active' AND e.status = 'approved'
                ORDER BY e.event_date ASC";
        
        $result = $conn->query($sql);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $row['banners'] = json_decode($row['banners'] ?? '[]');
            $data[] = $row;
        }
        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        exit();
    } elseif ($view == 'hosted' && $user_id > 0) {
        // Get events the user is hosting
        $sql = "SELECT e.*, u.full_name as organizer_name, u.profile_pic as organizer_avatar, u.is_student as organizer_is_student,
                (SELECT COUNT(*) FROM volunteers v WHERE v.event_id = e.id AND v.status = 'active') as volunteer_count,
                (SELECT COUNT(*) FROM attendees a WHERE a.event_id = e.id) as attendee_count,
                (SELECT COUNT(*) FROM participant p WHERE p.event_id = e.id AND p.status = 'active') as participant_count,
                'host' as user_role
                FROM events e 
                JOIN users u ON e.organizer_id = u.id 
                WHERE e.organizer_id = $user_id AND e.status = 'approved'
                ORDER BY e.event_date ASC";
        
        $result = $conn->query($sql);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $row['banners'] = json_decode($row['banners'] ?? '[]');
            $data[] = $row;
        }
        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        exit();
    } elseif ($view == 'hosted_all' && $user_id > 0) {
        // Get all events the user is hosting (including pending/hold)
        $sql = "SELECT e.*, u.full_name as organizer_name, u.profile_pic as organizer_avatar, u.is_student as organizer_is_student,
                (SELECT COUNT(*) FROM volunteers v WHERE v.event_id = e.id AND v.status = 'active') as volunteer_count,
                (SELECT COUNT(*) FROM attendees a WHERE a.event_id = e.id) as attendee_count,
                (SELECT COUNT(*) FROM participant p WHERE p.event_id = e.id AND p.status = 'active') as participant_count,
                'host' as user_role
                FROM events e 
                JOIN users u ON e.organizer_id = u.id 
                WHERE e.organizer_id = $user_id
                ORDER BY e.created_at DESC";
        
        $result = $conn->query($sql);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $row['banners'] = json_decode($row['banners'] ?? '[]');
            $data[] = $row;
        }
        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        exit();
    } else {
        // Live or past events (default behavior)
        $sql .= " AND e.status = 'approved'";

        if ($view == 'past') {
            $sql .= " AND e.event_date < NOW()";
        } else {
            $sql .= " AND e.event_date >= NOW()";
        }

        if (!empty($search_query)) {
            $search_query = $conn->real_escape_string($search_query);
            $sql .= " AND (e.title LIKE '%$search_query%' OR u.full_name LIKE '%$search_query%' OR e.venue LIKE '%$search_query%')";
        }

        if (!empty($category_filter)) {
            $category_filter = $conn->real_escape_string($category_filter);
            $sql .= " AND e.category = '$category_filter'";
        }
    }

    $sql .= " ORDER BY e.event_date ASC";

    $result = $conn->query($sql);
    $data = [];

    while ($row = $result->fetch_assoc()) {
        $row['banners'] = json_decode($row['banners'] ?? '[]');
        if ($id > 0) {
            $event_id = $row['id'];
            
            // Get volunteer list
            $vol_res = $conn->query("SELECT u.full_name as student_name, v.role FROM volunteers v JOIN users u ON v.user_id = u.id WHERE v.event_id = $event_id AND v.status = 'active'");
            $row['volunteer_list'] = [];
            while($v = $vol_res->fetch_assoc()) { $row['volunteer_list'][] = $v; }
            
            // Get participant list
            $part_res = $conn->query("SELECT u.full_name as student_name FROM participant p JOIN users u ON p.user_id = u.id WHERE p.event_id = $event_id AND p.status = 'active'");
            $row['participant_list'] = [];
            while($p = $part_res->fetch_assoc()) { $row['participant_list'][] = $p; }
        }
        $data[] = $row;
    }

    if ($id > 0 && empty($data)) {
        echo json_encode(["status" => "error", "message" => "Event pawa jayni"]);
    } else {
        echo json_encode(["status" => "success", "count" => count($data), "data" => ($id > 0) ? $data[0] : $data]);
    }
}

// --- 2. CREATE EVENT (POST) ---
elseif ($method == 'POST') {
    if (!isset($_POST['title']) || !isset($_POST['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Event-er proyojonio details dewa hoyni"]);
        exit();
    }

    $title = $conn->real_escape_string($_POST['title']);
    $desc = $conn->real_escape_string($_POST['description'] ?? '');
    $date = $conn->real_escape_string($_POST['event_date']);
    $cat = $conn->real_escape_string($_POST['category']);
    $venue = $conn->real_escape_string($_POST['venue']);
    $organizer_id = intval($_POST['user_id']);

    $image_paths = [];
    // Banner Upload korata OPTIONAL
    if (isset($_FILES['banners']) && !empty($_FILES['banners']['tmp_name'][0])) {
        if (!is_dir('../uploads/events/')) {
            mkdir('../uploads/events/', 0777, true);
        }

        foreach ($_FILES['banners']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['banners']['error'][$key] === UPLOAD_ERR_OK) {
                $filename = "evt_" . time() . "_" . $key . "_" . basename($_FILES['banners']['name'][$key]);
                if (move_uploaded_file($tmp_name, "../uploads/events/" . $filename)) {
                    $image_paths[] = $filename;
                }
            }
        }
    }
    
    $banners_json = json_encode($image_paths);

    $sql = "INSERT INTO events (title, description, event_date, category, venue, status, organizer_id, banners) 
            VALUES ('$title', '$desc', '$date', '$cat', '$venue', 'pending', $organizer_id, '$banners_json')";

    if ($conn->query($sql)) {
        echo json_encode(["status" => "success", "message" => "Event-ti admin-er approval-er jonyo pathano hoyechhe", "id" => $conn->insert_id]);
    } else {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
    }
}

// --- 3. UPDATE EVENT (PUT) ---
elseif ($method == 'PUT') {
    // Note: PUT usually takes JSON body
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($data['id'])) {
        echo json_encode(["status" => "error", "message" => "Event ID required"]);
        exit();
    }

    $id = intval($data['id']);
    $title = $conn->real_escape_string($data['title']);
    $desc = $conn->real_escape_string($data['description'] ?? '');
    $venue = $conn->real_escape_string($data['venue'] ?? '');
    
    $sql = "UPDATE events SET title='$title', description='$desc', venue='$venue' WHERE id=$id";

    if ($conn->query($sql)) {
        echo json_encode(["status" => "success", "message" => "Event updated"]);
    } else {
        echo json_encode(["status" => "error", "message" => $conn->error]);
    }
}

// --- 4. DELETE EVENT (DELETE) ---
elseif ($method == 'DELETE') {
    if (!isset($_GET['id']) || !isset($_GET['user_id'])) {
        echo json_encode(["status" => "error", "message" => "id and user_id required"]);
        exit();
    }

    $id = intval($_GET['id']);
    $user_id = intval($_GET['user_id']);

    // Fetch event status + ownership
    $check = $conn->prepare("SELECT organizer_id, status FROM events WHERE id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $evt = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$evt) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Event not found"]);
        exit();
    }

    if ((int)$evt['organizer_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "You are not allowed to delete this event"]);
        exit();
    }

    // Only allow delete before ANY approval (pending / hold-before-approval only)
    if (!in_array($evt['status'], ['pending', 'hold'], true)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Event cannot be deleted after approval/rejection"]);
        exit();
    }

    // If event was ever approved (even if currently on hold), do not allow user deletion
    $was_approved = false;
    $appr = $conn->prepare("SELECT 1 FROM event_status_log WHERE event_id = ? AND (old_status = 'approved' OR new_status = 'approved') LIMIT 1");
    if ($appr) {
        $appr->bind_param("i", $id);
        $appr->execute();
        $appr->store_result();
        $was_approved = ($appr->num_rows > 0);
        $appr->close();
    }

    if ($was_approved) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Event cannot be deleted after approval"]);
        exit();
    }

    // Delete related records first (if any), then delete the event
    $conn->begin_transaction();
    try {
        $del_tables = [
            "DELETE FROM favorites WHERE event_id = ?",
            "DELETE FROM attendees WHERE event_id = ?",
            "DELETE FROM volunteers WHERE event_id = ?",
            "DELETE FROM participant WHERE event_id = ?",
            "DELETE FROM event_status_log WHERE event_id = ?"
        ];

        foreach ($del_tables as $q) {
            $st = $conn->prepare($q);
            $st->bind_param("i", $id);
            $st->execute();
            $st->close();
        }

        $del = $conn->prepare("DELETE FROM events WHERE id = ? AND organizer_id = ? AND status IN ('pending','hold')");
        $del->bind_param("ii", $id, $user_id);
        $del->execute();
        $affected = $del->affected_rows;
        $del->close();

        if ($affected !== 1) {
            throw new Exception("Delete failed");
        }

        $conn->commit();
        echo json_encode(["status" => "success", "message" => "Event deleted"]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Delete failed"]);
    }
}

else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
}
?>