<?php
session_start();
include 'db.php';

if ((!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) || !isset($_GET['id'])) {
    header("Location: users.php");
    exit();
}

$user_id = intval($_GET['id']);
// Fetching user details securely
$user_res = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $user_res->fetch_assoc();

if(!$user) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h4>User not found in database.</h4><a href='users.php'>Back to Registry</a></div>");
}

$user_full_name = $user['full_name'];

// 1. Get Events Organized by this User
$hosted_events = $conn->query("SELECT * FROM events WHERE organizer_id = $user_id ORDER BY event_date DESC");

// 2. Get Events where this User was a Volunteer
$volunteered_events = $conn->query("
    SELECT e.title, e.event_date, v.role, e.id as event_id, e.status
    FROM volunteers v 
    JOIN events e ON v.event_id = e.id 
    WHERE v.user_id = $user_id
    ORDER BY e.event_date DESC
");

// 3. Get Events where this User was a Participant
$participated_events = $conn->query("
    SELECT e.title, e.event_date, e.id as event_id, e.status, e.category
    FROM participant p 
    JOIN events e ON p.event_id = e.id 
    WHERE p.user_id = $user_id
    ORDER BY e.event_date DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo $user_full_name; ?> | Profile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --brand-color: #FF5F15; 
            --brand-soft: rgba(255, 95, 21, 0.08);
            --bg-body: #f8f9fd;
            --card-shadow: 0 10px 40px rgba(0,0,0,0.04);
            --hover-shadow: 0 15px 35px rgba(0,0,0,0.08);
        }
        
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: var(--bg-body); 
            color: #2d3436;
        }

        .main-content { margin-left: 280px; padding: 40px; transition: 0.3s; }
        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 20px; } }
        
        .profile-card { background: white; border-radius: 24px; border: none; box-shadow: var(--card-shadow); overflow: hidden; }
        .profile-header { background: linear-gradient(135deg, #FF5F15 0%, #FF8A50 100%); padding: 45px; color: white; position: relative; }
        
        .avatar-main { 
            width: 110px; height: 110px; border-radius: 50%; background: white; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 2.8rem; font-weight: 800; color: var(--brand-color); 
            box-shadow: 0 10px 25px rgba(0,0,0,0.15); border: 4px solid rgba(255,255,255,0.4);
        }

        .stat-pill { 
            background: rgba(255,255,255,0.15); backdrop-filter: blur(12px); 
            padding: 15px 20px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.25);
            text-align: center; min-width: 110px;
        }

        .section-title { font-weight: 700; color: #2d3436; margin-bottom: 25px; display: flex; align-items: center; gap: 12px; }
        .section-title i { color: var(--brand-color); opacity: 0.8; }
        
        .event-card-mini { 
            background: #fff; border-radius: 18px; padding: 18px; border: 1px solid #f1f1f1;
            transition: 0.3s; margin-bottom: 15px; text-decoration: none; display: block;
            box-shadow: 0 2px 10px rgba(0,0,0,0.01);
        }
        .event-card-mini:hover { transform: translateY(-3px) translateX(5px); border-color: var(--brand-color); box-shadow: 0 8px 20px rgba(0,0,0,0.04); }

        .badge-soft { padding: 6px 14px; border-radius: 30px; font-weight: 700; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-orange { background: var(--brand-soft); color: var(--brand-color); }
        .badge-status { font-size: 0.6rem; border: 1px solid #eee; background: #fdfdfd; color: #7f8c8d; }
        .badge-category { font-size: 0.6rem; background: #e3f2fd; color: #1976d2; }

        .bio-text { color: #636e72; font-size: 0.95rem; line-height: 1.7; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="mb-4">
            <a href="users.php" class="btn btn-white rounded-pill shadow-sm border px-4 btn-sm fw-bold text-muted">
                <i class="fas fa-arrow-left me-2"></i> User Registry
            </a>
        </div>

        <div class="profile-card mb-5">
            <!-- Header Section -->
            <div class="profile-header d-flex justify-content-between align-items-center flex-wrap gap-4">
                <div class="d-flex align-items-center gap-4">
                    <div class="avatar-main">
                        <?php echo strtoupper(substr($user_full_name, 0, 1)); ?>
                    </div>
                    <div>
                        <h2 class="fw-bold mb-1"><?php echo $user_full_name; ?></h2>
                        <div class="d-flex flex-wrap gap-3 mt-1 opacity-90 small">
                            <span><i class="fas fa-envelope me-1"></i> <?php echo $user['email']; ?></span>
                            <span><i class="fas fa-phone me-1"></i> <?php echo $user['phone']; ?></span>
                        </div>
                        <div class="mt-3">
                            <span class="badge bg-white text-dark fw-bold text-uppercase px-3 py-2 rounded-pill shadow-sm" style="font-size: 0.65rem;">
                                <i class="fas fa-shield-alt me-1 text-primary"></i> Account: <?php echo $user['status']; ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-3">
                    <div class="stat-pill">
                        <div class="h3 fw-bold mb-0"><?php echo $hosted_events->num_rows; ?></div>
                        <div class="small opacity-80 fw-semibold text-uppercase" style="font-size: 0.7rem;">Hosted</div>
                    </div>
                    <div class="stat-pill">
                        <div class="h3 fw-bold mb-0"><?php echo $volunteered_events->num_rows; ?></div>
                        <div class="small opacity-80 fw-semibold text-uppercase" style="font-size: 0.7rem;">Volunteered</div>
                    </div>
                    <div class="stat-pill">
                        <div class="h3 fw-bold mb-0"><?php echo $participated_events->num_rows; ?></div>
                        <div class="small opacity-80 fw-semibold text-uppercase" style="font-size: 0.7rem;">Participated</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="p-5 bg-white">
                <div class="row g-5">
                    <!-- Bio & Interests -->
                    <div class="col-lg-3 border-end border-light">
                        <div class="mb-5">
                            <h6 class="section-title small text-muted text-uppercase letter-spacing-1">
                                <i class="fas fa-id-card"></i> Biography
                            </h6>
                            <div class="bio-text">
                                <?php echo !empty($user['bio']) ? nl2br($user['bio']) : '<em>No biography provided by the user.</em>'; ?>
                            </div>
                        </div>
                        <div>
                            <h6 class="section-title small text-muted text-uppercase letter-spacing-1">
                                <i class="fas fa-tags"></i> Area of Interests
                            </h6>
                            <div class="d-flex flex-wrap gap-2">
                                <?php 
                                    $interests = explode(',', $user['interests']);
                                    $found = false;
                                    foreach($interests as $int) {
                                        if(!empty(trim($int))) {
                                            echo '<span class="badge-soft badge-orange">'.trim($int).'</span>';
                                            $found = true;
                                        }
                                    }
                                    if(!$found) echo '<span class="text-muted small">No interests selected.</span>';
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- Hosting History -->
                    <div class="col-lg-3 border-end border-light">
                        <h6 class="section-title">
                            <i class="fas fa-calendar-star"></i> Events Organized
                        </h6>
                        <?php if($hosted_events->num_rows > 0): ?>
                            <?php while($evt = $hosted_events->fetch_assoc()): ?>
                                <a href="event_details.php?id=<?php echo $evt['id']; ?>" class="event-card-mini">
                                    <div class="fw-bold text-dark mb-1"><?php echo $evt['title']; ?></div>
                                    <div class="d-flex justify-content-between align-items-center mt-2">
                                        <small class="text-muted"><i class="far fa-calendar-alt me-1"></i> <?php echo date('M d, Y', strtotime($evt['event_date'])); ?></small>
                                        <span class="badge badge-status"><?php echo strtoupper($evt['status']); ?></span>
                                    </div>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-4 opacity-50">
                                <i class="fas fa-folder-open fa-2x mb-2"></i>
                                <p class="small m-0">No events organized yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Volunteering History -->
                    <div class="col-lg-3 border-end border-light">
                        <h6 class="section-title">
                            <i class="fas fa-hands-helping"></i> Volunteer Experience
                        </h6>
                        <?php if($volunteered_events->num_rows > 0): ?>
                            <?php while($vol = $volunteered_events->fetch_assoc()): ?>
                                <a href="event_details.php?id=<?php echo $vol['event_id']; ?>" class="event-card-mini">
                                    <div class="fw-bold text-dark mb-1"><?php echo $vol['title']; ?></div>
                                    <div class="d-flex justify-content-between align-items-center mt-2">
                                        <span class="text-primary fw-bold" style="font-size: 0.75rem;"><?php echo $vol['role']; ?></span>
                                        <small class="text-muted" style="font-size: 0.7rem;"><?php echo date('M d', strtotime($vol['event_date'])); ?></small>
                                    </div>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-4 opacity-50">
                                <i class="fas fa-user-clock fa-2x mb-2"></i>
                                <p class="small m-0">No volunteer history found.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Participation History -->
                    <div class="col-lg-3">
                        <h6 class="section-title">
                            <i class="fas fa-user-check"></i> Event Participation
                        </h6>
                        <?php if($participated_events->num_rows > 0): ?>
                            <?php while($part = $participated_events->fetch_assoc()): ?>
                                <a href="event_details.php?id=<?php echo $part['event_id']; ?>" class="event-card-mini">
                                    <div class="fw-bold text-dark mb-1"><?php echo $part['title']; ?></div>
                                    <div class="d-flex justify-content-between align-items-center mt-2">
                                        <span class="badge badge-category"><?php echo $part['category']; ?></span>
                                        <small class="text-muted" style="font-size: 0.7rem;"><?php echo date('M d', strtotime($part['event_date'])); ?></small>
                                    </div>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-4 opacity-50">
                                <i class="fas fa-user-slash fa-2x mb-2"></i>
                                <p class="small m-0">No participation history found.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>