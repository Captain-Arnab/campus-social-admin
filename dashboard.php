<?php
session_start();
include 'db.php';

// Check if user is logged in (admin or subadmin)
if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}

$user_type = $_SESSION['user_type'] ?? 'admin';
$username = isset($_SESSION['admin']) ? $_SESSION['admin'] : $_SESSION['subadmin'];

// Stats
$total_events = $conn->query("SELECT * FROM events")->num_rows;
$pending_events = $conn->query("SELECT * FROM events WHERE status='pending'")->num_rows;
$hold_events = $conn->query("SELECT * FROM events WHERE status='hold'")->num_rows;
$live_events = $conn->query("SELECT * FROM events WHERE status='approved'")->num_rows;

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
        .main-content { margin-left: 280px; padding: 40px; transition: 0.3s; }
        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 20px; } }

        .stat-card { border: none; border-radius: 24px; color: white; padding: 30px; box-shadow: var(--card-shadow); position: relative; overflow: hidden; transition: 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card .icon-overlay { position: absolute; right: -15px; bottom: -15px; font-size: 6rem; opacity: 0.15; transform: rotate(-10deg); }
        
        .bg-orange-grad { background: linear-gradient(135deg, #FF5F15 0%, #FF9068 100%); }
        .bg-hold-grad { background: linear-gradient(135deg, #f39c12 0%, #f1c40f 100%); }
        .bg-dark-grad { background: linear-gradient(135deg, #2d3436 0%, #636e72 100%); }
        .bg-info-grad { background: linear-gradient(135deg, #0984e3 0%, #74b9ff 100%); }

        .pending-card { background: white; border-radius: 20px; padding: 25px; box-shadow: var(--card-shadow); border: none; margin-bottom: 15px; transition: 0.3s; }
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
        <div class="mb-5">
            <h4 class="fw-bold m-0">Platform Overview</h4>
            <p class="text-muted small">Real-time engagement and operational statistics</p>
        </div>

        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="stat-card bg-orange-grad">
                    <small class="text-uppercase fw-bold opacity-75">Needs Review</small>
                    <h2 class="display-5 fw-bold mb-0 mt-2"><?php echo $pending_events; ?></h2>
                    <i class="fas fa-hourglass-half icon-overlay"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-hold-grad">
                    <small class="text-uppercase fw-bold opacity-75">On Hold</small>
                    <h2 class="display-5 fw-bold mb-0 mt-2"><?php echo $hold_events; ?></h2>
                    <i class="fas fa-pause-circle icon-overlay"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-dark-grad">
                    <small class="text-uppercase fw-bold opacity-50">Active Events</small>
                    <h2 class="display-5 fw-bold mb-0 mt-2"><?php echo $live_events; ?></h2>
                    <i class="fas fa-broadcast-tower icon-overlay"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-info-grad">
                    <small class="text-uppercase fw-bold opacity-50">App Users</small>
                    <h2 class="display-5 fw-bold mb-0 mt-2"><?php echo $conn->query("SELECT * FROM users")->num_rows; ?></h2>
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
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                <i class="fas fa-calendar-plus text-warning"></i>
                            </div>
                            <div>
                                <span class="category-pill mb-1 d-inline-block"><?php echo $row['category']; ?></span>
                                <h6 class="fw-bold m-0"><?php echo $row['title']; ?></h6>
                                <small class="text-muted"><i class="fas fa-user me-1"></i><?php echo $row['organizer_name']; ?> • <i class="far fa-clock me-1"></i><?php echo date('M d, Y', strtotime($row['event_date'])); ?></small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <a href="event_details.php?id=<?php echo $row['id']; ?>" class="btn-review">
                                <i class="fas fa-search-plus me-2"></i> Review & Verify
                            </a>
                            <a href="edit_event.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-dark" style="border-radius: 12px; font-weight: 700; padding: 10px 16px;">
                                <i class="fas fa-pen-to-square me-2"></i> Edit
                            </a>
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
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; background: rgba(243, 156, 18, 0.1);">
                                <i class="fas fa-clock text-warning"></i>
                            </div>
                            <div>
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
                        <div class="d-flex align-items-center gap-3">
                            <a href="event_details.php?id=<?php echo $row['id']; ?>" class="btn-review" style="background: #f39c12;">
                                <i class="fas fa-edit me-2"></i> Manage Hold
                            </a>
                            <a href="edit_event.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-dark" style="border-radius: 12px; font-weight: 700; padding: 10px 16px;">
                                <i class="fas fa-pen-to-square me-2"></i> Edit
                            </a>
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
</body>
</html>