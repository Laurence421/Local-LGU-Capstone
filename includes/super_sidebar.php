<?php
/**
 * Super Admin UI Theme Kit - Sidebar Component
 * Integrated with LGU Research System
 *
 * Usage: Include this file in your PHP pages (pages on the shared layout get it from includes/layout/start.php)
 * <?php include __DIR__ . '/includes/super_sidebar.php'; ?>
 *
 * Required: User must be logged in (auth.php handles authentication)
 */

// Get user data from LGU session variables
$userName = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'User';
$userEmail = $_SESSION['email'] ?? '';
$userAvatar = avatar_url($_SESSION['avatar_url'] ?? '');
$userRole = strtolower($_SESSION['user_type'] ?? '');

// Helper function to check role access - adapted for LGU system
if (!function_exists('hasAccess')) {
    function hasAccess($userRole, $allowedRoles) {
        // Admin has access to everything
        if ($userRole === 'admin') {
            return true;
        }
        return in_array($userRole, $allowedRoles);
    }
}
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
            <img src="<?= e(url('assets/img/LGU2.png')) ?>" alt="QC Logo" style="width: 48px; height: 48px; object-fit: contain;">
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
            <?php if (!empty($userEmail)): ?>
            <span class="profile-email"><?php echo htmlspecialchars($userEmail); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <hr class="sidebar-divider">

    <!-- Navigation -->
    <nav class="sidebar-nav" id="sideNav">
        <!-- Dashboard - visible to all users -->
        <div class="nav-group">
            <a href="<?= e(url('dashboard.php')) ?>" class="nav-link-single">
                <span class="nav-icon"><i class="fas fa-gauge-high"></i></span>
                <span>Dashboard</span>
            </a>
        </div>

        <!-- Sections that live in the other LGU2 systems: one link each, opened through SSO (sso_go.php) -->
        <?php
        $otherSystems = [
            ['system' => 'ordinance', 'roles' => ['ordinance', 'admin'], 'icon' => 'fa-gavel', 'label' => 'Ordinance and Resolution'],
            ['system' => 'minutes', 'roles' => ['minutes', 'admin'], 'icon' => 'fa-handshake-angle', 'label' => 'Minutes Section'],
            ['system' => 'agenda', 'roles' => ['agenda', 'records', 'admin'], 'icon' => 'fa-calendar-days', 'label' => 'Agenda and Briefing'],
            ['system' => 'committee', 'roles' => ['committee', 'admin'], 'icon' => 'fa-users', 'label' => 'Committee Management System'],
            ['system' => 'committeejournal', 'roles' => ['journal', 'admin'], 'icon' => 'fa-check-to-slot', 'label' => 'Committee Journal'],
        ];
        foreach ($otherSystems as $other):
            if (!hasAccess($userRole, $other['roles'])) {
                continue;
            }
        ?>
        <div class="nav-group">
            <a href="<?= e(url('sso_go.php?system=' . $other['system'])) ?>" class="nav-link-single">
                <span class="nav-icon"><i class="fas <?= e($other['icon']) ?>"></i></span>
                <span><?= e($other['label']) ?></span>
            </a>
        </div>
        <?php endforeach; ?>

        <!-- Records and Correspondence -->
        <?php if (hasAccess($userRole, ['records', 'admin'])): ?>
        <div class="nav-group">
            <button class="nav-group-toggle" type="button" onclick="toggleNavGroup(this)">
                <span class="nav-icon"><i class="fas fa-folder-open"></i></span>
                <span>Records and Correspondence</span>
                <i class="fas fa-chevron-down nav-caret"></i>
            </button>
            <ul class="nav-sublist">
                <li><a href="<?= e(url('contents/records-and-correspondence/measure-docketing.php')) ?>">Measure Docketing</a></li>
                <li><a href="<?= e(url('contents/records-and-correspondence/categorization-and-classification.php')) ?>">Categorization and Classification</a></li>
                <li><a href="<?= e(url('contents/records-and-correspondence/document-tracking.php')) ?>">Document Tracking</a></li>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Archive Section (lives in the Archives system) -->
        <?php if (hasAccess($userRole, ['archive', 'admin'])): ?>
        <div class="nav-group">
            <a href="<?= e(url('sso_go.php?system=archives')) ?>" class="nav-link-single">
                <span class="nav-icon"><i class="fas fa-box-archive"></i></span>
                <span>Archive Section</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- Research Section -->
        <?php if (hasAccess($userRole, ['research', 'researchstaff', 'admin', 'superadmin'])): ?>
        <div class="nav-group">
            <button class="nav-group-toggle" type="button" onclick="toggleNavGroup(this)">
                <span class="nav-icon"><i class="fas fa-magnifying-glass-chart"></i></span>
                <span>Research Section</span>
                <i class="fas fa-chevron-down nav-caret"></i>
            </button>
            <ul class="nav-sublist">
                <li><a href="<?= e(url('contents/legislative-research-section/draftmeasurestask.php')) ?>">Draft Measures Task</a></li>
                <li><a href="<?= e(url('contents/legislative-research-section/similarity-checking-tool.php')) ?>">Similarity Checking Tool</a></li>
                <li><a href="<?= e(url('contents/legislative-research-section/citationcheckingtool.php')) ?>">Citation Checking Tool</a></li>
                <li><a href="<?= e(url('contents/legislative-research-section/measurecomparisontool.php')) ?>">Measure Comparison Tool</a></li>
                <li><a href="<?= e(url('contents/legislative-research-section/document_management.php')) ?>">Document Management</a></li>
                <?php if ($userRole === 'admin'): ?>
                <li><a href="<?= e(url('contents/legislative-research-section/data_synchronization.php')) ?>">Data Synchronization</a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- User Management -->
        <?php if (hasAccess($userRole, ['research', 'admin', 'superadmin'])): ?>
        <div class="nav-group">
            <button class="nav-group-toggle" type="button" onclick="toggleNavGroup(this)">
                <span class="nav-icon"><i class="fas fa-users"></i></span>
                <span>User Management</span>
                <i class="fas fa-chevron-down nav-caret"></i>
            </button>
            <ul class="nav-sublist">
                <li><a href="<?= e(url('contents/legislative-research-section/create_account.php')) ?>">Create Account</a></li>
                <li><a href="<?= e(url('contents/legislative-research-section/accounts_list.php')) ?>">Accounts List</a></li>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Super Admin Panel -->
        <?php if (hasAccess($userRole, ['research', 'admin', 'superadmin'])): ?>
        <div class="nav-group">
            <a href="<?= e(url('super_admin_uitheme/super_dashboard.php')) ?>" class="nav-link-single">
                <span class="nav-icon"><i class="fas fa-shield-halved"></i></span>
                <span>Super Admin Panel</span>
            </a>
        </div>
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
