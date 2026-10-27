<?php
/**
 * Super Admin UI Demo Page
 * Demonstrates the integration of super_header.php, super_sidebar.php, and super_styles.css
 * with the LGU Research System
 */

// Start session and include authentication
session_start();
require_once __DIR__ . '/auth.php';

$pageTitle = "Super Admin UI Demo";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - LGU Research System</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    
    <!-- Unicons -->
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.0/css/line.css">
    
    <!-- Super Admin Styles -->
    <link href="<?= e(url('assets/css/super_styles.css')) ?>" rel="stylesheet">
    
    <!-- System Lock CSS -->
    <link href="<?= e(url('assets/css/system-lock.css')) ?>" rel="stylesheet">
    
    <style>
        /* Page-specific styles */
        .demo-content {
            padding: 2rem;
            min-height: calc(100vh - var(--header-height));
        }
        
        .demo-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px var(--shadow-color);
        }
        
        .demo-card h2 {
            color: var(--text-primary);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            border-bottom: 2px solid var(--brand-primary);
            padding-bottom: 0.5rem;
        }
        
        .demo-card h3 {
            color: var(--text-primary);
            font-size: 1.2rem;
            font-weight: 600;
            margin-top: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .demo-card p {
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 1rem;
        }
        
        .demo-card ul {
            color: var(--text-secondary);
            line-height: 1.8;
        }
        
        .demo-card code {
            background: var(--bg-tertiary);
            color: var(--brand-secondary);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
        
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .feature-item {
            background: var(--bg-tertiary);
            padding: 1.5rem;
            border-radius: var(--border-radius-sm);
            border-left: 4px solid var(--brand-primary);
        }
        
        .feature-item h4 {
            color: var(--text-primary);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .feature-item p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin: 0;
        }
        
        .btn-demo {
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-secondary));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius-sm);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        
        .btn-demo:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px var(--shadow-lg);
        }
    </style>
</head>
<body>
    <!-- Include Super Admin Header -->
    <?php include __DIR__ . '/includes/super_header.php'; ?>
    
    <!-- Include Super Admin Sidebar -->
    <?php include __DIR__ . '/includes/super_sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="demo-content">
        <div class="container-fluid">
            <div class="demo-card">
                <h2><i class="fas fa-rocket me-2"></i>Super Admin UI Theme Kit - Integration Demo</h2>
                <p>
                    This page demonstrates the successful integration of the Super Admin UI components with the LGU Research System. 
                    The theme kit includes a modern header, collapsible sidebar, and comprehensive styling that works seamlessly with your existing system.
                </p>
            </div>
            
            <div class="demo-card">
                <h2><i class="fas fa-check-circle me-2"></i>Integration Features</h2>
                
                <div class="feature-grid">
                    <div class="feature-item">
                        <h4><i class="fas fa-palette"></i> Theme Toggle</h4>
                        <p>Click the sun/moon icon in the header to switch between light and dark modes. Theme preference is saved in localStorage.</p>
                    </div>
                    
                    <div class="feature-item">
                        <h4><i class="fas fa-bars"></i> Responsive Sidebar</h4>
                        <p>Click the burger menu to open the sidebar. It features smooth animations and role-based navigation.</p>
                    </div>
                    
                    <div class="feature-item">
                        <h4><i class="fas fa-user-circle"></i> Profile Dropdown</h4>
                        <p>Your profile displays with avatar from session. Includes System Lock toggle and logout option.</p>
                    </div>
                    
                    <div class="feature-item">
                        <h4><i class="fas fa-shield-alt"></i> System Lock</h4>
                        <p>Toggle System Lock from the profile dropdown to secure your session without logging out.</p>
                    </div>
                    
                    <div class="feature-item">
                        <h4><i class="fas fa-mobile-alt"></i> Mobile Responsive</h4>
                        <p>Fully responsive design that adapts to all screen sizes with touch-friendly controls.</p>
                    </div>
                    
                    <div class="feature-item">
                        <h4><i class="fas fa-lock"></i> Role-Based Access</h4>
                        <p>Navigation items are filtered based on user role from <code>$_SESSION['user_type']</code>.</p>
                    </div>
                </div>
            </div>
            
            <div class="demo-card">
                <h2><i class="fas fa-code me-2"></i>How to Use in Your Pages</h2>
                
                <h3>1. Include Required Files</h3>
                <p>Add these includes to your PHP pages:</p>
                <pre style="background: var(--bg-tertiary); padding: 1rem; border-radius: 8px; overflow-x: auto;"><code>&lt;?php
