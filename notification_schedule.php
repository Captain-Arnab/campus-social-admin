<?php
/**
 * Manage celebration_days — the only calendar the cron uses for all-user greeting pushes
 * (see api/send_scheduled_notifications.php). Optional push_title / push_message after migration.
 */
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header('Location: index.php');
    exit();
}
require_priv('notification_schedule');

function celebration_days_has_push_columns_local(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = @$conn->query("SHOW COLUMNS FROM `celebration_days` LIKE 'push_title'");
    $cache = ($r && $r->num_rows > 0);
    return $cache;
}

$table_ok = false;
$tc = @$conn->query("SHOW TABLES LIKE 'celebration_days'");
if ($tc && $tc->num_rows > 0) {
    $table_ok = true;
}

$has_push = $table_ok ? celebration_days_has_push_columns_local($conn) : false;

$flash_ok = '';
$flash_err = '';

if ($table_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $occasion_name = trim((string) ($_POST['occasion_name'] ?? ''));
        $occasion_date = trim((string) ($_POST['occasion_date'] ?? ''));
        $is_fixed = !empty($_POST['is_fixed']) ? 1 : 0;
        $is_tentative = !empty($_POST['is_tentative']) ? 1 : 0;
        $sort_raw = trim((string) ($_POST['sort_order'] ?? ''));
        $sort_order = $sort_raw === '' ? null : (int) $sort_raw;
        $push_title = trim((string) ($_POST['push_title'] ?? ''));
        $push_message = trim((string) ($_POST['push_message'] ?? ''));

        if ($occasion_name === '') {
            $flash_err = 'Occasion name is required.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $occasion_date)) {
            $flash_err = 'Enter a valid date (YYYY-MM-DD).';
        } else {
            $pt = $push_title === '' ? null : $push_title;
            $pm = $push_message === '' ? null : $push_message;
            $stmt = null;
            if ($has_push) {
                if ($sort_order === null) {
                    $stmt = $conn->prepare(
                        'INSERT INTO celebration_days (occasion_name, push_title, push_message, occasion_date, is_fixed, is_tentative)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param('ssssii', $occasion_name, $pt, $pm, $occasion_date, $is_fixed, $is_tentative);
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO celebration_days (occasion_name, push_title, push_message, occasion_date, is_fixed, is_tentative, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param('ssssiii', $occasion_name, $pt, $pm, $occasion_date, $is_fixed, $is_tentative, $sort_order);
                }
            } elseif ($sort_order === null) {
                $stmt = $conn->prepare(
                    'INSERT INTO celebration_days (occasion_name, occasion_date, is_fixed, is_tentative)
                     VALUES (?, ?, ?, ?)'
                );
                $stmt->bind_param('ssii', $occasion_name, $occasion_date, $is_fixed, $is_tentative);
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO celebration_days (occasion_name, occasion_date, is_fixed, is_tentative, sort_order)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('ssiii', $occasion_name, $occasion_date, $is_fixed, $is_tentative, $sort_order);
            }
            if ($stmt && $stmt->execute()) {
                header('Location: notification_schedule.php?saved=1');
                exit();
            }
            if ($stmt && $conn->errno === 1062) {
                $flash_err = 'That occasion and date combination already exists.';
            } else {
                $flash_err = $stmt ? 'Could not save: ' . $stmt->error : 'Database error.';
            }
            if ($stmt) {
                $stmt->close();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $flash_err = 'Invalid entry.';
        } else {
            $stmt = $conn->prepare('DELETE FROM celebration_days WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                header('Location: notification_schedule.php?deleted=1');
                exit();
            }
            $flash_err = 'Could not remove that entry (it may have already been deleted).';
            $stmt->close();
        }
    }
}

if (isset($_GET['saved'])) {
    $flash_ok = 'Celebration day saved.';
}
if (isset($_GET['deleted'])) {
    $flash_ok = 'Entry removed.';
}

$items_per_page = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));
$total_rows = 0;
$total_pages = 1;
$rows = [];

