<?php
include 'db.php';
require_once __DIR__ . '/sms_helper.php';
require_once __DIR__ . '/../event_date_range_schema.php';
$method = $_SERVER['REQUEST_METHOD'];

/** Public web base for deep links / share sheet (override with MICAMPUS_PUBLIC_BASE). */
function events_api_public_base(): string
{
    $b = getenv('MICAMPUS_PUBLIC_BASE');
    return rtrim(($b !== false && $b !== '') ? $b : 'https://micampus.co.in', '/');
}

/**
 * viewer_count = attendees who tapped "join" for the event (spectators / venue sizing).
 * share_* helps mobile clients build a working Share intent.
 */
function events_api_enrich_event_row(array &$row): void
{
    $row['viewer_count'] = isset($row['attendee_count']) ? (int) $row['attendee_count'] : 0;
    $q = getenv('MICAMPUS_EVENT_SHARE_PATH');
    $path = ($q !== false && $q !== '') ? $q : '/event?id=';
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    $eid = (int) ($row['id'] ?? 0);
    $row['share_url'] = events_api_public_base() . $path . $eid;
    $title = (string) ($row['title'] ?? 'Event');
    $row['share_text'] = $title . ' — ' . $row['share_url'];
}

/**
 * Search string from GET: clients use different keys (search, q, query, keyword, text).
 */
function events_api_get_search_string(): string
{
    foreach (['search', 'q', 'query', 'keyword', 'text'] as $key) {
        if (!isset($_GET[$key])) {
            continue;
        }
        $v = trim((string) $_GET[$key]);
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

/**
 * SQL AND clause for event discovery: name, description, venue, category, rules, organizer.
 * Multi-word queries require each token to match at least one of those fields (AND across tokens).
 */
function events_api_search_sql_fragment(mysqli $conn, string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($raw, 'UTF-8') > 200) {
        $raw = mb_substr($raw, 0, 200, 'UTF-8');
    } elseif (strlen($raw) > 200) {
        $raw = substr($raw, 0, 200);
    }
    $tokens = preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if ($tokens === false || $tokens === []) {
        $tokens = [$raw];
    }
    $tokenConds = [];
    foreach ($tokens as $tok) {
        if (function_exists('mb_strlen') && mb_strlen($tok, 'UTF-8') > 128) {
            $tok = mb_substr($tok, 0, 128, 'UTF-8');
        } elseif (strlen($tok) > 128) {
            $tok = substr($tok, 0, 128);
        }
        $e = $conn->real_escape_string($tok);
        $tokenConds[] = "(
            e.title LIKE '%$e%'
            OR e.description LIKE '%$e%'
            OR e.venue LIKE '%$e%'
            OR e.category LIKE '%$e%'
            OR IFNULL(e.rules, '') LIKE '%$e%'
            OR u.full_name LIKE '%$e%'
        )";
    }
    return ' AND (' . implode(' AND ', $tokenConds) . ')';
}

