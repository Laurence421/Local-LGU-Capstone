/**
 * System Lock - Inactivity Detection and Auto-Lock System
 * Professional & Modern Implementation for LGU Research System
 */

class SystemLock {
    constructor() {
        this.isEnabled = false;
        this.inactivityTimer = null;
        this.warningTimer = null;
        this.isLocked = false;
        this.isWarning = false;
        this.warningCountdown = 10; // 10 seconds warning
        this.inactivityTimeout = 30000; // 30 seconds in milliseconds
        this.currentCountdown = 0;
        this.countdownInterval = null;
        
        // Activity event types to monitor
        this.activityEvents = [
            'mousedown', 'mousemove', 'keypress', 'scroll', 
            'touchstart', 'touchmove', 'click', 'keydown'
        ];
        
        this.init();
    }

    init() {
        this.loadSettings();
        this.createSystemLockOverlay();
        this.setupEventListeners();
        this.updateToggleState();
        
        // Check if system was locked before refresh
        this.checkLockStateOnLoad();
        
        if (this.isEnabled) {
            this.startActivityMonitoring();
        }
        
    }
    
    checkLockStateOnLoad() {
        const lockActive = localStorage.getItem('systemLockActive');
        const lockTime = localStorage.getItem('systemLockTime');
        
        if (lockActive === 'true' && lockTime) {
            // System was locked, restore the lock state
            this.isLocked = true;
            this.isWarning = true;
            
            const overlay = document.getElementById('systemLockOverlay');
            const passwordSection = document.getElementById('passwordSection');
            const passwordInput = document.getElementById('systemLockPassword');
            const passwordError = document.getElementById('passwordError');
            const continueBtn = document.getElementById('continueBtn');
            const verifyBtn = document.getElementById('verifyBtn');
            
            if (overlay) {
                // Reset form to initial state
                if (passwordSection) passwordSection.style.display = 'none';
                if (passwordInput) {
                    passwordInput.value = '';
                    passwordInput.type = 'password';
                }
                if (passwordError) passwordError.style.display = 'none';
                if (continueBtn) continueBtn.style.display = 'inline-flex';
                if (verifyBtn) {
                    verifyBtn.style.display = 'none';
                    verifyBtn.innerHTML = '<i class="fas fa-check"></i> Verify & Continue';
                    verifyBtn.disabled = false;
                }
                
                overlay.classList.add('active');
                
                // Focus on continue button
                setTimeout(() => {
                    if (continueBtn) continueBtn.focus();
                }, 100);
            }
        }
    }

    loadSettings() {
        // Load setting from localStorage or session
        this.isEnabled = localStorage.getItem('systemLockEnabled') === 'true';
    }

    saveSettings() {
        localStorage.setItem('systemLockEnabled', this.isEnabled.toString());
    }

