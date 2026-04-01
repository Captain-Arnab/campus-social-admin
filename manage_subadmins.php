<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header('Location: index.php');
    exit();
}
if (!is_main_admin()) {
    header('Location: dashboard.php?forbidden=1');
    exit();
}

$defs = subadmin_privilege_definitions();
$err = '';

function subadmin_flash_redirect(string $message, string $type = 'success', ?string $query = null): void
{
    $_SESSION['manage_subadmin_flash'] = ['type' => $type, 'message' => $message];
    $url = 'manage_subadmins.php';
    if ($query !== null && $query !== '') {
        $url .= '?' . ltrim($query, '?');
    }
    header('Location: ' . $url);
    exit();
}

function normalize_subadmin_privileges(array $keys, array $defs): array
{
    $valid = array_keys($defs);
    $out = [];
    foreach ($keys as $k) {
        $k = is_string($k) ? trim($k) : '';
        if ($k !== '' && in_array($k, $valid, true)) {
            $out[] = $k;
        }
    }
    $out = array_values(array_unique($out));
    if ($out !== [] && !in_array('dashboard', $out, true)) {
        $out[] = 'dashboard';
    }
    if (in_array('approve_events', $out, true) && !in_array('events', $out, true)) {
        $out[] = 'events';
    }
    sort($out);
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $full_name = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $priv_post = isset($_POST['privileges']) && is_array($_POST['privileges']) ? $_POST['privileges'] : [];
        $privs = normalize_subadmin_privileges($priv_post, $defs);

        if ($username === '' || $password === '' || $full_name === '') {
            $err = 'Username, password, and full name are required.';
        } elseif ($privs === []) {
            $err = 'Select at least one privilege (dashboard is added automatically if you pick any other).';
        } else {
            $u_esc = $conn->real_escape_string($username);
            $dup = $conn->query("SELECT id FROM subadmins WHERE username = '$u_esc' LIMIT 1");
            if ($dup && $dup->num_rows > 0) {
                $err = 'Username already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $fn_esc = $conn->real_escape_string($full_name);
                $em_esc = $email !== '' ? "'" . $conn->real_escape_string($email) . "'" : 'NULL';
                $hash_esc = $conn->real_escape_string($hash);
                if ($conn->query("INSERT INTO subadmins (username, password, full_name, email, status) VALUES ('$u_esc', '$hash_esc', '$fn_esc', $em_esc, 'active')")) {
                    $sid = (int) $conn->insert_id;
                    foreach ($privs as $p) {
                        $ins = $conn->prepare('INSERT INTO subadmin_privileges (subadmin_id, privilege) VALUES (?, ?)');
                        $ins->bind_param('is', $sid, $p);
                        $ins->execute();
                        $ins->close();
                    }
                    subadmin_flash_redirect('Sub-admin created.');
                } else {
                    $err = 'Could not create account: ' . $conn->error;
                }
            }
        }
    } elseif ($action === 'update_privileges') {
        $sid = (int) ($_POST['subadmin_id'] ?? 0);
        $priv_post = isset($_POST['privileges']) && is_array($_POST['privileges']) ? $_POST['privileges'] : [];
        $privs = normalize_subadmin_privileges($priv_post, $defs);
        if ($sid <= 0) {
            $err = 'Invalid sub-admin.';
        } elseif ($privs === []) {
            $err = 'Select at least one privilege.';
        } else {
            $conn->begin_transaction();
            try {
                $conn->query('DELETE FROM subadmin_privileges WHERE subadmin_id = ' . $sid);
                foreach ($privs as $p) {
                    $ins = $conn->prepare('INSERT INTO subadmin_privileges (subadmin_id, privilege) VALUES (?, ?)');
                    $ins->bind_param('is', $sid, $p);
                    $ins->execute();
                    $ins->close();
                }
                $conn->commit();
                subadmin_flash_redirect('Privileges updated.');
            } catch (Throwable $e) {
                $conn->rollback();
                $err = 'Update failed.';
            }
        }
    } elseif ($action === 'toggle_status') {
        $sid = (int) ($_POST['subadmin_id'] ?? 0);
        if ($sid > 0) {
            $row = $conn->query("SELECT status FROM subadmins WHERE id = $sid")->fetch_assoc();
            if ($row) {
                $next = $row['status'] === 'active' ? 'inactive' : 'active';
                $conn->query("UPDATE subadmins SET status = '" . $conn->real_escape_string($next) . "' WHERE id = $sid");
                subadmin_flash_redirect('Status updated.');
            }
        }
    } elseif ($action === 'reset_password') {
        $sid = (int) ($_POST['subadmin_id'] ?? 0);
        $newp = (string) ($_POST['new_password'] ?? '');
        if ($sid > 0 && strlen($newp) >= 6) {
            $hash = password_hash($newp, PASSWORD_DEFAULT);
            $h = $conn->real_escape_string($hash);
            $conn->query("UPDATE subadmins SET password = '$h' WHERE id = $sid");
            subadmin_flash_redirect('Password reset.', 'success', 'edit=' . $sid);
        } else {
            $err = 'Password must be at least 6 characters.';
        }
    }
}