if ($table_ok) {
    $count_q = $conn->query('SELECT COUNT(*) AS total FROM celebration_days');
    if ($count_q) {
        $total_rows = (int) (($count_q->fetch_assoc()['total'] ?? 0));
    }
    $total_pages = max(1, (int) ceil($total_rows / $items_per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $items_per_page;

    $sel = $has_push
        ? 'id, occasion_name, push_title, push_message, occasion_date, is_fixed, is_tentative, sort_order, created_at'
        : 'id, occasion_name, occasion_date, is_fixed, is_tentative, sort_order, created_at';
    $q = $conn->query(
        "SELECT {$sel} FROM celebration_days ORDER BY occasion_date DESC, id DESC LIMIT {$items_per_page} OFFSET {$offset}"
    );
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $rows[] = $r;
        }
    }
}

function celebration_page_url(int $page): string
{
    $params = [];
    if ($page > 1) {
        $params['page'] = $page;
    }
    $qs = http_build_query($params);
    return 'notification_schedule.php' . ($qs !== '' ? '?' . $qs : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Celebration days | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --brand-color: #FF5F15; --brand-soft: rgba(255, 95, 21, 0.08); }
        .card-panel { border: none; border-radius: 20px; }
        .btn-brand { background: var(--brand-color); color: #fff; border: none; border-radius: 12px; font-weight: 600; }
        .btn-brand:hover { background: #e04e0b; color: #fff; }
        .pagination-controls { display: flex; justify-content: flex-end; gap: 6px; flex-wrap: wrap; align-items: center; }
        .pagination-controls a,
        .pagination-controls span.page-btn {
            min-width: 38px; height: 38px; border: 1px solid #e9ecef; border-radius: 10px;
            background: white; color: #636e72; font-weight: 600; text-decoration: none;
            display: inline-flex; align-items: center; justify-content: center; padding: 0 10px;
        }
        .pagination-controls a:hover { background: var(--brand-color); border-color: var(--brand-color); color: white; }
        .pagination-controls a.active { background: var(--brand-color); border-color: var(--brand-color); color: white; }
        .pagination-controls span.page-btn.disabled { opacity: 0.45; pointer-events: none; }
        .pagination-ellipsis { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 38px; color: #636e72; }
        .list-meta { font-size: 0.8rem; color: #78716c; font-weight: 600; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="page-hero">
        <div>
            <div class="page-kicker">Notifications</div>
            <h1 class="page-title">Celebration days</h1>
            <p class="page-sub">Dates used for campus-wide greeting broadcasts</p>
        </div>
    </div>

    <?php if (!$table_ok): ?>
        <div class="alert alert-warning rounded-3">
            The <strong>celebration_days</strong> table is missing. Import your schema (e.g. <code>college_event_db.sql</code>), then refresh.
        </div>
    <?php else: ?>

        <?php if (!$has_push): ?>
            <div class="alert alert-info rounded-3 small mb-3">
                Optional: add columns <code>push_title</code> and <code>push_message</code> to customize FCM text per occasion —
                run <code>api/migrations/add_celebration_push_columns.sql</code> in phpMyAdmin, then reload this page.
            </div>
        <?php endif; ?>

        <?php if ($flash_ok !== ''): ?>
            <div class="alert alert-success rounded-3 py-2"><?php echo htmlspecialchars($flash_ok); ?></div>
        <?php endif; ?>
        <?php if ($flash_err !== ''): ?>
            <div class="alert alert-danger rounded-3 py-2"><?php echo htmlspecialchars($flash_err); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card card-panel">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-3"><i class="fas fa-plus-circle text-primary me-2"></i>Add a day</h6>
                        <form method="post" class="vstack gap-3">
                            <input type="hidden" name="action" value="add">
                            <div>
                                <label class="form-label small fw-bold">Occasion name</label>
                                <input type="text" name="occasion_name" class="form-control rounded-3" required maxlength="150" placeholder="e.g. Republic Day">
                            </div>
                            <div>
                                <label class="form-label small fw-bold">Date</label>
                                <input type="date" name="occasion_date" class="form-control rounded-3" required value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
                            </div>
                            <?php if ($has_push): ?>
                            <div>
                                <label class="form-label small fw-bold">Push title <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" name="push_title" class="form-control rounded-3" maxlength="255" placeholder="Default: MiCampus">
                            </div>
                            <div>
                                <label class="form-label small fw-bold">Push message <span class="text-muted fw-normal">(optional)</span></label>
                                <textarea name="push_message" class="form-control rounded-3" rows="3" placeholder="Default: Happy {occasion}!"></textarea>
                            </div>
                            <?php endif; ?>
                            <div>
                                <label class="form-label small fw-bold">Sort order <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="number" name="sort_order" class="form-control rounded-3" placeholder="e.g. 1 for first in list">
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_fixed" value="1" id="is_fixed">
                                <label class="form-check-label small" for="is_fixed">Fixed calendar date every year (reference only)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_tentative" value="1" id="is_tentative">
                                <label class="form-check-label small" for="is_tentative">Tentative date (e.g. Eid)</label>
                            </div>
                            <button type="submit" class="btn btn-brand py-2">Save</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card card-panel">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <h6 class="fw-bold m-0"><i class="fas fa-list me-2"></i>Current list</h6>
                            <span class="list-meta">
                                <?php
                                if ($total_rows === 0) {
                                    echo '0 items';
                                } else {
                                    $from = $offset + 1;
                                    $to = min($offset + $items_per_page, $total_rows);
                                    echo htmlspecialchars("{$from}–{$to} of {$total_rows}");
                                }
                                ?>
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead>
                                    <tr class="text-muted">
                                        <th>Date</th>
                                        <th>Occasion</th>
                                        <th>Flags</th>
                                        <?php if ($has_push): ?><th>Custom push</th><?php endif; ?>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($rows) === 0): ?>
                                        <tr><td colspan="<?php echo $has_push ? 5 : 4; ?>" class="text-muted py-4 text-center">No rows yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($rows as $row): ?>
                                            <tr>
                                                <td class="text-nowrap fw-semibold"><?php echo htmlspecialchars($row['occasion_date']); ?></td>
                                                <td class="fw-semibold"><?php echo htmlspecialchars($row['occasion_name']); ?></td>
                                                <td>
                                                    <?php if (!empty($row['is_fixed'])): ?><span class="badge bg-secondary rounded-pill">Fixed</span><?php endif; ?>
                                                    <?php if (!empty($row['is_tentative'])): ?><span class="badge bg-warning text-dark rounded-pill">Tentative</span><?php endif; ?>
                                                    <?php if (empty($row['is_fixed']) && empty($row['is_tentative'])): ?><span class="text-muted">—</span><?php endif; ?>
                                                </td>
                                                <?php if ($has_push): ?>
                                                <td class="text-muted" style="max-width: 200px;">
                                                    <?php
                                                    $pt = trim((string)($row['push_title'] ?? ''));
                                                    $pm = trim((string)($row['push_message'] ?? ''));
                                                    if ($pt !== '' || $pm !== '') {
                                                        $pmShow = $pm !== '' ? (strlen($pm) > 80 ? substr($pm, 0, 77) . '…' : $pm) : '—';
                                                        echo htmlspecialchars($pt !== '' ? $pt : '—') . '<br><small>' . htmlspecialchars($pmShow) . '</small>';
                                                    } else {
                                                        echo '<span class="text-muted">Default</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <?php endif; ?>
                                                <td class="text-end">
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Remove this celebration day?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-3">Remove</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                        <nav class="pagination-controls mt-3" aria-label="Celebration days pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo htmlspecialchars(celebration_page_url($page - 1)); ?>" aria-label="Previous">&lsaquo;</a>
                            <?php else: ?>
                                <span class="page-btn disabled" aria-disabled="true">&lsaquo;</span>
                            <?php endif; ?>

                            <?php
                            $window = 2;
                            $start = max(1, $page - $window);
                            $end = min($total_pages, $page + $window);
                            if ($start > 1) {
                                echo '<a href="' . htmlspecialchars(celebration_page_url(1)) . '">1</a>';
                                if ($start > 2) {
                                    echo '<span class="pagination-ellipsis">…</span>';
                                }
                            }
                            for ($p = $start; $p <= $end; $p++) {
                                $cls = $p === $page ? ' active' : '';
                                echo '<a class="' . trim($cls) . '" href="' . htmlspecialchars(celebration_page_url($p)) . '">' . $p . '</a>';
                            }
                            if ($end < $total_pages) {
                                if ($end < $total_pages - 1) {
                                    echo '<span class="pagination-ellipsis">…</span>';
                                }
                                echo '<a href="' . htmlspecialchars(celebration_page_url($total_pages)) . '">' . $total_pages . '</a>';
                            }
                            ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo htmlspecialchars(celebration_page_url($page + 1)); ?>" aria-label="Next">&rsaquo;</a>
                            <?php else: ?>
                                <span class="page-btn disabled" aria-disabled="true">&rsaquo;</span>
                            <?php endif; ?>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
