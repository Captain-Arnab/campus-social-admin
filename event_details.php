<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/event_date_range_schema.php';
require_once __DIR__ . '/event_pending_edits_helper.php';
require_once __DIR__ . '/api/admin_public_url.php';

if ((!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) || !isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit();
}
if (!has_priv('events') && !has_priv('approve_events')) {
    header('Location: dashboard.php?forbidden=1');
    exit();
}

$user_type = $_SESSION['user_type'] ?? 'admin';
$username = isset($_SESSION['admin']) ? $_SESSION['admin'] : $_SESSION['subadmin'];

$id = intval($_GET['id']);
$event_query = $conn->query("
    SELECT e.*, u.full_name as organizer_name, u.email as organizer_email, u.status as user_status 
    FROM events e 
    JOIN users u ON e.organizer_id = u.id 
    WHERE e.id = $id
");

$event = $event_query->fetch_assoc();
if (!$event) {
    die("<div style='padding:50px; text-align:center; font-family:sans-serif;'><h4>Event not found.</h4><a href='dashboard.php'>Back to Dashboard</a></div>");
}

$banners = json_decode($event['banners'] ?? '[]');
$is_pending = ($event['status'] == 'pending');
$is_hold = ($event['status'] == 'hold');
$is_past_event = events_row_is_fully_past($event);
$can_edit_event_roster = has_priv('events');

$volunteers = $conn->query("
    SELECT v.id as vol_link_id, v.role, v.status as vol_status, v.attended as vol_attended, v.attendance_marked_at as vol_attendance_at,
           u.full_name, u.email, u.phone, u.id as user_id
    FROM volunteers v
    JOIN users u ON v.user_id = u.id
    WHERE v.event_id = $id
");

// Fetch Participants
$participants = $conn->query("
    SELECT p.id as participant_link_id, p.status as participant_status, p.department_class, p.attended as participant_attended, p.attendance_marked_at as participant_attendance_at,
           u.full_name, u.email, u.phone, u.id as user_id
    FROM participant p
    JOIN users u ON p.user_id = u.id
    WHERE p.event_id = $id
");

// Active counts (blocked members still show in the tables below, but the badge
// counts match the figures the mobile app shows so the numbers stay in sync).
$active_volunteer_count = (int) ($conn->query(
    "SELECT COUNT(*) AS c FROM volunteers WHERE event_id = $id AND status = 'active'"
)->fetch_assoc()['c'] ?? 0);
$active_participant_count = (int) ($conn->query(
    "SELECT COUNT(*) AS c FROM participant WHERE event_id = $id AND status = 'active'"
)->fetch_assoc()['c'] ?? 0);

// Fetch status change log
$status_log = $conn->query("SELECT * FROM event_status_log WHERE event_id = $id ORDER BY changed_at DESC LIMIT 5");

// Event editors (table may not exist until migration is run)
$event_editors = [];
$ed_res = @$conn->query("SELECT ee.user_id, u.full_name, u.email FROM event_editors ee JOIN users u ON ee.user_id = u.id WHERE ee.event_id = $id");
if ($ed_res) {
    while ($r = $ed_res->fetch_assoc()) { $event_editors[] = $r; }
}

// Event winners (table may not exist until migration is run)
$event_winners = [];
$win_res = @$conn->query("SELECT w.user_id, w.position, u.full_name FROM event_winners w JOIN users u ON w.user_id = u.id WHERE w.event_id = $id ORDER BY w.position ASC");
if ($win_res) {
    while ($r = $win_res->fetch_assoc()) { $event_winners[] = $r; }
}

// Certificates per user for this event (for display in tables)
$certificates = [];
$cert_res = @$conn->query("SELECT user_id, type, file_path FROM event_certificates WHERE event_id = $id");
if ($cert_res) {
    while ($r = $cert_res->fetch_assoc()) {
        $key = $r['user_id'] . '_' . $r['type'];
        $certificates[$key] = $r['file_path'];
    }
}

// Event review files
$review_files = [];
$rf_res = @$conn->query("SELECT id, file_path, file_type, original_name, uploaded_at FROM event_review_files WHERE event_id = $id ORDER BY uploaded_at ASC");
if ($rf_res) {
    while ($r = $rf_res->fetch_assoc()) {
        $r['file_url'] = admin_public_file_url($r['file_path'] ?? '');
        $review_files[] = $r;
    }
}

// Meeting minutes (approved / pending / rejected) — same idea as organizer review
$meeting_minutes = [];
$mm_res = @$conn->query(
    "SELECT mm.*, u.full_name AS submitted_by_name
     FROM meeting_minutes mm
     LEFT JOIN users u ON u.id = mm.submitted_by
     WHERE mm.event_id = $id
     ORDER BY mm.created_at DESC, mm.id DESC"
);
if ($mm_res) {
    while ($r = $mm_res->fetch_assoc()) {
        $r['file_url'] = !empty($r['file_path']) ? admin_public_file_url($r['file_path']) : '';
        $meeting_minutes[] = $r;
    }
}

// Pending edit from organizer/editor (when event has editors, edits require admin approval)
schema_event_pending_edits_ensure_extras($conn);
$pending_edit = null;
$pending_edit_res = @$conn->query("SELECT p.*, u.full_name as submitted_by_name FROM event_pending_edits p JOIN users u ON p.submitted_by_user_id = u.id WHERE p.event_id = $id");
if ($pending_edit_res && $pending_edit_res->num_rows > 0) {
    $pending_edit = $pending_edit_res->fetch_assoc();
}

$has_post_event_docs = !empty($event['organizer_review']) || !empty($review_files) || !empty($meeting_minutes);
$event_rules = trim((string) ($event['rules'] ?? ''));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Review: <?php echo $event['title']; ?> | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin-shell.css">
    
    <style>
        :root { 
            --brand-color: #FF5F15; 
            --brand-soft: rgba(255, 95, 21, 0.06);
            --success-btn: #2ecc71;
            --hold-color: #f39c12;
            --text-main: #1c1917;
            --text-muted: #78716c;
            --line: #e7e5e4;
        }
        
        body { font-size: 0.875rem; }
        .container-compact { max-width: 1180px; margin: 24px auto; padding: 0 16px 40px; }
        .compact-card { background: white; border-radius: 16px; border: 1px solid var(--line); margin-bottom: 16px; overflow: hidden; }
        .compact-body { padding: 22px; }
        .section-label {
            font-size: 0.62rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--text-muted); margin-bottom: 8px; display: block;
        }
        .info-panel {
            background: #f8fafc; border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px;
        }
        .info-panel + .info-panel { margin-top: 12px; }
        .info-panel.review { background: #f0fdf4; border-color: #bbf7d0; }
        .info-panel.minutes { background: #eff6ff; border-color: #bfdbfe; }
        .info-panel.attach { background: #fffbeb; border-color: #fde68a; }
        .info-panel.rules { background: #fafafa; border-color: #e5e7eb; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
        @media (max-width: 575.98px) { .meta-grid { grid-template-columns: 1fr; } }
        .meta-item {
            background: #f8fafc; border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px;
        }
        .meta-item .label { font-size: 0.62rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; }
        .meta-item .value { font-weight: 600; color: var(--text-main); line-height: 1.35; }

        .hero-layout { display: flex; gap: 18px; align-items: flex-start; margin-bottom: 18px; }
        .banner-container {
            width: 140px; height: 180px; flex-shrink: 0;
            background: #f1f5f9; border-radius: 14px; overflow: hidden; position: relative;
            border: 1px solid var(--line);
        }
        .banner-img { width: 100%; height: 100%; object-fit: cover; }
        .banner-fallback {
            width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(145deg, #FF5F15 0%, #ff8a50 100%); color: white;
        }
        .hero-copy { min-width: 0; flex: 1; }
        @media (max-width: 575.98px) {
            .hero-layout { flex-direction: column; }
            .banner-container { width: 100%; height: 200px; }
        }

        .category-pill { background: var(--brand-soft); color: var(--brand-color); padding: 4px 10px; border-radius: 8px; font-weight: 700; font-size: 0.65rem; text-transform: uppercase; margin-bottom: 8px; display: inline-block; }
        .event-title { font-family: 'Fraunces', Georgia, serif; font-size: 1.45rem; font-weight: 700; color: #1c1917; margin: 0 0 6px; line-height: 1.25; letter-spacing: -0.02em; }
        .hero-sub { color: var(--text-muted); font-size: 0.8rem; margin: 0; }

        .checklist-item { display: flex; align-items: center; padding: 10px 15px; border-radius: 10px; background: #f9f9f9; margin-bottom: 8px; transition: 0.2s; cursor: pointer; }
        .checklist-item:hover { background: #fff; border: 1px solid #eee; }
        .form-check-input { width: 18px; height: 18px; margin-right: 12px; cursor: pointer; }
        .form-check-input:checked { background-color: var(--success-btn); border-color: var(--success-btn); }

        .vol-table th { font-size: 0.65rem; text-transform: uppercase; color: #95a5a6; padding: 12px 15px; border-bottom: 2px solid #f8f9fa; }
        .vol-table td { padding: 12px 15px; vertical-align: middle; }
        .vol-badge { font-size: 0.65rem; font-weight: 700; padding: 4px 10px; border-radius: 30px; }

        .btn-action-main { width: 100%; padding: 12px; border-radius: 10px; font-weight: 700; border: none; transition: 0.3s; text-transform: uppercase; font-size: 0.8rem; margin-bottom: 8px; }
        .btn-locked { background: #eee; color: #aaa; cursor: not-allowed; }
        .btn-unlocked { background: var(--success-btn); color: white; cursor: pointer; }
        .btn-hold { background: var(--hold-color); color: white; }
        .btn-hold:hover { background: #e67e22; }

        .status-badge { padding: 8px 16px; border-radius: 20px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; }
        .status-hold { background: rgba(243, 156, 18, 0.1); color: #f39c12; border: 2px solid #f39c12; }
        .status-pending { background: rgba(255, 193, 7, 0.1); color: #ffc107; border: 2px solid #ffc107; }
        .status-approved { background: rgba(46, 204, 113, 0.1); color: #2ecc71; border: 2px solid #2ecc71; }
        .status-rejected { background: rgba(231, 76, 60, 0.1); color: #e74c3c; border: 2px solid #e74c3c; }

        .nav-tabs { border-bottom: 1px solid var(--line); }
        .nav-tabs .nav-link {
            border: none; color: #94a3b8; font-weight: 600; font-size: 0.8rem;
            padding: 10px 16px; border-bottom: 3px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: var(--brand-color); border-bottom-color: var(--brand-color); background: transparent;
        }
        .nav-tabs .nav-link:hover { color: var(--brand-color); }

        .side-block-title {
            font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;
            color: var(--text-muted); margin-bottom: 12px;
        }
        .side-actions { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; }
        .side-actions .btn-action-main { margin-bottom: 0; }
        .minutes-status {
            font-size: 0.65rem; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 999px;
        }
        .minutes-status.approved { background: #dcfce7; color: #166534; }
        .minutes-status.pending { background: #fef3c7; color: #92400e; }
        .minutes-status.rejected { background: #fee2e2; color: #991b1b; }
        .docs-heading {
            font-size: 0.78rem; font-weight: 700; color: var(--text-main);
            margin: 18px 0 10px; padding-top: 4px; border-top: 1px dashed var(--line);
        }
        .minutes-section {
            background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; margin-top: 12px; overflow: hidden;
        }
        .minutes-section-toggle {
            width: 100%; border: 0; background: transparent; padding: 14px 16px;
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            font-weight: 700; color: #1d4ed8; text-align: left;
        }
        .minutes-section-toggle:hover { background: rgba(59, 130, 246, 0.06); }
        .minutes-section-toggle .chevron { transition: transform 0.2s; color: #64748b; }
        .minutes-section-toggle[aria-expanded="true"] .chevron { transform: rotate(180deg); }
        .minutes-section-body { padding: 0 12px 12px; }
        .minutes-acc-item {
            border: 1px solid #dbeafe; border-radius: 10px; background: #fff; margin-bottom: 8px; overflow: hidden;
        }
        .minutes-acc-btn {
            width: 100%; border: 0; background: #fff; padding: 10px 12px;
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            text-align: left; font-size: 0.8rem; font-weight: 600; color: #0f172a;
        }
        .minutes-acc-btn:hover { background: #f8fafc; }
        .minutes-acc-body { padding: 0 12px 12px; border-top: 1px solid #eff6ff; }
        .minutes-pagination {
            display: flex; justify-content: flex-end; gap: 6px; flex-wrap: wrap; margin-top: 8px;
        }
        .minutes-pagination button {
            min-width: 34px; height: 34px; border: 1px solid #dbeafe; border-radius: 8px;
            background: #fff; color: #475569; font-weight: 600; font-size: 0.75rem;
        }
        .minutes-pagination button.active,
        .minutes-pagination button:hover:not(:disabled) {
            background: #2563eb; border-color: #2563eb; color: #fff;
        }
        .minutes-pagination button:disabled { opacity: 0.45; cursor: not-allowed; }
        .admin-rail { position: sticky; top: 16px; }
        @media (max-width: 991.98px) { .admin-rail { position: static; } }
    </style>
</head>
<body>

    <div class="container-compact">
        <div class="mb-3 d-flex justify-content-between align-items-center">
            <a href="dashboard.php" class="text-decoration-none text-muted fw-bold small">
                <i class="fas fa-chevron-left me-1"></i> Dashboard
            </a>
            <span class="status-badge status-<?php echo $event['status']; ?>">
                <?php 
                    $status_icons = ['pending' => 'hourglass-half', 'hold' => 'pause-circle', 'approved' => 'check-circle', 'rejected' => 'times-circle'];
                    echo '<i class="fas fa-'.$status_icons[$event['status']].' me-1"></i>'.strtoupper($event['status']); 
                ?>
            </span>
        </div>

        <div class="row g-3">
            <!-- Left Side: Event Details -->
            <div class="col-lg-7">
                <div class="compact-card">
                    <div class="compact-body">
                        <div class="hero-layout">
                            <div class="banner-container">
                                <?php
                                $banner_file = '';
                                if (is_array($banners) && !empty($banners[0])) {
                                    $banner_file = basename(str_replace('\\', '/', (string) $banners[0]));
                                }
                                ?>
                                <?php if ($banner_file !== ''): ?>
                                    <img src="uploads/events/<?php echo htmlspecialchars($banner_file, ENT_QUOTES, 'UTF-8'); ?>" class="banner-img" alt="Event poster" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="banner-fallback" style="display:none;"><i class="fas fa-image fa-2x"></i></div>
                                <?php else: ?>
                                    <div class="banner-fallback"><i class="fas fa-image fa-2x opacity-75"></i></div>
                                <?php endif; ?>
                            </div>
                            <div class="hero-copy">
                                <span class="category-pill"><?php echo htmlspecialchars((string) $event['category']); ?></span>
                                <h1 class="event-title"><?php echo htmlspecialchars((string) $event['title']); ?></h1>
                                <p class="hero-sub"><i class="fas fa-user-tie me-1"></i><?php echo htmlspecialchars((string) $event['organizer_name']); ?></p>
                            </div>
                        </div>

                        <div class="meta-grid">
                            <div class="meta-item">
                                <div class="label">Location</div>
                                <div class="value"><i class="fas fa-map-marker-alt text-danger me-1"></i><?php echo htmlspecialchars((string) $event['venue']); ?></div>
                            </div>
                            <div class="meta-item">
                                <div class="label">Timeline</div>
                                <div class="value"><i class="far fa-clock text-primary me-1"></i>
                                    <?php
                                    $ed_start = date('M d, Y | h:i A', strtotime($event['event_date']));
                                    $ed_end_raw = $event['event_end_date'] ?? null;
                                    if (!empty($ed_end_raw) && $ed_end_raw !== '0000-00-00 00:00:00') {
                                        echo htmlspecialchars($ed_start) . ' <span class="text-muted">→</span> ' . htmlspecialchars(date('M d, Y | h:i A', strtotime($ed_end_raw)));
                                    } else {
                                        echo htmlspecialchars($ed_start);
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="meta-item" style="grid-column: 1 / -1;">
                                <div class="label">Registration closes</div>
                                <div class="value">
                                <?php
                                $reg_dl = $event['registration_deadline'] ?? null;
                                if (!empty($reg_dl) && $reg_dl !== '0000-00-00 00:00:00') {
                                    $reg_closed = strtotime($reg_dl) <= time();
                                    ?>
                                    <span style="color:<?php echo $reg_closed ? '#b91c1c' : '#0f766e'; ?>;">
                                        <i class="fas fa-user-clock me-1"></i>
                                        <?php echo htmlspecialchars(date('M d, Y | h:i A', strtotime($reg_dl))); ?>
                                        <?php if ($reg_closed): ?><span class="badge bg-danger ms-1" style="font-size:0.65rem;">Closed</span><?php endif; ?>
                                    </span>
                                <?php } else { ?>
                                    <span class="text-muted"><i class="fas fa-user-clock me-1"></i> Not set</span>
                                    <?php if (has_priv('events')): ?>
                                    <a href="bulk_registration_deadline.php" class="small ms-1">Set deadline</a>
                                    <?php endif; ?>
                                <?php } ?>
                                </div>
                            </div>
                            <?php if($event['reschedule_date']): ?>
                            <div class="meta-item" style="grid-column: 1 / -1;">
                                <div class="alert alert-warning mb-0 py-2">
                                    <i class="fas fa-calendar-alt me-2"></i><strong>Rescheduled To:</strong> <?php echo date('M d, Y | h:i A', strtotime($event['reschedule_date'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if($event['hold_reason']): ?>
                            <div class="meta-item" style="grid-column: 1 / -1;">
                                <div class="label">Hold Reason</div>
                                <div class="alert alert-warning mb-0 py-2">
                                    <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($event['hold_reason']); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (($event['status'] ?? '') === 'rejected' && !empty($event['rejection_reason'])): ?>
                            <div class="meta-item" style="grid-column: 1 / -1;">
                                <div class="label">Rejection Reason</div>
                                <div class="alert alert-danger mb-0 py-2">
                                    <i class="fas fa-times-circle me-2"></i><?php echo htmlspecialchars($event['rejection_reason']); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="info-panel">
                            <span class="section-label">About Event</span>
                            <p class="mb-0 text-secondary"><?php echo nl2br(htmlspecialchars((string) $event['description'])); ?></p>
                        </div>

                        <?php if ($event_rules !== ''): ?>
                        <div class="info-panel rules">
                            <span class="section-label"><i class="fas fa-list-ul me-1"></i>Event Rules</span>
                            <p class="mb-0 text-secondary"><?php echo nl2br(htmlspecialchars($event_rules)); ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if ($has_post_event_docs): ?>
                        <div class="docs-heading"><i class="fas fa-folder-open me-1 text-muted"></i> Post-event documents</div>
                        <?php endif; ?>

                        <?php if (!empty($event['organizer_review'])): ?>
                        <div class="info-panel review">
                            <span class="section-label text-success"><i class="fas fa-star me-1"></i>Organizer Review</span>
                            <p class="mb-1 text-secondary"><?php echo nl2br(htmlspecialchars($event['organizer_review'])); ?></p>
                            <?php if (!empty($event['organizer_review_at'])): ?>
                            <small class="text-muted" style="font-size:0.65rem;">Submitted: <?php echo date('M d, Y h:i A', strtotime($event['organizer_review_at'])); ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($review_files)): ?>
                        <div class="info-panel attach">
                            <span class="section-label" style="color:#b45309;"><i class="fas fa-paperclip me-1"></i>Review Attachments (<?php echo count($review_files); ?>)</span>
                            <div class="d-flex flex-wrap gap-3">
                                <?php foreach ($review_files as $rf):
                                    $rfPath = ltrim(str_replace('\\', '/', (string) ($rf['file_path'] ?? '')), '/');
                                    $is_image = strpos($rf['file_type'] ?? '', 'image') !== false
                                        || preg_match('/\.(jpe?g|png|gif|webp)$/i', $rfPath);
                                    $is_pdf = strpos($rf['file_type'] ?? '', 'pdf') !== false
                                        || preg_match('/\.pdf$/i', $rfPath);
                                    $href = $rfPath !== '' ? $rfPath : (string) ($rf['file_url'] ?? '');
                                ?>
                                <div class="text-center" style="max-width: 180px;">
                                    <?php if ($is_image && $href !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($href); ?>" target="_blank" class="d-block">
                                            <img src="<?php echo htmlspecialchars($href); ?>"
                                                 alt="<?php echo htmlspecialchars($rf['original_name'] ?: 'Review image'); ?>"
                                                 style="width: 160px; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid #fde68a; background: #fff;">
                                        </a>
                                        <small class="d-block text-muted mt-1 text-truncate" title="<?php echo htmlspecialchars($rf['original_name'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($rf['original_name'] ?: 'Image'); ?>
                                        </small>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars($href); ?>" target="_blank" class="btn btn-sm <?php echo $is_pdf ? 'btn-outline-danger' : 'btn-outline-primary'; ?>">
                                            <i class="fas fa-<?php echo $is_pdf ? 'file-pdf' : 'file'; ?> me-1"></i>
                                            <?php echo htmlspecialchars($rf['original_name'] ?: 'File'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($meeting_minutes)):
                            $mm_total = count($meeting_minutes);
                            $mm_per_page = 10;
                            $mm_pages = max(1, (int) ceil($mm_total / $mm_per_page));
                        ?>
                        <div class="minutes-section" id="minutesSection">
                            <button type="button" class="minutes-section-toggle" data-bs-toggle="collapse" data-bs-target="#minutesCollapse" aria-expanded="false" aria-controls="minutesCollapse">
                                <span><i class="fas fa-file-alt me-2"></i>Minutes of Meeting <span class="badge bg-primary ms-1"><?php echo (int) $mm_total; ?></span></span>
                                <i class="fas fa-chevron-down chevron"></i>
                            </button>
                            <div class="collapse" id="minutesCollapse">
                                <div class="minutes-section-body">
                                    <div class="accordion" id="minutesAccordion">
                                        <?php foreach ($meeting_minutes as $mmIndex => $mm):
                                            $mmStatus = strtolower((string) ($mm['status'] ?? 'pending'));
                                            if (!in_array($mmStatus, ['approved', 'pending', 'rejected'], true)) {
                                                $mmStatus = 'pending';
                                            }
                                            $mmPath = (string) ($mm['file_path'] ?? '');
                                            $mmPathNorm = ltrim(str_replace('\\', '/', $mmPath), '/');
                                            // Prefer relative admin path (event_details.php lives in /admin)
                                            $mmHref = $mmPathNorm !== '' ? $mmPathNorm : (string) ($mm['file_url'] ?? '');
                                            $mmIsImg = $mmPathNorm !== '' && preg_match('/\.(jpe?g|png|gif|webp)$/i', $mmPathNorm);
                                            $mmIsPdf = $mmPathNorm !== '' && preg_match('/\.pdf$/i', $mmPathNorm);
                                            $contentPlain = trim((string) ($mm['content'] ?? ''));
                                            $isPlaceholder = ($contentPlain === '' || strcasecmp($contentPlain, '(See attached minutes file)') === 0);
                                            $mmWhen = $mm['reviewed_at'] ?: ($mm['created_at'] ?? null);
                                            $mmWhenLabel = !empty($mmWhen) ? date('M d, Y h:i A', strtotime($mmWhen)) : '';
                                            $previewSource = $isPlaceholder
                                                ? (!empty($mmHref) ? 'Attachment available' : 'No content')
                                                : $contentPlain;
                                            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                                                $preview = mb_strlen($previewSource) > 70 ? (mb_substr($previewSource, 0, 70) . '…') : $previewSource;
                                            } else {
                                                $preview = strlen($previewSource) > 70 ? (substr($previewSource, 0, 70) . '…') : $previewSource;
                                            }
                                            $itemId = 'mmItem' . (int) $mmIndex;
                                        ?>
                                        <div class="minutes-acc-item" data-mm-index="<?php echo (int) $mmIndex; ?>">
                                            <button type="button" class="minutes-acc-btn" data-bs-toggle="collapse" data-bs-target="#<?php echo $itemId; ?>" aria-expanded="false" aria-controls="<?php echo $itemId; ?>">
                                                <span class="min-w-0">
                                                    <span class="d-block text-truncate">#<?php echo (int) ($mmIndex + 1); ?> — <?php echo htmlspecialchars($preview); ?></span>
                                                    <small class="text-muted fw-normal" style="font-size:0.65rem;">
                                                        <?php
                                                        if (!empty($mm['submitted_by_name'])) {
                                                            echo htmlspecialchars($mm['submitted_by_name']);
                                                            if ($mmWhenLabel !== '') echo ' · ';
                                                        }
                                                        echo htmlspecialchars($mmWhenLabel);
                                                        ?>
                                                    </small>
                                                </span>
                                                <span class="minutes-status <?php echo htmlspecialchars($mmStatus); ?> flex-shrink-0"><?php echo htmlspecialchars($mmStatus); ?></span>
                                            </button>
                                            <div id="<?php echo $itemId; ?>" class="collapse" data-bs-parent="#minutesAccordion">
                                                <div class="minutes-acc-body pt-2">
                                                    <?php if (!$isPlaceholder): ?>
                                                    <p class="mb-2 text-secondary small"><?php echo nl2br(htmlspecialchars($contentPlain)); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($mmHref !== ''): ?>
                                                        <?php if ($mmIsImg): ?>
                                                            <a href="<?php echo htmlspecialchars($mmHref); ?>" target="_blank" rel="noopener" class="d-inline-block mb-2">
                                                                <img src="<?php echo htmlspecialchars($mmHref); ?>" alt="Minutes attachment"
                                                                     style="max-width:220px;max-height:160px;object-fit:cover;border-radius:10px;border:1px solid #bfdbfe;background:#fff;">
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?php echo htmlspecialchars($mmHref); ?>" target="_blank" rel="noopener" class="btn btn-sm <?php echo $mmIsPdf ? 'btn-outline-danger' : 'btn-outline-primary'; ?> mb-2">
                                                                <i class="fas fa-<?php echo $mmIsPdf ? 'file-pdf' : 'paperclip'; ?> me-1"></i>
                                                                View attachment
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php elseif ($isPlaceholder): ?>
                                                        <p class="mb-2 text-muted small">No text content provided.</p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($mm_pages > 1): ?>
                                    <nav class="minutes-pagination" id="minutesPagination" aria-label="Minutes pagination"
                                         data-total="<?php echo (int) $mm_total; ?>"
                                         data-per-page="<?php echo (int) $mm_per_page; ?>"
                                         data-pages="<?php echo (int) $mm_pages; ?>"></nav>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tabbed User Management Section -->
                <div class="compact-card">
                    <div class="p-3 border-bottom">
                        <h6 class="fw-bold m-0"><i class="fas fa-users text-primary me-2"></i> Event Participants & Staff</h6>
                    </div>
                    
                    <!-- Tabs -->
                    <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="volunteers-tab" data-bs-toggle="tab" data-bs-target="#volunteers" type="button" role="tab">
                                <i class="fas fa-hands-helping me-1"></i> Volunteers <span class="badge bg-light text-dark ms-1"><?php echo $active_volunteer_count; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="participants-tab" data-bs-toggle="tab" data-bs-target="#participants" type="button" role="tab">
                                <i class="fas fa-user-check me-1"></i> Participants <span class="badge bg-light text-dark ms-1"><?php echo $active_participant_count; ?></span>
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content">
                        <!-- Volunteers Tab -->
                        <div class="tab-pane fade show active" id="volunteers" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table vol-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Student Name</th>
                                            <th>Assigned Role</th>
                                            <th>Phone</th>
                                            <th>Attendance</th>
                                            <th>Status</th>
                                            <th>E-Certificate</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if($volunteers->num_rows > 0): ?>
                                            <?php 
                                            $volunteers->data_seek(0);
                                            while($vol = $volunteers->fetch_assoc()): 
                                                $cert_key = $vol['user_id'] . '_volunteer';
                                                $has_cert = isset($certificates[$cert_key]);
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($vol['full_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($vol['email']); ?></small>
                                                </td>
                                                <td><span class="text-primary fw-semibold"><?php echo htmlspecialchars($vol['role']); ?></span></td>
                                                <td><small class="text-muted"><?php echo htmlspecialchars($vol['phone']); ?></small></td>
                                                <td><small class="text-muted"><?php
                                                    $va = $vol['vol_attended'] ?? null;
                                                    echo $va === null || $va === '' ? '—' : ((int)$va === 1 ? 'Present' : 'Absent');
                                                ?></small></td>
                                                <td>
                                                    <span class="vol-badge bg-<?php echo $vol['vol_status'] == 'active' ? 'success' : 'danger'; ?> bg-opacity-10 text-<?php echo $vol['vol_status'] == 'active' ? 'success' : 'danger'; ?>">
                                                        <?php echo strtoupper($vol['vol_status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($is_past_event && has_priv('certificates')): ?>
                                                        <?php
                                                        $cert_gen_vol = 'certificate_generator.php?' . http_build_query([
                                                            'event_id' => $id,
                                                            'user_id' => (int) $vol['user_id'],
                                                            'type' => 'volunteer',
                                                            'name' => $vol['full_name'],
                                                            'title' => $event['title'],
                                                            'organised_by' => trim(($event['category'] ?? '') . ($vol['role'] ? ' — ' . $vol['role'] : '')),
                                                        ]);
                                                        ?>
                                                        <?php if ($has_cert): ?>
                                                            <a href="<?php echo htmlspecialchars($certificates[$cert_key]); ?>" target="_blank" class="btn btn-sm btn-outline-success"><i class="fas fa-certificate me-1"></i>View</a>
                                                        <?php endif; ?>
                                                        <a href="<?php echo htmlspecialchars($cert_gen_vol); ?>" class="btn btn-sm btn-outline-warning <?php echo $has_cert ? 'ms-1' : ''; ?>" title="Generate certificate"><i class="fas fa-magic me-1"></i>Generate</a>
                                                        <label class="btn btn-sm btn-outline-primary mb-0 ms-1">
                                                            <i class="fas fa-upload me-1"></i><?php echo $has_cert ? 'Replace' : 'Upload'; ?>
                                                            <input type="file" accept=".pdf,image/jpeg,image/png,image/gif,image/webp" hidden onchange="uploadCertificate(<?php echo (int)$vol['user_id']; ?>, 'volunteer', this)">
                                                        </label>
                                                        <small class="d-block text-muted" style="font-size: 0.65rem;">Generate, upload PDF/image (max 5MB)</small>
                                                    <?php elseif ($is_past_event): ?>
                                                        <span class="text-muted small">No certificate access</span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Available after event ends</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1 justify-content-end align-items-center flex-wrap">
                                                        <?php if ($can_edit_event_roster): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" style="font-size: 0.65rem;" title="Change role" onclick="editVolunteerRole(<?php echo (int) $vol['vol_link_id']; ?>, <?php echo htmlspecialchars(json_encode((string) $vol['role']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode((string) $vol['full_name']), ENT_QUOTES, 'UTF-8'); ?>)"><i class="fas fa-user-tag"></i></button>
                                                        <?php endif; ?>
                                                        <a href="manage_user.php?id=<?php echo $vol['vol_link_id']; ?>&action=<?php echo $vol['vol_status'] == 'active' ? 'block' : 'unblock'; ?>&type=volunteer" 
                                                           class="btn btn-sm <?php echo $vol['vol_status'] == 'active' ? 'btn-outline-danger' : 'btn-outline-success'; ?>"
                                                           style="font-size: 0.65rem;">
                                                            <i class="fas fa-<?php echo $vol['vol_status'] == 'active' ? 'ban' : 'check'; ?>"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="7" class="text-center py-4 text-muted">No volunteers have joined yet.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Participants Tab -->
                        <div class="tab-pane fade" id="participants" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table vol-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Student Name</th>
                                            <th>Contact</th>
                                            <th>Dept / Class</th>
                                            <th>Attendance</th>
                                            <th>Status</th>
                                            <th>E-Certificate</th>
                                            <th>Winner</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $winner_user_ids = array_column($event_winners, 'user_id');
                                        if($participants->num_rows > 0): ?>
                                            <?php while($part = $participants->fetch_assoc()): 
                                                $cert_key = $part['user_id'] . '_participant';
                                                $has_cert = isset($certificates[$cert_key]);
                                                $is_winner = in_array((int)$part['user_id'], $winner_user_ids);
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($part['full_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($part['email']); ?></small>
                                                </td>
                                                <td><small class="text-muted"><?php echo htmlspecialchars($part['phone']); ?></small></td>
                                                <td><small class="text-muted"><?php echo htmlspecialchars($part['department_class'] ?? ''); ?></small></td>
                                                <td><small class="text-muted"><?php
                                                    $pa = $part['participant_attended'] ?? null;
                                                    echo $pa === null || $pa === '' ? '—' : ((int)$pa === 1 ? 'Present' : 'Absent');
                                                ?></small></td>
                                                <td>
                                                    <span class="vol-badge bg-<?php echo $part['participant_status'] == 'active' ? 'success' : 'danger'; ?> bg-opacity-10 text-<?php echo $part['participant_status'] == 'active' ? 'success' : 'danger'; ?>">
                                                        <?php echo strtoupper($part['participant_status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($is_past_event && has_priv('certificates')): ?>
                                                        <?php
                                                        $part_winner_pos = 0;
                                                        foreach ($event_winners as $ew) {
                                                            if ((int) $ew['user_id'] === (int) $part['user_id']) {
                                                                $part_winner_pos = (int) $ew['position'];
                                                                break;
                                                            }
                                                        }
                                                        $cert_gen_part = 'certificate_generator.php?' . http_build_query([
                                                            'event_id' => $id,
                                                            'user_id' => (int) $part['user_id'],
                                                            'type' => 'participant',
                                                            'name' => $part['full_name'],
                                                            'title' => $event['title'],
                                                            'organised_by' => trim($part['department_class'] ?? $event['category'] ?? ''),
                                                            'position' => $part_winner_pos,
                                                        ]);
                                                        ?>
                                                        <?php if ($has_cert): ?>
                                                            <a href="<?php echo htmlspecialchars($certificates[$cert_key]); ?>" target="_blank" class="btn btn-sm btn-outline-success"><i class="fas fa-certificate me-1"></i>View</a>
                                                        <?php endif; ?>
                                                        <a href="<?php echo htmlspecialchars($cert_gen_part); ?>" class="btn btn-sm btn-outline-warning <?php echo $has_cert ? 'ms-1' : ''; ?>" title="Generate certificate"><i class="fas fa-magic me-1"></i>Generate</a>
                                                        <label class="btn btn-sm btn-outline-primary mb-0 ms-1">
                                                            <i class="fas fa-upload me-1"></i><?php echo $has_cert ? 'Replace' : 'Upload'; ?>
                                                            <input type="file" accept=".pdf,image/jpeg,image/png,image/gif,image/webp" hidden onchange="uploadCertificate(<?php echo (int)$part['user_id']; ?>, 'participant', this)">
                                                        </label>
                                                        <small class="d-block text-muted" style="font-size: 0.65rem;">Generate, upload PDF/image (max 5MB)</small>
                                                    <?php elseif ($is_past_event): ?>
                                                        <span class="text-muted small">No certificate access</span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Available after event ends</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($is_past_event): ?>
                                                        <?php if ($is_winner): 
                                                            $winner_pos = array_search((int)$part['user_id'], array_column($event_winners, 'user_id'));
                                                            $pos = $winner_pos !== false && isset($event_winners[$winner_pos]) ? (int)$event_winners[$winner_pos]['position'] : 0;
                                                            $posLabel = $pos === 1 ? '1st' : ($pos === 2 ? '2nd' : ($pos === 3 ? '3rd' : $pos . 'th'));
                                                        ?>
                                                            <span class="badge bg-warning text-dark me-1"><i class="fas fa-trophy"></i> <?php echo $posLabel; ?> winner</span>
                                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setWinner(<?php echo (int)$part['user_id']; ?>, '<?php echo htmlspecialchars(addslashes($part['full_name'])); ?>', true, this)">Remove</button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="setWinner(<?php echo (int)$part['user_id']; ?>, '<?php echo htmlspecialchars(addslashes($part['full_name'])); ?>', false, this)"><i class="fas fa-trophy me-1"></i>Set as winner</button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Available after event ends</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1 justify-content-end align-items-center flex-wrap">
                                                        <?php if ($can_edit_event_roster): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" style="font-size: 0.65rem;" title="Update dept / class" onclick="editParticipantDept(<?php echo (int) $part['participant_link_id']; ?>, <?php echo htmlspecialchars(json_encode((string) ($part['department_class'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode((string) $part['full_name']), ENT_QUOTES, 'UTF-8'); ?>)"><i class="fas fa-user-tag"></i></button>
                                                        <?php endif; ?>
                                                        <a href="manage_user.php?id=<?php echo $part['participant_link_id']; ?>&action=<?php echo $part['participant_status'] == 'active' ? 'block' : 'unblock'; ?>&type=participant" 
                                                           class="btn btn-sm <?php echo $part['participant_status'] == 'active' ? 'btn-outline-danger' : 'btn-outline-success'; ?>"
                                                           style="font-size: 0.65rem;">
                                                            <i class="fas fa-<?php echo $part['participant_status'] == 'active' ? 'ban' : 'check'; ?>"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="8" class="text-center py-4 text-muted">No participants have registered yet.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side: Administration -->
            <div class="col-lg-5">
                <div class="admin-rail">
                <div class="compact-card p-4">
                    <div class="side-block-title"><i class="fas fa-shield-halved text-warning me-1"></i> Event Management</div>
                    <div class="side-actions">
                    <a href="edit_event.php?id=<?php echo $id; ?>" class="btn-action-main" style="background: #2d3436; color: white; text-decoration: none; display: inline-block; text-align:center;">
                        <i class="fas fa-pen-to-square me-2"></i>EDIT EVENT DETAILS
                    </a>
                    <button type="button" class="btn-action-main w-100" style="background: #6c5ce7; color: white; border: none;" onclick="openAddEditorsModal()">
                        <i class="fas fa-user-plus me-2"></i>ADD FACULTY COORDINATORS
                    </button>
                    <?php if ($is_past_event && has_priv('certificates')): ?>
                    <a href="certificate_generator.php?<?php echo http_build_query(['event_id' => $id, 'bulk' => 1]); ?>"
                       class="btn-action-main w-100" style="background: #0f766e; color: white; text-decoration: none; display: inline-block; text-align:center;">
                        <i class="fas fa-certificates me-2"></i>GENERATE ALL CERTIFICATES
                    </a>
                    <?php endif; ?>
                    </div>
                    <div class="side-block-title mb-2">Faculty coordinators</div>
                    <div id="editorsList" class="mb-3 small">
                        <?php foreach ($event_editors as $ed): ?>
                        <div class="d-flex align-items-center justify-content-between py-1 px-2 rounded mb-1" style="background: var(--brand-soft);">
                            <span class="fw-semibold"><?php echo htmlspecialchars($ed['full_name']); ?></span>
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeEditor(<?php echo (int)$ed['user_id']; ?>, this)" title="Remove editor"><i class="fas fa-times"></i></button>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($event_editors)): ?>
                        <p class="text-muted mb-0 small">No additional editors.</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="side-block-title">Status actions</div>
                    <?php if ($is_pending && has_priv('approve_events')): ?>
                        <!-- Pending Event Management -->
                        <div class="checklist-item" onclick="document.getElementById('checkVenue').click()">
                            <input class="form-check-input verify-check" type="checkbox" id="checkVenue" onclick="event.stopPropagation()">
                            <label class="form-check-label flex-grow-1" for="checkVenue">Venue & location verified</label>
                        </div>
                        <div class="checklist-item" onclick="document.getElementById('checkMedia').click()">
                            <input class="form-check-input verify-check" type="checkbox" id="checkMedia" onclick="event.stopPropagation()">
                            <label class="form-check-label flex-grow-1" for="checkMedia">Banner/Media guidelines followed</label>
                        </div>
                        <div class="checklist-item" onclick="document.getElementById('checkConflict').click()">
                            <input class="form-check-input verify-check" type="checkbox" id="checkConflict" onclick="event.stopPropagation()">
                            <label class="form-check-label flex-grow-1" for="checkConflict">No date/time scheduling conflicts</label>
                        </div>
                        <div class="checklist-item" onclick="document.getElementById('checkUser').click()">
                            <input class="form-check-input verify-check" type="checkbox" id="checkUser" onclick="event.stopPropagation()">
                            <label class="form-check-label flex-grow-1" for="checkUser">Organizer authorized</label>
                        </div>

                        <button id="approveBtn" onclick="processAction('approve')" class="btn-action-main <?php echo $is_pending ? 'btn-locked' : 'btn-unlocked'; ?>" <?php echo $is_pending ? 'disabled' : ''; ?>>
                            <i class="fas fa-check-circle me-2"></i>APPROVE EVENT
                        </button>
                        
                        <button onclick="showHoldModal()" class="btn-action-main btn-hold">
                            <i class="fas fa-pause-circle me-2"></i>PUT ON HOLD
                        </button>
                        
                        <button onclick="showRejectModal()" class="btn btn-action-main btn-link text-danger text-decoration-none">
                            <i class="fas fa-times-circle me-2"></i>REJECT REQUEST
                        </button>

                    <?php elseif ($is_pending): ?>
                        <p class="text-muted small mb-0">You do not have permission to approve or reject events.</p>

                    <?php elseif($is_hold && has_priv('approve_events')): ?>
                        <!-- On Hold Event Management -->
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-pause-circle me-2"></i>This event is currently <strong>ON HOLD</strong>
                        </div>
                        
                        <button onclick="processAction('approve')" class="btn-action-main btn-unlocked" style="background: #2ecc71; color: white;">
                            <i class="fas fa-check-circle me-2"></i>MOVE TO APPROVED
                        </button>
                        
                        <button onclick="showRescheduleModal()" class="btn-action-main" style="background: #3498db; color: white;">
                            <i class="fas fa-calendar-check me-2"></i>RESCHEDULE EVENT
                        </button>
                        
                        <button onclick="showRejectModal()" class="btn btn-action-main btn-link text-danger text-decoration-none">
                            <i class="fas fa-times-circle me-2"></i>REJECT REQUEST
                        </button>

                    <?php elseif($is_hold): ?>
                        <p class="text-muted small mb-0">You do not have permission to change hold status.</p>

                    <?php elseif($event['status'] == 'approved' && has_priv('approve_events')): ?>
                        <!-- Approved Event Management -->
                        <div class="alert alert-success mb-3">
                            <i class="fas fa-check-circle me-2"></i>This event is <strong>LIVE & APPROVED</strong>
                        </div>
                        
                        <button onclick="showHoldModal()" class="btn-action-main btn-hold">
                            <i class="fas fa-pause-circle me-2"></i>PUT ON HOLD
                        </button>
                        
                        <button onclick="showRescheduleModal()" class="btn-action-main" style="background: #3498db; color: white;">
                            <i class="fas fa-calendar-check me-2"></i>RESCHEDULE EVENT
                        </button>

                    <?php elseif($event['status'] == 'approved'): ?>
                        <p class="text-muted small mb-0">You do not have permission to hold or reschedule from this role.</p>
                        
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-times-circle text-danger fa-3x mb-2 opacity-50"></i>
                            <h6 class="fw-bold">No Actions Available</h6>
                            <p class="text-muted small mb-0">This event is <strong><?php echo strtoupper($event['status']); ?></strong>.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pending edit from organizer/editor (requires admin approval) -->
                <?php if ($pending_edit): ?>
                <div class="compact-card p-4 border-warning border-2">
                    <h6 class="fw-bold text-warning mb-3"><i class="fas fa-clock me-2"></i> Pending edit (awaiting your approval)</h6>
                    <p class="small text-muted mb-2">Submitted by: <strong><?php echo htmlspecialchars($pending_edit['submitted_by_name']); ?></strong></p>
                    <div class="small mb-2"><strong>Title:</strong> <?php echo htmlspecialchars($pending_edit['title']); ?></div>
                    <?php if (!empty($pending_edit['description'])): ?><div class="small mb-2"><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($pending_edit['description'])); ?></div><?php endif; ?>
                    <div class="small mb-2"><strong>Venue:</strong> <?php echo htmlspecialchars($pending_edit['venue']); ?></div>
                    <?php if (!empty($pending_edit['event_date'])): ?>
                    <div class="small mb-2"><strong>Starts:</strong> <?php echo date('M d, Y h:i A', strtotime($pending_edit['event_date'])); ?>
                    <?php
                    $pend_end = $pending_edit['event_end_date'] ?? null;
                    if (!empty($pend_end) && $pend_end !== '0000-00-00 00:00:00') {
                        echo ' &nbsp;<strong>Ends:</strong> ' . date('M d, Y h:i A', strtotime($pend_end));
                    }
                    ?></div>
                    <?php endif; ?>
                    <?php
                    $pend_reg = $pending_edit['registration_deadline'] ?? null;
                    if (!empty($pend_reg) && $pend_reg !== '0000-00-00 00:00:00'):
                    ?>
                    <div class="small mb-2"><strong>Registration closes:</strong> <?php echo date('M d, Y h:i A', strtotime($pend_reg)); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($pending_edit['category'])): ?><div class="small mb-3"><strong>Category:</strong> <?php echo htmlspecialchars($pending_edit['category']); ?></div><?php endif; ?>
                    <?php if (!empty($pending_edit['rules'])): ?><div class="small mb-3"><strong>Rules:</strong> <?php echo nl2br(htmlspecialchars($pending_edit['rules'])); ?></div><?php endif; ?>
                    <?php if (!empty($pending_edit['minutes_content']) || !empty($pending_edit['minutes_file_path'])): ?>
                    <div class="small mb-3 p-2 rounded" style="background:#fff7ed;border:1px solid #fed7aa;">
                        <strong class="d-block mb-1"><i class="fas fa-file-alt me-1"></i>Minutes of meeting (pending)</strong>
                        <?php if (!empty($pending_edit['minutes_content'])): ?>
                            <div class="mb-2"><?php echo nl2br(htmlspecialchars($pending_edit['minutes_content'])); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($pending_edit['minutes_file_path'])):
                            $mmRel = ltrim(str_replace('\\', '/', (string) $pending_edit['minutes_file_path']), '/');
                            $mmUrl = $mmRel !== '' ? $mmRel : admin_public_file_url($pending_edit['minutes_file_path']);
                            $mmIsImg = preg_match('/\.(jpe?g|png|gif|webp)$/i', $mmRel);
                        ?>
                            <?php if ($mmIsImg): ?>
                                <a href="<?php echo htmlspecialchars($mmUrl); ?>" target="_blank">
                                    <img src="<?php echo htmlspecialchars($mmUrl); ?>" alt="Minutes attachment" style="max-width:220px;max-height:160px;object-fit:cover;border-radius:8px;border:1px solid #fed7aa;">
                                </a>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($mmUrl); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">View attachment</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($pending_edit['editors_json'])):
                        $pendEditors = json_decode((string) $pending_edit['editors_json'], true);
                        if (is_array($pendEditors)):
                    ?>
                    <div class="small mb-3"><strong>Committee (faculty coordinators) proposed IDs:</strong>
                        <?php echo htmlspecialchars(implode(', ', array_map('intval', $pendEditors))); ?>
                    </div>
                    <?php endif; endif; ?>
                    <?php if (!empty($pending_edit['meeting_update_message'])): ?>
                    <div class="small mb-3 p-2 rounded" style="background:#eff6ff;border:1px solid #bfdbfe;">
                        <strong class="d-block mb-1"><i class="fas fa-bullhorn me-1"></i>Meeting update (will send on approve)</strong>
                        <div><?php echo nl2br(htmlspecialchars($pending_edit['meeting_update_message'])); ?></div>
                        <?php if (!empty($pending_edit['meeting_update_recipient_type'])): ?>
                        <div class="text-muted mt-1">Recipients: <?php echo htmlspecialchars($pending_edit['meeting_update_recipient_type']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (has_priv('approve_events')): ?>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-success btn-sm" onclick="approveOrRejectEdit('approve')"><i class="fas fa-check me-1"></i>Approve edit</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="approveOrRejectEdit('reject')"><i class="fas fa-times me-1"></i>Reject</button>
                    </div>
                    <?php else: ?>
                    <p class="text-muted small mb-0">You do not have permission to approve pending edits.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Event Winners -->
                <?php if (!empty($event_winners)): ?>
                <div class="compact-card p-4">
                    <div class="side-block-title"><i class="fas fa-trophy text-warning me-1"></i> Event Winners</div>
                    <?php foreach ($event_winners as $w): 
                        $pos = (int)$w['position'];
                        $posLabel = $pos === 1 ? '1st' : ($pos === 2 ? '2nd' : ($pos === 3 ? '3rd' : $pos . 'th'));
                    ?>
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge bg-warning text-dark me-2"><?php echo $posLabel; ?></span>
                        <span class="fw-semibold"><?php echo htmlspecialchars($w['full_name']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Organizer Info -->
                <div class="compact-card p-4">
                    <div class="side-block-title">Organizer</div>
                    <div class="d-flex align-items-center mb-3">
                        <div class="rounded-circle bg-light d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                            <i class="fas fa-user-tie text-brand"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars((string) $event['organizer_name']); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars((string) $event['organizer_email']); ?></small>
                        </div>
                    </div>
                    <div class="small fw-semibold text-muted">
                        Account status: <span class="text-<?php echo $event['user_status'] == 'active' ? 'success' : 'danger'; ?>"><?php echo strtoupper($event['user_status']); ?></span>
                    </div>
                </div>

                <!-- Status Change Log -->
                <?php if($status_log->num_rows > 0): ?>
                <div class="compact-card p-4">
                    <div class="side-block-title">Change History</div>
                    <?php while($log = $status_log->fetch_assoc()): ?>
                    <div class="small mb-2 pb-2 border-bottom">
                        <div class="fw-bold"><?php echo ucfirst($log['admin_type']); ?>: <?php echo htmlspecialchars((string) $log['admin_username']); ?></div>
                        <div class="text-muted"><?php echo htmlspecialchars((string) $log['old_status']); ?> → <?php echo htmlspecialchars((string) $log['new_status']); ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;"><?php echo date('M d, Y h:i A', strtotime($log['changed_at'])); ?></div>
                        <?php if($log['remarks']): ?>
                        <div class="mt-1"><small class="badge bg-light text-dark text-wrap text-start"><?php echo htmlspecialchars((string) $log['remarks']); ?></small></div>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php endif; ?>
                </div><!-- /.admin-rail -->
            </div>
        </div>
    </div>

    <!-- Add Editors Modal -->
    <div class="modal fade" id="addEditorsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add faculty coordinators</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Search by name or email. Only <strong>faculty</strong> app accounts are listed. The event creator is not shown.</p>
                    <input type="text" id="editorSearch" class="form-control mb-3" placeholder="Search by name or email..." autocomplete="off">
                    <div id="editorSearchResults" class="list-group list-group-flush"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        (function initEventDetailsContext() {
            const fromQuery = parseInt(new URLSearchParams(window.location.search).get('id'), 10);
            const fromPhp = <?php echo json_encode((int) $id); ?>;
            window.__eventDetailsEventId = fromQuery > 0 ? fromQuery : (fromPhp > 0 ? fromPhp : 0);
        })();

        (function initMinutesPagination() {
            const nav = document.getElementById('minutesPagination');
            const items = Array.from(document.querySelectorAll('#minutesAccordion .minutes-acc-item'));
            if (!items.length) return;

            const perPage = nav ? (parseInt(nav.getAttribute('data-per-page'), 10) || 10) : 10;
            const total = items.length;
            const pages = Math.max(1, Math.ceil(total / perPage));
            let page = 1;

            function renderPage(targetPage) {
                page = Math.min(Math.max(1, targetPage), pages);
                const start = (page - 1) * perPage;
                const end = start + perPage;
                items.forEach((el, idx) => {
                    el.style.display = (idx >= start && idx < end) ? '' : 'none';
                });
                if (!nav) return;
                nav.innerHTML = '';
                if (pages <= 1) return;

                const addBtn = (label, target, disabled, active) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = label;
                    btn.disabled = !!disabled;
                    if (active) btn.className = 'active';
                    btn.addEventListener('click', () => renderPage(target));
                    nav.appendChild(btn);
                };

                addBtn('Prev', page - 1, page === 1, false);
                const visible = [...new Set([1, page - 1, page, page + 1, pages])]
                    .filter(n => n >= 1 && n <= pages)
                    .sort((a, b) => a - b);
                let prev = null;
                visible.forEach(n => {
                    if (prev !== null && n - prev > 1) {
                        const dots = document.createElement('span');
                        dots.textContent = '…';
                        dots.className = 'px-1 text-muted';
                        nav.appendChild(dots);
                    }
                    addBtn(String(n), n, false, n === page);
                    prev = n;
                });
                addBtn('Next', page + 1, page === pages, false);
            }

            renderPage(1);
        })();
        function getPageEventId() {
            const id = window.__eventDetailsEventId;
            return typeof id === 'number' && id > 0 ? id : 0;
        }

        /**
         * Apache/nginx often 301s script.php → script. For fetch(POST), following that redirect drops FormData
         * (method becomes GET), so APIs see empty POST and return "Invalid parameters". Match the page URL style.
         */
        const ADMIN_FETCH_EXT = /\.php$/i.test(window.location.pathname) ? '.php' : '';

        /** Same-origin session cookies + clear errors when the server returns HTML (PHP fatal) instead of JSON. */
        function adminFetchJson(input, init) {
            const opts = Object.assign({ credentials: 'same-origin' }, init || {});
            return fetch(input, opts).then(function (r) {
                return r.text().then(function (text) {
                    let data;
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (e) {
                        const snippet = (text || '').trim().slice(0, 400);
                        throw new Error(snippet || 'Server returned non-JSON (check Network response / PHP error log).');
                    }
                    if (!r.ok) {
                        throw new Error((data && data.message) ? data.message : ('Request failed (' + r.status + ')'));
                    }
                    return data;
                });
            });
        }

        (function showMsgAlert() {
            const params = new URLSearchParams(window.location.search);
            const msg = params.get('msg');
            const messages = {
                edit_approved: { title: 'Edit approved', text: 'Event edit has been approved and applied.', icon: 'success' },
                edit_rejected: { title: 'Edit rejected', text: 'Event edit has been rejected.', icon: 'info' },
                edit_failed: { title: 'Error', text: 'Could not apply edit.', icon: 'error' },
                no_pending: { title: 'No pending edit', text: 'There is no pending edit for this event.', icon: 'info' },
                updated: { title: 'Event updated', text: 'Event details have been saved.', icon: 'success' },
                blocked: { title: 'Blocked', text: 'User has been blocked.', icon: 'success' },
                unblocked: { title: 'Unblocked', text: 'User has been unblocked.', icon: 'success' }
            };
            if (msg && messages[msg]) {
                Swal.fire(messages[msg].title, messages[msg].text, messages[msg].icon).then(() => {
                    window.history.replaceState({}, '', window.location.pathname + '?id=<?php echo (int)$id; ?>');
                });
            }
        })();

        const checks = document.querySelectorAll('.verify-check');
        const approveBtn = document.getElementById('approveBtn');

        if(checks.length > 0) {
            checks.forEach(check => {
                check.addEventListener('change', () => {
                    const allChecked = Array.from(checks).every(c => c.checked);
                    if(allChecked) {
                        approveBtn.classList.remove('btn-locked');
                        approveBtn.classList.add('btn-unlocked');
                        approveBtn.disabled = false;
                    } else {
                        approveBtn.classList.add('btn-locked');
                        approveBtn.classList.remove('btn-unlocked');
                        approveBtn.disabled = true;
                    }
                });
            });
        }

        function processAction(action) {
            if (action === 'reject') {
                showRejectModal();
                return;
            }
            const labels = { approve: 'Approve this event' };
            const text = labels[action] || 'Continue?';
            Swal.fire({
                title: 'Confirm',
                text: text,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2ecc71',
                cancelButtonColor: '#95a5a6'
            }).then((res) => {
                if (res.isConfirmed) window.location.href = `approve.php?id=<?php echo $id; ?>&action=${action}`;
            });
        }

        function showRejectModal() {
            Swal.fire({
                title: 'Reject Event',
                html: `
                    <div class="text-start">
                        <p class="small text-muted mb-2">The organizer who published this event will see your reason in the app.</p>
                        <label class="form-label small fw-bold">Rejection reason <span class="text-danger">*</span></label>
                        <textarea id="rejectReason" class="form-control" rows="4" maxlength="2000" placeholder="Explain why this event is being rejected..."></textarea>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Reject Event',
                confirmButtonColor: '#e74c3c',
                cancelButtonColor: '#95a5a6',
                preConfirm: () => {
                    const reason = (document.getElementById('rejectReason').value || '').trim();
                    if (!reason) {
                        Swal.showValidationMessage('Please provide a rejection reason');
                        return false;
                    }
                    return { reason };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const data = result.value;
                    window.location.href = `approve.php?id=<?php echo $id; ?>&action=reject&reason=${encodeURIComponent(data.reason)}`;
                }
            });
        }

        function approveOrRejectEdit(action) {
            const isApprove = action === 'approve';
            Swal.fire({
                title: isApprove ? 'Approve edit?' : 'Reject edit?',
                text: isApprove ? 'Event details will be updated with the submitted changes.' : 'The pending edit will be discarded.',
                icon: isApprove ? 'question' : 'warning',
                showCancelButton: true,
                confirmButtonColor: isApprove ? '#2ecc71' : '#e74c3c',
                cancelButtonColor: '#95a5a6'
            }).then((res) => {
                if (res.isConfirmed) window.location.href = `approve_event_edit.php?id=<?php echo $id; ?>&action=${action}`;
            });
        }

        function showHoldModal() {
            Swal.fire({
                title: 'Put Event On Hold',
                html: `
                    <div class="text-start">
                        <label class="form-label small fw-bold">Hold Reason</label>
                        <textarea id="holdReason" class="form-control mb-3" rows="2" placeholder="Why is this event being put on hold?"></textarea>
                        
                        <label class="form-label small fw-bold">Reschedule Date (Optional)</label>
                        <input type="datetime-local" id="rescheduleDate" class="form-control">
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Put On Hold',
                confirmButtonColor: '#f39c12',
                preConfirm: () => {
                    const reason = document.getElementById('holdReason').value;
                    const rescheduleDate = document.getElementById('rescheduleDate').value;
                    
                    if (!reason) {
                        Swal.showValidationMessage('Please provide a reason');
                        return false;
                    }
                    
                    return { reason, rescheduleDate };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const data = result.value;
                    window.location.href = `approve.php?id=<?php echo $id; ?>&action=hold&reason=${encodeURIComponent(data.reason)}&reschedule_date=${encodeURIComponent(data.rescheduleDate)}`;
                }
            });
        }

        function showRescheduleModal() {
            Swal.fire({
                title: 'Reschedule Event',
                html: `
                    <div class="text-start">
                        <label class="form-label small fw-bold">New Event Date & Time</label>
                        <input type="datetime-local" id="newEventDate" class="form-control mb-3">
                        
                        <label class="form-label small fw-bold">Reason for Rescheduling</label>
                        <textarea id="rescheduleReason" class="form-control" rows="2" placeholder="Why is this event being rescheduled?"></textarea>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Reschedule Event',
                confirmButtonColor: '#3498db',
                preConfirm: () => {
                    const newDate = document.getElementById('newEventDate').value;
                    const reason = document.getElementById('rescheduleReason').value;
                    
                    if (!newDate) {
                        Swal.showValidationMessage('Please select a new date and time');
                        return false;
                    }
                    
                    if (!reason) {
                        Swal.showValidationMessage('Please provide a reason for rescheduling');
                        return false;
                    }
                    
                    return { newDate, reason };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const data = result.value;
                    window.location.href = `approve.php?id=<?php echo $id; ?>&action=reschedule&new_date=${encodeURIComponent(data.newDate)}&reason=${encodeURIComponent(data.reason)}`;
                }
            });
        }

        function openAddEditorsModal() {
            const modal = new bootstrap.Modal(document.getElementById('addEditorsModal'));
            modal.show();
            document.getElementById('editorSearch').value = '';
            fetchEditorUsers('');
        }

        let editorSearchTimer;
        document.getElementById('editorSearch').addEventListener('input', function() {
            clearTimeout(editorSearchTimer);
            editorSearchTimer = setTimeout(() => fetchEditorUsers(this.value), 300);
        });

        function fetchEditorUsers(search) {
            const url = `get_users_for_editors${ADMIN_FETCH_EXT}?event_id=${getPageEventId()}&search=${encodeURIComponent(search)}`;
            adminFetchJson(url).then(data => {
                const el = document.getElementById('editorSearchResults');
                el.innerHTML = '';
                if (data.status !== 'success') {
                    el.innerHTML = '<div class="text-danger small py-2">' + escapeHtml(data.message || 'Could not load users.') + '</div>';
                    return;
                }
                if (!data.data.length) {
                    el.innerHTML = '<div class="text-muted small py-2">No faculty accounts found. Coordinators must be app users with a faculty profile.</div>';
                    return;
                }
                data.data.forEach(u => {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                    item.innerHTML = `<div><strong>${escapeHtml(u.full_name)}</strong><br><small class="text-muted">${escapeHtml(u.email)}</small></div><span class="badge bg-primary">Add</span>`;
                    item.onclick = () => addEditor(u.id, u.full_name, item);
                    el.appendChild(item);
                });
            }).catch(err => {
                document.getElementById('editorSearchResults').innerHTML =
                    '<div class="text-danger small py-2">' + escapeHtml(err.message || 'Request failed') + '</div>';
            });
        }

        function escapeHtml(s) {
            const div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }

        function addEditor(userId, fullName, btnRow) {
            const fd = new FormData();
            fd.append('event_id', String(getPageEventId()));
            fd.append('user_id', userId);
            fd.append('action', 'add');
            adminFetchJson('event_editors_action' + ADMIN_FETCH_EXT, { method: 'POST', body: fd }).then(data => {
                if (data.status === 'success') {
                    const list = document.getElementById('editorsList');
                    const p = list.querySelector('p.text-muted');
                    if (p) p.remove();
                    const div = document.createElement('div');
                    div.className = 'd-flex align-items-center justify-content-between py-1 px-2 rounded mb-1';
                    div.style.background = 'var(--brand-soft)';
                    div.innerHTML = `<span class="fw-semibold">${escapeHtml(fullName)}</span><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeEditor(${userId}, this)" title="Remove editor"><i class="fas fa-times"></i></button>`;
                    list.insertBefore(div, list.firstChild);
                    const btn = btnRow.closest('button');
                    if (btn) { btn.disabled = true; const b = btn.querySelector('.badge'); if (b) b.textContent = 'Added'; }
                    Swal.fire('Editor added', escapeHtml(fullName) + ' has been added as an editor.', 'success');
                } else {
                    Swal.fire('Error', data.message || 'Failed', 'error');
                }
            }).catch(err => Swal.fire('Error', err.message || 'Failed', 'error'));
        }

        function removeEditor(userId, btn) {
            const fd = new FormData();
            fd.append('event_id', String(getPageEventId()));
            fd.append('user_id', userId);
            fd.append('action', 'remove');
            adminFetchJson('event_editors_action' + ADMIN_FETCH_EXT, { method: 'POST', body: fd }).then(data => {
                if (data.status === 'success') {
                    btn.closest('.d-flex').remove();
                    const list = document.getElementById('editorsList');
                    if (!list.querySelector('.d-flex')) {
                        const p = document.createElement('p');
                        p.className = 'text-muted mb-0 small';
                        p.textContent = 'No additional editors.';
                        list.appendChild(p);
                    }
                    Swal.fire('Editor removed', 'The user has been removed from editors.', 'success');
                } else {
                    Swal.fire('Error', data.message || 'Failed', 'error');
                }
            }).catch(err => Swal.fire('Error', err.message || 'Failed', 'error'));
        }

        function uploadCertificate(userId, type, input) {
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];
            if (file.size > 5 * 1024 * 1024) {
                Swal.fire('Error', 'File must be 5 MB or less.', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('event_id', String(getPageEventId()));
            fd.append('user_id', userId);
            fd.append('type', type);
            fd.append('certificate', file);
            adminFetchJson('upload_certificate' + ADMIN_FETCH_EXT, { method: 'POST', body: fd }).then(data => {
                if (data.status === 'success') {
                    Swal.fire('Done', 'Certificate uploaded.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message || 'Upload failed', 'error');
                }
            }).catch(err => Swal.fire('Error', err.message || 'Upload failed', 'error'));
        }

        function editVolunteerRole(volunteerId, currentRole, fullName) {
            const eid = getPageEventId();
            if (eid <= 0) {
                Swal.fire('Error', 'Invalid event.', 'error');
                return;
            }
            Swal.fire({
                title: 'Update volunteer role',
                text: fullName ? String(fullName) : '',
                input: 'text',
                inputLabel: 'Assigned role',
                inputValue: currentRole ? String(currentRole) : '',
                showCancelButton: true,
                confirmButtonText: 'Save',
                confirmButtonColor: '#6c5ce7',
                cancelButtonColor: '#95a5a6',
                preConfirm: function (v) {
                    const t = v !== undefined && v !== null ? String(v).trim() : '';
                    if (!t) {
                        Swal.showValidationMessage('Role is required');
                        return false;
                    }
                    return t;
                }
            }).then(function (res) {
                if (!res.isConfirmed) return;
                const fd = new FormData();
                fd.append('event_id', String(eid));
                fd.append('kind', 'volunteer_role');
                fd.append('volunteer_id', String(volunteerId));
                fd.append('role', res.value);
                adminFetchJson('event_staff_update' + ADMIN_FETCH_EXT, { method: 'POST', body: fd }).then(function (data) {
                    Swal.fire('Saved', data.message || 'Volunteer role updated.', 'success').then(function () { location.reload(); });
                }).catch(function (err) { Swal.fire('Error', err.message || 'Failed', 'error'); });
            });
        }

        function editParticipantDept(participantId, currentDept, fullName) {
            const eid = getPageEventId();
            if (eid <= 0) {
                Swal.fire('Error', 'Invalid event.', 'error');
                return;
            }
            Swal.fire({
                title: 'Update participant',
                text: (fullName ? String(fullName) + ' — ' : '') + 'Department / class (roster)',
                input: 'text',
                inputLabel: 'Dept / class',
                inputValue: currentDept !== undefined && currentDept !== null ? String(currentDept) : '',
                showCancelButton: true,
                confirmButtonText: 'Save',
                confirmButtonColor: '#6c5ce7',
                cancelButtonColor: '#95a5a6',
                preConfirm: function (v) {
                    return v !== undefined && v !== null ? String(v).trim() : '';
                }
            }).then(function (res) {
                if (!res.isConfirmed) return;
                const fd = new FormData();
                fd.append('event_id', String(eid));
                fd.append('kind', 'participant_dept');
                fd.append('participant_id', String(participantId));
                fd.append('department_class', res.value !== undefined && res.value !== null ? String(res.value) : '');
                adminFetchJson('event_staff_update' + ADMIN_FETCH_EXT, { method: 'POST', body: fd }).then(function (data) {
                    Swal.fire('Saved', data.message || 'Participant updated.', 'success').then(function () { location.reload(); });
                }).catch(function (err) { Swal.fire('Error', err.message || 'Failed', 'error'); });
            });
        }

        function setWinner(userId, fullName, isWinner, btn) {
            const eid = getPageEventId();
            if (eid <= 0) {
                Swal.fire('Error', 'Invalid event', 'error');
                return;
            }
            const uid = parseInt(userId, 10);
            if (!Number.isFinite(uid) || uid <= 0) {
                Swal.fire('Error', 'Invalid participant.', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('event_id', String(eid));
            fd.append('winner_uid', String(uid));
            fd.append('user_id', String(uid));
            const winnerOp = isWinner ? 'remove' : 'add';
            fd.append('winner_op', winnerOp);
            const qs =
                'event_id=' + encodeURIComponent(eid) +
                '&winner_op=' + encodeURIComponent(winnerOp) +
                '&winner_uid=' + encodeURIComponent(uid);
            adminFetchJson('event_winners_action' + ADMIN_FETCH_EXT + '?' + qs, { method: 'POST', body: fd }).then(data => {
                if (data.status === 'success') {
                    if (isWinner) {
                        Swal.fire('Winner removed', fullName + ' has been removed from winners.', 'success').then(() => location.reload());
                    } else {
                        const posLabel = data.position_label || (data.position === 1 ? '1st' : (data.position === 2 ? '2nd' : (data.position === 3 ? '3rd' : data.position + 'th')));
                        Swal.fire('Winner selected', fullName + ' has been set as ' + posLabel + ' winner.', 'success').then(() => location.reload());
                    }
                } else {
                    Swal.fire('Error', data.message || 'Failed', 'error');
                }
            }).catch(err => Swal.fire('Error', err.message || 'Failed', 'error'));
        }
    </script>
</body>
</html>