$flash = null;
if (!empty($_SESSION['manage_subadmin_flash'])) {
    $flash = $_SESSION['manage_subadmin_flash'];
    unset($_SESSION['manage_subadmin_flash']);
}

$list = $conn->query('SELECT id, username, full_name, email, status, created_at FROM subadmins ORDER BY id ASC');
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$edit_privs = [];
if ($edit_id > 0) {
    $er = $conn->query('SELECT privilege FROM subadmin_privileges WHERE subadmin_id = ' . $edit_id);
    if ($er) {
        while ($r = $er->fetch_assoc()) {
            $edit_privs[] = $r['privilege'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sub-admins | Admin</title>
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
    <h4 class="fw-bold">Sub-admin accounts</h4>
    <p class="text-muted small">Create accounts and assign module access. New passwords are stored securely (hashed).</p>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Create sub-admin</h6>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="create">
                <div class="col-md-4"><label class="form-label small fw-bold">Username</label><input class="form-control" name="username" required></div>
                <div class="col-md-4"><label class="form-label small fw-bold">Password</label><input type="password" class="form-control" name="password" required minlength="6"></div>
                <div class="col-md-4"><label class="form-label small fw-bold">Full name</label><input class="form-control" name="full_name" required></div>
                <div class="col-12"><label class="form-label small fw-bold">Email (optional)</label><input type="email" class="form-control" name="email"></div>
                <div class="col-12">
                    <label class="form-label small fw-bold">Privileges</label>
                    <div class="row">
                        <?php foreach ($defs as $key => $label): ?>
                        <div class="col-md-6 mb-2">
                            <label class="d-flex align-items-center gap-2">
                                <input type="checkbox" name="privileges[]" value="<?php echo htmlspecialchars($key); ?>">
                                <span><?php echo htmlspecialchars($label); ?></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12"><button type="submit" class="btn btn-dark rounded-3 fw-bold">Create</button></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th>ID</th><th>Username</th><th>Name</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($list): while ($row = $list->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo (int) $row['id']; ?></td>
                        <td><?php echo htmlspecialchars($row['username']); ?></td>
                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                        <td><span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                        <td class="d-flex flex-wrap gap-2">
                            <a class="btn btn-sm btn-outline-primary rounded-3" href="manage_subadmins.php?edit=<?php echo (int) $row['id']; ?>">Edit privileges</a>
                            <form method="post" class="d-inline js-toggle-status-form">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="subadmin_id" value="<?php echo (int) $row['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary rounded-3">Toggle active</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($edit_id > 0): ?>
    <div class="card border-0 shadow-sm rounded-4 mt-4">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Edit privileges — ID <?php echo $edit_id; ?></h6>
            <form method="post" class="mb-4">
                <input type="hidden" name="action" value="update_privileges">
                <input type="hidden" name="subadmin_id" value="<?php echo $edit_id; ?>">
                <?php foreach ($defs as $key => $label): ?>
                <label class="d-flex align-items-center gap-2 mb-2">
                    <input type="checkbox" name="privileges[]" value="<?php echo htmlspecialchars($key); ?>" <?php echo in_array($key, $edit_privs, true) ? 'checked' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </label>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary rounded-3 mt-2">Save privileges</button>
                <a href="manage_subadmins.php" class="btn btn-link">Cancel</a>
            </form>
            <hr>
            <h6 class="fw-bold mb-2">Reset password</h6>
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="subadmin_id" value="<?php echo $edit_id; ?>">
                <div class="col-md-4"><input type="password" name="new_password" class="form-control" placeholder="New password" minlength="6" required></div>
                <div class="col-auto"><button type="submit" class="btn btn-warning rounded-3">Reset</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    var flash = <?php echo $flash ? json_encode($flash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null'; ?>;
    var err = <?php echo $err !== '' ? json_encode($err, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null'; ?>;

    function swalFeedback(title, text, icon) {
        Swal.fire({
            icon: icon,
            title: title,
            text: text,
            confirmButtonColor: '#0d6efd'
        });
    }

    if (flash && flash.message) {
        swalFeedback(flash.type === 'error' ? 'Error' : 'Done', flash.message, flash.type === 'error' ? 'error' : 'success');
    } else if (err) {
        swalFeedback('Error', err, 'error');
    }

    document.querySelectorAll('.js-toggle-status-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({
                title: 'Toggle status?',
                text: 'This will activate or deactivate the sub-admin account.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0d6efd',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, toggle',
                cancelButtonText: 'Cancel'
            }).then(function (res) {
                if (res.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
})();
</script>
</body>
</html>
