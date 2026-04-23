<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/event_date_range_schema.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}
if (!has_priv('events')) {
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit();
}

$event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
if ($event_id <= 0 && isset($_GET['event_id'])) {
    $event_id = intval($_GET['event_id']);
}

// Prefer winner_uid: some WAFs strip POST fields named "user_id".
$user_id = 0;
foreach (['winner_uid', 'user_id'] as $uidKey) {
    if (isset($_POST[$uidKey])) {
        $user_id = intval($_POST[$uidKey]);
        if ($user_id > 0) {
            break;
        }
    }
}
if ($user_id <= 0) {
    foreach (['winner_uid', 'user_id'] as $uidKey) {
        if (isset($_GET[$uidKey])) {
            $user_id = intval($_GET[$uidKey]);
            if ($user_id > 0) {
                break;
            }
        }
    }
}

// Prefer winner_op: some hosts/WAFs strip or alter POST fields named "action".
$action = '';
foreach (['winner_op', 'action'] as $key) {
    if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
        continue;
    }
    $t = strtolower(trim($_POST[$key]));
    if ($t === 'add' || $t === 'remove') {
        $action = $t;
        break;
    }
}
if ($action === '' && isset($_GET['winner_op']) && is_string($_GET['winner_op'])) {
    $t = strtolower(trim($_GET['winner_op']));
    if ($t === 'add' || $t === 'remove') {
        $action = $t;
    }
}

if ($event_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid event']);
    exit();
}

// Only past events: winner selection allowed only after event date
$ev = $conn->query("SELECT event_date, event_end_date FROM events WHERE id = $event_id")->fetch_assoc();
if (!$ev) {
    echo json_encode(['status' => 'error', 'message' => 'Event not found']);
    exit();
}
if (!events_row_is_fully_past($ev)) {
    echo json_encode(['status' => 'error', 'message' => 'Winners can only be selected for past events']);
    exit();
}

if ($action === 'add') {
    if ($user_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'user_id required to add winner']);
        exit();
    }
    $chk = $conn->query("SELECT 1 FROM participant WHERE event_id = $event_id AND user_id = $user_id AND status = 'active' LIMIT 1");
    if (!$chk || $chk->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'User is not a participant of this event']);
        exit();
    }
    // Next position = 1st selected = 1, 2nd = 2, etc.
    $max = $conn->query("SELECT COALESCE(MAX(position), 0) as m FROM event_winners WHERE event_id = $event_id")->fetch_assoc();
    $position = (int) $max['m'] + 1;

    $stmt = $conn->prepare("INSERT IGNORE INTO event_winners (event_id, user_id, position) VALUES (?, ?, ?)");
    $stmt->bind_param("iii", $event_id, $user_id, $position);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $stmt->close();
        $ord = $position === 1 ? '1st' : ($position === 2 ? '2nd' : ($position === 3 ? '3rd' : $position . 'th'));
        echo json_encode(['status' => 'success', 'message' => 'Winner added', 'position' => $position, 'position_label' => $ord]);
    } else {
        $stmt->close();
        echo json_encode(['status' => 'error', 'message' => 'Already a winner or insert failed']);
    }
} elseif ($action === 'remove') {
    if ($user_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'user_id required to remove winner']);
        exit();
    }
    $stmt = $conn->prepare("DELETE FROM event_winners WHERE event_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $event_id, $user_id);
    $stmt->execute();
    $stmt->close();
    // Renumber positions so they stay 1, 2, 3...
    $remaining = $conn->query("SELECT id, user_id FROM event_winners WHERE event_id = $event_id ORDER BY position ASC");
    $pos = 1;
    $upd = $conn->prepare("UPDATE event_winners SET position = ? WHERE id = ?");
    while ($row = $remaining->fetch_assoc()) {
        $upd->bind_param("ii", $pos, $row['id']);
        $upd->execute();
        $pos++;
    }
    $upd->close();
    echo json_encode(['status' => 'success', 'message' => 'Winner removed']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
