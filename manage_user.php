<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}
require_priv('manage_users');

/**
 * Remove dependent rows for an event so the event (and organizer user) can be deleted
 * even when FKs lack ON DELETE CASCADE (e.g. participant).
 */
function admin_delete_event_dependents(mysqli $conn, int $event_id): void
{
    $queries = [
        "DELETE FROM favorites WHERE event_id = ?",
        "DELETE FROM attendees WHERE event_id = ?",
        "DELETE FROM volunteers WHERE event_id = ?",
        "DELETE FROM participant WHERE event_id = ?",
        "DELETE FROM event_status_log WHERE event_id = ?",
        "DELETE FROM event_certificates WHERE event_id = ?",
        "DELETE FROM event_editors WHERE event_id = ?",
        "DELETE FROM event_winners WHERE event_id = ?",
        "DELETE FROM event_pending_edits WHERE event_id = ?",
        "DELETE FROM event_review_files WHERE event_id = ?",
        "DELETE FROM notification_dates WHERE event_id = ?",
        "DELETE FROM organizer_notifications WHERE event_id = ?",
    ];
    foreach ($queries as $sql) {
        $st = @$conn->prepare($sql);
        if ($st) {
            $st->bind_param('i', $event_id);
            $st->execute();
            $st->close();
        }
    }
}

function users_redirect(string $msg, string $view = ''): void
{
    $params = ['msg' => $msg];
    if ($view === 'students' || $view === 'faculty') {
        $params['view'] = $view;
    }
    header('Location: users.php?' . http_build_query($params));
    exit();
}

if (isset($_GET['id']) && isset($_GET['action']) && isset($_GET['type'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action']; // 'block', 'unblock', or 'delete'
    $type = $_GET['type'];     // 'user', 'volunteer', or 'participant'
    $view = isset($_GET['view']) ? (string) $_GET['view'] : '';

    if ($type == 'user' && $action == 'delete') {
        if ($id <= 0) {
            users_redirect('delete_failed', $view);
        }

        $check = $conn->prepare('SELECT id, is_student FROM users WHERE id = ? LIMIT 1');
        $check->bind_param('i', $id);
        $check->execute();
        $user = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$user) {
            users_redirect('delete_failed', $view);
        }

        // Keep the same Students/Faculty tab after delete when view was not passed
        if ($view !== 'students' && $view !== 'faculty') {
            $view = ((int) $user['is_student'] === 1) ? 'students' : 'faculty';
        }

        $conn->begin_transaction();
        try {
            // 1) Events hosted by this user — clean dependents first (participant has no CASCADE)
            $hosted = $conn->prepare('SELECT id FROM events WHERE organizer_id = ?');
            $hosted->bind_param('i', $id);
            $hosted->execute();
            $hosted_res = $hosted->get_result();
            $event_ids = [];
            while ($row = $hosted_res->fetch_assoc()) {
                $event_ids[] = (int) $row['id'];
            }
            $hosted->close();

            foreach ($event_ids as $eid) {
                admin_delete_event_dependents($conn, $eid);
            }

            if (!empty($event_ids)) {
                $st = $conn->prepare('DELETE FROM events WHERE organizer_id = ?');
                $st->bind_param('i', $id);
                $st->execute();
                $st->close();
            }

            // 2) Direct user membership / profile rows (some FKs have no ON DELETE CASCADE)
            $user_deletes = [
                'DELETE FROM participant WHERE user_id = ?',
                'DELETE FROM volunteers WHERE user_id = ?',
                'DELETE FROM attendees WHERE user_id = ?',
                'DELETE FROM favorites WHERE user_id = ?',
                'DELETE FROM event_editors WHERE user_id = ?',
                'DELETE FROM event_winners WHERE user_id = ?',
                'DELETE FROM event_certificates WHERE user_id = ?',
                'DELETE FROM event_pending_edits WHERE submitted_by_user_id = ?',
                'DELETE FROM login_otps WHERE user_id = ?',
                'DELETE FROM user_fcm_tokens WHERE user_id = ?',
                'DELETE FROM user_inbox_notifications WHERE user_id = ?',
                'DELETE FROM organizer_notifications WHERE organizer_id = ?',
                'DELETE FROM student_faculty WHERE user_id = ?',
            ];

            foreach ($user_deletes as $sql) {
                $st = @$conn->prepare($sql);
                if ($st) {
                    $st->bind_param('i', $id);
                    $st->execute();
                    $st->close();
                }
            }

            // Null out optional uploader refs if table exists
            $st = @$conn->prepare('UPDATE event_review_files SET uploaded_by = NULL WHERE uploaded_by = ?');
            if ($st) {
                $st->bind_param('i', $id);
                $st->execute();
                $st->close();
            }

            // 3) Delete the user account
            $st = $conn->prepare('DELETE FROM users WHERE id = ?');
            $st->bind_param('i', $id);
            $st->execute();
            $affected = $st->affected_rows;
            $st->close();

            if ($affected !== 1) {
                throw new Exception('User delete failed');
            }

            $conn->commit();
            users_redirect('deleted', $view);
        } catch (Exception $e) {
            $conn->rollback();
            users_redirect('delete_failed', $view);
        }

    } elseif ($type == 'user' && ($action == 'block' || $action == 'unblock')) {
        $new_status = ($action == 'block') ? 'blocked' : 'active';
        $msg = ($action == 'block') ? 'blocked' : 'unblocked';

        $stmt = $conn->prepare("UPDATE users SET status=? WHERE id=?");
        $stmt->bind_param("si", $new_status, $id);

        if ($stmt->execute()) {
            users_redirect($msg, $view);
        }
        header('Location: users.php' . (($view === 'students' || $view === 'faculty') ? '?view=' . urlencode($view) : ''));
        exit();

    } elseif ($type == 'volunteer' && ($action == 'block' || $action == 'unblock')) {
        $new_status = ($action == 'block') ? 'blocked' : 'active';
        $msg = ($action == 'block') ? 'blocked' : 'unblocked';

        $stmt = $conn->prepare("UPDATE volunteers SET status=? WHERE id=?");
        $stmt->bind_param("si", $new_status, $id);

        $vol_check = $conn->query("SELECT event_id FROM volunteers WHERE id=$id")->fetch_assoc();
        $event_id = $vol_check['event_id'];

        if ($stmt->execute()) {
            header("Location: event_details.php?id=$event_id&msg=" . $msg);
            exit();
        }

    } elseif ($type == 'participant' && ($action == 'block' || $action == 'unblock')) {
        $new_status = ($action == 'block') ? 'blocked' : 'active';
        $msg = ($action == 'block') ? 'blocked' : 'unblocked';

        $stmt = $conn->prepare("UPDATE participant SET status=? WHERE id=?");
        $stmt->bind_param("si", $new_status, $id);

        $part_check = $conn->query("SELECT event_id FROM participant WHERE id=$id")->fetch_assoc();
        $event_id = $part_check['event_id'];

        if ($stmt->execute()) {
            header("Location: event_details.php?id=$event_id&msg=" . $msg);
            exit();
        }
    }
}

header('Location: users.php');
exit();
?>
