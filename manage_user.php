<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}
require_priv('manage_users');

if (isset($_GET['id']) && isset($_GET['action']) && isset($_GET['type'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action']; // 'block' or 'unblock'
    $type = $_GET['type'];     // 'user', 'volunteer', or 'participant'

    $new_status = ($action == 'block') ? 'blocked' : 'active';
    $msg = ($action == 'block') ? 'blocked' : 'unblocked';

    if ($type == 'user') {
        // Block Global User
        $stmt = $conn->prepare("UPDATE users SET status=? WHERE id=?");
        $stmt->bind_param("si", $new_status, $id);
        
        if ($stmt->execute()) {
            header("Location: users.php?msg=" . $msg);
        }

    } elseif ($type == 'volunteer') {
        // Block Volunteer from an Event
        $stmt = $conn->prepare("UPDATE volunteers SET status=? WHERE id=?");
        $stmt->bind_param("si", $new_status, $id);
        
        // We need the event_id to redirect back to the correct details page
        $vol_check = $conn->query("SELECT event_id FROM volunteers WHERE id=$id")->fetch_assoc();
        $event_id = $vol_check['event_id'];

        if ($stmt->execute()) {
            header("Location: event_details.php?id=$event_id&msg=" . $msg);
        }
        
    } elseif ($type == 'participant') {
        // Block Participant from an Event
        $stmt = $conn->prepare("UPDATE participant SET status=? WHERE id=?");
        $stmt->bind_param("si", $new_status, $id);
        
        // We need the event_id to redirect back to the correct details page
        $part_check = $conn->query("SELECT event_id FROM participant WHERE id=$id")->fetch_assoc();
        $event_id = $part_check['event_id'];

        if ($stmt->execute()) {
            header("Location: event_details.php?id=$event_id&msg=" . $msg);
        }
    }
}
?>