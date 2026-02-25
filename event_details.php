<?php
session_start();
include 'db.php';

// Check if user is logged in (admin or subadmin)
if ((!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) || !isset($_GET['id'])) {
    header("Location: dashboard.php");
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

$volunteers = $conn->query("
    SELECT v.id as vol_link_id, v.role, v.status as vol_status, u.full_name, u.email, u.phone, u.id as user_id
    FROM volunteers v
    JOIN users u ON v.user_id = u.id
    WHERE v.event_id = $id
");

// Fetch Participants
$participants = $conn->query("
    SELECT p.id as participant_link_id, p.status as participant_status, u.full_name, u.email, u.phone, u.id as user_id
    FROM participant p
    JOIN users u ON p.user_id = u.id
    WHERE p.event_id = $id
");

// Fetch status change log
$status_log = $conn->query("SELECT * FROM event_status_log WHERE event_id = $id ORDER BY changed_at DESC LIMIT 5");
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
                                <span class="fw-semibold"><i class="far fa-clock text-primary me-1"></i> <?php echo date('M d, Y | h:i A', strtotime($event['event_date'])); ?></span>
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
                                            <th>Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if($volunteers->num_rows > 0): ?>
                                            <?php while($vol = $volunteers->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?php echo $vol['full_name']; ?></div>
                                                    <small class="text-muted"><?php echo $vol['email']; ?></small>
                                                </td>
                                                <td><span class="text-primary fw-semibold"><?php echo $vol['role']; ?></span></td>
                                                <td>
                                                    <span class="vol-badge bg-<?php echo $vol['vol_status'] == 'active' ? 'success' : 'danger'; ?> bg-opacity-10 text-<?php echo $vol['vol_status'] == 'active' ? 'success' : 'danger'; ?>">
                                                        <?php echo strtoupper($vol['vol_status']); ?>
                                                    </span>
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
                                            <tr><td colspan="4" class="text-center py-4 text-muted">No volunteers have joined yet.</td></tr>
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
                                            <th>Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if($participants->num_rows > 0): ?>
                                            <?php while($part = $participants->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?php echo $part['full_name']; ?></div>
                                                    <small class="text-muted"><?php echo $part['email']; ?></small>
                                                </td>
                                                <td><small class="text-muted"><?php echo $part['phone']; ?></small></td>
                                                <td>
                                                    <span class="vol-badge bg-<?php echo $part['participant_status'] == 'active' ? 'success' : 'danger'; ?> bg-opacity-10 text-<?php echo $part['participant_status'] == 'active' ? 'success' : 'danger'; ?>">
                                                        <?php echo strtoupper($part['participant_status']); ?>
                                                    </span>
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
                                            <tr><td colspan="4" class="text-center py-4 text-muted">No participants have registered yet.</td></tr>
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
                    
                    <?php if ($is_pending): ?>
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

                    <?php elseif($is_hold): ?>
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

                    <?php elseif($event['status'] == 'approved'): ?>
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
                        
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-times-circle text-danger fa-3x mb-2 opacity-50"></i>
                            <h6 class="fw-bold">No Actions Available</h6>
                            <p class="text-muted small mb-0">This event is <strong><?php echo strtoupper($event['status']); ?></strong>.</p>
                        </div>
                    <?php endif; ?>
                </div>

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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
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
            window.location.href = `approve.php?id=<?php echo $id; ?>&action=${action}`;
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
    </script>
</body>
</html>