<?php
require_once __DIR__ . '/../../auth.php';
require_role(['research', 'researchstaff', 'superadmin']); // same roles the sidebar shows this page to
$pageTitle = "Similarity Checking Tool";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - LGU Research System</title>
    
    <!-- Fonts (with preconnect to reduce CORS/OTS issues) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" crossorigin="anonymous" referrerpolicy="no-referrer">
    
    <!-- Unicons -->
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.0/css/line.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    
    <!-- Super Admin Styles -->
    <link href="<?= e(url('assets/css/super_styles.css')) ?>" rel="stylesheet">
    
    <!-- System Lock CSS -->
    <link href="<?= e(url('assets/css/system-lock.css')) ?>" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../../includes/super_header.php'; ?>
<?php include __DIR__ . '/../../includes/super_sidebar.php'; ?>

<style>
    :root {
        --primary-blue: #115272;
        --primary-blue-dark: #004a8b;
        --primary-blue-light: #0b3d6d;
        --accent-blue: #084157;
        --bg-primary: #f0f4f7;
        --bg-secondary: #f8f9fa;
        --text-primary: #333;
        --text-secondary: #666;
        --success-green: #28a745;
        --shadow-light: 0 4px 6px rgba(0, 0, 0, 0.05);
        --shadow-medium: 0 8px 25px rgba(0, 0, 0, 0.15);
        --shadow-heavy: 0 20px 40px rgba(0, 0, 0, 0.2);
        --border-radius: 16px;
        --border-radius-small: 8px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
        background: var(--bg-primary);
        font-family: 'Montserrat', system-ui, -apple-system, Arial, sans-serif;
        min-height: 100vh;
        margin: 0;
        padding: 0;
        position: relative;
    }

    /* Modern background pattern */
    body::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: 
            radial-gradient(circle at 20% 50%, rgba(17, 82, 114, 0.05) 0%, transparent 50%),
            radial-gradient(circle at 80% 20%, rgba(0, 74, 139, 0.05) 0%, transparent 50%),
            radial-gradient(circle at 40% 80%, rgba(11, 61, 109, 0.05) 0%, transparent 50%);
        pointer-events: none;
        z-index: -1;
    }
    
    .tool-access-container {
        max-width: 1400px;
        width: 100%;
        padding: 40px 20px;
        position: relative;
        margin: 20px auto 40px auto; /* Account for fixed header */
        min-height: calc(100vh - 120px);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* Modern glassmorphism header */
    .page-header {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
        color: white;
        padding: 40px;
        margin-bottom: 30px;
        border-radius: var(--border-radius);
        position: relative;
        overflow: hidden;
        box-shadow: var(--shadow-medium);
    }

    .page-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        pointer-events: none;
    }

    .page-header-content {
        position: relative;
        z-index: 2;
        text-align: center;
    }

    .page-header h1 {
        margin: 0 0 10px 0;
        font-size: 2.5rem;
        font-weight: 700;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        letter-spacing: -0.5px;
    }

    .page-header p {
        margin: 0;
        opacity: 0.9;
        font-size: 1.1rem;
        font-weight: 400;
    }
    
    /* Modern card design with glassmorphism - Landscape Layout */
    .access-notice {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: var(--border-radius);
        padding: 40px;
        box-shadow: var(--shadow-heavy);
        position: relative;
        overflow: hidden;
        transition: var(--transition);
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 40px;
        align-items: center;
        min-height: 500px;
    }

    .access-notice::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: linear-gradient(45deg, transparent, rgba(17, 82, 114, 0.03), transparent);
        animation: shimmer 3s infinite;
        pointer-events: none;
    }

    @keyframes shimmer {
        0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
        100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
    }

    .access-notice:hover {
        transform: translateY(-5px);
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
    }
    
    /* Landscape Layout: Left Section */
    .notice-left {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 20px;
        position: relative;
        z-index: 2;
    }

    .notice-icon {
        position: relative;
        display: inline-block;
        margin-bottom: 30px;
    }

    .notice-icon-bg {
        width: 160px;
        height: 160px;
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-blue-dark) 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
        box-shadow: 0 15px 40px rgba(17, 82, 114, 0.4);
        position: relative;
        overflow: hidden;
    }

    .notice-icon-bg::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        animation: iconShimmer 2s infinite;
    }

    @keyframes iconShimmer {
        0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
        100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
    }

    .notice-icon i {
        font-size: 4rem;
        color: white;
        z-index: 2;
        position: relative;
    }

    .notice-left h3 {
        color: var(--primary-blue-light);
        margin-bottom: 15px;
        font-size: 2.2rem;
        font-weight: 700;
        letter-spacing: -0.5px;
        line-height: 1.2;
    }
    
    .notice-left p {
        color: var(--text-secondary);
        margin-bottom: 0;
        font-size: 1rem;
        line-height: 1.5;
    }

    /* Landscape Layout: Right Section */
    .notice-right {
        padding: 20px;
        position: relative;
        z-index: 2;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    /* Enhanced Security Notice Styling */
    .security-notice {
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.08) 0%, rgba(147, 197, 253, 0.12) 100%);
        border: 1px solid rgba(59, 130, 246, 0.2);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
        backdrop-filter: blur(10px);
        position: relative;
        overflow: hidden;
        box-shadow: 0 4px 15px rgba(59, 130, 246, 0.1);
    }

    .security-notice::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-blue-dark) 100%);
        border-radius: 0 2px 2px 0;
    }


    .security-notice p {
        margin: 0;
        color: var(--text-primary);
        font-size: 0.95rem;
        line-height: 1.5;
        padding-left: 15px;
        position: relative;
    }

    .security-notice strong {
        color: var(--primary-blue-dark);
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    
    /* Modern button group - Landscape */
    .access-buttons {
        display: flex;
        gap: 15px;
        justify-content: flex-start;
        margin-bottom: 30px;
        flex-wrap: wrap;
        position: relative;
        z-index: 2;
    }
    
    .primary-btn, .secondary-btn {
        padding: 16px 28px;
        border-radius: 10px;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.95rem;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: var(--transition);
        border: none;
        cursor: pointer;
        position: relative;
        overflow: hidden;
        min-width: 180px;
        justify-content: center;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .primary-btn::before, .secondary-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        transition: left 0.5s;
    }

    .primary-btn:hover::before, .secondary-btn:hover::before {
        left: 100%;
    }
    
    .primary-btn {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-blue-dark) 100%);
        color: white;
        box-shadow: 0 8px 25px rgba(17, 82, 114, 0.3);
    }
    
    .primary-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(17, 82, 114, 0.4);
    }

    .primary-btn:active {
        transform: translateY(-1px);
    }
    
    .secondary-btn {
        background: rgba(255, 255, 255, 0.9);
        color: var(--primary-blue);
        border: 2px solid var(--primary-blue);
        backdrop-filter: blur(10px);
    }
    
    .secondary-btn:hover {
        background: var(--primary-blue);
        color: white;
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(17, 82, 114, 0.3);
    }
    
    /* Enhanced info section - Landscape */
    .tool-info {
        text-align: left;
        background: rgba(248, 249, 250, 0.8);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 12px;
        margin-top: 0;
        border: 1px solid rgba(255, 255, 255, 0.3);
        position: relative;
        z-index: 2;
        flex: 1;
    }
    
    .tool-info h4 {
        color: var(--primary-blue-light);
        margin-bottom: 25px;
        font-size: 1.3rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .tool-info h4::before {
        content: '⚡';
        font-size: 1.5rem;
    }
    
    .tool-info ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .tool-info li {
        padding: 12px 0;
        padding-left: 35px;
        position: relative;
        color: var(--text-primary);
        font-size: 1rem;
        line-height: 1.5;
        transition: var(--transition);
    }

    .tool-info li:hover {
        color: var(--primary-blue);
        transform: translateX(5px);
    }
    
    .tool-info li::before {
        content: "";
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 20px;
        height: 20px;
        background: linear-gradient(135deg, var(--success-green) 0%, #20c997 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        color: white;
        font-weight: bold;
    }

    .tool-info li::after {
        content: "✓";
        position: absolute;
        left: 6px;
        top: 50%;
        transform: translateY(-50%);
        color: white;
        font-size: 0.8rem;
        font-weight: bold;
    }

    /* Loading and error states */
    .loading-message {
        text-align: center;
        padding: 60px;
        color: var(--text-secondary);
        font-style: italic;
        font-size: 1.1rem;
    }
    
    .error-message {
        background: linear-gradient(135deg, #f8d7da 0%, #f1aeb5 100%);
        color: #721c24;
        padding: 20px;
        border-radius: var(--border-radius-small);
        border: 1px solid #f5c6cb;
        margin-bottom: 20px;
        backdrop-filter: blur(10px);
    }

    /* Responsive design - Landscape Priority */
    @media (max-width: 1200px) {
        .access-notice {
            grid-template-columns: 1fr;
            gap: 30px;
            text-align: center;
        }
        
        .notice-right {
            padding: 10px;
        }
        
        .access-buttons {
            justify-content: center;
        }
        
        .tool-access-container {
            margin-top: 40px;
            margin-bottom: 40px;
        }
    }

    @media (max-width: 768px) {
        body {
            align-items: flex-start;
            padding: 20px 0;
        }
        
        .tool-access-container {
            padding: 20px 15px;
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .access-notice {
            padding: 30px 20px;
            min-height: auto;
        }

        .access-buttons {
            flex-direction: column;
            align-items: center;
        }

        .primary-btn, .secondary-btn {
            width: 100%;
            max-width: 300px;
        }

        .notice-icon-bg {
            width: 120px;
            height: 120px;
        }

        .notice-icon i {
            font-size: 3rem;
        }
        
        .notice-left h3 {
            font-size: 1.8rem;
        }
    }

    @media (max-width: 480px) {
        .page-header h1 {
            font-size: 1.75rem;
        }

        .access-notice h3 {
            font-size: 1.5rem;
        }

        .tool-info {
            padding: 25px 15px;
        }
    }

    /* Accessibility improvements */
    .primary-btn:focus, .secondary-btn:focus {
        outline: 3px solid rgba(17, 82, 114, 0.5);
        outline-offset: 2px;
    }

    @media (prefers-reduced-motion: reduce) {
        * {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
        }
    }
</style>

</head>
<body>

<!-- Main Container -->
<div class="tool-access-container">
    <div class="access-notice">
        <!-- Left Section: Icon and Title -->
        <div class="notice-left">
            <div class="notice-icon">
                <div class="notice-icon-bg">
                    <i class="fa-solid fa-search-plus"></i>
                </div>
            </div>
            <h3>Access Similarity Checking Tool</h3>
            <p>Advanced AI-powered analysis for legislative document comparison and conflict detection.</p>
        </div>
        
        <!-- Right Section: Actions and Info -->
        <div class="notice-right">
            <div class="security-notice">
                <p><strong>Security Notice:</strong> Due to security restrictions, this tool needs to open in a new window to function properly. Choose your preferred access method below.</p>
            </div>
            
            <div class="access-buttons">
                <a href="https://jols5.app.n8n.cloud/webhook/b0546272-69b8-4684-9812-c2d2de0c9965/chat" 
                   target="_blank" 
                   class="primary-btn"
                   aria-label="Launch Similarity Checking Tool in new tab">
                    <i class="fa-solid fa-rocket"></i>
                    Launch Tool
                </a>
                
                <button onclick="openInPopup()" 
                        class="secondary-btn"
                        aria-label="Open Similarity Checking Tool in popup window">
                    <i class="fa-solid fa-window-restore"></i>
                    Popup Window
                </button>
            </div>
            
            <div class="tool-info">
                <h4>What this tool does:</h4>
                <ul>
                    <li>Analyzes pending legislative measures for similarities using advanced AI</li>
                    <li>Provides detailed similarity reports with percentage matches</li>
                    <li>Helps identify potential conflicts or duplications across documents</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    // Enhanced popup functionality with modern UX
    function openInPopup() {
        const url = 'https://jols5.app.n8n.cloud/webhook/b0546272-69b8-4684-9812-c2d2de0c9965/chat';
        const windowName = 'SimilarityCheckingTool';
        
        // Calculate optimal window size with landscape orientation
        const screenWidth = window.screen.availWidth;
        const screenHeight = window.screen.availHeight;
        
        // Force landscape-friendly dimensions
        let windowWidth, windowHeight;
        
        if (screenWidth > screenHeight) {
            // Already landscape screen
            windowWidth = Math.min(1400, screenWidth * 0.85);
            windowHeight = Math.min(800, screenHeight * 0.75);
        } else {
            // Portrait screen - make window more landscape
            windowWidth = Math.min(1200, screenWidth * 0.95);
            windowHeight = Math.min(600, screenHeight * 0.6);
        }
        
        // Ensure minimum landscape ratio (width should be at least 1.5x height)
        const minRatio = 1.5;
        if (windowWidth / windowHeight < minRatio) {
            windowHeight = Math.floor(windowWidth / minRatio);
        }
        
        const left = (screenWidth - windowWidth) / 2;
        const top = (screenHeight - windowHeight) / 2;
        
        const windowFeatures = `width=${windowWidth},height=${windowHeight},left=${left},top=${top},scrollbars=yes,resizable=yes,toolbar=no,menubar=no,location=no,status=no`;
        
        // Show loading indicator
        showLoadingState();
        
        // Open popup window
        const popup = window.open(url, windowName, windowFeatures);
        
        // Focus and handle popup
        if (popup) {
            popup.focus();
            
            // Monitor popup status
            const checkClosed = setInterval(() => {
                if (popup.closed) {
                    clearInterval(checkClosed);
                    hideLoadingState();
                    console.log('Similarity Checking Tool popup closed');
                }
            }, 1000);
            
            // Hide loading after popup opens
            setTimeout(hideLoadingState, 1500);
            
        } else {
            hideLoadingState();
            showModernAlert('Popup Blocked', 'Please allow popups for this site or use the "Launch Tool" button to open in a new tab.');
        }
    }
    
    // Modern loading state management
    function showLoadingState() {
        const buttons = document.querySelectorAll('.primary-btn, .secondary-btn');
        buttons.forEach(btn => {
            btn.style.opacity = '0.7';
            btn.style.pointerEvents = 'none';
            if (btn.textContent.includes('Popup')) {
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Opening...';
            }
        });
    }
    
    function hideLoadingState() {
        const buttons = document.querySelectorAll('.primary-btn, .secondary-btn');
        buttons.forEach(btn => {
            btn.style.opacity = '1';
            btn.style.pointerEvents = 'auto';
        });
        
        // Restore popup button text
        const popupBtn = document.querySelector('.secondary-btn');
        if (popupBtn && popupBtn.innerHTML.includes('Opening')) {
            popupBtn.innerHTML = '<i class="fa-solid fa-window-restore"></i> Popup Window';
        }
    }
    
    // Modern alert system
    function showModernAlert(title, message) {
        // Create modern alert overlay
        const alertOverlay = document.createElement('div');
        alertOverlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        `;
        
        const alertBox = document.createElement('div');
        alertBox.style.cssText = `
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 400px;
            margin: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            text-align: center;
            animation: slideUp 0.3s ease;
        `;
        
        alertBox.innerHTML = `
            <div style="font-size: 2rem; color: #115272; margin-bottom: 15px;">
                <i class="fa-solid fa-exclamation-triangle"></i>
            </div>
            <h3 style="margin: 0 0 15px 0; color: #115272; font-size: 1.3rem;">${title}</h3>
            <p style="margin: 0 0 25px 0; color: #666; line-height: 1.5;">${message}</p>
            <button onclick="this.closest('[style*=fixed]').remove()" style="
                background: linear-gradient(135deg, #115272 0%, #004a8b 100%);
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s ease;
            " onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                Got it
            </button>
        `;
        
        alertOverlay.appendChild(alertBox);
        document.body.appendChild(alertOverlay);
        
        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
            @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        `;
        document.head.appendChild(style);
        
        // Auto-remove after 10 seconds
        setTimeout(() => {
            if (alertOverlay.parentNode) {
                alertOverlay.remove();
            }
        }, 10000);
    }
    
    // Enhanced keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+Shift+S for popup
        if (e.ctrlKey && e.shiftKey && e.key === 'S') {
            e.preventDefault();
            openInPopup();
        }
        
        // Ctrl+Shift+L for launch in new tab
        if (e.ctrlKey && e.shiftKey && e.key === 'L') {
            e.preventDefault();
            const launchBtn = document.querySelector('.primary-btn');
            if (launchBtn) {
                launchBtn.click();
            }
        }
        
        // ESC to close any open alerts
        if (e.key === 'Escape') {
            const alerts = document.querySelectorAll('[style*="position: fixed"]');
            alerts.forEach(alert => {
                if (alert.style.zIndex === '10000') {
                    alert.remove();
                }
            });
        }
    });
    
    // Add hover effects and micro-interactions
    document.addEventListener('DOMContentLoaded', function() {
        // Add entrance animation
        const container = document.querySelector('.tool-access-container');
        if (container) {
            container.style.opacity = '0';
            container.style.transform = 'translateY(30px)';
            container.style.transition = 'all 0.6s ease';
            
            setTimeout(() => {
                container.style.opacity = '1';
                container.style.transform = 'translateY(0)';
            }, 100);
        }
        
        // Add button click effects
        const buttons = document.querySelectorAll('.primary-btn, .secondary-btn');
        buttons.forEach(btn => {
            btn.addEventListener('click', function(e) {
                // Create ripple effect
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.cssText = `
                    position: absolute;
                    width: ${size}px;
                    height: ${size}px;
                    left: ${x}px;
                    top: ${y}px;
                    background: rgba(255, 255, 255, 0.3);
                    border-radius: 50%;
                    transform: scale(0);
                    animation: ripple 0.6s ease-out;
                    pointer-events: none;
                `;
                
                this.appendChild(ripple);
                
                // Remove ripple after animation
                setTimeout(() => ripple.remove(), 600);
            });
        });
        
        // Add CSS for ripple animation
        const rippleStyle = document.createElement('style');
        rippleStyle.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(rippleStyle);
        
        console.log('🚀 Similarity Checking Tool loaded successfully!');
        console.log('💡 Keyboard shortcuts: Ctrl+Shift+S (popup), Ctrl+Shift+L (new tab)');
    });
    
    // Performance monitoring
    window.addEventListener('load', function() {
        const loadTime = performance.now();
        console.log(`⚡ Page loaded in ${Math.round(loadTime)}ms`);
        
        // Track user engagement
        let interactionCount = 0;
        document.addEventListener('click', () => {
            interactionCount++;
            if (interactionCount === 1) {
                console.log('👆 First user interaction detected');
            }
        });
    });
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<!-- System Lock JavaScript -->
<script src="<?= e(url('assets/js/system-lock.js')) ?>"></script>

</body>
</html>
