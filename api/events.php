<?php
include 'db.php';
require_once __DIR__ . '/sms_helper.php';
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
            $vol_res = $conn->query("SELECT u.full_name AS student_name, u.full_name AS full_name, u.phone AS phone, u.phone AS contact_number, v.role, v.user_id, v.attended, v.attendance_marked_at FROM volunteers v JOIN users u ON v.user_id = u.id WHERE v.event_id = $event_id AND v.status = 'active'");
            $row['volunteer_list'] = [];
            while ($v = $vol_res->fetch_assoc()) {
                $row['volunteer_list'][] = $v;
            }

            $part_res = $conn->query("SELECT u.full_name AS student_name, u.full_name AS full_name, u.phone AS phone, u.phone AS contact_number, p.user_id, p.department_class, 'Participant' AS role, p.attended, p.attendance_marked_at FROM participant p JOIN users u ON p.user_id = u.id WHERE p.event_id = $event_id AND p.status = 'active'");
            $row['participant_list'] = [];
            while ($p = $part_res->fetch_assoc()) {
                $row['participant_list'][] = $p;
            }
            
            // Event editors (for app: who can edit)
            $row['editor_ids'] = [];
            $ed_res = @$conn->query("SELECT user_id FROM event_editors WHERE event_id = $event_id");
            if ($ed_res) {
                while ($ed = $ed_res->fetch_assoc()) { $row['editor_ids'][] = (int) $ed['user_id']; }
            }
            
            // Event winners (from participants)
            $row['winners'] = [];
            $win_res = @$conn->query("SELECT w.user_id, w.position, u.full_name FROM event_winners w JOIN users u ON w.user_id = u.id WHERE w.event_id = $event_id ORDER BY w.position ASC");
            if ($win_res) {
                while ($w = $win_res->fetch_assoc()) {
                    $row['winners'][] = ['user_id' => (int) $w['user_id'], 'full_name' => $w['full_name'], 'position' => (int) $w['position']];
                }
            }
            // Pending edit (when event has editors, organizer/editor edits await admin approval)
            $row['pending_edit'] = null;
            $pe_cols = "title, description, venue, event_date, category, submitted_by_user_id, submitted_at";
            $has_banners = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'banners'");
            if ($has_banners && $has_banners->num_rows > 0) {
                $pe_cols .= ", banners";
            }
            $has_rules_pe = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'rules'");
            if ($has_rules_pe && $has_rules_pe->num_rows > 0) {
                $pe_cols .= ", rules";
            }
            $pe_res = @$conn->query("SELECT $pe_cols FROM event_pending_edits WHERE event_id = $event_id LIMIT 1");
            if ($pe_res && $pe_res->num_rows > 0) {
                $row['pending_edit'] = $pe_res->fetch_assoc();
            }
        }
        $data[] = $row;
    }

    if ($id > 0 && empty($data)) {
        echo json_encode(["status" => "error", "message" => "Event pawa jayni"]);
    } else {
        echo json_encode(["status" => "success", "count" => count($data), "data" => ($id > 0) ? $data[0] : $data]);
    }
}