// --- 1. GET EVENTS ---
if ($method == 'GET') {
    $view = isset($_GET['type']) ? $_GET['type'] : 'live';
    $search_query = events_api_get_search_string();
    $category_filter = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
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
            events_api_enrich_event_row($row);
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
            events_api_enrich_event_row($row);
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
            events_api_enrich_event_row($row);
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
            events_api_enrich_event_row($row);
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
            events_api_enrich_event_row($row);
            $data[] = $row;
        }
        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
        exit();
    } else {
        // Live or past events (default behavior)
        $sql .= " AND e.status = 'approved'";

        if ($view == 'past') {
            $sql .= ' AND ' . events_sql_past($conn, 'e');
        } else {
            $sql .= ' AND ' . events_sql_not_past($conn, 'e');
        }

        if ($search_query !== '') {
            $sql .= events_api_search_sql_fragment($conn, $search_query);
        }

        if ($category_filter !== '') {
            $category_esc = $conn->real_escape_string($category_filter);
            $sql .= " AND e.category = '$category_esc'";
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

            $part_res = $conn->query("SELECT u.full_name AS student_name, u.full_name AS full_name, u.phone AS phone, u.phone AS contact_number, p.user_id, p.department_class, IFNULL(NULLIF(TRIM(p.department_class), ''), 'Participant') AS role, p.attended, p.attendance_marked_at FROM participant p JOIN users u ON p.user_id = u.id WHERE p.event_id = $event_id AND p.status = 'active'");
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
            $row['attendance_locked'] = !empty($row['winners']);
            // Event review files
            $row['review_files'] = [];
            $rf_res = @$conn->query("SELECT id, file_path, file_type, original_name, uploaded_at FROM event_review_files WHERE event_id = $event_id ORDER BY uploaded_at ASC");
            if ($rf_res) {
                while ($rf = $rf_res->fetch_assoc()) { $row['review_files'][] = $rf; }
            }

            // Pending edit (when event has editors, organizer/editor edits await admin approval)
            $row['pending_edit'] = null;
            $pe_cols = "title, description, venue, event_date, category, submitted_by_user_id, submitted_at";
            if (schema_event_pending_edits_has_event_end_date($conn)) {
                $pe_cols = "title, description, venue, event_date, event_end_date, category, submitted_by_user_id, submitted_at";
            }
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
        events_api_enrich_event_row($row);
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
        $evSelect = "SELECT id, organizer_id, status, title, description, venue, event_date, category, banners, rules";
        if (schema_events_has_event_end_date($conn)) {
            $evSelect .= ", event_end_date";
        }
        $evSelect .= " FROM events WHERE id = ?";
        $check = $conn->prepare($evSelect);
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
        $start_raw = events_parse_start_from_request($_POST, 'event_date');
        if ($start_raw === null) {
            $start_raw = events_normalize_dt((string) ($evt['event_date'] ?? ''));
        }
        $posted_end_keys = array_key_exists('event_end_date', $_POST)
            || array_key_exists('event_date_to', $_POST)
            || array_key_exists('event_date_end', $_POST);
        if ($posted_end_keys) {
            $end_raw = events_parse_end_from_request($_POST);
        } elseif (schema_events_has_event_end_date($conn) && !empty($evt['event_end_date']) && ($evt['event_end_date'] ?? '') !== '0000-00-00 00:00:00') {
            $end_raw = events_normalize_dt((string) $evt['event_end_date']);
        } else {
            $end_raw = null;
        }
        if (!events_validate_end_after_start((string) $start_raw, $end_raw)) {
            echo json_encode(["status" => "error", "message" => "Event end date/time must be on or after the start date/time"]);
            exit();
        }
        $event_date     = $start_raw;
        $event_date_esc = $conn->real_escape_string((string) $start_raw);
        $post_end_bind  = $end_raw;
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
            $hasPeEnd = schema_event_pending_edits_has_event_end_date($conn);
            $ebe      = $post_end_bind;
            $has_banners_col = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'banners'");
            $has_banners_col = $has_banners_col && $has_banners_col->num_rows > 0;
            $has_rules_col = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'rules'");
            $has_rules_col = $has_rules_col && $has_rules_col->num_rows > 0;
            if ($has_banners_col && $has_rules_col) {
                if ($hasPeEnd) {
                    $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, event_end_date, category, banners, rules, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), event_end_date=VALUES(event_end_date), category=VALUES(category), banners=VALUES(banners), rules=VALUES(rules), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                    if ($stmt) {
                        $stmt->bind_param("issssssssi", $id, $title, $desc, $venue, $event_date, $ebe, $category, $banners_json, $rules_upd, $user_id);
                    }
                } else {
                    $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, banners, rules, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), banners=VALUES(banners), rules=VALUES(rules), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                    if ($stmt) {
                        $stmt->bind_param("isssssssi", $id, $title, $desc, $venue, $event_date, $category, $banners_json, $rules_upd, $user_id);
                    }
                }
                if ($stmt && $stmt->execute()) {
                    $stmt->close();
                    echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
                } elseif ($stmt) {
                    $stmt->close();
                    echo json_encode(["status" => "error", "message" => $conn->error]);
                }
                exit();
            }
            if ($has_banners_col) {
                if ($hasPeEnd) {
                    $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, event_end_date, category, banners, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), event_end_date=VALUES(event_end_date), category=VALUES(category), banners=VALUES(banners), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                    if ($stmt) {
                        $stmt->bind_param("isssssssi", $id, $title, $desc, $venue, $event_date, $ebe, $category, $banners_json, $user_id);
                    }
                } else {
                    $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, banners, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), banners=VALUES(banners), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                    if ($stmt) {
                        $stmt->bind_param("issssssi", $id, $title, $desc, $venue, $event_date, $category, $banners_json, $user_id);
                    }
                }
                if ($stmt && $stmt->execute()) {
                    $stmt->close();
                    echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
                } elseif ($stmt) {
                    $stmt->close();
                    echo json_encode(["status" => "error", "message" => $conn->error]);
                }
                exit();
            }
            if ($hasPeEnd) {
                $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, event_end_date, category, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), event_end_date=VALUES(event_end_date), category=VALUES(category), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                if ($stmt) {
                    $stmt->bind_param("issssssi", $id, $title, $desc, $venue, $event_date, $ebe, $category, $user_id);
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                if ($stmt) {
                    $stmt->bind_param("isssssi", $id, $title, $desc, $venue, $event_date, $category, $user_id);
                }
            }
            if ($stmt && $stmt->execute()) {
                $stmt->close();
                echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
            } elseif ($stmt) {
                $stmt->close();
                echo json_encode(["status" => "error", "message" => $conn->error]);
            }
            exit();
        }
        $banners_esc = $conn->real_escape_string($banners_json);
        $title_esc = $conn->real_escape_string($title);
        $desc_esc = $conn->real_escape_string($desc);
        $venue_esc = $conn->real_escape_string($venue);
        $category_esc = $conn->real_escape_string($category);
        $rules_esc = $conn->real_escape_string($rules_upd);
        $end_sql_fragment = '';
        if (schema_events_has_event_end_date($conn)) {
            if ($post_end_bind === null || $post_end_bind === '') {
                $end_sql_fragment = ', event_end_date = NULL';
            } else {
                $end_sql_fragment = ", event_end_date = '" . $conn->real_escape_string((string) $post_end_bind) . "'";
            }
        }
        $sql = "UPDATE events SET title='$title_esc', description='$desc_esc', venue='$venue_esc', event_date='$event_date_esc'{$end_sql_fragment}, category='$category_esc', banners='$banners_esc', rules='$rules_esc' WHERE id=$id";
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

    $title_plain = isset($_POST['title']) ? trim((string) $_POST['title']) : '';
    $cat_plain   = isset($_POST['category']) ? trim((string) $_POST['category']) : '';
    $venue_plain = isset($_POST['venue']) ? trim((string) $_POST['venue']) : '';

    $start_raw = events_parse_start_from_request($_POST, 'event_date');
    if ($start_raw === null) {
        echo json_encode(["status" => "error", "message" => "Event start date/time is required (event_date or event_date_from)"]);
        exit();
    }
    $end_raw = events_parse_end_from_request($_POST);
    if (!events_validate_end_after_start($start_raw, $end_raw)) {
        echo json_encode(["status" => "error", "message" => "Event end date/time must be on or after the start"]);
        exit();
    }

    $title = $conn->real_escape_string($title_plain);
    $desc = $conn->real_escape_string($_POST['description'] ?? '');
    $date_esc = $conn->real_escape_string($start_raw);
    $cat = $conn->real_escape_string($cat_plain);
    $venue = $conn->real_escape_string($venue_plain);
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

    if (schema_events_has_event_end_date($conn)) {
        if ($end_raw === null || $end_raw === '') {
            $sql = "INSERT INTO events (title, description, event_date, event_end_date, category, venue, status, organizer_id, banners, rules) 
            VALUES ('$title', '$desc', '$date_esc', NULL, '$cat', '$venue', 'pending', $organizer_id, '$banners_json', '$rules')";
        } else {
            $end_esc = $conn->real_escape_string($end_raw);
            $sql = "INSERT INTO events (title, description, event_date, event_end_date, category, venue, status, organizer_id, banners, rules) 
            VALUES ('$title', '$desc', '$date_esc', '$end_esc', '$cat', '$venue', 'pending', $organizer_id, '$banners_json', '$rules')";
        }
    } else {
        $sql = "INSERT INTO events (title, description, event_date, category, venue, status, organizer_id, banners, rules) 
            VALUES ('$title', '$desc', '$date_esc', '$cat', '$venue', 'pending', $organizer_id, '$banners_json', '$rules')";
    }

    if ($conn->query($sql)) {
        $new_id = (int) $conn->insert_id;
        $title_for_sms = $title_plain;
        if ($title_for_sms !== '') {
            sms_notify_admins_event_created($conn, $title_for_sms);
        }
        if ($new_id > 0) {
            $inbox_helper = __DIR__ . '/app_inbox_notifications_helper.php';
            if (is_readable($inbox_helper)) {
                require_once $inbox_helper;
                try {
                    campus_inbox_after_event_created($conn, $new_id, $title_plain, $cat_plain, $venue_plain);
                } catch (Throwable $e) {
                    error_log('[events.php] campus_inbox_after_event_created: ' . $e->getMessage());
                }
            }
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
    
    $evSelectPut = "SELECT id, organizer_id, status, title, description, venue, event_date, category, banners, rules";
    if (schema_events_has_event_end_date($conn)) {
        $evSelectPut .= ", event_end_date";
    }
    $evSelectPut .= " FROM events WHERE id = ?";
    $check = $conn->prepare($evSelectPut);
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
    $start_raw = (array_key_exists('event_date', $data) || array_key_exists('event_date_from', $data))
        ? events_parse_start_from_request($data, 'event_date')
        : null;
    if ($start_raw === null) {
        $start_raw = events_normalize_dt((string) ($evt['event_date'] ?? ''));
    }
    $posted_end_put = array_key_exists('event_end_date', $data)
        || array_key_exists('event_date_to', $data)
        || array_key_exists('event_date_end', $data);
    if ($posted_end_put) {
        $end_raw = events_parse_end_from_request($data);
    } elseif (schema_events_has_event_end_date($conn) && !empty($evt['event_end_date']) && ($evt['event_end_date'] ?? '') !== '0000-00-00 00:00:00') {
        $end_raw = events_normalize_dt((string) $evt['event_end_date']);
    } else {
        $end_raw = null;
    }
    if (!events_validate_end_after_start((string) $start_raw, $end_raw)) {
        echo json_encode(["status" => "error", "message" => "Event end date/time must be on or after the start date/time"]);
        exit();
    }
    $event_date     = $start_raw;
    $event_date_esc = $conn->real_escape_string((string) $start_raw);
    $post_end_bind  = $end_raw;
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
        $hasPeEnd = schema_event_pending_edits_has_event_end_date($conn);
        $ebe      = $post_end_bind;
        $has_banners_col = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'banners'");
        $has_banners_col = $has_banners_col && $has_banners_col->num_rows > 0;
        $has_rules_col = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'rules'");
        $has_rules_col = $has_rules_col && $has_rules_col->num_rows > 0;
        if ($has_banners_col && $has_rules_col) {
            if ($hasPeEnd) {
                $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, event_end_date, category, banners, rules, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), event_end_date=VALUES(event_end_date), category=VALUES(category), banners=VALUES(banners), rules=VALUES(rules), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                if ($stmt) {
                    $stmt->bind_param("issssssssi", $id, $title, $desc, $venue, $event_date, $ebe, $category, $banners_json, $rules, $user_id);
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, banners, rules, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), banners=VALUES(banners), rules=VALUES(rules), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                if ($stmt) {
                    $stmt->bind_param("isssssssi", $id, $title, $desc, $venue, $event_date, $category, $banners_json, $rules, $user_id);
                }
            }
            if ($stmt && $stmt->execute()) {
                $stmt->close();
                echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
            } elseif ($stmt) {
                $stmt->close();
                echo json_encode(["status" => "error", "message" => $conn->error]);
            }
            exit();
        }
        if ($has_banners_col) {
            if ($hasPeEnd) {
                $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, event_end_date, category, banners, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), event_end_date=VALUES(event_end_date), category=VALUES(category), banners=VALUES(banners), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                if ($stmt) {
                    $stmt->bind_param("isssssssi", $id, $title, $desc, $venue, $event_date, $ebe, $category, $banners_json, $user_id);
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, banners, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), banners=VALUES(banners), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
                if ($stmt) {
                    $stmt->bind_param("issssssi", $id, $title, $desc, $venue, $event_date, $category, $banners_json, $user_id);
                }
            }
            if ($stmt && $stmt->execute()) {
                $stmt->close();
                echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
            } elseif ($stmt) {
                $stmt->close();
                echo json_encode(["status" => "error", "message" => $conn->error]);
            }
            exit();
        }
        if ($hasPeEnd) {
            $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, event_end_date, category, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), event_end_date=VALUES(event_end_date), category=VALUES(category), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
            if ($stmt) {
                $stmt->bind_param("issssssi", $id, $title, $desc, $venue, $event_date, $ebe, $category, $user_id);
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO event_pending_edits (event_id, title, description, venue, event_date, category, submitted_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), venue=VALUES(venue), event_date=VALUES(event_date), category=VALUES(category), submitted_by_user_id=VALUES(submitted_by_user_id), submitted_at=CURRENT_TIMESTAMP");
            if ($stmt) {
                $stmt->bind_param("isssssi", $id, $title, $desc, $venue, $event_date, $category, $user_id);
            }
        }
        if ($stmt && $stmt->execute()) {
            $stmt->close();
            echo json_encode(["status" => "success", "message" => "Edit submitted for admin approval", "pending_approval" => true]);
        } elseif ($stmt) {
            $stmt->close();
            echo json_encode(["status" => "error", "message" => $conn->error]);
        }
        exit();
    }

    $banners_esc = $conn->real_escape_string($banners_json);
    $rules_esc = $conn->real_escape_string($rules);
    $end_sql_put = '';
    if (schema_events_has_event_end_date($conn)) {
        if ($post_end_bind === null || $post_end_bind === '') {
            $end_sql_put = ', event_end_date = NULL';
        } else {
            $end_sql_put = ", event_end_date = '" . $conn->real_escape_string((string) $post_end_bind) . "'";
        }
    }
    $sql = "UPDATE events SET title='$title', description='$desc', venue='$venue', event_date='$event_date_esc'{$end_sql_put}, category='$category', banners='$banners_esc', rules='$rules_esc' WHERE id=$id";

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