    createSystemLockOverlay() {
        // Create the overlay HTML
        const overlay = document.createElement('div');
        overlay.id = 'systemLockOverlay';
        overlay.className = 'system-lock-overlay';
        overlay.innerHTML = `
            <div class="system-lock-dialog" role="dialog" aria-labelledby="lockTitle" aria-describedby="lockMessage">
                <div class="system-lock-icon">
                    <i class="fas fa-lock" aria-hidden="true"></i>
                </div>
                <h2 id="lockTitle" class="system-lock-title">Session Lock</h2>
                <p id="lockMessage" class="system-lock-message">
                    Your session has been locked due to inactivity for security purposes. Please choose to continue or logout.
                </p>
                <div class="system-lock-timer" id="lockTimer" aria-live="polite" style="display: none;">10</div>
                
                <!-- Password Input Section -->
                <div class="system-lock-password-section" id="passwordSection" style="display: none;">
                    <div class="password-input-group">
                        <label for="systemLockPassword" class="password-label">
                            <i class="fas fa-key"></i> Enter Your Password
                        </label>
                        <div class="password-input-wrapper">
                            <input type="password" id="systemLockPassword" class="password-input" placeholder="Enter your login password" autocomplete="current-password">
                            <button type="button" class="password-toggle-btn" onclick="systemLock.togglePasswordVisibility()">
                                <i class="fas fa-eye" id="passwordToggleIcon"></i>
                            </button>
                        </div>
                        <div class="password-error" id="passwordError" style="display: none;"></div>
                    </div>
                </div>
                
                <div class="system-lock-actions">
                    <button class="system-lock-btn continue" id="continueBtn" onclick="systemLock.showPasswordInput()">
                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        Continue
                    </button>
                    <button class="system-lock-btn verify" id="verifyBtn" onclick="systemLock.verifyAndContinue()" style="display: none;">
                        <i class="fas fa-check" aria-hidden="true"></i>
                        Verify & Continue
                    </button>
                    <button class="system-lock-btn logout" id="logoutBtn" onclick="systemLock.logoutUser()">
                        <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                        Logout
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        // Add keyboard navigation
        overlay.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.continuSession();
            } else if (e.key === 'Enter') {
                // Only handle Enter if NOT in password input
                if (e.target.id === 'systemLockPassword') {
                    // Let password input handler deal with this
                    return;
                }
                
                if (e.target.classList.contains('logout')) {
                    this.logoutUser();
                } else {
                    this.continuSession();
                }
            }
        });
    }

    setupEventListeners() {
        // Activity detection
        this.activityEvents.forEach(event => {
            document.addEventListener(event, this.resetInactivityTimer.bind(this), {
                passive: true,
                capture: true
            });
        });

        // Window focus/blur events
        window.addEventListener('focus', () => {
            if (this.isEnabled && !this.isLocked) {
                this.resetInactivityTimer();
            }
        });

        window.addEventListener('blur', () => {
            if (this.isEnabled) {
                this.resetInactivityTimer();
            }
        });

        // Page visibility API
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.isEnabled && !this.isLocked) {
                this.resetInactivityTimer();
            }
        });
    }

    startActivityMonitoring() {
        if (!this.isEnabled) return;
        
        this.resetInactivityTimer();
    }

    stopActivityMonitoring() {
        this.clearTimers();
    }

    resetInactivityTimer() {
        if (!this.isEnabled || this.isLocked) return;

        this.clearTimers();
        
        // Start inactivity timer
        this.inactivityTimer = setTimeout(() => {
            this.showWarning();
        }, this.inactivityTimeout);
    }

    clearTimers() {
        if (this.inactivityTimer) {
            clearTimeout(this.inactivityTimer);
            this.inactivityTimer = null;
        }
        
        if (this.warningTimer) {
            clearTimeout(this.warningTimer);
            this.warningTimer = null;
        }
        
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
            this.countdownInterval = null;
        }
    }

    showWarning() {
        if (this.isLocked) return;
        
        this.isWarning = true;
        this.isLocked = true;
        
        // Save lock state to localStorage for persistence across refreshes
        localStorage.setItem('systemLockActive', 'true');
        localStorage.setItem('systemLockTime', Date.now().toString());
        
        // Show overlay
        const overlay = document.getElementById('systemLockOverlay');
        const timer = document.getElementById('lockTimer');
        const message = document.getElementById('lockMessage');
        const passwordSection = document.getElementById('passwordSection');
        const passwordInput = document.getElementById('systemLockPassword');
        const passwordError = document.getElementById('passwordError');
        const continueBtn = document.getElementById('continueBtn');
        const verifyBtn = document.getElementById('verifyBtn');
        
        // Reset password section
        if (passwordSection) passwordSection.style.display = 'none';
        if (passwordInput) passwordInput.value = '';
        if (passwordError) passwordError.style.display = 'none';
        if (continueBtn) continueBtn.style.display = 'inline-flex';
        if (verifyBtn) verifyBtn.style.display = 'none';
        
        message.textContent = `Your session has been locked due to inactivity for security purposes. Please choose to continue or logout.`;
        timer.style.display = 'none'; // Hide timer since we're not auto-logging out
        
        overlay.classList.add('active');
        
        // Focus on continue button for accessibility
        setTimeout(() => {
            document.getElementById('continueBtn').focus();
        }, 100);
        
        // Add escape key listener
        document.addEventListener('keydown', this.handleEscapeKey);
        
        // Dispatch custom event for other components
        window.dispatchEvent(new CustomEvent('systemLockShown', {
            detail: { autoLogout: false }
        }));
    }

    handleEscapeKey = (e) => {
        if (e.key === 'Escape' && this.isLocked) {
            this.continuSession();
        }
    }

    showPasswordInput() {
        const passwordSection = document.getElementById('passwordSection');
        const continueBtn = document.getElementById('continueBtn');
        const verifyBtn = document.getElementById('verifyBtn');
        const passwordInput = document.getElementById('systemLockPassword');
        const passwordError = document.getElementById('passwordError');
        
        // Hide continue button, show password section and verify button
        continueBtn.style.display = 'none';
        passwordSection.style.display = 'block';
        verifyBtn.style.display = 'inline-flex';
        
        // Clear previous errors
        passwordError.style.display = 'none';
        passwordInput.value = '';
        
        // Remove any existing Enter key handler to prevent duplicates
        if (this.passwordEnterHandler) {
            passwordInput.removeEventListener('keydown', this.passwordEnterHandler);
        }
        
        // Create and store the Enter key handler
        this.passwordEnterHandler = (e) => {
            if (e.key === 'Enter') {
                e.preventDefault(); // Prevent default form submission
                e.stopPropagation(); // Stop event from bubbling to overlay
                this.verifyAndContinue();
            }
        };
        
        // Add Enter key handler
        passwordInput.addEventListener('keydown', this.passwordEnterHandler);
        
        // Focus on password input
        setTimeout(() => {
            passwordInput.focus();
        }, 100);
    }
    
    togglePasswordVisibility() {
        const passwordInput = document.getElementById('systemLockPassword');
        const toggleIcon = document.getElementById('passwordToggleIcon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
    }
    
    async verifyAndContinue() {
        const passwordInput = document.getElementById('systemLockPassword');
        const passwordError = document.getElementById('passwordError');
        const verifyBtn = document.getElementById('verifyBtn');
        const password = passwordInput.value.trim();
        
        if (!password) {
            passwordError.textContent = 'Please enter your password.';
            passwordError.style.display = 'block';
            return;
        }
        
        // Hide previous errors
        passwordError.style.display = 'none';
        
        // Show loading state
        const originalText = verifyBtn.innerHTML;
        verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
        verifyBtn.disabled = true;
        
        try {
            // Send password verification request
            const formData = new FormData();
            formData.append('action', 'verify_password');
            formData.append('password', password);
            
            const response = await fetch((window.APP_BASE || '') + '/contents/legislative-research-section/verify_password.php', {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            
            const text = await response.text();
            let data;
            
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response text:', text);
                throw new Error('Invalid response from server');
            }
            
            if (data.success) {
                // Password verified, continue session
                this.continuSession();
            } else if (data.logout) {
                // Too many wrong passwords: the server ended the session
                window.location.href = (window.APP_BASE || '') + '/index.php';
            } else {
                passwordError.textContent = data.message || 'Invalid password. Please try again.';
                passwordError.style.display = 'block';
                passwordInput.value = ''; // Clear password on error
                verifyBtn.innerHTML = originalText;
                verifyBtn.disabled = false;
                passwordInput.focus(); // Refocus on input
            }
        } catch (error) {
            console.error('Error verifying password:', error);
            passwordError.textContent = 'An error occurred. Please try again.';
            passwordError.style.display = 'block';
            passwordInput.value = ''; // Clear password on error
            verifyBtn.innerHTML = originalText;
            verifyBtn.disabled = false;
            passwordInput.focus(); // Refocus on input
        }
    }
    
    continuSession() {
        this.hideOverlay();
        this.resetInactivityTimer();
        
        // Dispatch custom event
        window.dispatchEvent(new CustomEvent('systemLockContinued'));
        
        // Show brief notification
        this.showNotification('Session continued', 'success');
    }

    hideOverlay() {
        const overlay = document.getElementById('systemLockOverlay');
        const timer = document.getElementById('lockTimer');
        const passwordSection = document.getElementById('passwordSection');
        const passwordInput = document.getElementById('systemLockPassword');
        const passwordError = document.getElementById('passwordError');
        const continueBtn = document.getElementById('continueBtn');
        const verifyBtn = document.getElementById('verifyBtn');
        
        overlay.classList.remove('active');
        timer.classList.remove('warning');
        
        // Reset all form elements to initial state
        if (passwordSection) passwordSection.style.display = 'none';
        if (passwordInput) {
            passwordInput.value = '';
            passwordInput.type = 'password';
        }
        if (passwordError) passwordError.style.display = 'none';
        if (continueBtn) continueBtn.style.display = 'inline-flex';
        if (verifyBtn) {
            verifyBtn.style.display = 'none';
            verifyBtn.innerHTML = '<i class="fas fa-check"></i> Verify & Continue';
            verifyBtn.disabled = false;
        }
        
        // Reset password toggle icon
        const toggleIcon = document.getElementById('passwordToggleIcon');
        if (toggleIcon) {
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
        
        // Remove password Enter key handler
        if (this.passwordEnterHandler && passwordInput) {
            passwordInput.removeEventListener('keydown', this.passwordEnterHandler);
            this.passwordEnterHandler = null;
        }
        
        this.isLocked = false;
        this.isWarning = false;
        this.clearTimers();
        
        // Clear lock state from localStorage
        localStorage.removeItem('systemLockActive');
        localStorage.removeItem('systemLockTime');
        
        // Remove escape key listener
        document.removeEventListener('keydown', this.handleEscapeKey);
        
        // Return focus to body
        document.body.focus();
    }

    logoutUser() {
        // Dispatch custom event before logout
        window.dispatchEvent(new CustomEvent('systemLockLogout'));
        
        // Show logging out message
        const message = document.getElementById('lockMessage');
        const timer = document.getElementById('lockTimer');
        const actions = document.querySelector('.system-lock-actions');
        
        message.textContent = 'Logging out...';
        timer.style.display = 'none';
        actions.style.display = 'none';
        
        // Redirect to logout
        setTimeout(() => {
            window.location.href = (window.APP_BASE || '') + '/logout.php';
        }, 1500);
    }


    toggleSystemLock() {
        this.isEnabled = !this.isEnabled;
        this.saveSettings();
        this.updateToggleState();
        
        if (this.isEnabled) {
            this.startActivityMonitoring();
            this.showNotification('System Lock Enabled', 'success', 'Your session will be locked after 30 seconds of inactivity.');
        } else {
            this.stopActivityMonitoring();
            this.hideOverlay(); // Hide overlay if currently shown
            this.showNotification('System Lock Disabled', 'info', 'Your session will remain active without automatic locking.');
        }
        
        
        // Dispatch custom event
        window.dispatchEvent(new CustomEvent('systemLockToggled', {
            detail: { enabled: this.isEnabled }
        }));
    }

    updateToggleState() {
        const toggle = document.getElementById('systemLockToggle');
        
        if (toggle) {
            if (this.isEnabled) {
                toggle.classList.add('active');
            } else {
                toggle.classList.remove('active');
            }
        }
    }

    showNotification(message, type = 'info', subtitle = '') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `system-lock-notification ${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <div class="notification-text">
                    <div class="notification-title">${message}</div>
                    ${subtitle ? `<div class="notification-subtitle">${subtitle}</div>` : ''}
                </div>
            </div>
        `;
        
        // Add notification styles
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000000;
            background: ${type === 'success' ? 'rgba(39, 174, 96, 0.95)' : 
                          type === 'error' ? 'rgba(231, 76, 60, 0.95)' : 
                          'rgba(17, 82, 114, 0.95)'};
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            font-family: 'Montserrat', sans-serif;
            min-width: 320px;
            max-width: 400px;
            transform: translateX(100%);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            opacity: 0;
        `;
        
        document.body.appendChild(notification);
        
        // Animate in with smooth transition
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                notification.style.transform = 'translateX(0)';
                notification.style.opacity = '1';
            });
        });
        
        // Remove after delay with smooth transition
        setTimeout(() => {
            notification.style.transform = 'translateX(100%)';
            notification.style.opacity = '0';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 400);
        }, 3000);
    }

    // Public methods for external control
    enable() {
        if (!this.isEnabled) {
            this.toggleSystemLock();
        }
    }

    disable() {
        if (this.isEnabled) {
            this.toggleSystemLock();
        }
    }

    isActive() {
        return this.isEnabled;
    }

    isCurrentlyLocked() {
        return this.isLocked;
    }

    // Method to update timeout (in seconds)
    setInactivityTimeout(seconds) {
        this.inactivityTimeout = seconds * 1000;
        
        if (this.isEnabled) {
            this.resetInactivityTimer();
        }
    }
}

// Initialize System Lock
let systemLock;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    systemLock = new SystemLock();
    
    // Make it globally available
    window.systemLock = systemLock;
});

// Fallback initialization if DOMContentLoaded already fired
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        if (!window.systemLock) {
            systemLock = new SystemLock();
            window.systemLock = systemLock;
        }
    });
} else {
    systemLock = new SystemLock();
    window.systemLock = systemLock;
}
