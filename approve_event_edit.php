<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/api/background_jobs_helper.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}
require_priv('approve_events');

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

$evBefore = $conn->query("SELECT status, title, organizer_id FROM events WHERE id = $id")->fetch_assoc();
$oldStatus = $evBefore['status'] ?? 'pending';

if ($action === 'approve') {
    $title = $conn->real_escape_string($pending['title']);
    $desc = $conn->real_escape_string($pending['description'] ?? '');
    $venue = $conn->real_escape_string($pending['venue']);
    $event_date = $pending['event_date'] ? $conn->real_escape_string($pending['event_date']) : null;
    $category = isset($pending['category']) && $pending['category'] !== '' ? $conn->real_escape_string($pending['category']) : null;

    $stmt = $conn->prepare("UPDATE events SET title=?, description=?, venue=? WHERE id=?");
    $stmt->bind_param("sssi", $title, $desc, $venue, $id);
    if ($stmt->execute()) {
        $stmt->close();
        if ($event_date) {
            $conn->query("UPDATE events SET event_date='" . $conn->real_escape_string($event_date) . "' WHERE id=$id");
        }
        $has_pe_end = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'event_end_date'");
        if ($has_pe_end && $has_pe_end->num_rows > 0) {
            $pe_end_raw = $pending['event_end_date'] ?? null;
            if ($pe_end_raw !== null && $pe_end_raw !== '' && $pe_end_raw !== '0000-00-00 00:00:00') {
                $pe_esc = $conn->real_escape_string((string) $pe_end_raw);
                $conn->query("UPDATE events SET event_end_date='$pe_esc' WHERE id=$id");
            } else {
                $conn->query("UPDATE events SET event_end_date=NULL WHERE id=$id");
            }
        }
        if ($category) {
            $conn->query("UPDATE events SET category='" . $conn->real_escape_string($category) . "' WHERE id=$id");
        }
        $has_rules = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'rules'");
        if ($has_rules && $has_rules->num_rows > 0 && array_key_exists('rules', $pending) && $pending['rules'] !== null) {
            $rules_esc = $conn->real_escape_string($pending['rules']);
            $conn->query("UPDATE events SET rules='$rules_esc' WHERE id=$id");
        }
        $has_banners_pe = @$conn->query("SHOW COLUMNS FROM event_pending_edits LIKE 'banners'");
        if ($has_banners_pe && $has_banners_pe->num_rows > 0 && !empty($pending['banners'])) {
            $b_esc = $conn->real_escape_string($pending['banners']);
            $conn->query("UPDATE events SET banners='$b_esc' WHERE id=$id");
        }

        // Restore to approved (C2: was flipped to pending on edit).
        $conn->query("UPDATE events SET status = 'approved' WHERE id = $id");

        $conn->query("DELETE FROM event_pending_edits WHERE event_id = $id");
        $log_stmt = $conn->prepare("INSERT INTO event_status_log (event_id, admin_type, admin_username, old_status, new_status, remarks) VALUES (?, ?, ?, ?, ?, ?)");
        $newStatus = 'approved';
        $remarks = "Event edit approved by " . $user_type . " (" . $username . ")";
        $log_stmt->bind_param("isssss", $id, $user_type, $username, $oldStatus, $newStatus, $remarks);
        $log_stmt->execute();
        $log_stmt->close();

        // Notify organizer via existing FCM/inbox path
        require_once __DIR__ . '/api/app_inbox_notifications_helper.php';
        $orgId = (int) ($evBefore['organizer_id'] ?? 0);
        $titlePlain = (string) ($pending['title'] ?? ($evBefore['title'] ?? 'Event'));
        if ($orgId > 0) {
            campus_inbox_after_admin_approve_or_reject($conn, $id, 'approve', $orgId, $titlePlain, '');
        }

        bg_jobs_enqueue($conn, 'event_approved_notify', [
            'event_id' => $id,
            'title' => $titlePlain,
        ]);

        header("Location: event_details.php?id=$id&msg=edit_approved");
        exit();
    }
    $stmt->close();
}

// Reject: discard pending edits; restore status to approved (live content unchanged).
$conn->query("DELETE FROM event_pending_edits WHERE event_id = $id");
if (($oldStatus === 'pending') || $oldStatus === 'approved') {
    $conn->query("UPDATE events SET status = 'approved' WHERE id = $id");
    $log_stmt = $conn->prepare("INSERT INTO event_status_log (event_id, admin_type, admin_username, old_status, new_status, remarks) VALUES (?, ?, ?, ?, ?, ?)");
    $newStatus = 'approved';
    $remarks = "Event edit rejected by " . $user_type . " (" . $username . "); previous content kept";
    $log_stmt->bind_param("isssss", $id, $user_type, $username, $oldStatus, $newStatus, $remarks);
    $log_stmt->execute();
    $log_stmt->close();

    require_once __DIR__ . '/api/app_inbox_notifications_helper.php';
    $orgId = (int) ($evBefore['organizer_id'] ?? 0);
    $titlePlain = (string) ($evBefore['title'] ?? 'Event');
    if ($orgId > 0) {
        campus_inbox_after_admin_approve_or_reject(
            $conn,
            $id,
            'reject',
            $orgId,
            $titlePlain,
            'Your recent event edit was rejected; previous details remain.'
        );
    }
}

header("Location: event_details.php?id=$id&msg=" . ($action === 'reject' ? 'edit_rejected' : 'edit_failed'));
exit();
