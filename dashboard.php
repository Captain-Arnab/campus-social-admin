<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/event_date_range_schema.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}
require_priv('dashboard');

$user_type = $_SESSION['user_type'] ?? 'admin';
$username = isset($_SESSION['admin']) ? $_SESSION['admin'] : $_SESSION['subadmin'];

// Stats
$total_events = $conn->query("SELECT * FROM events")->num_rows;
$pending_events = $conn->query("SELECT * FROM events WHERE status='pending'")->num_rows;
$hold_events = $conn->query("SELECT * FROM events WHERE status='hold'")->num_rows;
// Approved events not yet fully finished (includes multi-day ongoing)
$active_events_sql = "SELECT COUNT(*) AS c FROM events WHERE status = 'approved'
    AND (" . events_sql_not_past_naked($conn) . ')';
$active_events_row = $conn->query($active_events_sql);
$live_events = $active_events_row ? (int) $active_events_row->fetch_assoc()['c'] : 0;

// Fetch Pending Events
$pending_requests = $conn->query("
    SELECT e.*, u.full_name as organizer_name 
    FROM events e 
    JOIN users u ON e.organizer_id = u.id 
    WHERE e.status='pending' 
    ORDER BY e.created_at DESC
");

// Fetch Hold Events
$hold_requests = $conn->query("
    SELECT e.*, u.full_name as organizer_name 
    FROM events e 
    JOIN users u ON e.organizer_id = u.id 
    WHERE e.status='hold' 
    ORDER BY e.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Dashboard | College Connect</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        :root { 
            --brand-color: #FF5F15; 
            --brand-soft: rgba(255, 95, 21, 0.08);
            --hold-color: #f39c12;
            --bg-body: #f8f9fd;
            --card-shadow: 0 10px 30px rgba(0,0,0,0.04);
        }
        
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-body); color: #2d3436; }
        .main-content { margin-left: 280px; padding: 40px; transition: 0.3s; box-sizing: border-box; width: 100%; max-width: 100%; }
        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 12px; } }

        .stat-card { border: none; border-radius: 24px; color: white; padding: 22px 24px; box-shadow: var(--card-shadow); position: relative; overflow: hidden; transition: 0.3s; min-height: 120px; }
        @media (min-width: 992px) {
            .stat-card { padding: 30px; min-height: 0; }
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card .icon-overlay { position: absolute; right: -15px; bottom: -15px; font-size: 4.5rem; opacity: 0.15; transform: rotate(-10deg); }
        @media (min-width: 576px) {
            .stat-card .icon-overlay { font-size: 5.5rem; }
        }
        @media (min-width: 992px) {
            .stat-card .icon-overlay { font-size: 6rem; }
        }
        .stat-card .stat-value { font-size: clamp(1.75rem, 5vw, 3rem); line-height: 1.1; font-weight: 700; }
        .stat-card small { font-size: clamp(0.65rem, 2vw, 0.75rem); }
        
        .bg-orange-grad { background: linear-gradient(135deg, #FF5F15 0%, #FF9068 100%); }
        .bg-hold-grad { background: linear-gradient(135deg, #f39c12 0%, #f1c40f 100%); }
        .bg-dark-grad { background: linear-gradient(135deg, #2d3436 0%, #636e72 100%); }
        .bg-info-grad { background: linear-gradient(135deg, #0984e3 0%, #74b9ff 100%); }

        .pending-card { background: white; border-radius: 20px; padding: 25px; box-shadow: var(--card-shadow); border: none; margin-bottom: 15px; transition: 0.3s; }
        @media (max-width: 767.98px) {
            .main-content { box-sizing: border-box; max-width: 100%; }
            .pending-card { padding: 16px 14px; border-radius: 16px; }
        }
        .pending-card:hover { transform: scale(1.01); box-shadow: 0 15px 40px rgba(0,0,0,0.08); }
        
        .category-pill { background: var(--brand-soft); color: var(--brand-color); padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        .hold-pill { background: rgba(243, 156, 18, 0.1); color: #f39c12; }
        
        .btn-review { background-color: var(--brand-color); color: white; border: none; padding: 10px 20px; border-radius: 12px; font-weight: 600; text-decoration: none; transition: 0.3s; }
        .btn-review:hover { background-color: #e04e0b; color: white; box-shadow: 0 5px 15px rgba(255, 95, 21, 0.3); }
        
        .section-divider { margin: 50px 0 30px; padding-top: 30px; border-top: 2px dashed #e0e0e0; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="mb-5 d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h4 class="fw-bold m-0">Platform Overview</h4>
                <p class="text-muted small mb-0">Real-time engagement and operational statistics</p>
            </div>
            <a href="https://micampus.co.in/" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary rounded-pill fw-semibold px-4 py-2 d-inline-flex align-items-center gap-2 flex-shrink-0" style="border-width: 2px;">
                <i class="fas fa-globe"></i> MiCampus website
                <i class="fas fa-external-link-alt small opacity-75"></i>
            </a>
        </div>

        <!-- xl: four across; lg–xl narrow main column: 2×2 so cards are not clipped (e.g. 1024px viewport with sidebar) -->
        <div class="row g-3 g-xl-4 mb-5">
            <div class="col-12 col-sm-6 col-xl-3 min-w-0">
                <div class="stat-card bg-orange-grad">
                    <small class="text-uppercase fw-bold opacity-75">Needs Review</small>
                    <div class="stat-value mt-2 mb-0"><?php echo $pending_events; ?></div>
                    <i class="fas fa-hourglass-half icon-overlay"></i>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3 min-w-0">
                <div class="stat-card bg-hold-grad">
                    <small class="text-uppercase fw-bold opacity-75">On Hold</small>
                    <div class="stat-value mt-2 mb-0"><?php echo $hold_events; ?></div>
                    <i class="fas fa-pause-circle icon-overlay"></i>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3 min-w-0">
                <div class="stat-card bg-dark-grad">
                    <small class="text-uppercase fw-bold opacity-50">Active Events</small>
                    <div class="stat-value mt-2 mb-0"><?php echo $live_events; ?></div>
                    <i class="fas fa-broadcast-tower icon-overlay"></i>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3 min-w-0">
                <div class="stat-card bg-info-grad">
                    <small class="text-uppercase fw-bold opacity-50">App Users</small>
                    <div class="stat-value mt-2 mb-0"><?php echo $conn->query("SELECT * FROM users")->num_rows; ?></div>
                    <i class="fas fa-user-friends icon-overlay"></i>
                </div>
            </div>
        </div>

        <!-- Pending Approvals -->
        <h5 class="fw-bold mb-4">Pending Approvals</h5>
        <?php if($pending_requests->num_rows > 0): ?>
            <div class="row">
                <?php while($row = $pending_requests->fetch_assoc()): ?>
                <div class="col-12">
                    <div class="pending-card d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center flex-shrink-0" style="width: 50px; height: 50px;">
                                <i class="fas fa-calendar-plus text-warning"></i>
                            </div>
                            <div class="min-w-0">
                                <span class="category-pill mb-1 d-inline-block"><?php echo $row['category']; ?></span>
                                <h6 class="fw-bold m-0"><?php echo $row['title']; ?></h6>
                                <small class="text-muted"><i class="fas fa-user me-1"></i><?php echo $row['organizer_name']; ?> • <i class="far fa-clock me-1"></i><?php echo date('M d, Y', strtotime($row['event_date'])); ?>
                                <?php
                                $pendEnd = $row['event_end_date'] ?? null;
                                if (!empty($pendEnd) && $pendEnd !== '0000-00-00 00:00:00') {
                                    echo ' → ' . date('M d, Y', strtotime($pendEnd));
                                }
                                ?></small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 flex-shrink-0">
                            <?php if (has_priv('events')): ?>
                            <a href="event_details.php?id=<?php echo $row['id']; ?>" class="btn-review">
                                <i class="fas fa-search-plus me-2"></i> Review & Verify
                            </a>
                            <a href="edit_event.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-dark" style="border-radius: 12px; font-weight: 700; padding: 10px 16px;">
                                <i class="fas fa-pen-to-square me-2"></i> Edit
                            </a>
                            <?php elseif (has_priv('approve_events')): ?>
                            <span class="text-muted small">Event details require the &quot;events&quot; privilege.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="text-center p-5 card border-0 shadow-sm rounded-4">
                <i class="fas fa-check-circle fa-3x text-success mb-3 opacity-25"></i>
                <p class="text-muted">Great! All pending events have been processed.</p>
            </div>
        <?php endif; ?>

        <!-- Hold Events Section -->
        <div class="section-divider">
            <h5 class="fw-bold mb-4"><i class="fas fa-pause-circle text-warning me-2"></i>Events On Hold</h5>
        </div>
        
        <?php if($hold_requests->num_rows > 0): ?>
            <div class="row">
                <?php while($row = $hold_requests->fetch_assoc()): ?>
                <div class="col-12">
                    <div class="pending-card d-flex justify-content-between align-items-center flex-wrap gap-3" style="border-left: 4px solid #f39c12;">
                        <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 50px; height: 50px; background: rgba(243, 156, 18, 0.1);">
                                <i class="fas fa-clock text-warning"></i>
                            </div>
                            <div class="min-w-0">
                                <span class="category-pill hold-pill mb-1 d-inline-block"><?php echo $row['category']; ?></span>
                                <h6 class="fw-bold m-0"><?php echo $row['title']; ?></h6>
                                <small class="text-muted">
                                    <i class="fas fa-user me-1"></i><?php echo $row['organizer_name']; ?> • 
                                    <?php if($row['reschedule_date']): ?>
                                        <i class="fas fa-calendar-alt me-1"></i>Reschedule: <?php echo date('M d, Y', strtotime($row['reschedule_date'])); ?>
                                    <?php else: ?>
                                        <i class="fas fa-question-circle me-1"></i>Date TBD
                                    <?php endif; ?>
                                </small>
                                <?php if($row['hold_reason']): ?>
                                    <div class="mt-1"><small class="badge bg-light text-dark border"><i class="fas fa-info-circle me-1"></i><?php echo $row['hold_reason']; ?></small></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 flex-shrink-0">
                            <?php if (has_priv('events')): ?>
                            <a href="event_details.php?id=<?php echo $row['id']; ?>" class="btn-review" style="background: #f39c12;">
                                <i class="fas fa-edit me-2"></i> Manage Hold
                            </a>
                            <a href="edit_event.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-dark" style="border-radius: 12px; font-weight: 700; padding: 10px 16px;">
                                <i class="fas fa-pen-to-square me-2"></i> Edit
                            </a>
                            <?php elseif (has_priv('approve_events')): ?>
                            <span class="text-muted small">Event details require the &quot;events&quot; privilege.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="text-center p-5 card border-0 shadow-sm rounded-4">
                <i class="fas fa-check-circle fa-3x text-warning mb-3 opacity-25"></i>
                <p class="text-muted">No events are currently on hold.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        (function showMsgAlert() {
            const params = new URLSearchParams(window.location.search);
            const msg = params.get('msg');
            if (params.get('forbidden') === '1') {
                Swal.fire({ icon: 'warning', title: 'Access denied', text: 'You do not have permission for that action.', confirmButtonColor: '#FF5F15' });
                window.history.replaceState({}, document.title, window.location.pathname);
            }
            const messages = {
                approve: { title: 'Event approved', text: 'The event has been approved and is now live.', icon: 'success' },
                reject: { title: 'Event rejected', text: 'The event has been rejected.', icon: 'info' },
                hold: { title: 'Event on hold', text: 'The event has been put on hold.', icon: 'warning' },
                reschedule: { title: 'Event rescheduled', text: 'The event has been rescheduled.', icon: 'success' },
                error: { title: 'Error', text: 'Something went wrong. Please try again.', icon: 'error' },
                welcome: { title: 'Welcome', text: 'You are logged in successfully.', icon: 'success' }
            };
            if (msg && messages[msg]) {
                Swal.fire(messages[msg].title, messages[msg].text, messages[msg].icon).then(() => {
                    window.history.replaceState({}, '', 'dashboard.php');
                });
            }
        })();
    </script>
</body>
</html>