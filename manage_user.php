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

function users_redirect(string $msg, string $view = '', int $page = 1, array $filters = []): void
{
    $params = ['msg' => $msg];
    if ($view === 'students' || $view === 'faculty') {
        $params['view'] = $view;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    foreach (['name', 'email', 'phone', 'date'] as $filter) {
        if (!empty($filters[$filter])) {
            $params[$filter] = $filters[$filter];
        }
    }
    header('Location: users.php?' . http_build_query($params));
    exit();
}

$view = isset($_GET['view']) ? (string) $_GET['view'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = [];
foreach (['name', 'email', 'phone', 'date'] as $filter) {
    if (isset($_GET[$filter])) {
        $filters[$filter] = trim((string) $_GET[$filter]);
    }
}

if (isset($_GET['ids']) && isset($_GET['action']) && $_GET['type'] === 'user') {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $_GET['ids'])), static function (int $id): bool {
        return $id > 0;
    })));
    $bulk_action = (string) $_GET['action'];

    if (empty($ids) || !in_array($bulk_action, ['bulk_block', 'bulk_unblock', 'bulk_delete'], true)) {
        users_redirect('bulk_failed', $view, $page, $filters);
    }

    $id_list = implode(',', $ids);
    if ($bulk_action === 'bulk_block' || $bulk_action === 'bulk_unblock') {
        $new_status = $bulk_action === 'bulk_block' ? 'blocked' : 'active';
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id IN ($id_list)");
        $stmt->bind_param('s', $new_status);
        $success = $stmt->execute();
        $stmt->close();
        users_redirect($success ? ($new_status === 'blocked' ? 'blocked' : 'unblocked') : 'bulk_failed', $view, $page, $filters);
    }

    $conn->begin_transaction();
    try {
        $hosted = $conn->query("SELECT id FROM events WHERE organizer_id IN ($id_list)");
        $event_ids = [];
        while ($event = $hosted->fetch_assoc()) {
            $event_ids[] = (int) $event['id'];
        }
        foreach ($event_ids as $event_id) {
            admin_delete_event_dependents($conn, $event_id);
        }
        $conn->query("DELETE FROM events WHERE organizer_id IN ($id_list)");

        $user_deletes = [
            'DELETE FROM participant WHERE user_id IN (' . $id_list . ')',
            'DELETE FROM volunteers WHERE user_id IN (' . $id_list . ')',
            'DELETE FROM attendees WHERE user_id IN (' . $id_list . ')',
            'DELETE FROM favorites WHERE user_id IN (' . $id_list . ')',
            'DELETE FROM event_editors WHERE user_id IN (' . $id_list . ')',
            'DELETE FROM event_winners WHERE user_id IN (' . $id_list . ')',
            'DELETE FROM event_certificates WHERE user_id IN (' . $id_list . ')',
            'DELETE FROM event_pending_edits WHERE submitted_by_user_id IN (' . $id_list . ')',
            'DELETE FROM login_otps WHERE user_id IN (' . $id_list . ')',
            'DELETE FROM user_fcm_tokens WHERE user_id IN (' . $id_list . ')',
            'DELETE FROM user_inbox_notifications WHERE user_id IN (' . $id_list . ')',
            'DELETE FROM organizer_notifications WHERE organizer_id IN (' . $id_list . ')',
            'DELETE FROM student_faculty WHERE user_id IN (' . $id_list . ')',
        ];
        foreach ($user_deletes as $sql) {
            @$conn->query($sql);
        }
        @$conn->query("UPDATE event_review_files SET uploaded_by = NULL WHERE uploaded_by IN ($id_list)");
        $deleted = $conn->query("DELETE FROM users WHERE id IN ($id_list)");
        if (!$deleted) {
            throw new Exception('Bulk user delete failed');
        }
        $conn->commit();
        users_redirect('deleted', $view, $page, $filters);
    } catch (Exception $e) {
        $conn->rollback();
        users_redirect('bulk_failed', $view, $page, $filters);
    }
}

if (isset($_GET['id']) && isset($_GET['action']) && isset($_GET['type'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action']; // 'block', 'unblock', or 'delete'
    $type = $_GET['type'];     // 'user', 'volunteer', or 'participant'
    if ($type == 'user' && $action == 'delete') {
        if ($id <= 0) {
            users_redirect('delete_failed', $view, $page, $filters);
        }

        $check = $conn->prepare('SELECT id, is_student FROM users WHERE id = ? LIMIT 1');
        $check->bind_param('i', $id);
        $check->execute();
        $user = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$user) {
            users_redirect('delete_failed', $view, $page, $filters);
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
            users_redirect('deleted', $view, $page, $filters);
        } catch (Exception $e) {
            $conn->rollback();
            users_redirect('delete_failed', $view, $page, $filters);
        }

    } elseif ($type == 'user' && ($action == 'block' || $action == 'unblock')) {
        $new_status = ($action == 'block') ? 'blocked' : 'active';
        $msg = ($action == 'block') ? 'blocked' : 'unblocked';

        $stmt = $conn->prepare("UPDATE users SET status=? WHERE id=?");
        $stmt->bind_param("si", $new_status, $id);

        if ($stmt->execute()) {
            users_redirect($msg, $view, $page, $filters);
        }
        users_redirect('update_failed', $view, $page, $filters);
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
