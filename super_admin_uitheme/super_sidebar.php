<?php
/**
 * Super Admin UI Theme Kit - Sidebar Component
 * 
 * Usage: Include this file in your PHP pages
 * <?php include 'super_admin_uitheme/super_sidebar.php'; ?>
 * 
 * Required: 
 * 1. Include super_sso.php before this file
 * 2. User must be logged in with session variables:
 *    - $_SESSION['fullname'] or $_SESSION['username']
 *    - $_SESSION['email']
 *    - $_SESSION['role'] = 'superadmin' for full access
 */

// Include SSO functions if not already included
if (!function_exists('getSSOUrl')) {
    require_once __DIR__ . '/super_sso.php';
}

// Get user data from session
$userName = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin';
$userEmail = $_SESSION['email'] ?? '';
$userAvatar = avatar_url($_SESSION['avatar_url'] ?? '');
$userRole = $_SESSION['user_type'] ?? $_SESSION['role'] ?? '';
$isSuperAdmin = in_array($userRole, ['admin', 'research', 'superadmin']);

// Get current system to highlight active link
$currentSystem = getCurrentSystemKey();
?>

<!-- Sidebar Backdrop -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebar()"></div>

<!-- Sidebar Offcanvas -->
<aside class="sidebar-offcanvas" id="sidebarOffcanvas">
    <div class="offcanvas-header">
        <button type="button" class="btn-close-sidebar" onclick="closeSidebar()">
            <i class="uil uil-times"></i>
        </button>
    </div>

    <!-- Branding Section -->
    <div class="sidebar-branding">
        <div class="d-flex align-items-center justify-content-center gap-3 px-3 py-2">
            <img src="<?= e(url('assets/img/LGU2.png')) ?>" alt="LGU Logo" style="width: 48px; height: 48px; object-fit: contain;">
            <div>
                <h6 class="mb-0 fw-bold" style="font-size: 13px; line-height: 1.3; color: var(--text-primary);">LOCAL GOVERNMENT UNIT 2</h6>
            </div>
        </div>
    </div>

    <hr class="sidebar-divider my-2">

    <!-- User Profile Section -->
    <div class="sidebar-profile">
        <div class="profile-avatar">
            <img src="<?php echo htmlspecialchars($userAvatar); ?>" alt="Avatar" onerror="this.src='<?= e(url('assets/img/default-avatar.png')) ?>'">
        </div>
        <div class="profile-info">
            <h6 class="profile-name"><?php echo htmlspecialchars(ucfirst($userName)); ?></h6>
            <span class="profile-email"><?php echo htmlspecialchars($userEmail); ?></span>
        </div>
    </div>

    <hr class="sidebar-divider">

    <!-- Navigation -->
    <nav class="sidebar-nav" id="sideNav">
        <?php if ($isSuperAdmin): ?>
        <!-- ========== SUPERADMIN VIEW ========== -->
        
        <!-- super_dashboard -->
        <div class="nav-group">
            <a href="super_dashboard.php" class="nav-link-single <?php echo $currentSystem === getCurrentSystemKey() ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-gauge-high"></i></span>
                <span>Dashboard</span>
            </a>
        </div>

        <!-- Ordinance and Resolution -->
        <div class="nav-group">
            <a href="<?php echo getSSOUrl('ordinance'); ?>" class="nav-link-single <?php echo $currentSystem === 'ordinance' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-gavel"></i></span>
                <span>Ordinance and Resolution</span>
            </a>
        </div>

        <!-- Minutes Section -->
        <div class="nav-group">
            <a href="<?php echo getSSOUrl('minutes'); ?>" class="nav-link-single <?php echo $currentSystem === 'minutes' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-handshake-angle"></i></span>
                <span>Minutes Section</span>
            </a>
        </div>

        <!-- Agenda and Briefing -->
        <div class="nav-group">
            <a href="<?php echo getSSOUrl('agenda'); ?>" class="nav-link-single <?php echo $currentSystem === 'agenda' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-calendar-days"></i></span>
                <span>Agenda and Briefing</span>
            </a>
        </div>

        <!-- Committee Management -->
        <div class="nav-group">
            <a href="<?php echo getSSOUrl('committee'); ?>" class="nav-link-single <?php echo $currentSystem === 'committee' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-users"></i></span>
                <span>Committee Management</span>
            </a>
        </div>

        <!-- Committee Journal -->
        <div class="nav-group">
            <a href="<?php echo getSSOUrl('committeejournal'); ?>" class="nav-link-single <?php echo $currentSystem === 'committeejournal' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-check-to-slot"></i></span>
                <span>Committee Journal</span>
            </a>
        </div>

        <!-- Journal -->
        <div class="nav-group">
            <a href="<?php echo getSSOUrl('journal'); ?>" class="nav-link-single <?php echo $currentSystem === 'journal' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-book"></i></span>
                <span>Journal</span>
            </a>
        </div>

        <!-- Records and Correspondence -->
        <div class="nav-group">
            <a href="<?php echo getSSOUrl('racs'); ?>" class="nav-link-single <?php echo $currentSystem === 'racs' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-folder-open"></i></span>
                <span>Records and Correspondence</span>
            </a>
        </div>

        <!-- Archive Section -->
        <div class="nav-group">
            <a href="<?php echo getSSOUrl('archives'); ?>" class="nav-link-single <?php echo $currentSystem === 'archives' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-box-archive"></i></span>
                <span>Archive Section</span>
            </a>
        </div>

        <!-- Research Section -->
        <div class="nav-group">
            <a href="<?php echo getSSOUrl('research'); ?>" class="nav-link-single <?php echo $currentSystem === 'research' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-magnifying-glass-chart"></i></span>
                <span>Research Section</span>
            </a>
        </div>

        <?php else: ?>
        <!-- ========== REGULAR USER VIEW ========== -->
        
        <!-- super_dashboard -->
        <div class="nav-group">
            <a href="super_dashboard.php" class="nav-link-single active">
                <span class="nav-icon"><i class="fas fa-gauge-high"></i></span>
                <span>Dashboard</span>
            </a>
        </div>

        <!-- Add system-specific navigation here based on the current system -->
        
        <?php endif; ?>
    </nav>
</aside>

<script>
// Sidebar Toggle Functions
function openSidebar() {
    document.getElementById('sidebarOffcanvas').classList.add('show');
    document.getElementById('sidebarBackdrop').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    document.getElementById('sidebarOffcanvas').classList.remove('show');
    document.getElementById('sidebarBackdrop').classList.remove('show');
    document.body.style.overflow = '';
}

// Nav Group Toggle (for collapsible menus)
function toggleNavGroup(button) {
    const navGroup = button.closest('.nav-group');
    navGroup.classList.toggle('open');
}

// Close sidebar on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeSidebar();
    }
});
</script>
