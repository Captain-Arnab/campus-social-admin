<?php
session_start();
include 'db.php';
require_once __DIR__ . '/admin_priv.php';
require_once __DIR__ . '/event_date_range_schema.php';

if (!isset($_SESSION['admin']) && !isset($_SESSION['subadmin'])) {
    header("Location: index.php");
    exit();
}
require_priv('events');

$view = isset($_GET['view']) ? $_GET['view'] : 'live';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';

// 1. AJAX HANDLER - Real-time filtering
if (isset($_GET['ajax_filter'])) {
    $filter_sql = "";
    
    $date_condition = "";
    if ($view == 'pending') {
        $date_condition = "AND e.status = 'pending'";
    } elseif ($view == 'past') {
        $date_condition = 'AND ' . events_sql_past($conn, 'e') . ' AND COALESCE(e.event_end_date, e.event_date) >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    } elseif ($view == 'archive') {
        $date_condition = 'AND ' . events_sql_past($conn, 'e') . ' AND COALESCE(e.event_end_date, e.event_date) < DATE_SUB(NOW(), INTERVAL 30 DAY)';
    } elseif ($view == 'hold') {
        $date_condition = "AND e.status = 'hold'";
    } else {
        $date_condition = 'AND ' . events_sql_not_past($conn, 'e') . " AND e.status = 'approved'";
    }

    if (!empty($search_query)) $filter_sql .= " AND (e.title LIKE '%$search_query%' OR u.full_name LIKE '%$search_query%')";
    if (!empty($category_filter)) $filter_sql .= " AND e.category = '$category_filter'";
    if (!empty($date_filter) && $view != 'hold' && $view != 'pending') {
        $dates = explode(" to ", $date_filter);
        if(count($dates) == 2) {
            $filter_sql .= " AND DATE(e.event_date) BETWEEN '$dates[0]' AND '$dates[1]'";
        } else {
            $filter_sql .= " AND DATE(e.event_date) = '$dates[0]'";
        }
    }

    $order_by = ($view == 'pending') ? "e.created_at DESC" : "e.event_date DESC";
    $sql = "SELECT e.*, u.full_name as organizer_name 
            FROM events e 
            JOIN users u ON e.organizer_id = u.id 
            WHERE 1=1 $date_condition $filter_sql 
            ORDER BY $order_by";
            
    $events = $conn->query($sql);

    if ($events->num_rows > 0) {
        while($row = $events->fetch_assoc()) {
            $banners = json_decode($row['banners'] ?? '[]');
            ?>
            <tr class="event-row">
                <td class="ps-4">
                    <input type="checkbox" class="form-check-input event-checkbox" value="<?php echo $row['id']; ?>" style="cursor: pointer;">
                </td>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="thumbnail-mini me-3">
                            <?php if(!empty($banners)): ?>
                                <img src="../uploads/events/<?php echo $banners[0]; ?>" alt="Event" onerror="this.src='../assets/placeholder.png';">
                            <?php else: ?>
                                <div class="thumb-fallback"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="fw-bold text-dark mb-0" style="font-size: 0.9rem;"><?php echo $row['title']; ?></div>
                            <small class="text-muted" style="font-size: 0.75rem;"><i class="fas fa-user-circle me-1"></i><?php echo $row['organizer_name']; ?></small>
                            <?php if($row['status'] == 'hold' && $row['hold_reason']): ?>
                            <small class="text-warning" style="font-size: 0.7rem;"><i class="fas fa-info-circle me-1"></i><?php echo $row['hold_reason']; ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td><span class="badge badge-brand-soft"><?php echo $row['category']; ?></span></td>
                <td>
                    <div class="d-flex flex-column">
                        <span class="fw-semibold" style="font-size: 0.8rem;"><?php echo date('M d, Y', strtotime($row['event_date'])); ?></span>
                        <small class="text-muted" style="font-size: 0.65rem;"><?php echo date('h:i A', strtotime($row['event_date'])); ?></small>
                        <?php
                        $rowEnd = $row['event_end_date'] ?? null;
                        if (!empty($rowEnd) && $rowEnd !== '0000-00-00 00:00:00') {
                            echo '<small class="text-muted d-block" style="font-size:0.6rem;">→ ' . date('M d, Y h:i A', strtotime($rowEnd)) . '</small>';
                        }
                        ?>
                        <?php if($row['status'] == 'hold' && $row['reschedule_date']): ?>
                        <small class="text-primary" style="font-size: 0.65rem;"><i class="fas fa-calendar-check"></i> Reschedule: <?php echo date('M d, Y', strtotime($row['reschedule_date'])); ?></small>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <div class="d-flex align-items-center text-danger fw-bold" style="font-size: 0.8rem;">
                        <i class="fas fa-heart me-1"></i> <?php echo $row['interest_count']; ?>
                    </div>
                </td>
                <td class="text-end pe-4">
                    <div class="d-flex gap-2 justify-content-end">
                        <?php if (has_priv('reports')): ?>
                        <div class="dropdown d-inline-block">
                            <button class="btn-icon btn-download dropdown-toggle" data-bs-toggle="dropdown" title="Download Report" style="font-size:0.7rem;">
                                <i class="fas fa-download"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item small" href="download_report.php?event_id=<?php echo $row['id']; ?>&list_type=all"><i class="fas fa-file-alt me-2"></i>Full Report</a></li>
                                <li><a class="dropdown-item small" href="download_report.php?event_id=<?php echo $row['id']; ?>&list_type=volunteers"><i class="fas fa-hands-helping me-2"></i>Volunteers</a></li>
                                <li><a class="dropdown-item small" href="download_report.php?event_id=<?php echo $row['id']; ?>&list_type=participants"><i class="fas fa-user-check me-2"></i>Participants</a></li>
                                <li><a class="dropdown-item small" href="download_report.php?event_id=<?php echo $row['id']; ?>&list_type=joinees"><i class="fas fa-user-plus me-2"></i>Joinees</a></li>
                            </ul>
                        </div>
                        <?php endif; ?>
                        <a href="event_details.php?id=<?php echo $row['id']; ?>" class="btn-icon btn-view">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php
        }
    } else {
        echo '<tr><td colspan="6" class="text-center py-5 text-muted fw-light">No events found.</td></tr>';
    }
    exit;
}

