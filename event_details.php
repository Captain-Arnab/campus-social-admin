<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/event_date_range_schema.php';

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
    while ($r = $rf_res->fetch_assoc()) { $review_files[] = $r; }
}

// Pending edit from organizer/editor (when event has editors, edits require admin approval)
$pending_edit = null;
$pending_edit_res = @$conn->query("SELECT p.*, u.full_name as submitted_by_name FROM event_pending_edits p JOIN users u ON p.submitted_by_user_id = u.id WHERE p.event_id = $id");
if ($pending_edit_res && $pending_edit_res->num_rows > 0) {
    $pending_edit = $pending_edit_res->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Review: <?php echo $event['title']; ?> | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <style>
        :root { 
            --brand-color: #FF5F15; 
            --brand-soft: rgba(255, 95, 21, 0.06);
            --bg-body: #f8f9fd;
            --card-shadow: 0 4px 20px rgba(0,0,0,0.03);
            --success-btn: #2ecc71;
            --hold-color: #f39c12;
        }
        
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-body); color: #2d3436; font-size: 0.85rem; }
        .container-compact { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .compact-card { background: white; border-radius: 16px; box-shadow: var(--card-shadow); border: 1px solid #f0f0f0; margin-bottom: 20px; overflow: hidden; }
        .compact-body { padding: 25px; }

        .banner-container {
            width: 250px;
            height: 80%;
            background: #f1f1f1;
            border-radius: 12px;
            margin-bottom: 20px;
            overflow: hidden;
            position: relative;
        }
        .banner-img { width: 100%; height: 100%; object-fit: cover; }
        .banner-fallback {
            width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #FF5F15 0%, #FF8A50 100%); color: white;
        }

        .category-pill { background: var(--brand-soft); color: var(--brand-color); padding: 4px 10px; border-radius: 8px; font-weight: 700; font-size: 0.65rem; text-transform: uppercase; margin-bottom: 10px; display: inline-block; }
        .event-title { font-size: 1.3rem; font-weight: 800; color: #1a1a1a; margin-bottom: 15px; }

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

        .nav-tabs .nav-link {
            border: none;
            color: #95a5a6;
            font-weight: 600;
            font-size: 0.8rem;
            padding: 10px 20px;
            border-bottom: 3px solid transparent;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--brand-color);
            border-bottom-color: var(--brand-color);
            background: transparent;
        }

        .nav-tabs .nav-link:hover {
            color: var(--brand-color);
        }
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

        <div class="row g-4">
            <!-- Left Side: Event Details -->
            <div class="col-lg-7">
                <div class="compact-card">
                    <div class="compact-body">
                        <span class="category-pill"><?php echo $event['category']; ?></span>
                        <h1 class="event-title"><?php echo $event['title']; ?></h1>
                        
                        <div class="banner-container">
                            <?php if (!empty($banners)): ?>
                                <img src="./uploads/events/<?php echo $banners[0]; ?>" class="banner-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="banner-fallback" style="display:none;"><i class="fas fa-image fa-2x"></i></div>
                            <?php else: ?>
                                <div class="banner-fallback"><i class="fas fa-sparkles fa-2x opacity-50"></i></div>
                            <?php endif; ?>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <small class="text-muted fw-bold text-uppercase d-block mb-1" style="font-size: 0.6rem;">Location</small>
                                <span class="fw-semibold"><i class="fas fa-map-marker-alt text-danger me-1"></i> <?php echo $event['venue']; ?></span>
                            </div>
                            <div class="col-6">
                                <small class="text-muted fw-bold text-uppercase d-block mb-1" style="font-size: 0.6rem;">Timeline</small>
                                <span class="fw-semibold"><i class="far fa-clock text-primary me-1"></i>
                                    <?php
                                    $ed_start = date('M d, Y | h:i A', strtotime($event['event_date']));
                                    $ed_end_raw = $event['event_end_date'] ?? null;
                                    if (!empty($ed_end_raw) && $ed_end_raw !== '0000-00-00 00:00:00') {
                                        echo htmlspecialchars($ed_start) . ' <span class="text-muted">→</span> ' . htmlspecialchars(date('M d, Y | h:i A', strtotime($ed_end_raw)));
                                    } else {
                                        echo htmlspecialchars($ed_start);
                                    }
                                    ?>
                                </span>
                            </div>
                            <?php if($event['reschedule_date']): ?>
                            <div class="col-12">
                                <div class="alert alert-warning mb-0">
                                    <i class="fas fa-calendar-alt me-2"></i><strong>Rescheduled To:</strong> <?php echo date('M d, Y | h:i A', strtotime($event['reschedule_date'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if($event['hold_reason']): ?>
                            <div class="col-12">
                                <small class="text-muted fw-bold text-uppercase d-block mb-1" style="font-size: 0.6rem;">Hold Reason</small>
                                <div class="alert alert-warning mb-0">
                                    <i class="fas fa-info-circle me-2"></i><?php echo $event['hold_reason']; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div style="background: #fdfdfd; padding: 15px; border-radius: 12px; border: 1px solid #f5f5f5;">
                            <small class="text-muted fw-bold text-uppercase d-block mb-1" style="font-size: 0.6rem;">About Event</small>
                            <p class="mb-0 text-secondary"><?php echo nl2br($event['description']); ?></p>
                        </div>

                        <?php if (!empty($event['organizer_review'])): ?>
                        <div style="background: #f0fdf4; padding: 15px; border-radius: 12px; border: 1px solid #bbf7d0; margin-top: 15px;">
                            <small class="text-success fw-bold text-uppercase d-block mb-1" style="font-size: 0.6rem;">
                                <i class="fas fa-star me-1"></i>Organizer Review
                            </small>
                            <p class="mb-1 text-secondary"><?php echo nl2br(htmlspecialchars($event['organizer_review'])); ?></p>
                            <?php if ($event['organizer_review_at']): ?>
                            <small class="text-muted" style="font-size:0.65rem;">Submitted: <?php echo date('M d, Y h:i A', strtotime($event['organizer_review_at'])); ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($review_files)): ?>
                        <div style="background: #fefce8; padding: 15px; border-radius: 12px; border: 1px solid #fde68a; margin-top: 15px;">
                            <small class="text-warning fw-bold text-uppercase d-block mb-2" style="font-size: 0.6rem;">
                                <i class="fas fa-paperclip me-1"></i>Review Attachments (<?php echo count($review_files); ?>)
                            </small>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($review_files as $rf): 
                                    $is_image = strpos($rf['file_type'] ?? '', 'image') !== false;
                                    $is_pdf = strpos($rf['file_type'] ?? '', 'pdf') !== false;
                                ?>
                                <a href="<?php echo htmlspecialchars($rf['file_path']); ?>" target="_blank" class="btn btn-sm <?php echo $is_pdf ? 'btn-outline-danger' : 'btn-outline-primary'; ?>" title="<?php echo htmlspecialchars($rf['original_name'] ?? 'File'); ?>">
                                    <i class="fas fa-<?php echo $is_pdf ? 'file-pdf' : ($is_image ? 'image' : 'file'); ?> me-1"></i>
                                    <?php echo htmlspecialchars($rf['original_name'] ?: 'File'); ?>
                                </a>
                                <?php endforeach; ?>
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
                                <i class="fas fa-hands-helping me-1"></i> Volunteers <span class="badge bg-light text-dark ms-1"><?php echo $volunteers->num_rows; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="participants-tab" data-bs-toggle="tab" data-bs-target="#participants" type="button" role="tab">
                                <i class="fas fa-user-check me-1"></i> Participants <span class="badge bg-light text-dark ms-1"><?php echo $participants->num_rows; ?></span>
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
                                                        <?php if ($has_cert): ?>
                                                            <a href="<?php echo htmlspecialchars($certificates[$cert_key]); ?>" target="_blank" class="btn btn-sm btn-outline-success"><i class="fas fa-certificate me-1"></i>View</a>
                                                        <?php endif; ?>
                                                        <label class="btn btn-sm btn-outline-primary mb-0 <?php echo $has_cert ? 'ms-1' : ''; ?>">
                                                            <i class="fas fa-upload me-1"></i><?php echo $has_cert ? 'Replace' : 'Upload'; ?>
                                                            <input type="file" accept=".pdf,image/jpeg,image/png,image/gif,image/webp" hidden onchange="uploadCertificate(<?php echo (int)$vol['user_id']; ?>, 'volunteer', this)">
                                                        </label>
                                                        <small class="d-block text-muted" style="font-size: 0.65rem;">PDF/Image, max 5MB</small>
                                                    <?php elseif ($is_past_event): ?>
                                                        <span class="text-muted small">No certificate access</span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Available after event ends</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <a href="manage_user.php?id=<?php echo $vol['vol_link_id']; ?>&action=<?php echo $vol['vol_status'] == 'active' ? 'block' : 'unblock'; ?>&type=volunteer" 
                                                       class="btn btn-sm <?php echo $vol['vol_status'] == 'active' ? 'btn-outline-danger' : 'btn-outline-success'; ?>"
                                                       style="font-size: 0.65rem;">
                                                        <i class="fas fa-<?php echo $vol['vol_status'] == 'active' ? 'ban' : 'check'; ?>"></i>
                                                    </a>
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
                                                        <?php if ($has_cert): ?>
                                                            <a href="<?php echo htmlspecialchars($certificates[$cert_key]); ?>" target="_blank" class="btn btn-sm btn-outline-success"><i class="fas fa-certificate me-1"></i>View</a>
                                                        <?php endif; ?>
                                                        <label class="btn btn-sm btn-outline-primary mb-0 <?php echo $has_cert ? 'ms-1' : ''; ?>">
                                                            <i class="fas fa-upload me-1"></i><?php echo $has_cert ? 'Replace' : 'Upload'; ?>
                                                            <input type="file" accept=".pdf,image/jpeg,image/png,image/gif,image/webp" hidden onchange="uploadCertificate(<?php echo (int)$part['user_id']; ?>, 'participant', this)">
                                                        </label>
                                                        <small class="d-block text-muted" style="font-size: 0.65rem;">PDF/Image, max 5MB</small>
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
                                                    <a href="manage_user.php?id=<?php echo $part['participant_link_id']; ?>&action=<?php echo $part['participant_status'] == 'active' ? 'block' : 'unblock'; ?>&type=participant" 
                                                       class="btn btn-sm <?php echo $part['participant_status'] == 'active' ? 'btn-outline-danger' : 'btn-outline-success'; ?>"
                                                       style="font-size: 0.65rem;">
                                                        <i class="fas fa-<?php echo $part['participant_status'] == 'active' ? 'ban' : 'check'; ?>"></i>
                                                    </a>
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
                <div class="compact-card p-4">
                    <h6 class="fw-bold mb-3"><i class="fas fa-shield-check text-warning me-2"></i> Event Management</h6>
                    <a href="edit_event.php?id=<?php echo $id; ?>" class="btn-action-main" style="background: #2d3436; color: white; text-decoration: none; display: inline-block; text-align:center;">
                        <i class="fas fa-pen-to-square me-2"></i>EDIT EVENT DETAILS
                    </a>
                    <button type="button" class="btn-action-main w-100 mt-2" style="background: #6c5ce7; color: white; border: none;" onclick="openAddEditorsModal()">
                        <i class="fas fa-user-plus me-2"></i>ADD EDITORS
                    </button>
                    <div id="editorsList" class="mt-2 small">
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
                        
                        <button onclick="processAction('reject')" class="btn btn-action-main btn-link text-danger text-decoration-none">
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
                        
                        <button onclick="processAction('reject')" class="btn btn-action-main btn-link text-danger text-decoration-none">
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
                    <?php if (!empty($pending_edit['category'])): ?><div class="small mb-3"><strong>Category:</strong> <?php echo htmlspecialchars($pending_edit['category']); ?></div><?php endif; ?>
                    <?php if (!empty($pending_edit['rules'])): ?><div class="small mb-3"><strong>Rules:</strong> <?php echo nl2br(htmlspecialchars($pending_edit['rules'])); ?></div><?php endif; ?>
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
                    <h6 class="fw-bold small text-muted text-uppercase mb-3"><i class="fas fa-trophy text-warning me-1"></i> Event Winners</h6>
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
                    <h6 class="fw-bold small text-muted text-uppercase mb-3">Organizer Insight</h6>
                    <div class="d-flex align-items-center mb-3">
                        <div class="rounded-circle bg-light d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                            <i class="fas fa-user-tie text-brand"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark"><?php echo $event['organizer_name']; ?></div>
                            <small class="text-muted"><?php echo $event['organizer_email']; ?></small>
                        </div>
                    </div>
                    <div class="small fw-semibold text-muted">
                        User Global Status: <span class="text-<?php echo $event['user_status'] == 'active' ? 'success' : 'danger'; ?>"><?php echo strtoupper($event['user_status']); ?></span>
                    </div>
                </div>

                <!-- Status Change Log -->
                <?php if($status_log->num_rows > 0): ?>
                <div class="compact-card p-4">
                    <h6 class="fw-bold small text-muted text-uppercase mb-3">Change History</h6>
                    <?php while($log = $status_log->fetch_assoc()): ?>
                    <div class="small mb-2 pb-2 border-bottom">
                        <div class="fw-bold"><?php echo ucfirst($log['admin_type']); ?>: <?php echo $log['admin_username']; ?></div>
                        <div class="text-muted"><?php echo $log['old_status']; ?> → <?php echo $log['new_status']; ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;"><?php echo date('M d, Y h:i A', strtotime($log['changed_at'])); ?></div>
                        <?php if($log['remarks']): ?>
                        <div class="mt-1"><small class="badge bg-light text-dark"><?php echo $log['remarks']; ?></small></div>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Editors Modal -->
    <div class="modal fade" id="addEditorsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Editors</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Search by name or email. Event creator is not listed.</p>
                    <input type="text" id="editorSearch" class="form-control mb-3" placeholder="Search by name or email..." autocomplete="off">
                    <div id="editorSearchResults" class="list-group list-group-flush"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
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
            const labels = { approve: 'Approve this event', reject: 'Reject this event' };
            const text = labels[action] || 'Continue?';
            Swal.fire({
                title: 'Confirm',
                text: text,
                icon: action === 'reject' ? 'warning' : 'question',
                showCancelButton: true,
                confirmButtonColor: action === 'reject' ? '#e74c3c' : '#2ecc71',
                cancelButtonColor: '#95a5a6'
            }).then((res) => {
                if (res.isConfirmed) window.location.href = `approve.php?id=<?php echo $id; ?>&action=${action}`;
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

        const eventId = <?php echo (int)$id; ?>;

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
            const url = `get_users_for_editors.php?event_id=${eventId}&search=${encodeURIComponent(search)}`;
            fetch(url).then(r => r.json()).then(data => {
                const el = document.getElementById('editorSearchResults');
                el.innerHTML = '';
                if (data.status !== 'success' || !data.data.length) {
                    el.innerHTML = '<div class="text-muted small py-2">No users found.</div>';
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
            });
        }

        function escapeHtml(s) {
            const div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }

        function addEditor(userId, fullName, btnRow) {
            const fd = new FormData();
            fd.append('event_id', eventId);
            fd.append('user_id', userId);
            fd.append('action', 'add');
            fetch('event_editors_action.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
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
            });
        }

        function removeEditor(userId, btn) {
            const fd = new FormData();
            fd.append('event_id', eventId);
            fd.append('user_id', userId);
            fd.append('action', 'remove');
            fetch('event_editors_action.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
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
            });
        }

        function uploadCertificate(userId, type, input) {
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];
            if (file.size > 5 * 1024 * 1024) {
                Swal.fire('Error', 'File must be 5 MB or less.', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('event_id', eventId);
            fd.append('user_id', userId);
            fd.append('type', type);
            fd.append('certificate', file);
            fetch('upload_certificate.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
                if (data.status === 'success') {
                    Swal.fire('Done', 'Certificate uploaded.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message || 'Upload failed', 'error');
                }
            });
        }

        function setWinner(userId, fullName, isWinner, btn) {
            const fd = new FormData();
            fd.append('event_id', eventId);
            fd.append('user_id', userId);
            fd.append('action', isWinner ? 'remove' : 'add');
            fetch('event_winners_action.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
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
            });
        }
    </script>
</body>
</html>