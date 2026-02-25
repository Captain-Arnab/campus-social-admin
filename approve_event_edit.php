<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}

$user_type = $_SESSION['user_type'] ?? 'admin';
$username = isset($_SESSION['admin']) ? $_SESSION['admin'] : $_SESSION['subadmin'];

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($id <= 0 || !in_array($action, ['approve', 'reject'])) {
    header("Location: event_details.php?id=$id");
    exit();
}

$pending = @$conn->query("SELECT * FROM event_pending_edits WHERE event_id = $id")->fetch_assoc();
if (!$pending) {
    header("Location: event_details.php?id=$id&msg=no_pending");
    exit();
}

if ($action === 'approve') {
    $title = $conn->real_escape_string($pending['title']);
    $desc = $conn->real_escape_string($pending['description'] ?? '');
    $venue = $conn->real_escape_string($pending['venue']);
    $event_date = $pending['event_date'] ? $conn->real_escape_string($pending['event_date']) : null;
    $category = isset($pending['category']) && $pending['category'] !== '' ? $conn->real_escape_string($pending['category']) : null;
    $banners = isset($pending['banners']) && $pending['banners'] !== '' && $pending['banners'] !== null ? $conn->real_escape_string($pending['banners']) : null;

    $stmt = $conn->prepare("UPDATE events SET title=?, description=?, venue=? WHERE id=?");
    $stmt->bind_param("sssi", $title, $desc, $venue, $id);
    if ($stmt->execute()) {
        $stmt->close();
        if ($event_date) {
            $conn->query("UPDATE events SET event_date='" . $conn->real_escape_string($event_date) . "' WHERE id=$id");
        }
        if ($category) {
            $conn->query("UPDATE events SET category='" . $conn->real_escape_string($category) . "' WHERE id=$id");
        }
        if ($banners !== null) {
            $conn->query("UPDATE events SET banners='" . $banners . "' WHERE id=$id");
        }
        $conn->query("DELETE FROM event_pending_edits WHERE event_id = $id");
        $log_stmt = $conn->prepare("INSERT INTO event_status_log (event_id, admin_type, admin_username, old_status, new_status, remarks) VALUES (?, ?, ?, ?, ?, ?)");
        $ev = $conn->query("SELECT status FROM events WHERE id = $id")->fetch_assoc();
        $st = $ev['status'];
        $remarks = "Event edit approved by " . $user_type . " (" . $username . ")";
        $log_stmt->bind_param("isssss", $id, $user_type, $username, $st, $st, $remarks);
        $log_stmt->execute();
        $log_stmt->close();
        header("Location: event_details.php?id=$id&msg=edit_approved");
        exit();
    }
    $stmt->close();
}

// Reject or on error
$conn->query("DELETE FROM event_pending_edits WHERE event_id = $id");
header("Location: event_details.php?id=$id&msg=" . ($action === 'reject' ? 'edit_rejected' : 'edit_failed'));
exit();
