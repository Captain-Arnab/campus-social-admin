<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if ((!isset($_SESSION['admin']) && !isset($_SESSION['subadmin']))) {
    header("Location: index.php");
    exit();
}
require_priv('approve_events');

$user_type = $_SESSION['user_type'] ?? 'admin';
$username = isset($_SESSION['admin']) ? $_SESSION['admin'] : $_SESSION['subadmin'];

if (isset($_GET['id']) && isset($_GET['action'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action']; // approve, reject, hold, reschedule

    // Get current event status before update
    $current_event = $conn->query("SELECT status, event_date FROM events WHERE id=$id")->fetch_assoc();
    $old_status = $current_event['status'];
    
    $new_status = '';
    $remarks = '';
    $reschedule_date = NULL;
    $hold_reason = NULL;
    $new_event_date = NULL;

    switch($action) {
        case 'approve':
            $new_status = 'approved';
            $remarks = 'Event approved and published';
            break;
            
        case 'reject':
            $new_status = 'rejected';
            $remarks = 'Event rejected';
            break;
            
        case 'hold':
            $new_status = 'hold';
            $hold_reason = isset($_GET['reason']) ? $_GET['reason'] : 'No reason provided';
            $reschedule_date = isset($_GET['reschedule_date']) && !empty($_GET['reschedule_date']) ? $_GET['reschedule_date'] : NULL;
            $remarks = 'Event put on hold: ' . $hold_reason;
            break;

        case 'reschedule':
            // For rescheduling approved events
            $new_status = 'approved';
            $new_event_date = isset($_GET['new_date']) && !empty($_GET['new_date']) ? $_GET['new_date'] : NULL;
            $reschedule_reason = isset($_GET['reason']) ? $_GET['reason'] : 'No reason provided';
            $remarks = 'Event rescheduled: ' . $reschedule_reason . ' (From ' . date('M d, Y', strtotime($current_event['event_date'])) . ' to ' . date('M d, Y', strtotime($new_event_date)) . ')';
            break;
    }

    // Update event status
    if ($action == 'hold') {
        // Update with hold reason and reschedule date
        $stmt = $conn->prepare("UPDATE events SET status=?, hold_reason=?, reschedule_date=? WHERE id=?");
        $stmt->bind_param("sssi", $new_status, $hold_reason, $reschedule_date, $id);
    } elseif ($action == 'reschedule') {
        // Update event date for rescheduling
        $stmt = $conn->prepare("UPDATE events SET event_date=? WHERE id=?");
        $stmt->bind_param("si", $new_event_date, $id);
    } else {
        // Clear hold fields when approving/rejecting
        $empty_date = NULL;
        $empty_reason = NULL;
        $stmt = $conn->prepare("UPDATE events SET status=?, hold_reason=?, reschedule_date=? WHERE id=?");
        $stmt->bind_param("sssi", $new_status, $empty_reason, $empty_date, $id);
    }

    if ($stmt->execute()) {
        // Log the status change
        $log_stmt = $conn->prepare("INSERT INTO event_status_log (event_id, admin_type, admin_username, old_status, new_status, remarks) VALUES (?, ?, ?, ?, ?, ?)");
        $log_stmt->bind_param("isssss", $id, $user_type, $username, $old_status, $new_status, $remarks);
        $log_stmt->execute();
        
        // Redirect with success message
        header("Location: dashboard.php?msg=" . $action);
    } else {
        // Redirect with error
        header("Location: dashboard.php?msg=error");
    }
}
?>