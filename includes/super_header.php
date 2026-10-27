<?php
/**
 * Super Admin UI Theme Kit - Header Component
 * Integrated with LGU Research System
 * 
 * Usage: pages on the shared layout get it from includes/layout/start.php;
 * others: <?php include __DIR__ . '/includes/super_header.php'; ?> (path relative to the page)
 * 
 * Required: User must be logged in (auth.php handles authentication)
 */

// Get user data from LGU session variables
$userName = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'User';
$userAvatar = avatar_url($_SESSION['avatar_url'] ?? '');
$isLoggedIn = isset($_SESSION['user_id']);
?>
<!-- App base path for scripts ("" in production, "/local-lgu-research" locally) -->
<script>window.APP_BASE = <?= json_encode(BASE_URL) ?>;</script>
<?php if (DEMO_MODE): ?>
<?php $demoNotice = $_SESSION['demo_notice'] ?? ''; unset($_SESSION['demo_notice']); ?>
<!-- Portfolio demo banner -->
<div class="demo-banner" role="status" style="background: var(--brand-accent); color: var(--footer-text); text-align: center; font-size: 0.875rem; padding: 0.375rem 1rem;">
    <i class="fas fa-circle-info me-1"></i>
    <?= $demoNotice !== '' ? '<strong>' . e($demoNotice) . '</strong>' : e(DEMO_MESSAGE) ?>
</div>
<?php endif; ?>

<!-- Top Blue Bar with Centered Logo -->
<div class="top-bar d-none d-md-block">
    <div class="top-bar-inner d-flex align-items-center justify-content-center">
        <img class="top-bar-logo" src="<?= e(url('assets/img/LGU2.png')) ?>" alt="QC Logo">
    </div>
</div>

<!-- Header -->
<header class="header d-flex align-items-center">
    <!-- Burger Menu Button -->
    <div class="burger-container">
        <button class="burger-btn" type="button" onclick="openSidebar()">
            <i class="uil uil-bars"></i>
        </button>
    </div>
    
    <!-- Logo & Title -->
    <div class="header-brand d-flex align-items-center">
        <img class="header-logo" src="<?= e(url('assets/img/LGU2.png')) ?>" alt="QC">
        <h1 class="header-title d-none d-sm-block">LOCAL GOVERNMENT UNIT 2</h1>
    </div>
    
    <!-- Right Side Actions -->
    <div class="header-actions ms-auto d-flex align-items-center gap-3">
        
        <!-- Profile Dropdown -->
        <?php if ($isLoggedIn): ?>
        <div class="profile-dropdown dropdown">
            <button class="dropdown-toggle d-flex align-items-center gap-2" type="button" onclick="toggleDropdown(this)">
                <div class="avatar-sm">
                    <img src="<?php echo htmlspecialchars($userAvatar); ?>" alt="Avatar" onerror="this.src='<?= e(url('assets/img/default-avatar.png')) ?>'">
                </div>
                <span class="user-name d-none d-md-inline"><?php echo htmlspecialchars(ucfirst($userName)); ?></span>
                <i class="fas fa-caret-down"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a class="dropdown-item" href="<?= e(url('profile.php')) ?>">
                        <i class="fas fa-user-gear me-2"></i>Profile Settings
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <div class="dropdown-item-text">
                        <div class="system-lock-toggle-container">
                            <div class="system-lock-toggle-label">
                                <i class="fas fa-shield-alt"></i>
                                System Lock
                            </div>
                            <div class="d-flex align-items-center">
                                <div class="toggle-switch" id="systemLockToggle" onclick="window.systemLock?.toggleSystemLock()">
                                    <div class="toggle-slider"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item text-danger" href="#" onclick="showLogoutModal(); return false;">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
        <?php else: ?>
        <a href="<?= e(url('index.php')) ?>" class="btn btn-primary btn-sm">Login</a>
        <?php endif; ?>
    </div>
</header>

<!-- Logout Confirmation Modal -->
<div class="logout-modal" id="logoutModal">
    <div class="logout-modal-backdrop" onclick="hideLogoutModal()"></div>
    <div class="logout-modal-content">
        <div class="logout-modal-header">
            <i class="fas fa-sign-out-alt logout-icon"></i>
            <h3>Confirm Logout</h3>
        </div>
        <div class="logout-modal-body">
            <p>Are you sure you want to logout from your account?</p>
        </div>
        <div class="logout-modal-footer">
            <button class="btn-cancel" onclick="hideLogoutModal()">Cancel</button>
            <button class="btn-logout" onclick="confirmLogout()">Logout</button>
        </div>
    </div>
</div>

<script>

// Dropdown Toggle
function toggleDropdown(button) {
    const dropdown = button.closest('.dropdown');
    const menu = dropdown.querySelector('.dropdown-menu');
    
    // Close all other dropdowns first
    document.querySelectorAll('.dropdown-menu.show').forEach(function(openMenu) {
        if (openMenu !== menu) {
            openMenu.classList.remove('show');
        }
    });
    
    menu.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
            menu.classList.remove('show');
        });
    }
});

// Logout Modal Functions
function showLogoutModal() {
    document.getElementById('logoutModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function hideLogoutModal() {
    document.getElementById('logoutModal').classList.remove('show');
    document.body.style.overflow = '';
}

function confirmLogout() {
    window.location.href = (window.APP_BASE || '') + '/logout.php';
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideLogoutModal();
    }
});
</script>
