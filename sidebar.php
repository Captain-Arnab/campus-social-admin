<?php
if (!function_exists('has_priv')) {
    require_once __DIR__ . '/admin_priv.php';
}
// Get user type and name
$user_type = $_SESSION['user_type'] ?? 'admin';
$display_name = '';
$display_role = '';

if ($user_type == 'admin') {
    $display_name = isset($_SESSION['admin']) ? $_SESSION['admin'] : 'Admin';
    $display_role = 'Administrator';
} else {
    $display_name = isset($_SESSION['subadmin_name']) ? $_SESSION['subadmin_name'] : $_SESSION['subadmin'];
    $display_role = 'Sub Administrator';
}
?>

<style>
    :root {
        --primary-color: #FF5F15;
        --primary-hover: #e04e0b;
        --sidebar-bg: #1a1a1a;
        --sidebar-dark: #0f0f0f;
    }

    #sidebar-wrapper {
        width: 280px;
        height: 100vh;
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1050;
        transition: transform 0.3s ease-in-out;
        background: linear-gradient(180deg, var(--sidebar-dark) 0%, var(--sidebar-bg) 100%);
        border-right: 1px solid #2a2a2a;
    }

    @media (max-width: 991.98px) {
        #sidebar-wrapper { transform: translateX(-100%); }
        #sidebar-wrapper.active { transform: translateX(0); }
    }

    .logo-section {
        padding: 30px 20px 25px;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        background: rgba(255, 95, 21, 0.03);
        position: relative;
    }

    .sidebar-logo {
        height: 60px;
        width: auto;
        max-width: 180px;
        object-fit: contain;
        filter: brightness(1.1);
        transition: all 0.3s ease;
    }

    .sidebar-logo:hover {
        transform: scale(1.05);
        filter: brightness(1.2);
    }

    .nav-link {
        color: #9ca3af;
        padding: 13px 20px;
        border-radius: 10px;
        margin: 0 12px 6px;
        transition: all 0.25s ease;
        font-weight: 500;
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        text-decoration: none;
        position: relative;
        overflow: hidden;
    }
    
    .nav-link::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 3px;
        background: var(--primary-color);
        transform: scaleY(0);
        transition: transform 0.25s ease;
    }

    .nav-link:hover {
        color: #fff;
        background: rgba(255, 95, 21, 0.08);
        transform: translateX(3px);
    }

    .nav-link:hover::before {
        transform: scaleY(1);
    }

    .nav-link.active {
        background: linear-gradient(90deg, rgba(255, 95, 21, 0.15) 0%, rgba(255, 95, 21, 0.05) 100%) !important;
        color: #fff !important;
        font-weight: 600;
    }

    .nav-link.active::before {
        transform: scaleY(1);
    }

    .nav-link.active i {
        color: var(--primary-color);
    }

    .nav-link i {
        width: 24px;
        text-align: center;
        margin-right: 12px;
        font-size: 1.1rem;
        transition: all 0.25s ease;
    }

    .menu-header {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: #6b7280;
        margin: 30px 0 12px 20px;
        font-weight: 700;
        padding-left: 12px;
        border-left: 2px solid rgba(255, 95, 21, 0.3);
    }

    .user-profile-section {
        margin-top: auto;
        padding: 20px 12px 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
        background: rgba(0, 0, 0, 0.2);
    }

    .user-profile-link {
        display: flex;
        align-items: center;
        padding: 12px;
        border-radius: 10px;
        transition: all 0.3s ease;
        text-decoration: none;
        color: #fff;
    }

    .user-profile-link:hover {
        background: rgba(255, 95, 21, 0.1);
    }

    .user-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-hover) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1rem;
        color: white;
        margin-right: 12px;
        box-shadow: 0 4px 12px rgba(255, 95, 21, 0.3);
    }

    .user-info {
        flex: 1;
        min-width: 0;
    }

    .user-name {
        font-weight: 600;
        font-size: 0.95rem;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: block;
        margin-bottom: 2px;
    }

    .user-role {
        font-size: 0.75rem;
        color: #9ca3af;
    }

    .nav-scroll::-webkit-scrollbar {
        width: 6px;
    }

    .nav-scroll::-webkit-scrollbar-track {
        background: transparent;
    }

    .nav-scroll::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
    }

    .nav-scroll::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .close-sidebar-btn {
        position: absolute;
        top: 35px;
        right: 15px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #9ca3af;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
    }

    .close-sidebar-btn:hover {
        background: rgba(255, 95, 21, 0.2);
        border-color: var(--primary-color);
        color: var(--primary-color);
    }

    .mobile-menu-toggle {
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1049;
        width: 50px;
        height: 50px;
        border-radius: 12px;
        background: var(--primary-color);
        border: none;
        color: white;
        font-size: 1.3rem;
        box-shadow: 0 4px 20px rgba(255, 95, 21, 0.4);
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .mobile-menu-toggle:hover {
        background: var(--primary-hover);
        transform: scale(1.05);
        box-shadow: 0 6px 24px rgba(255, 95, 21, 0.5);
    }

    @media (min-width: 992px) {
        .mobile-menu-toggle {
            display: none;
        }
    }
</style>

<div id="sidebar-wrapper" class="d-flex flex-column">
    <!-- Logo Section -->
    <div class="logo-section">
        <a href="dashboard.php">
            <img src="assets/images/logo.jpeg" alt="Campus Social" class="sidebar-logo">
        </a>
        <button class="close-sidebar-btn d-lg-none" id="close-sidebar">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- Navigation -->
    <div class="overflow-auto nav-scroll flex-grow-1" style="scrollbar-width: thin;">
        <ul class="nav nav-pills flex-column mb-auto pt-3">
            
            <?php if (has_priv('dashboard')): ?>
            <div class="menu-header">Overview</div>
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-th-large"></i> Dashboard
                </a>
            </li>
            <?php endif; ?>

            <?php if (has_priv('events')): ?>
            <div class="menu-header">Event Management</div>
            <li class="nav-item">
                <a href="events.php?view=live" class="nav-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'live') ? 'active' : ''; ?>">
                    <i class="fas fa-broadcast-tower"></i> Live / Upcoming
                </a>
            </li>
            <li class="nav-item">
                <a href="events.php?view=past" class="nav-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'past') ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i> Past Events
                </a>
            </li>
            <li class="nav-item">
                <a href="events.php?view=archive" class="nav-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'archive') ? 'active' : ''; ?>">
                    <i class="fas fa-archive"></i> Archived (>30 Days)
                </a>
            </li>
            <?php endif; ?>

            <?php if (has_priv('manage_users') || is_main_admin()): ?>
            <div class="menu-header">User Administration</div>
            <?php if (has_priv('manage_users')): ?>
            <li class="nav-item">
                <a href="users.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i> Manage Users
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_main_admin()): ?>
            <li class="nav-item">
                <a href="manage_subadmins.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_subadmins.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user-shield"></i> Sub-admins
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
        </ul>
    </div>

    <!-- User Profile Section -->
    <div class="user-profile-section">
        <div class="dropdown">
            <a href="#" class="user-profile-link dropdown-toggle" id="dropdownUser1" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($display_name, 0, 1)); ?>
                </div>
                <div class="user-info">
                    <span class="user-name"><?php echo $display_name; ?></span>
                    <span class="user-role"><?php echo $display_role; ?></span>
                </div>
                <i class="fas fa-chevron-down" style="color: #6b7280; font-size: 0.8rem;"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="dropdownUser1">
                <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Sign out</a></li>
            </ul>
        </div>
    </div>
</div>

<div id="sidebar-backdrop" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); z-index: 1040;"></div>

<!-- Mobile Menu Toggle Button -->
<button class="mobile-menu-toggle d-lg-none" id="mobile-menu-toggle">
    <i class="fas fa-bars"></i>
</button>

<script>
    const sidebar = document.getElementById('sidebar-wrapper');
    const backdrop = document.getElementById('sidebar-backdrop');
    const closeBtn = document.getElementById('close-sidebar');
    const menuToggle = document.getElementById('mobile-menu-toggle');
    
    function openSidebar() {
        sidebar.classList.add('active');
        backdrop.style.display = 'block';
    }
    
    function closeSidebar() {
        sidebar.classList.remove('active');
        backdrop.style.display = 'none';
    }

    if(menuToggle) menuToggle.addEventListener('click', openSidebar);
    if(closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if(backdrop) backdrop.addEventListener('click', closeSidebar);
</script>