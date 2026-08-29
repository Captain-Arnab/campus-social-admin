<?php
/**
 * Admin panel — User Administration UI.
 * Do NOT overwrite this with api/users.php (mobile register/login API).
 */
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}
require_priv('manage_users');

// Get view type (students or faculty)
$view = (isset($_GET['view']) && $_GET['view'] === 'faculty') ? 'faculty' : 'students';
$items_per_page = 20;

// 1. AJAX HANDLER
if (isset($_GET['ajax_filter'])) {
    $filter_sql = "";
    $name = trim(isset($_GET['name']) ? $_GET['name'] : '');
    $email = trim(isset($_GET['email']) ? $_GET['email'] : '');
    $phone = trim(isset($_GET['phone']) ? $_GET['phone'] : '');
    $date = trim(isset($_GET['date']) ? $_GET['date'] : '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    
    // Add student/faculty filter
    $user_type_filter = ($view == 'students') ? " AND is_student = 1" : " AND is_student = 0";

    if (!empty($name)) $filter_sql .= " AND full_name LIKE '%" . $conn->real_escape_string($name) . "%'";
    if (!empty($email)) $filter_sql .= " AND email LIKE '%" . $conn->real_escape_string($email) . "%'";
    if (!empty($phone)) $filter_sql .= " AND phone LIKE '%" . $conn->real_escape_string($phone) . "%'";
    if (!empty($date)) {
        $dates = explode(" to ", $date);
        if(count($dates) == 2) {
            $start_date = $conn->real_escape_string($dates[0]);
            $end_date = $conn->real_escape_string($dates[1]);
            $filter_sql .= " AND DATE(joined_at) BETWEEN '$start_date' AND '$end_date'";
        } else {
            $filter_sql .= " AND DATE(joined_at) = '" . $conn->real_escape_string($dates[0]) . "'";
        }
    }

    $total_result = $conn->query("SELECT COUNT(*) AS total FROM users WHERE 1=1 $user_type_filter $filter_sql");
    $total_users = (int) $total_result->fetch_assoc()['total'];
    $total_pages = max(1, (int) ceil($total_users / $items_per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $items_per_page;
    $users = $conn->query("SELECT * FROM users WHERE 1=1 $user_type_filter $filter_sql ORDER BY joined_at DESC, id DESC LIMIT $items_per_page OFFSET $offset");

    ob_start();
    if ($users->num_rows > 0) {
        while($row = $users->fetch_assoc()) {
            $status_class = ($row['status'] == 'active') ? 'status-active' : 'status-blocked';
            $status_icon = ($row['status'] == 'active') ? 'check-circle' : 'ban';
            $status_text = ucfirst($row['status']);
            ?>
            <tr class="user-row">
                <td class="selection-cell"><input type="checkbox" class="user-select" value="<?php echo (int) $row['id']; ?>" aria-label="Select user"></td>
                <td class="ps-4">
                    <div class="d-flex align-items-center">
                        <div class="avatar-circle me-3">
                            <?php echo strtoupper(substr($row['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <a href="user_profile.php?id=<?php echo $row['id']; ?>" class="fw-bold user-link text-dark"><?php echo $row['full_name']; ?></a>
                            <div class="small text-muted mt-1"><i class="far fa-clock me-1"></i> <?php echo date('M d, Y', strtotime($row['joined_at'])); ?></div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="d-flex flex-column">
                        <span class="mb-1 text-dark"><i class="fas fa-envelope text-muted me-2 small-icon"></i><?php echo $row['email']; ?></span>
                        <span class="text-muted small"><i class="fas fa-phone text-muted me-2 small-icon"></i><?php echo $row['phone']; ?></span>
                    </div>
                </td>
                <td>
                    <?php 
                        // Get roll/emp number
                        $uid = $row['id'];
                        $sf_data = $conn->query("SELECT roll_number, emp_number FROM student_faculty WHERE user_id = $uid")->fetch_assoc();
                        if($sf_data) {
                            if($view == 'students' && $sf_data['roll_number'] != 'NA') {
                                echo '<span class="badge badge-pill badge-info-soft">' . $sf_data['roll_number'] . '</span>';
                            } elseif($view == 'faculty' && $sf_data['emp_number'] != 'NA') {
                                echo '<span class="badge badge-pill badge-info-soft">' . $sf_data['emp_number'] . '</span>';
                            }
                        }
                    ?>
                </td>
                <td>
                    <?php 
                        $is_organizer = $conn->query("SELECT 1 FROM events WHERE organizer_id = $uid LIMIT 1")->num_rows > 0;
                        $is_volunteer = $conn->query("SELECT 1 FROM volunteers WHERE user_id = $uid LIMIT 1")->num_rows > 0;
                        
                        echo '<div class="d-flex gap-2 flex-wrap">';
                        if($is_organizer) echo "<span class='badge badge-pill badge-brand-soft'><i class='fas fa-star me-1'></i>Organizer</span>";
                        if($is_volunteer) echo "<span class='badge badge-pill badge-gray-soft'><i class='fas fa-hands-helping me-1'></i>Volunteer</span>";
                        if(!$is_organizer && !$is_volunteer) echo "<span class='badge badge-pill badge-light text-muted'>New Member</span>";
                        echo '</div>';
                    ?>
                </td>
                <td>
                    <span class="status-badge <?php echo $status_class; ?>">
                        <i class="fas fa-<?php echo $status_icon; ?> me-1"></i> <?php echo $status_text; ?>
                    </span>
                </td>
                <td class="text-end pe-4">
                    <div class="action-btn-group">
                        <a href="user_profile.php?id=<?php echo $row['id']; ?>" class="btn-icon btn-view" title="View Profile">
                            <i class="fas fa-arrow-right"></i>
                        </a>
                        <?php if($row['status'] == 'active'): ?>
                            <button onclick="toggleUser(<?php echo $row['id']; ?>, 'block')" class="btn-icon btn-block" title="Block User">
                                <i class="fas fa-ban"></i>
                            </button>
                        <?php else: ?>
                            <button onclick="toggleUser(<?php echo $row['id']; ?>, 'unblock')" class="btn-icon btn-unblock" title="Unblock User">
                                <i class="fas fa-unlock"></i>
                            </button>
                        <?php endif; ?>
                        <button onclick="deleteUser(<?php echo $row['id']; ?>)" class="btn-icon btn-delete" title="Delete User">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php
        }
    } else {
        echo '<tr><td colspan="7" class="text-center py-5 text-muted fw-light">No users found.</td></tr>';
    }
    $rows_html = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'html' => $rows_html,
        'total' => $total_users,
        'page' => $page,
        'pages' => $total_pages
    ]);
    exit;
}

// Initial query based on view
$user_type_filter = ($view == 'students') ? " AND is_student = 1" : " AND is_student = 0";
$name = trim(isset($_GET['name']) ? $_GET['name'] : '');
$email = trim(isset($_GET['email']) ? $_GET['email'] : '');
$phone = trim(isset($_GET['phone']) ? $_GET['phone'] : '');
$date = trim(isset($_GET['date']) ? $_GET['date'] : '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$filter_sql = '';

if ($name !== '') $filter_sql .= " AND full_name LIKE '%" . $conn->real_escape_string($name) . "%'";
if ($email !== '') $filter_sql .= " AND email LIKE '%" . $conn->real_escape_string($email) . "%'";
if ($phone !== '') $filter_sql .= " AND phone LIKE '%" . $conn->real_escape_string($phone) . "%'";
if ($date !== '') {
    $dates = explode(" to ", $date);
    if (count($dates) === 2) {
        $start_date = $conn->real_escape_string($dates[0]);
        $end_date = $conn->real_escape_string($dates[1]);
        $filter_sql .= " AND DATE(joined_at) BETWEEN '$start_date' AND '$end_date'";
    } else {
        $filter_sql .= " AND DATE(joined_at) = '" . $conn->real_escape_string($dates[0]) . "'";
    }
}

$total_users = (int) $conn->query("SELECT COUNT(*) AS total FROM users WHERE 1=1 $user_type_filter $filter_sql")->fetch_assoc()['total'];
$total_pages = max(1, (int) ceil($total_users / $items_per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $items_per_page;
$users = $conn->query("SELECT * FROM users WHERE 1=1 $user_type_filter $filter_sql ORDER BY joined_at DESC, id DESC LIMIT $items_per_page OFFSET $offset");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Users | Admin Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        :root { 
            --brand-color: #FF5F15; 
            --brand-hover: #e04e0b;
            --brand-soft: rgba(255, 95, 21, 0.1);
            --bg-body: #f8f9fd;
            --text-main: #2d3436;
            --text-muted: #636e72;
            --card-shadow: 0 10px 30px rgba(0,0,0,0.04);
            --hover-shadow: 0 15px 35px rgba(0,0,0,0.08);
        }
        
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-body); color: var(--text-main); }
        .main-content { margin-left: 280px; padding: 40px; transition: 0.3s; }
        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 12px; } }

        .page-header h4 { font-weight: 700; letter-spacing: -0.5px; }
        .count-badge { background: linear-gradient(135deg, var(--brand-color), #ff8a50); color: white; padding: 8px 16px; border-radius: 30px; box-shadow: 0 4px 15px rgba(255, 95, 21, 0.3); font-weight: 600; font-size: 0.9rem; }

        /* View Navigation Tabs */
        .view-tabs { margin-bottom: 30px; border-bottom: 2px solid #f0f0f0; display: flex; flex-wrap: nowrap; gap: 10px; }
        .view-tabs-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: thin; }
        .view-tab { flex: 0 0 auto; white-space: nowrap; text-decoration: none; padding: 12px 18px; border-bottom: 3px solid transparent; transition: all 0.3s; font-weight: 600; color: var(--text-muted); }
        .view-tab:hover { color: var(--brand-color); }
        .view-tab.active { color: var(--brand-color); border-bottom-color: var(--brand-color); }

        .filter-card { background: white; border-radius: 20px; padding: 25px; box-shadow: var(--card-shadow); border: none; margin-bottom: 30px; }
        @media (max-width: 767.98px) {
            .filter-card { padding: 16px 14px; border-radius: 14px; }
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: thin;
            }
            .custom-table {
                width: 100%;
                min-width: 680px;
            }
            .custom-table thead th { white-space: nowrap; padding-left: 14px !important; font-size: 0.68rem; letter-spacing: 0.04em; }
            .user-row td { padding: 14px 14px !important; vertical-align: top; }
        }
        .mui-form-group { position: relative; margin-bottom: 0; }
        .mui-input { width: 100%; padding: 12px 15px; font-size: 0.95rem; font-weight: 500; color: var(--text-main); border: 2px solid #f0f0f0; border-radius: 12px; background-color: #fff; outline: none; transition: all 0.3s ease; height: 52px; appearance: none; }
        .mui-input:focus { border-color: var(--brand-color); box-shadow: 0 0 0 4px rgba(255, 95, 21, 0.1); }
        .mui-label { position: absolute; left: 12px; top: 16px; font-size: 0.95rem; color: #95a5a6; pointer-events: none; transition: 0.2s ease all; background-color: transparent; padding: 0 4px; }
        .mui-input:focus ~ .mui-label, .mui-input:not(:placeholder-shown) ~ .mui-label { top: -10px; left: 10px; font-size: 0.75rem; color: var(--brand-color); background-color: white; font-weight: 700; }

        .btn-brand-solid { background-color: var(--brand-color); color: white; border: none; height: 52px; border-radius: 12px; font-weight: 700; transition: all 0.3s; width: 100%; box-shadow: 0 4px 15px rgba(255, 95, 21, 0.2); }
        .btn-brand-solid:hover { background-color: var(--brand-hover); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(255, 95, 21, 0.3); color: white; }
        .btn-reset { background-color: #f1f3f5; color: var(--text-muted); border: none; height: 52px; border-radius: 12px; font-weight: 600; transition: all 0.3s; width: 100%; }
        .btn-reset:hover { background-color: #e9ecef; color: var(--text-main); }

        .custom-table { border-collapse: separate; border-spacing: 0 12px; }
        .custom-table thead th { background: transparent; border: none; color: #a0a0a0; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; padding-left: 25px; }
        .user-row { background: white; box-shadow: 0 5px 20px rgba(0,0,0,0.03); transition: all 0.3s ease; }
        .user-row td { border: none; padding: 20px 25px; vertical-align: middle; }
        .user-row td:first-child { border-top-left-radius: 16px; border-bottom-left-radius: 16px; }
        .user-row td:last-child { border-top-right-radius: 16px; border-bottom-right-radius: 16px; }
        .user-row:hover { transform: translateY(-5px); box-shadow: var(--hover-shadow); z-index: 2; }

        .avatar-circle { width: 45px; height: 45px; border-radius: 50%; background: var(--brand-soft); display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--brand-color); border: 2px solid white; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .badge-pill { padding: 6px 12px; border-radius: 20px; font-weight: 600; font-size: 0.75rem; }
        .badge-brand-soft { background-color: var(--brand-soft); color: var(--brand-color); }
        .badge-gray-soft { background-color: #f1f3f5; color: #495057; }
        .badge-info-soft { background-color: rgba(52, 152, 219, 0.1); color: #3498db; }
        
        .status-badge { font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items-center; }
        .status-active { color: #2ecc71; }
        .status-blocked { color: #e74c3c; }

        .action-btn-group { display: flex; gap: 8px; justify-content: flex-end; }
        .btn-icon { width: 38px; height: 38px; border-radius: 10px; border: none; display: flex; align-items: center; justify-content: center; transition: all 0.2s; cursor: pointer; }
        .btn-view { background-color: #f8f9fa; color: var(--text-muted); }
        .btn-view:hover { background-color: var(--brand-color); color: white; }
        .btn-block { background-color: #fff5f5; color: #e74c3c; }
        .btn-block:hover { background-color: #e74c3c; color: white; }
        .btn-unblock { background-color: #f0fdf4; color: #2ecc71; }
        .btn-unblock:hover { background-color: #2ecc71; color: white; }
        .btn-delete { background-color: #f8f9fa; color: #6c757d; }
        .btn-delete:hover { background-color: #c0392b; color: white; }

        .user-link { text-decoration: none; transition: 0.2s; }
        .user-link:hover { color: var(--brand-color) !important; }
        .small-icon { width: 15px; text-align: center; }
        .pagination-controls { display: flex; justify-content: flex-end; gap: 6px; flex-wrap: wrap; }
        .pagination-controls button { min-width: 38px; height: 38px; border: 1px solid #e9ecef; border-radius: 10px; background: white; color: var(--text-muted); font-weight: 600; }
        .pagination-controls button:hover:not(:disabled), .pagination-controls button.active { background: var(--brand-color); border-color: var(--brand-color); color: white; }
        .pagination-controls button:disabled { opacity: 0.45; cursor: not-allowed; }
        .pagination-ellipsis { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 38px; color: var(--text-muted); }
        .bulk-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .bulk-toolbar select, .bulk-toolbar button { height: 40px; border: 1px solid #e9ecef; border-radius: 10px; padding: 0 12px; background: white; color: var(--text-muted); font-weight: 600; }
        .bulk-toolbar button { background: var(--brand-color); border-color: var(--brand-color); color: white; }
        .bulk-toolbar button:disabled { opacity: 0.5; cursor: not-allowed; }
        .selection-cell { width: 42px; padding-left: 12px !important; padding-right: 0 !important; text-align: center; }
        .user-select, #selectAllUsers { width: 16px; height: 16px; accent-color: var(--brand-color); }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="d-flex flex-column flex-sm-row flex-sm-wrap justify-content-sm-between align-items-sm-center gap-3 mb-4 page-header">
            <div class="min-w-0">
                <h4 class="m-0 text-dark">User Administration</h4>
                <p class="text-muted small m-0 mt-1">Manage platform participants and moderation</p>
            </div>
            <span class="count-badge align-self-start align-self-sm-center flex-shrink-0" id="userCount"><?php echo $total_users; ?> Users</span>
        </div>

        <!-- View Tabs -->
        <div class="view-tabs view-tabs-scroll">
            <a href="users.php?view=students" class="view-tab <?php echo ($view == 'students') ? 'active' : ''; ?>">
                <i class="fas fa-user-graduate me-2"></i>Students
            </a>
            <a href="users.php?view=faculty" class="view-tab <?php echo ($view == 'faculty') ? 'active' : ''; ?>">
                <i class="fas fa-chalkboard-teacher me-2"></i>Faculty
            </a>
        </div>

        <div class="filter-card">
            <div class="row g-4 align-items-center">
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="mui-form-group">
                        <input type="text" id="nameInput" class="mui-input" placeholder=" " value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="mui-label">Search Name</label>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="mui-form-group">
                        <input type="text" id="emailInput" class="mui-input" placeholder=" " value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="mui-label">Search Email</label>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-2">
                    <div class="mui-form-group">
                        <input type="text" id="phoneInput" class="mui-input" placeholder=" " value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="mui-label">Search Phone</label>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-2">
                    <div class="mui-form-group">
                        <input type="text" id="dateInput" class="mui-input bg-white" placeholder=" " value="<?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="mui-label">Joined Date</label>
                    </div>
                </div>
                <div class="col-12 col-lg-2 d-flex gap-2">
                    <button type="button" onclick="fetchUsers()" class="btn btn-brand-solid shadow-sm"><i class="fas fa-search"></i></button>
                    <button type="button" id="resetBtn" class="btn btn-reset shadow-sm"><i class="fas fa-redo-alt"></i></button>
                </div>
            </div>
        </div>

        <div class="bulk-toolbar">
            <span class="small text-muted" id="selectionSummary">0 users selected</span>
            <div class="d-flex gap-2">
                <select id="bulkAction" aria-label="Bulk user action">
                    <option value="">Bulk edit status...</option>
                    <option value="block">Block selected</option>
                    <option value="unblock">Unblock selected</option>
                    <option value="delete">Delete selected</option>
                </select>
                <button type="button" id="applyBulkAction" disabled>Apply</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table custom-table mb-0 text-nowrap">
                <thead>
                    <tr>
                        <th class="selection-cell"><input type="checkbox" id="selectAllUsers" aria-label="Select all users on this page"></th>
                        <th>User Profile</th>
                        <th>Contact Info</th>
                        <th><?php echo ($view == 'students') ? 'Roll Number' : 'Employee ID'; ?></th>
                        <th>Roles & Badges</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    <?php if($users->num_rows > 0): ?>
                        <?php while($row = $users->fetch_assoc()): 
                            $status_class = ($row['status'] == 'active') ? 'status-active' : 'status-blocked';
                            $status_icon = ($row['status'] == 'active') ? 'check-circle' : 'ban';
                            $status_text = ucfirst($row['status']);
                        ?>
                        <tr class="user-row">
                            <td class="selection-cell"><input type="checkbox" class="user-select" value="<?php echo (int) $row['id']; ?>" aria-label="Select user"></td>
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle me-3">
                                        <?php echo strtoupper(substr($row['full_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <a href="user_profile.php?id=<?php echo $row['id']; ?>" class="fw-bold user-link text-dark"><?php echo $row['full_name']; ?></a>
                                        <div class="small text-muted mt-1"><i class="far fa-clock me-1"></i> <?php echo date('M d, Y', strtotime($row['joined_at'])); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="mb-1 text-dark"><i class="fas fa-envelope text-muted me-2 small-icon"></i><?php echo $row['email']; ?></span>
                                    <span class="text-muted small"><i class="fas fa-phone text-muted me-2 small-icon"></i><?php echo $row['phone']; ?></span>
                                </div>
                            </td>
                            <td>
                                <?php 
                                    $uid = $row['id'];
                                    $sf_data = $conn->query("SELECT roll_number, emp_number FROM student_faculty WHERE user_id = $uid")->fetch_assoc();
                                    if($sf_data) {
                                        if($view == 'students' && $sf_data['roll_number'] != 'NA') {
                                            echo '<span class="badge badge-pill badge-info-soft">' . $sf_data['roll_number'] . '</span>';
                                        } elseif($view == 'faculty' && $sf_data['emp_number'] != 'NA') {
                                            echo '<span class="badge badge-pill badge-info-soft">' . $sf_data['emp_number'] . '</span>';
                                        }
                                    }
                                ?>
                            </td>
                            <td>
                                <?php 
                                    $is_organizer = $conn->query("SELECT 1 FROM events WHERE organizer_id = $uid LIMIT 1")->num_rows > 0;
                                    $is_volunteer = $conn->query("SELECT 1 FROM volunteers WHERE user_id = $uid LIMIT 1")->num_rows > 0;
                                    echo '<div class="d-flex gap-2 flex-wrap">';
                                    if($is_organizer) echo "<span class='badge badge-pill badge-brand-soft'><i class='fas fa-star me-1'></i>Organizer</span>";
                                    if($is_volunteer) echo "<span class='badge badge-pill badge-gray-soft'><i class='fas fa-hands-helping me-1'></i>Volunteer</span>";
                                    if(!$is_organizer && !$is_volunteer) echo "<span class='badge badge-pill badge-light text-muted'>New Member</span>";
                                    echo '</div>';
                                ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <i class="fas fa-<?php echo $status_icon; ?> me-1"></i> <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td class="text-end pe-4">
                                <div class="action-btn-group">
                                    <a href="user_profile.php?id=<?php echo $row['id']; ?>" class="btn-icon btn-view" title="View Profile"><i class="fas fa-arrow-right"></i></a>
                                    <?php if($row['status'] == 'active'): ?>
                                        <button onclick="toggleUser(<?php echo $row['id']; ?>, 'block')" class="btn-icon btn-block" title="Block"><i class="fas fa-ban"></i></button>
                                    <?php else: ?>
                                        <button onclick="toggleUser(<?php echo $row['id']; ?>, 'unblock')" class="btn-icon btn-unblock" title="Unblock"><i class="fas fa-unlock"></i></button>
                                    <?php endif; ?>
                                    <button onclick="deleteUser(<?php echo $row['id']; ?>)" class="btn-icon btn-delete" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <nav class="pagination-controls mt-3" aria-label="Users pagination" id="paginationControls"></nav>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        (function showMsgAlert() {
            const params = new URLSearchParams(window.location.search);
            const msg = params.get('msg');
            const clearMsg = () => {
                params.delete('msg');
                window.history.replaceState({}, '', 'users.php' + (params.toString() ? '?' + params.toString() : ''));
            };
            if (msg === 'blocked') {
                Swal.fire('User blocked', 'The user has been blocked.', 'success').then(clearMsg);
            } else if (msg === 'unblocked') {
                Swal.fire('User unblocked', 'The user has been unblocked.', 'success').then(clearMsg);
            } else if (msg === 'deleted') {
                Swal.fire('User deleted', 'The user account and related records have been removed.', 'success').then(clearMsg);
            } else if (msg === 'delete_failed') {
                Swal.fire('Delete failed', 'Could not delete this user. Please try again.', 'error').then(clearMsg);
            } else if (msg === 'bulk_failed') {
                Swal.fire('Bulk action failed', 'Could not update all selected users. Please try again.', 'error').then(clearMsg);
            }
        })();

        const currentView = '<?php echo $view; ?>';
        const fp = flatpickr("#dateInput", { mode: "range", dateFormat: "Y-m-d" });
        const nameIn = document.getElementById('nameInput');
        const emailIn = document.getElementById('emailInput');
        const phoneIn = document.getElementById('phoneInput');
        const dateIn = document.getElementById('dateInput');
        const tableBody = document.getElementById('usersTableBody');
        const paginationControls = document.getElementById('paginationControls');
        const selectAllUsers = document.getElementById('selectAllUsers');
        const bulkAction = document.getElementById('bulkAction');
        const applyBulkAction = document.getElementById('applyBulkAction');
        const selectionSummary = document.getElementById('selectionSummary');
        let currentPage = <?php echo $page; ?>;
        let requestSequence = 0;

        function selectedUserIds() {
            return [...tableBody.querySelectorAll('.user-select:checked')].map(input => input.value);
        }

        function updateBulkState() {
            const selectedCount = selectedUserIds().length;
            selectionSummary.textContent = `${selectedCount} user${selectedCount === 1 ? '' : 's'} selected`;
            applyBulkAction.disabled = selectedCount === 0 || bulkAction.value === '';
            if (selectAllUsers) {
                const checkboxes = tableBody.querySelectorAll('.user-select');
                selectAllUsers.checked = checkboxes.length > 0 && selectedCount === checkboxes.length;
                selectAllUsers.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
            }
        }

        selectAllUsers.addEventListener('change', () => {
            tableBody.querySelectorAll('.user-select').forEach(input => {
                input.checked = selectAllUsers.checked;
            });
            updateBulkState();
        });
        tableBody.addEventListener('change', event => {
            if (event.target.classList.contains('user-select')) updateBulkState();
        });
        bulkAction.addEventListener('change', updateBulkState);

        function renderPagination(total, page, pages) {
            paginationControls.innerHTML = '';
            if (total === 0 || pages <= 1) return;

            const addButton = (label, targetPage, disabled = false, active = false) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = label;
                button.disabled = disabled;
                button.className = active ? 'active' : '';
                button.setAttribute('aria-label', `Page ${targetPage}`);
                button.addEventListener('click', () => fetchUsers(targetPage));
                paginationControls.appendChild(button);
            };

            addButton('Previous', page - 1, page === 1);
            const visiblePages = [...new Set([1, page - 2, page - 1, page, page + 1, page + 2, pages])]
                .filter(pageNumber => pageNumber >= 1 && pageNumber <= pages)
                .sort((firstPage, secondPage) => firstPage - secondPage);
            let previousPage = null;
            visiblePages.forEach(pageNumber => {
                if (previousPage !== null && pageNumber - previousPage > 1) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'pagination-ellipsis';
                    ellipsis.textContent = '...';
                    paginationControls.appendChild(ellipsis);
                }
                addButton(String(pageNumber), pageNumber, false, pageNumber === page);
                previousPage = pageNumber;
            });
            addButton('Next', page + 1, page === pages);
        }

        function fetchUsers(page = 1) {
            currentPage = page;
            const pageParams = new URLSearchParams(window.location.search);
            pageParams.set('view', currentView);
            pageParams.set('page', page);
            [['name', nameIn.value], ['email', emailIn.value], ['phone', phoneIn.value], ['date', dateIn.value]].forEach(([key, value]) => {
                if (value) pageParams.set(key, value);
                else pageParams.delete(key);
            });
            window.history.replaceState({}, '', `users.php?${pageParams.toString()}`);
            const sequence = ++requestSequence;
            const url = `users.php?ajax_filter=1&view=${currentView}&page=${page}&name=${encodeURIComponent(nameIn.value)}&email=${encodeURIComponent(emailIn.value)}&phone=${encodeURIComponent(phoneIn.value)}&date=${encodeURIComponent(dateIn.value)}`;
            tableBody.setAttribute('aria-busy', 'true');
            fetch(url)
                .then(res => {
                    if (!res.ok) throw new Error('Could not load users');
                    return res.json();
                })
                .then(data => {
                    if (sequence !== requestSequence) return;
                    tableBody.innerHTML = data.html;
                    currentPage = data.page;
                    document.getElementById('userCount').textContent = `${data.total} Users`;
                    renderPagination(data.total, data.page, data.pages);
                    selectAllUsers.checked = false;
                    selectAllUsers.indeterminate = false;
                    updateBulkState();
                })
                .catch(() => {
                    if (sequence === requestSequence) {
                        tableBody.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-danger">Could not load users.</td></tr>';
                    }
                })
                .finally(() => tableBody.removeAttribute('aria-busy'));
        }

        renderPagination(<?php echo $total_users; ?>, <?php echo $page; ?>, <?php echo $total_pages; ?>);

        document.getElementById('resetBtn').addEventListener('click', () => {
            nameIn.value = ''; emailIn.value = ''; phoneIn.value = ''; fp.clear(); fetchUsers(1);
        });

        let timeout = null;
        function debounceFetch() { clearTimeout(timeout); timeout = setTimeout(() => fetchUsers(1), 300); }

        [nameIn, emailIn, phoneIn].forEach(el => el.addEventListener('input', debounceFetch));
        dateIn.addEventListener('change', () => fetchUsers(1));

        function toggleUser(id, action) {
            Swal.fire({
                title: 'Confirm', text: `Are you sure you want to ${action} this user?`, icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#FF5F15'
            }).then((res) => {
                if (res.isConfirmed) {
                    window.location.href = buildUserActionUrl(id, action);
                }
            });
        }

        function deleteUser(id) {
            Swal.fire({
                title: 'Delete this user?',
                html: 'This permanently removes the account. Any events they hosted will also be deleted. This cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#c0392b',
                confirmButtonText: 'Yes, delete',
                cancelButtonText: 'Cancel'
            }).then((res) => {
                if (res.isConfirmed) {
                    window.location.href = buildUserActionUrl(id, 'delete');
                }
            });
        }

        function buildUserActionUrl(id, action) {
            const params = new URLSearchParams({
                id: id,
                action: action,
                type: 'user',
                view: currentView,
                page: currentPage,
                name: nameIn.value,
                email: emailIn.value,
                phone: phoneIn.value,
                date: dateIn.value
            });
            return `manage_user.php?${params.toString()}`;
        }

        applyBulkAction.addEventListener('click', () => {
            const ids = selectedUserIds();
            const action = bulkAction.value;
            if (!ids.length || !action) return;
            const isDelete = action === 'delete';
            Swal.fire({
                title: isDelete ? 'Delete selected users?' : `${action === 'block' ? 'Block' : 'Unblock'} selected users?`,
                text: `${ids.length} user${ids.length === 1 ? '' : 's'} will be affected.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: isDelete ? '#c0392b' : '#FF5F15',
                confirmButtonText: isDelete ? 'Yes, delete' : 'Apply'
            }).then(result => {
                if (!result.isConfirmed) return;
                const params = new URLSearchParams({
                    action: `bulk_${action}`,
                    type: 'user',
                    view: currentView,
                    page: currentPage,
                    name: nameIn.value,
                    email: emailIn.value,
                    phone: phoneIn.value,
                    date: dateIn.value,
                    ids: ids.join(',')
                });
                window.location.href = `manage_user.php?${params.toString()}`;
            });
        });
    </script>
</body>
</html>