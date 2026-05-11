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
    } elseif ($action === 'update_subadmin') {
        $sid = (int) ($_POST['subadmin_id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        $full_name = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $password2 = (string) ($_POST['password_confirm'] ?? '');
        $priv_post = isset($_POST['privileges']) && is_array($_POST['privileges']) ? $_POST['privileges'] : [];
        $privs = normalize_subadmin_privileges($priv_post, $defs);

        if ($sid <= 0) {
            $err = 'Invalid sub-admin.';
        } elseif ($username === '' || $full_name === '') {
            $err = 'Username and full name are required.';
        } elseif ($privs === []) {
            $err = 'Select at least one privilege.';
        } elseif ($password !== '' && $password !== $password2) {
            $err = 'Password confirmation does not match.';
        } elseif ($password !== '' && strlen($password) < 6) {
            $err = 'Password must be at least 6 characters.';
        } else {
            $u_esc = $conn->real_escape_string($username);
            $dup = $conn->query("SELECT id FROM subadmins WHERE username = '$u_esc' AND id <> $sid LIMIT 1");
            if ($dup && $dup->num_rows > 0) {
                $err = 'Username already exists.';
            } else {
                $conn->begin_transaction();
                try {
                    $fn_esc = $conn->real_escape_string($full_name);
                    $em_sql = $email !== '' ? "'" . $conn->real_escape_string($email) . "'" : 'NULL';
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $h_esc = $conn->real_escape_string($hash);
                        $ok = $conn->query("UPDATE subadmins SET username = '$u_esc', full_name = '$fn_esc', email = $em_sql, password = '$h_esc' WHERE id = $sid");
                    } else {
                        $ok = $conn->query("UPDATE subadmins SET username = '$u_esc', full_name = '$fn_esc', email = $em_sql WHERE id = $sid");
                    }
                    if (!$ok) {
                        throw new RuntimeException('profile');
                    }
                    $conn->query('DELETE FROM subadmin_privileges WHERE subadmin_id = ' . $sid);
                    foreach ($privs as $p) {
                        $ins = $conn->prepare('INSERT INTO subadmin_privileges (subadmin_id, privilege) VALUES (?, ?)');
                        $ins->bind_param('is', $sid, $p);
                        $ins->execute();
                        $ins->close();
                    }
                    $conn->commit();
                    subadmin_flash_redirect('Sub-admin updated.');
                } catch (Throwable $e) {
                    $conn->rollback();
                    $err = 'Update failed.';
                }
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
    }
}

$flash = null;
if (!empty($_SESSION['manage_subadmin_flash'])) {
    $flash = $_SESSION['manage_subadmin_flash'];
    unset($_SESSION['manage_subadmin_flash']);
}

$list = $conn->query('SELECT id, username, full_name, email, status, created_at FROM subadmins ORDER BY id ASC');
$priv_by_subadmin = [];
$pr_all = $conn->query('SELECT subadmin_id, privilege FROM subadmin_privileges ORDER BY subadmin_id ASC, privilege ASC');
if ($pr_all) {
    while ($r = $pr_all->fetch_assoc()) {
        $sid = (int) $r['subadmin_id'];
        if (!isset($priv_by_subadmin[$sid])) {
            $priv_by_subadmin[$sid] = [];
        }
        $priv_by_subadmin[$sid][] = $r['privilege'];
    }
}
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$edit_open_payload = null;
if ($edit_id > 0) {
    $erow = $conn->query('SELECT id, username, full_name, email FROM subadmins WHERE id = ' . $edit_id . ' LIMIT 1');
    if ($erow && $erow->num_rows > 0) {
        $r = $erow->fetch_assoc();
        $edit_open_payload = [
            'id' => (int) $r['id'],
            'username' => $r['username'],
            'full_name' => $r['full_name'],
            'email' => $r['email'] ?? '',
            'privileges' => $priv_by_subadmin[$edit_id] ?? [],
        ];
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
        .main-content { margin-left: 280px; padding: 40px; box-sizing: border-box; width: 100%; max-width: 100%; }
        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 12px; } }
        @media (max-width: 767.98px) {
            .subadmins-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: thin; }
            .subadmins-table-wrap table { min-width: 640px; }
            .subadmins-table-wrap thead th { white-space: nowrap; }
        }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="main-content">
    <h4 class="fw-bold">Sub-admin accounts</h4>
    <p class="text-muted small">Create accounts and assign module access. New passwords are stored securely (hashed).</p>
    <p class="text-muted small border-start border-3 border-primary ps-3 mb-4"><strong>How to assign a sub-admin:</strong> Sign in with the <strong>main administrator</strong> account (not a sub-admin account). In the sidebar, open <strong>User Administration</strong> → <strong>Sub-admins</strong>. Use <strong>Create sub-admin</strong> to set username, password, full name, and tick the modules this person may access, then submit. They sign in on the same login page as you; inactive accounts cannot log in. Use <strong>Edit</strong> on a row to update profile, optional new password, and privileges in one place.</p>

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
        <div class="card-body p-0 subadmins-table-wrap">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th>ID</th><th>Username</th><th>Name</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($list): while ($row = $list->fetch_assoc()): ?>
                    <?php
                    $sid = (int) $row['id'];
                    $row_privs = $priv_by_subadmin[$sid] ?? [];
                    $edit_payload = [
                        'id' => $sid,
                        'username' => $row['username'],
                        'full_name' => $row['full_name'],
                        'email' => $row['email'] ?? '',
                        'privileges' => $row_privs,
                    ];
                    ?>
                    <tr>
                        <td><?php echo $sid; ?></td>
                        <td><?php echo htmlspecialchars($row['username']); ?></td>
                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                        <td><span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                        <td class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-3 js-open-edit-subadmin" data-payload="<?php echo htmlspecialchars(json_encode($edit_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>">Edit</button>
                            <form method="post" class="d-inline js-toggle-status-form">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="subadmin_id" value="<?php echo $sid; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary rounded-3">Toggle active</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit sub-admin (profile + password + privileges) -->
    <div class="modal fade" id="editSubadminModal" tabindex="-1" aria-labelledby="editSubadminModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content rounded-4">
                <form method="post" id="editSubadminForm">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold" id="editSubadminModalLabel">Edit sub-admin</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body pt-2">
                        <input type="hidden" name="action" value="update_subadmin">
                        <input type="hidden" name="subadmin_id" id="edit_subadmin_id" value="">
                        <p class="text-muted small mb-3">Passwords are stored securely (hashed). Leave password fields blank to keep the current password.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Username</label>
                                <input class="form-control" name="username" id="edit_subadmin_username" required autocomplete="username">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Full name</label>
                                <input class="form-control" name="full_name" id="edit_subadmin_full_name" required autocomplete="name">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Email (optional)</label>
                                <input type="email" class="form-control" name="email" id="edit_subadmin_email" autocomplete="email">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">New password (optional)</label>
                                <input type="password" class="form-control" name="password" id="edit_subadmin_password" autocomplete="new-password" placeholder="Leave blank to keep (min 6 characters)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Confirm new password</label>
                                <input type="password" class="form-control" name="password_confirm" id="edit_subadmin_password_confirm" autocomplete="new-password" placeholder="Repeat if changing">
                            </div>
                        </div>
                        <hr class="my-4">
                        <label class="form-label small fw-bold d-block mb-2">Privileges</label>
                        <div class="row">
                            <?php foreach ($defs as $key => $label): ?>
                            <div class="col-md-6 mb-2">
                                <label class="d-flex align-items-center gap-2">
                                    <input type="checkbox" name="privileges[]" value="<?php echo htmlspecialchars($key); ?>" class="edit-subadmin-priv">
                                    <span><?php echo htmlspecialchars($label); ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-3 fw-bold px-4">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
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

    var editModalEl = document.getElementById('editSubadminModal');
    var editModal = editModalEl ? new bootstrap.Modal(editModalEl) : null;

    function openEditSubadminModal(payload) {
        if (!editModal || !payload || !payload.id) return;
        document.getElementById('edit_subadmin_id').value = String(payload.id);
        document.getElementById('edit_subadmin_username').value = payload.username || '';
        document.getElementById('edit_subadmin_full_name').value = payload.full_name || '';
        document.getElementById('edit_subadmin_email').value = payload.email || '';
        document.getElementById('edit_subadmin_password').value = '';
        document.getElementById('edit_subadmin_password_confirm').value = '';
        var privSet = {};
        (payload.privileges || []).forEach(function (p) { privSet[p] = true; });
        document.querySelectorAll('.edit-subadmin-priv').forEach(function (cb) {
            cb.checked = !!privSet[cb.value];
        });
        editModal.show();
    }

    document.querySelectorAll('.js-open-edit-subadmin').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var raw = btn.getAttribute('data-payload');
            try {
                openEditSubadminModal(JSON.parse(raw));
            } catch (e) {
                swalFeedback('Error', 'Could not open editor.', 'error');
            }
        });
    });

    <?php if ($edit_open_payload !== null): ?>
    (function openFromQuery() {
        var payload = <?php echo json_encode($edit_open_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        function run() {
            openEditSubadminModal(payload);
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, document.title, 'manage_subadmins.php');
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    })();
    <?php endif; ?>
})();
</script>
</body>
</html>
