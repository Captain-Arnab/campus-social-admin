<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header('Location: index.php');
    exit();
}
// Main admin only — SMS credentials / delivery diagnostics
if (!is_main_admin()) {
    header('Location: dashboard.php?forbidden=1');
    exit();
}

@$conn->query(
    "CREATE TABLE IF NOT EXISTS `sms_log` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `purpose` VARCHAR(64) NOT NULL DEFAULT '',
      `destination` VARCHAR(20) NOT NULL DEFAULT '',
      `user_id` INT NULL,
      `job_id` BIGINT UNSIGNED NULL,
      `ok` TINYINT(1) NOT NULL DEFAULT 0,
      `http_code` INT NOT NULL DEFAULT 0,
      `gateway_body` TEXT NULL,
      `error_message` TEXT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_sms_created` (`created_at`),
      KEY `idx_sms_purpose_ok` (`purpose`, `ok`, `created_at`),
      KEY `idx_sms_job` (`job_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$jobRows = [];
$logRows = [];
$jr = @$conn->query(
    "SELECT id, job_type, status, attempts, max_attempts, last_error, available_at, created_at, updated_at,
            LEFT(payload_json, 120) AS payload_preview
       FROM background_jobs
      WHERE job_type = 'login_otp_sms'
      ORDER BY id DESC
      LIMIT 50"
);
if ($jr) {
    while ($row = $jr->fetch_assoc()) {
        $jobRows[] = $row;
    }
}
$lr = @$conn->query(
    "SELECT id, purpose, destination, user_id, job_id, ok, http_code,
            LEFT(gateway_body, 200) AS gateway_body, LEFT(error_message, 200) AS error_message, created_at
       FROM sms_log
      ORDER BY id DESC
      LIMIT 50"
);
if ($lr) {
    while ($row = $lr->fetch_assoc()) {
        $logRows[] = $row;
    }
}

$pending = 0;
$failed = 0;
$pr = @$conn->query(
    "SELECT
        SUM(status = 'pending') AS pending_n,
        SUM(status = 'failed') AS failed_n,
        SUM(status = 'processing') AS processing_n
       FROM background_jobs WHERE job_type = 'login_otp_sms'"
);
if ($pr && ($psum = $pr->fetch_assoc())) {
    $pending = (int) ($psum['pending_n'] ?? 0);
    $failed = (int) ($psum['failed_n'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Jobs — MiCampus Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">
<div class="d-flex" id="wrapper">
    <?php include 'sidebar.php'; ?>
    <div id="page-content-wrapper" class="w-100">
        <div class="container-fluid p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">SMS / OTP delivery</h3>
                    <p class="text-muted mb-0 small">Recent <code>login_otp_sms</code> jobs and gateway responses</p>
                </div>
                <a href="sms_jobs.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-sync"></i> Refresh</a>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="text-muted small">Pending OTP jobs</div>
                            <div class="fs-3 fw-bold <?php echo $pending > 0 ? 'text-warning' : ''; ?>"><?php echo $pending; ?></div>
                            <?php if ($pending > 0): ?>
                                <div class="small text-warning">Cron may not be draining the queue — check <code>process_background_jobs.php</code></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="text-muted small">Failed OTP jobs</div>
                            <div class="fs-3 fw-bold <?php echo $failed > 0 ? 'text-danger' : ''; ?>"><?php echo $failed; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body small">
                            <div class="fw-semibold mb-1">Cron (every minute)</div>
                            <code class="d-block" style="white-space:pre-wrap;font-size:0.75rem;">* * * * * cd /path/to/admin/api &amp;&amp; php process_background_jobs.php &gt;&gt; /var/log/micampus_jobs.log 2&gt;&amp;1</code>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">background_jobs — login_otp_sms (last 50)</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Status</th>
                                <th>Attempts</th>
                                <th>Last error</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($jobRows === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No OTP jobs yet</td></tr>
                        <?php else: ?>
                            <?php foreach ($jobRows as $r): ?>
                                <tr>
                                    <td><?php echo (int) $r['id']; ?></td>
                                    <td>
                                        <?php
                                        $st = $r['status'];
                                        $badge = 'secondary';
                                        if ($st === 'done') $badge = 'success';
                                        elseif ($st === 'failed') $badge = 'danger';
                                        elseif ($st === 'pending') $badge = 'warning';
                                        elseif ($st === 'processing') $badge = 'info';
                                        ?>
                                        <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($st); ?></span>
                                    </td>
                                    <td><?php echo (int) $r['attempts']; ?>/<?php echo (int) $r['max_attempts']; ?></td>
                                    <td class="small text-danger" style="max-width:360px;word-break:break-word;">
                                        <?php echo htmlspecialchars((string) ($r['last_error'] ?? '')); ?>
                                    </td>
                                    <td class="small text-nowrap"><?php echo htmlspecialchars((string) $r['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">sms_log (last 50)</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>OK</th>
                                <th>Dest</th>
                                <th>HTTP</th>
                                <th>Gateway body / error</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($logRows === []): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No SMS log rows yet</td></tr>
                        <?php else: ?>
                            <?php foreach ($logRows as $r): ?>
                                <tr>
                                    <td><?php echo (int) $r['id']; ?></td>
                                    <td>
                                        <?php if ((int) $r['ok'] === 1): ?>
                                            <span class="badge bg-success">ok</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">fail</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?php echo htmlspecialchars((string) $r['destination']); ?></td>
                                    <td><?php echo (int) $r['http_code']; ?></td>
                                    <td class="small" style="max-width:420px;word-break:break-word;">
                                        <?php
                                        $gb = trim((string) ($r['gateway_body'] ?? ''));
                                        $em = trim((string) ($r['error_message'] ?? ''));
                                        echo htmlspecialchars($em !== '' ? $em : $gb);
                                        ?>
                                    </td>
                                    <td class="small text-nowrap"><?php echo htmlspecialchars((string) $r['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