// Initial Load logic
$date_condition = "";
if ($view == 'pending') {
    $date_condition = "AND e.status = 'pending'";
} elseif ($view == 'past') {
    $date_condition = 'AND ' . events_sql_past($conn, 'e') . ' AND COALESCE(e.event_end_date, e.event_date) >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
} elseif ($view == 'archive') {
    $date_condition = 'AND ' . events_sql_past($conn, 'e') . ' AND COALESCE(e.event_end_date, e.event_date) < DATE_SUB(NOW(), INTERVAL 30 DAY)';
} elseif ($view == 'hold') {
    $date_condition = "AND e.status = 'hold'";
} else {
    $date_condition = 'AND ' . events_sql_not_past($conn, 'e') . " AND e.status = 'approved'";
}

$order_by = ($view == 'pending') ? "e.created_at DESC" : "e.event_date DESC";
$sql = "SELECT e.*, u.full_name as organizer_name FROM events e JOIN users u ON e.organizer_id = u.id WHERE 1=1 $date_condition ORDER BY $order_by";
$events = $conn->query($sql);
$categories = $conn->query("SELECT DISTINCT category FROM events ORDER BY category ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Events | College Connect</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        :root { 
            --brand-color: #FF5F15; 
            --brand-soft: rgba(255, 95, 21, 0.08);
            --bg-body: #f8f9fd;
            --card-shadow: 0 4px 20px rgba(0,0,0,0.04);
            --hover-shadow: 0 8px 30px rgba(0,0,0,0.06);
        }
        
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: var(--bg-body); 
            color: #2d3436;
        }

        .main-content { margin-left: 280px; padding: 30px; transition: 0.3s; }
        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 15px; } }

        /* Bulk Download Section */
        .bulk-actions-bar {
            background: white; border-radius: 16px; padding: 15px 25px;
            box-shadow: var(--card-shadow); margin-bottom: 20px;
            display: none; /* Hidden by default */
        }
        .bulk-actions-bar.active {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-bulk-download {
            background: var(--brand-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: 0.3s;
        }
        .btn-bulk-download:hover {
            background: #e04e0b;
            transform: translateY(-2px);
        }

        .filter-card {
            background: white; border-radius: 16px; padding: 20px;
            box-shadow: var(--card-shadow); border: 1px solid #f0f0f0; margin-bottom: 25px;
        }

        .mui-form-group { position: relative; margin-bottom: 0; }
        .mui-input { 
            width: 100%; padding: 10px 14px; font-size: 0.9rem; font-weight: 500;
            color: #2d3436; border: 2px solid #f0f0f0; border-radius: 10px;
            background-color: #fff; outline: none; transition: 0.3s; height: 48px;
            appearance: none;
        }
        .mui-input:focus { border-color: var(--brand-color); }
        .mui-label { 
            position: absolute; left: 14px; top: 14px; font-size: 0.9rem; color: #95a5a6;
            pointer-events: none; transition: 0.2s ease all; background-color: transparent;
            padding: 0 4px;
        }
        .mui-input:focus ~ .mui-label, .mui-input:not(:placeholder-shown) ~ .mui-label { 
            top: -10px; left: 10px; font-size: 0.7rem; color: var(--brand-color);
            background-color: white; font-weight: 700;
        }

        .custom-table { border-collapse: separate; border-spacing: 0 8px; }
        .custom-table thead th { 
            background: transparent; border: none; color: #a0a0a0;
            font-weight: 600; text-transform: uppercase; font-size: 0.65rem;
            letter-spacing: 1px; padding-left: 25px;
        }
        
        .event-row { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.01); transition: 0.2s ease; }
        .event-row:hover { transform: translateY(-2px); box-shadow: var(--hover-shadow); }
        .event-row td { border: none; padding: 12px 25px; vertical-align: middle; }
        .event-row td:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
        .event-row td:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }

        .thumbnail-mini { width: 45px; height: 45px; border-radius: 10px; overflow: hidden; background: #f1f1f1; flex-shrink: 0; }
        .thumbnail-mini img { width: 100%; height: 100%; object-fit: cover; }
        .thumb-fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #ccc; }

        .badge-brand-soft { background-color: var(--brand-soft); color: var(--brand-color); font-weight: 700; padding: 4px 10px; border-radius: 8px; font-size: 0.65rem; }
        
        .btn-view, .btn-download { 
            width: 32px; height: 32px; border-radius: 8px; border: none;
            background: #f8f9fa; color: #636e72; display: flex; align-items: center;
            justify-content: center; transition: 0.2s; text-decoration: none;
        }
        .btn-view:hover { background: var(--brand-color); color: white; }
        .btn-download { background: #e8f5e9; color: #2ecc71; }
        .btn-download:hover { background: #2ecc71; color: white; }

        .btn-search { background: var(--brand-color); color: white; border: none; border-radius: 10px; height: 48px; font-weight: 700; font-size: 0.9rem; }
        .btn-reset { background: #f1f3f5; color: #636e72; border: none; border-radius: 10px; height: 48px; font-weight: 600; font-size: 0.9rem; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="fw-bold m-0 text-dark"><?php echo ucfirst($view); ?> Events</h5>
                <p class="text-muted small m-0">Manage and monitor student engagement</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-white text-dark border px-3 py-2 rounded-pill small fw-bold">
                    Count: <span id="eventCount"><?php echo $events->num_rows; ?></span>
                </span>
                <button class="btn btn-sm btn-outline-secondary d-lg-none" id="menu-toggle"><i class="fas fa-bars"></i></button>
            </div>
        </div>

        <!-- View Navigation Tabs -->
        <div style="margin-bottom: 20px; border-bottom: 2px solid #f0f0f0; display: flex; gap: 10px; overflow-x: auto;">
            <a href="events.php?view=pending" class="text-decoration-none <?php echo ($view == 'pending') ? 'fw-bold text-dark' : 'text-muted'; ?>" style="padding-bottom: 12px; border-bottom: <?php echo ($view == 'pending') ? '3px solid #e67e22' : 'none'; ?> ;">
                <i class="fas fa-hourglass-half me-1"></i>Pending Approval
            </a>
            <a href="events.php?view=live" class="text-decoration-none <?php echo ($view == 'live') ? 'fw-bold text-dark' : 'text-muted'; ?>" style="padding-bottom: 12px; border-bottom: <?php echo ($view == 'live') ? '3px solid #FF5F15' : 'none'; ?> ;">
                <i class="fas fa-play-circle me-1"></i>Live Events
            </a>
            <a href="events.php?view=hold" class="text-decoration-none <?php echo ($view == 'hold') ? 'fw-bold text-dark' : 'text-muted'; ?>" style="padding-bottom: 12px; border-bottom: <?php echo ($view == 'hold') ? '3px solid #f39c12' : 'none'; ?> ;">
                <i class="fas fa-pause-circle me-1"></i>On Hold
            </a>
            <a href="events.php?view=past" class="text-decoration-none <?php echo ($view == 'past') ? 'fw-bold text-dark' : 'text-muted'; ?>" style="padding-bottom: 12px; border-bottom: <?php echo ($view == 'past') ? '3px solid #3498db' : 'none'; ?> ;">
                <i class="fas fa-history me-1"></i>Past Events
            </a>
            <a href="events.php?view=archive" class="text-decoration-none <?php echo ($view == 'archive') ? 'fw-bold text-dark' : 'text-muted'; ?>" style="padding-bottom: 12px; border-bottom: <?php echo ($view == 'archive') ? '3px solid #95a5a6' : 'none'; ?> ;">
                <i class="fas fa-archive me-1"></i>Archive
            </a>
        </div>

        <!-- Bulk Actions Bar -->
        <?php if (has_priv('reports')): ?>
        <div class="bulk-actions-bar" id="bulkActionsBar">
            <div>
                <span class="fw-bold"><span id="selectedCount">0</span> events selected</span>
            </div>
            <div class="d-flex gap-2">
                <button onclick="clearSelection()" class="btn btn-sm btn-outline-secondary">Clear Selection</button>
                <div class="dropdown">
                    <button class="btn-bulk-download dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-2"></i>Download Reports
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="downloadBulkReports('all');return false;"><i class="fas fa-file-alt me-2"></i>Full Report</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="#" onclick="downloadBulkReports('volunteers');return false;"><i class="fas fa-hands-helping me-2"></i>Volunteers Only</a></li>
                        <li><a class="dropdown-item" href="#" onclick="downloadBulkReports('participants');return false;"><i class="fas fa-user-check me-2"></i>Participants Only</a></li>
                        <li><a class="dropdown-item" href="#" onclick="downloadBulkReports('joinees');return false;"><i class="fas fa-user-plus me-2"></i>Joinees Only</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="filter-card">
            <div class="row g-3 align-items-center">
                <div class="col-lg-4 col-md-6">
                    <div class="mui-form-group">
                        <input type="text" id="searchInput" class="mui-input" placeholder=" ">
                        <label class="mui-label">Event or Organizer</label>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="mui-form-group">
                        <select id="categoryInput" class="mui-input">
                            <option value="">All Categories</option>
                            <?php while($cat = $categories->fetch_assoc()) echo "<option value='{$cat['category']}'>{$cat['category']}</option>"; ?>
                        </select>
                        <label class="mui-label">Category</label>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="mui-form-group">
                        <input type="text" id="dateInput" class="mui-input bg-white" placeholder=" ">
                        <label class="mui-label">Date Range</label>
                    </div>
                </div>
                <div class="col-lg-2 col-md-6 d-flex gap-2">
                    <button type="button" onclick="fetchEvents()" class="btn btn-search flex-grow-1"><i class="fas fa-search"></i></button>
                    <button type="button" id="resetBtn" class="btn btn-reset flex-grow-1"><i class="fas fa-redo"></i></button>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table custom-table mb-0 text-nowrap">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" class="form-check-input" style="cursor: pointer;"></th>
                        <th>Event Summary</th>
                        <th>Category</th>
                        <th>Timeline</th>
                        <th>Engagement</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody id="eventsTableBody">
                    <?php if($events->num_rows > 0): ?>
                        <?php while($row = $events->fetch_assoc()): 
                            $banners = json_decode($row['banners'] ?? '[]');
                        ?>
                        <tr class="event-row">
                            <td class="ps-4">
                                <input type="checkbox" class="form-check-input event-checkbox" value="<?php echo $row['id']; ?>" style="cursor: pointer;">
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="thumbnail-mini me-3">
                                        <?php if(!empty($banners)): ?>
                                            <img src="../uploads/events/<?php echo $banners[0]; ?>" alt="Thumb" onerror="this.parentElement.innerHTML='<div class=\'thumb-fallback\'><i class=\'fas fa-image\'></i></div>';">
                                        <?php else: ?>
                                            <div class="thumb-fallback"><i class="fas fa-image"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark mb-0" style="font-size: 0.9rem;"><?php echo $row['title']; ?></div>
                                        <small class="text-muted" style="font-size: 0.75rem;"><i class="fas fa-user-circle me-1"></i><?php echo $row['organizer_name']; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge badge-brand-soft"><?php echo $row['category']; ?></span></td>
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="fw-semibold" style="font-size: 0.8rem;"><?php echo date('M d, Y', strtotime($row['event_date'])); ?></span>
                                    <small class="text-muted" style="font-size: 0.65rem;"><?php echo date('h:i A', strtotime($row['event_date'])); ?></small>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center text-danger fw-bold" style="font-size: 0.8rem;">
                                    <i class="fas fa-heart me-1"></i> <?php echo $row['interest_count']; ?>
                                </div>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex gap-2 justify-content-end">
                                    <?php if (has_priv('reports')): ?>
                                    <div class="dropdown d-inline-block">
                                        <button class="btn-icon btn-download dropdown-toggle" data-bs-toggle="dropdown" title="Download Report" style="font-size:0.7rem;">
                                            <i class="fas fa-download"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item small" href="download_report.php?event_id=<?php echo $row['id']; ?>&list_type=all"><i class="fas fa-file-alt me-2"></i>Full Report</a></li>
                                            <li><a class="dropdown-item small" href="download_report.php?event_id=<?php echo $row['id']; ?>&list_type=volunteers"><i class="fas fa-hands-helping me-2"></i>Volunteers</a></li>
                                            <li><a class="dropdown-item small" href="download_report.php?event_id=<?php echo $row['id']; ?>&list_type=participants"><i class="fas fa-user-check me-2"></i>Participants</a></li>
                                            <li><a class="dropdown-item small" href="download_report.php?event_id=<?php echo $row['id']; ?>&list_type=joinees"><i class="fas fa-user-plus me-2"></i>Joinees</a></li>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                    <a href="event_details.php?id=<?php echo $row['id']; ?>" class="btn-icon btn-view">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted fw-light">No events found matching your criteria.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        const fp = flatpickr("#dateInput", { mode: "range", dateFormat: "Y-m-d" });
        const sIn = document.getElementById('searchInput');
        const cIn = document.getElementById('categoryInput');
        const dIn = document.getElementById('dateInput');
        const tBody = document.getElementById('eventsTableBody');

        function fetchEvents() {
            const url = `events.php?ajax_filter=1&view=<?php echo $view; ?>&search=${encodeURIComponent(sIn.value)}&category=${encodeURIComponent(cIn.value)}&date=${encodeURIComponent(dIn.value)}`;
            fetch(url).then(res => res.text()).then(data => { 
                tBody.innerHTML = data; 
                updateCheckboxListeners();
            });
        }

        document.getElementById('resetBtn').addEventListener('click', () => {
            sIn.value = ''; cIn.value = ''; fp.clear(); fetchEvents();
        });

        let timeout = null;
        sIn.addEventListener('input', () => { clearTimeout(timeout); timeout = setTimeout(fetchEvents, 300); });
        cIn.addEventListener('change', fetchEvents);
        dIn.addEventListener('change', fetchEvents);

        // Bulk selection functionality
        const selectAllCheckbox = document.getElementById('selectAll');
        const bulkActionsBar = document.getElementById('bulkActionsBar');
        const selectedCountSpan = document.getElementById('selectedCount');

        function updateCheckboxListeners() {
            document.querySelectorAll('.event-checkbox').forEach(cb => {
                cb.addEventListener('change', updateBulkActions);
            });
        }

        selectAllCheckbox?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.event-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            updateBulkActions();
        });

        function updateBulkActions() {
            const checkedBoxes = document.querySelectorAll('.event-checkbox:checked');
            const count = checkedBoxes.length;
            
            if (selectedCountSpan) selectedCountSpan.textContent = count;
            
            if (count > 0) {
                bulkActionsBar?.classList.add('active');
            } else {
                bulkActionsBar?.classList.remove('active');
            }
            
            // Update select all checkbox state
            const allCheckboxes = document.querySelectorAll('.event-checkbox');
            selectAllCheckbox.checked = count === allCheckboxes.length && count > 0;
        }

        function clearSelection() {
            document.querySelectorAll('.event-checkbox').forEach(cb => cb.checked = false);
            selectAllCheckbox.checked = false;
            updateBulkActions();
        }

        function downloadBulkReports(listType = 'all') {
            const checkedBoxes = document.querySelectorAll('.event-checkbox:checked');
            const eventIds = Array.from(checkedBoxes).map(cb => cb.value);
            
            if (eventIds.length === 0) {
                alert('Please select at least one event');
                return;
            }
            
            window.location.href = `download_report.php?event_ids=${eventIds.join(',')}&list_type=${listType}`;
        }

        // Initialize checkbox listeners on page load
        updateCheckboxListeners();

        document.getElementById('menu-toggle')?.addEventListener('click', () => {
            document.getElementById('sidebar-wrapper').classList.add('active');
            document.getElementById('sidebar-backdrop').style.display = 'block';
        });
    </script>
</body>
</html>