// --- 2. CREATE EVENT (POST) or UPDATE EVENT (POST action=update with multipart/form-data) ---
elseif ($method == 'POST') {
    $is_update = isset($_POST['action']) && $_POST['action'] === 'update' && isset($_POST['event_id']) && isset($_POST['user_id']);

    if ($is_update) {
        // --- UPDATE EVENT (same fields as create: title, description, event_date, category, venue, optional banners) ---
        $id = intval($_POST['event_id']);
        $user_id = intval($_POST['user_id']);
        $check = $conn->prepare("SELECT id, organizer_id, status, title, description, venue, event_date, category, banners, rules FROM events WHERE id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $evt = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$evt) {
            echo json_encode(["status" => "error", "message" => "Event not found"]);
            exit();
        }
        $rules_upd = isset($_POST['rules']) ? (string) $_POST['rules'] : (string) ($evt['rules'] ?? '');
        $is_organizer = ((int) $evt['organizer_id']) === $user_id;
        $is_editor = false;
        $ed = $conn->prepare("SELECT 1 FROM event_editors WHERE event_id = ? AND user_id = ? LIMIT 1");
        if ($ed) {
            $ed->bind_param("ii", $id, $user_id);
            $ed->execute();
            $ed->store_result();
            $is_editor = ($ed->num_rows > 0);
            $ed->close();
        }
        if (!$is_organizer && !$is_editor) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Only organizer or an editor can update this event"]);
            exit();
        }
        $title = isset($_POST['title']) ? $conn->real_escape_string($_POST['title']) : $evt['title'];
        $desc = isset($_POST['description']) ? $conn->real_escape_string($_POST['description']) : $evt['description'];
        $venue = isset($_POST['venue']) ? $conn->real_escape_string($_POST['venue']) : $evt['venue'];
        $event_date_raw = isset($_POST['event_date']) && $_POST['event_date'] !== '' ? $_POST['event_date'] : null;
        if ($event_date_raw !== null) {
            $event_date_raw = str_replace('T', ' ', $event_date_raw);
            if (strlen($event_date_raw) === 16) {
                $event_date_raw .= ':00';
            }
        }
        $event_date = $event_date_raw !== null ? $conn->real_escape_string($event_date_raw) : $evt['event_date'];
        $category = isset($_POST['category']) ? $conn->real_escape_string($_POST['category']) : $evt['category'];
        $image_paths = [];
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
        $banners_json = !empty($image_paths) ? json_encode($image_paths) : $evt['banners'];

        $event_has_editors = false;
        $ed_count = @$conn->query("SELECT 1 FROM event_editors WHERE event_id = $id LIMIT 1");
        if ($ed_count && $ed_count->num_rows > 0) {
            $event_has_editors = true;
        }
        $needs_approval = ($event_has_editors && $evt['status'] === 'approved');

        if ($needs_approval) {
            $has_banners_col = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'banners'");
            $has_banners_col = $has_banners_col && $has_banners_col->num_rows > 0;
            $has_rules_col = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'rules'");
            $has_rules_col = $has_rules_col && $has_rules_col->num_rows > 0;
            if ($has_banners_col && $has_rules_col) {
                $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, banners, rules, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), banners=VALUES(banners), rules=VALUES(rules), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                if ($stmt) {
                    $stmt->bind_param("isssssssi", $id, $title, $desc, $venue, $event_date, $category, $banners_json, $rules_upd, $user_id);
                    if ($stmt->execute()) {
                        $stmt->close();
                        echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
                    } else {
                        $stmt->close();
                        echo json_encode(["status" => "error", "message" => $conn->error]);
                    }
                    exit();
                }
            }
            if ($has_banners_col) {
                $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, banners, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), banners=VALUES(banners), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                if ($stmt) {
                    $stmt->bind_param("issssssi", $id, $title, $desc, $venue, $event_date, $category, $banners_json, $user_id);
                    if ($stmt->execute()) {
                        $stmt->close();
                        echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
                    } else {
                        $stmt->close();
                        echo json_encode(["status" => "error", "message" => $conn->error]);
                    }
                    exit();
                }
            }
            $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
            if ($stmt) {
                $stmt->bind_param("isssssi", $id, $title, $desc, $venue, $event_date, $category, $user_id);
                if ($stmt->execute()) {
                    $stmt->close();
                    echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
                } else {
                    $stmt->close();
                    echo json_encode(["status" => "error", "message" => $conn->error]);
                }
                exit();
            }
        }
        $banners_esc = $conn->real_escape_string($banners_json);
        $event_date_esc = $conn->real_escape_string($event_date);
        $title_esc = $conn->real_escape_string($title);
        $desc_esc = $conn->real_escape_string($desc);
        $venue_esc = $conn->real_escape_string($venue);
        $category_esc = $conn->real_escape_string($category);
        $rules_esc = $conn->real_escape_string($rules_upd);
        $sql = "UPDATE events SET title='$title_esc', description='$desc_esc', venue='$venue_esc', event_date='$event_date_esc', category='$category_esc', banners='$banners_esc', rules='$rules_esc' WHERE id=$id";
        if ($conn->query($sql)) {
            echo json_encode(["status" => "success", "message" => "Event updated"]);
        } else {
            echo json_encode(["status" => "error", "message" => $conn->error]);
        }
        exit();
    }

    // --- CREATE EVENT ---
    if (!isset($_POST['title']) || !isset($_POST['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Event-er proyojonio details dewa hoyni"]);
        exit();
    }

    $title = $conn->real_escape_string($_POST['title']);
    $desc = $conn->real_escape_string($_POST['description'] ?? '');
    $date = $conn->real_escape_string($_POST['event_date']);
    $cat = $conn->real_escape_string($_POST['category']);
    $venue = $conn->real_escape_string($_POST['venue']);
    $rules = $conn->real_escape_string($_POST['rules'] ?? '');
    $organizer_id = intval($_POST['user_id']);

    $image_paths = [];
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

    $sql = "INSERT INTO events (title, description, event_date, category, venue, status, organizer_id, banners, rules) 
            VALUES ('$title', '$desc', '$date', '$cat', '$venue', 'pending', $organizer_id, '$banners_json', '$rules')";

    if ($conn->query($sql)) {
        $new_id = (int) $conn->insert_id;
        $title_for_sms = isset($_POST['title']) ? trim((string) $_POST['title']) : '';
        if ($title_for_sms !== '') {
            sms_notify_admins_event_created($conn, $title_for_sms);
        }
        echo json_encode(["status" => "success", "message" => "Event-ti admin-er approval-er jonyo pathano hoyechhe", "id" => $new_id]);
    } else {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
    }
}

// --- 3. UPDATE EVENT (PUT) - JSON body: same fields as create (title, description, event_date, category, venue) ---
elseif ($method == 'PUT') {
    // Organizer or event editor. If event has editors and is approved, edit goes to pending (admin approval required).
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($data['id']) || !isset($data['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Event ID and user_id required"]);
        exit();
    }

    $id = intval($data['id']);
    $user_id = intval($data['user_id']);
    
    $check = $conn->prepare("SELECT id, organizer_id, status, title, description, venue, event_date, category, banners, rules FROM events WHERE id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $evt = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$evt) {
        echo json_encode(["status" => "error", "message" => "Event not found"]);
        exit();
    }
    $is_organizer = ((int) $evt['organizer_id']) === $user_id;
    $is_editor = false;
    $ed = $conn->prepare("SELECT 1 FROM event_editors WHERE event_id = ? AND user_id = ? LIMIT 1");
    if ($ed) {
        $ed->bind_param("ii", $id, $user_id);
        $ed->execute();
        $ed->store_result();
        $is_editor = ($ed->num_rows > 0);
        $ed->close();
    }
    if (!$is_organizer && !$is_editor) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Only organizer or an editor can update this event"]);
        exit();
    }

    // Merge with current: use provided values or keep existing (same as create-event form fields)
    $title = array_key_exists('title', $data) ? $conn->real_escape_string($data['title']) : $evt['title'];
    $desc = array_key_exists('description', $data) ? $conn->real_escape_string($data['description']) : $evt['description'];
    $venue = array_key_exists('venue', $data) ? $conn->real_escape_string($data['venue']) : $evt['venue'];
    $event_date_raw = array_key_exists('event_date', $data) && $data['event_date'] !== '' && $data['event_date'] !== null ? $data['event_date'] : null;
    if ($event_date_raw !== null) {
        $event_date_raw = str_replace('T', ' ', $event_date_raw);
        if (strlen($event_date_raw) === 16) {
            $event_date_raw .= ':00';
        }
    }
    $event_date = $event_date_raw !== null ? $conn->real_escape_string($event_date_raw) : $evt['event_date'];
    $category = array_key_exists('category', $data) ? $conn->real_escape_string($data['category']) : $evt['category'];
    $rules = array_key_exists('rules', $data) ? (string) $data['rules'] : (string) ($evt['rules'] ?? '');
    // Banners in PUT: optional JSON array of filenames (client can replace with new list; no file upload in PUT)
    $banners_json = $evt['banners'];
    if (array_key_exists('banners', $data) && is_array($data['banners'])) {
        $banners_json = json_encode(array_map(function ($f) use ($conn) { return $conn->real_escape_string($f); }, $data['banners']));
    }

    $event_has_editors = false;
    $ed_count = @$conn->query("SELECT 1 FROM event_editors WHERE event_id = $id LIMIT 1");
    if ($ed_count && $ed_count->num_rows > 0) {
        $event_has_editors = true;
    }
    $needs_approval = ($event_has_editors && $evt['status'] === 'approved');

    if ($needs_approval) {
        // Save to pending_edits; admin will approve or reject
        $has_banners_col = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'banners'");
        $has_banners_col = $has_banners_col && $has_banners_col->num_rows > 0;
        $has_rules_col = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'rules'");
        $has_rules_col = $has_rules_col && $has_rules_col->num_rows > 0;
        if ($has_banners_col && $has_rules_col) {
            $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, banners, rules, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), banners=VALUES(banners), rules=VALUES(rules), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
            if ($stmt) {
                $stmt->bind_param("isssssssi", $id, $title, $desc, $venue, $event_date, $category, $banners_json, $rules, $user_id);
                if ($stmt->execute()) {
                    $stmt->close();
                    echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
                } else {
                    $stmt->close();
                    echo json_encode(["status" => "error", "message" => $conn->error]);
                }
                exit();
            }
        }
        if ($has_banners_col) {
            $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, banners, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), banners=VALUES(banners), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
            if ($stmt) {
                $stmt->bind_param("issssssi", $id, $title, $desc, $venue, $event_date, $category, $banners_json, $user_id);
                if ($stmt->execute()) {
                    $stmt->close();
                    echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
                } else {
                    $stmt->close();
                    echo json_encode(["status" => "error", "message" => $conn->error]);
                }
                exit();
            }
        }
        $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error"]);
            exit();
        }
        $stmt->bind_param("isssssi", $id, $title, $desc, $venue, $event_date, $category, $user_id);
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
        } else {
            $stmt->close();
            echo json_encode(["status" => "error", "message" => $conn->error]);
        }
        exit();
    }
    
    $banners_esc = $conn->real_escape_string($banners_json);
    $event_date_esc = $conn->real_escape_string($event_date);
    $rules_esc = $conn->real_escape_string($rules);
    $sql = "UPDATE events SET title='$title', description='$desc', venue='$venue', event_date='$event_date_esc', category='$category', banners='$banners_esc', rules='$rules_esc' WHERE id=$id";

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