// Include authentication
require_once $_SERVER['DOCUMENT_ROOT'] . '/auth.php';
?&gt;

&lt;!-- In your HTML head --&gt;
&lt;link href="/assets/css/super_styles.css" rel="stylesheet"&gt;
&lt;link href="/assets/css/system-lock.css" rel="stylesheet"&gt;

&lt;!-- After opening body tag --&gt;
&lt;?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/super_header.php'; ?&gt;
&lt;?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/super_sidebar.php'; ?&gt;</code></pre>
                
                <h3>2. Session Variables Used</h3>
                <ul>
                    <li><code>$_SESSION['fullname']</code> or <code>$_SESSION['username']</code> - User display name</li>
                    <li><code>$_SESSION['email']</code> - User email (optional)</li>
                    <li><code>$_SESSION['avatar_url']</code> - User avatar path</li>
                    <li><code>$_SESSION['user_type']</code> - User role for navigation filtering</li>
                    <li><code>$_SESSION['user_id']</code> - Checks if user is logged in</li>
                </ul>
                
                <h3>3. User Roles Supported</h3>
                <ul>
                    <li><strong>admin</strong> - Full access to all sections</li>
                    <li><strong>research</strong> / <strong>researchstaff</strong> - Research section access</li>
                    <li><strong>records</strong> - Records and correspondence access</li>
                    <li><strong>minutes</strong> - Minutes section access</li>
                    <li><strong>journal</strong> - Committee journal access</li>
                    <li><strong>ordinance</strong> - Ordinance and resolution access</li>
                    <li><strong>committee</strong> - Committee management access</li>
                    <li><strong>archive</strong> - Archive section access</li>
                    <li><strong>consult</strong> - Public consultation access</li>
                </ul>
            </div>
            
            <div class="demo-card">
                <h2><i class="fas fa-cogs me-2"></i>Customization</h2>
                <p>The theme uses CSS variables for easy customization. Key variables in <code>super_styles.css</code>:</p>
                <ul>
                    <li><code>--brand-primary</code>, <code>--brand-secondary</code>, <code>--brand-accent</code> - Brand colors</li>
                    <li><code>--bg-primary</code>, <code>--bg-secondary</code>, <code>--bg-tertiary</code> - Background colors</li>
                    <li><code>--text-primary</code>, <code>--text-secondary</code> - Text colors</li>
                    <li><code>--sidebar-width</code>, <code>--header-height</code> - Layout dimensions</li>
                </ul>
                <p>Dark mode automatically switches these variables using <code>[data-theme="dark"]</code> selector.</p>
            </div>
            
            <div class="demo-card">
                <h2><i class="fas fa-info-circle me-2"></i>Current Session Info</h2>
                <div style="background: var(--bg-tertiary); padding: 1rem; border-radius: 8px;">
                    <p><strong>User:</strong> <?= htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'N/A') ?></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($_SESSION['email'] ?? 'Not set') ?></p>
                    <p><strong>Role:</strong> <?= htmlspecialchars($_SESSION['user_type'] ?? 'N/A') ?></p>
                    <p><strong>Avatar:</strong> <?= htmlspecialchars($_SESSION['avatar_url'] ?? 'Default') ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- System Lock JavaScript -->
    <script src="<?= e(url('assets/js/system-lock.js')) ?>"></script>
</body>
</html>
