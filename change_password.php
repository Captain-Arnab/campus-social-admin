<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/portal_auth.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header('Location: index.php');
    exit();
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($current === '' || $new === '' || $confirm === '') {
        $err = 'Please fill in all fields.';
    } elseif (strlen($new) < 6) {
        $err = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $err = 'New password and confirmation do not match.';
    } elseif (hash_equals($current, $new)) {
        $err = 'New password must be different from your current password.';
    } elseif (is_main_admin()) {
        $user = (string) $_SESSION['admin'];
        $stmt = $conn->prepare('SELECT password FROM admins WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $user);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !portal_verify_password($row['password'], $current)) {
            $err = 'Current password is incorrect.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt2 = $conn->prepare('UPDATE admins SET password = ? WHERE username = ?');
            $stmt2->bind_param('ss', $hash, $user);
            if ($stmt2->execute()) {
                $msg = 'Your password has been updated.';
            } else {
                $err = 'Could not update password. Please try again.';
            }
            $stmt2->close();
        }
    } else {
        $sid = (int) ($_SESSION['subadmin_id'] ?? 0);
        if ($sid <= 0) {
            $err = 'Session error. Please sign out and sign in again.';
        } else {
            $stmt = $conn->prepare('SELECT password FROM subadmins WHERE id = ? AND status = ? LIMIT 1');
            $status = 'active';
            $stmt->bind_param('is', $sid, $status);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row || !portal_verify_password($row['password'], $current)) {
                $err = 'Current password is incorrect.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $stmt2 = $conn->prepare('UPDATE subadmins SET password = ? WHERE id = ?');
                $stmt2->bind_param('si', $hash, $sid);
                if ($stmt2->execute()) {
                    $msg = 'Your password has been updated.';
                } else {
                    $err = 'Could not update password. Please try again.';
                }
                $stmt2->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change password | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8f9fd; }
        .main-content { margin-left: 280px; padding: 40px; }
        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 20px; } }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="main-content">
    <h4 class="fw-bold">Change password</h4>
    <p class="text-muted small">Use a strong password you do not use elsewhere.</p>

    <div class="card border-0 shadow-sm rounded-4 mt-3" style="max-width: 480px;">
        <div class="card-body p-4">
            <form method="post" class="row g-3" id="pwd-form" autocomplete="off">
                <div class="col-12">
                    <label class="form-label small fw-bold">Current password</label>
                    <input type="password" name="current_password" class="form-control rounded-3" required minlength="1">
                </div>
                <div class="col-12">
                    <label class="form-label small fw-bold">New password</label>
                    <input type="password" name="new_password" class="form-control rounded-3" required minlength="6">
                </div>
                <div class="col-12">
                    <label class="form-label small fw-bold">Confirm new password</label>
                    <input type="password" name="confirm_password" class="form-control rounded-3" required minlength="6">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-dark rounded-3 fw-bold px-4">Update password</button>
                    <a href="dashboard.php" class="btn btn-link">Back to dashboard</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    var msg = <?php echo $msg !== '' ? json_encode($msg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null'; ?>;
    var err = <?php echo $err !== '' ? json_encode($err, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null'; ?>;
    if (msg) {
        Swal.fire({ icon: 'success', title: 'Done', text: msg, confirmButtonColor: '#0d6efd' }).then(function () {
            document.getElementById('pwd-form').reset();
        });
    } else if (err) {
        Swal.fire({ icon: 'error', title: 'Could not update', text: err, confirmButtonColor: '#0d6efd' });
    }
})();
</script>
</body>
</html>
