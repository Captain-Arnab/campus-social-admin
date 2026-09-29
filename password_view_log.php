<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/password_vault.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header('Location: index.php');
    exit();
}
if (!is_main_admin()) {
    header('Location: dashboard.php?forbidden=1');
    exit();
}

$schema_ok = password_vault_ensure_schema($conn);
$key_ok = password_vault_is_configured();

$per_page = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$filter_user = max(0, (int) ($_GET['user_id'] ?? 0));
$where = $filter_user > 0 ? "WHERE l.target_user_id = $filter_user" : '';

$total_rows = 0;
$rows = [];
$stored_count = 0;
$user_count = 0;
if ($schema_ok) {
    $total_rows = (int) ($conn->query("SELECT COUNT(*) AS c FROM password_view_log l $where")->fetch_assoc()['c'] ?? 0);
    $total_pages = max(1, (int) ceil($total_rows / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;
    $res = $conn->query(
        "SELECT l.id, l.admin_id, l.admin_type, l.target_user_id, l.viewed_at, l.ip_address,
                a.username AS admin_username, s.username AS subadmin_username, s.full_name AS subadmin_name,
                u.full_name AS target_name, u.email AS target_email, u.is_student AS target_is_student
         FROM password_view_log l
         LEFT JOIN admins a ON l.admin_type = 'admin' AND a.id = l.admin_id
         LEFT JOIN subadmins s ON l.admin_type = 'subadmin' AND s.id = l.admin_id
         LEFT JOIN users u ON u.id = l.target_user_id
         $where
         ORDER BY l.viewed_at DESC, l.id DESC
         LIMIT $per_page OFFSET $offset"
    );
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    $counts = $conn->query("SELECT COUNT(*) AS total_users, SUM(password_encrypted IS NOT NULL) AS viewable_users FROM users")->fetch_assoc();
    $user_count = (int) ($counts['total_users'] ?? 0);
    $stored_count = (int) ($counts['viewable_users'] ?? 0);
} else {
    $total_pages = 1;
}

function pvl_page_url(int $p, int $filter_user): string
{
    $q = ['page' => $p];
    if ($filter_user > 0) {
        $q['user_id'] = $filter_user;
    }
    return 'password_view_log.php?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password view log | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @media (max-width: 767.98px) {
            .pvl-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .pvl-table-wrap table { min-width: 720px; }
        }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="main-content">
    <div class="page-hero">
        <div>
            <div class="page-kicker">User administration</div>
            <h1 class="page-title">Password view log</h1>
            <p class="page-sub">Every time an admin reveals a student or faculty password, it is recorded here.</p>
        </div>
    </div>

    <?php if (!$schema_ok): ?>
        <div class="alert alert-danger rounded-4">Could not create the audit table. Run <code>uploads/migrations/009_user_password_vault.sql</code>.</div>
    <?php endif; ?>
    <?php if (!$key_ok): ?>
        <div class="alert alert-warning rounded-4">
            <strong>Encryption key not configured.</strong> Set the <code>USER_PASSWORD_ENC_KEY</code> environment variable or create
            <code>api/password_vault_config.local.php</code> (see the <code>.example</code> file). Until then, new and reset passwords are not captured for viewing.
        </div>
    <?php endif; ?>

    <?php if ($schema_ok): ?>
    <p class="text-muted small border-start border-3 ps-3 mb-4" style="border-color: var(--brand) !important;">
        <strong><?php echo number_format($stored_count); ?></strong> of <strong><?php echo number_format($user_count); ?></strong> users have a viewable password.
        Users who registered before this feature show “Not available” until they reset their password.
    </p>

    <?php if ($filter_user > 0): ?>
        <div class="mb-3">
            <span class="badge bg-light text-dark border px-3 py-2">Filtered to user #<?php echo $filter_user; ?></span>
            <a href="password_view_log.php" class="btn btn-link btn-sm">Clear filter</a>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0 pvl-table-wrap">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>When</th><th>Viewed by</th><th>Whose password</th><th>IP address</th></tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-5">No passwords have been revealed yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    if ($r['admin_type'] === 'admin') {
                        $who = $r['admin_username'] !== null ? $r['admin_username'] : 'Admin #' . (int) $r['admin_id'] . ' (deleted)';
                        $role = 'Administrator';
                    } else {
                        $who = $r['subadmin_name'] !== null ? $r['subadmin_name'] . ' (' . $r['subadmin_username'] . ')' : 'Sub-admin #' . (int) $r['admin_id'] . ' (deleted)';
                        $role = 'Sub-admin';
                    }
                    $tid = (int) $r['target_user_id'];
                    ?>
                    <tr>
                        <td class="text-nowrap"><?php echo htmlspecialchars(date('M d, Y · h:i:s A', strtotime($r['viewed_at']))); ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo htmlspecialchars($who); ?></div>
                            <div class="small text-muted"><?php echo $role; ?></div>
                        </td>
                        <td>
                            <?php if ($r['target_name'] !== null): ?>
                                <a href="user_profile.php?id=<?php echo $tid; ?>" class="fw-semibold text-decoration-none"><?php echo htmlspecialchars($r['target_name']); ?></a>
                                <span class="badge bg-light text-dark border ms-1"><?php echo ((int) $r['target_is_student'] === 1) ? 'Student' : 'Faculty'; ?></span>
                                <div class="small text-muted"><?php echo htmlspecialchars((string) $r['target_email']); ?> · #<?php echo $tid; ?></div>
                            <?php else: ?>
                                <span class="text-muted">User #<?php echo $tid; ?> (deleted)</span>
                            <?php endif; ?>
                            <?php if ($filter_user === 0): ?>
                                <a href="<?php echo htmlspecialchars(pvl_page_url(1, $tid)); ?>" class="small ms-1" title="Show only this user"><i class="fas fa-filter"></i></a>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo htmlspecialchars((string) ($r['ip_address'] ?? '')); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
        <nav class="d-flex justify-content-between align-items-center mt-3" aria-label="Password view log pagination">
            <span class="small text-muted">Page <?php echo $page; ?> of <?php echo $total_pages; ?> · <?php echo number_format($total_rows); ?> entries</span>
            <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-secondary rounded-3 <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(pvl_page_url($page - 1, $filter_user)); ?>">Previous</a>
                <a class="btn btn-sm btn-outline-secondary rounded-3 <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(pvl_page_url($page + 1, $filter_user)); ?>">Next</a>
            </div>
        </nav>
    <?php endif; ?>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
