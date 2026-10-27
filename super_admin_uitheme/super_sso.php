<?php
/**
 * Super Admin SSO Functions for Vanilla PHP Systems
 * 
 * Include this file in your pages to enable SSO functionality.
 * Usage: require_once 'super_admin_uitheme/super_sso.php';
 */

require_once __DIR__ . '/../bootstrap.php';

// SSO Secret Key - MUST be the same across ALL systems. Set SSO_SECRET in .env.
defined('SSO_SECRET') || define('SSO_SECRET', (string) env('SSO_SECRET', ''));
defined('CURRENT_SYSTEM') || define('CURRENT_SYSTEM', env('SYSTEM_KEY', 'research'));

// System URLs - All LGU2 systems
define('SSO_SYSTEMS', [
    'racs' => [
        'name' => 'Records and Correspondence',
        'url' => 'https://racs.jampzdev.com',
        'icon' => 'fa-folder-open',
        'color' => '#28a745'
    ],
    'ordinance' => [
        'name' => 'Ordinance and Resolution',
        'url' => 'https://lgu2ordinance.jampzdev.com',
        'icon' => 'fa-gavel',
        'color' => '#dc3545'
    ],
    'minutes' => [
        'name' => 'Minutes Section',
        'url' => 'https://lgu2minutes.jampzdev.com',
        'icon' => 'fa-handshake-angle',
        'color' => '#17a2b8'
    ],
    'agenda' => [
        'name' => 'Agenda and Briefing',
        'url' => 'https://lgu2agenda.jampzdev.com',
        'icon' => 'fa-calendar-days',
        'color' => '#ffc107'
    ],
    'committee' => [
        'name' => 'Committee Management',
        'url' => 'https://lgu2committee.jampzdev.com',
        'icon' => 'fa-users',
        'color' => '#6f42c1'
    ],
    'committeejournal' => [
        'name' => 'Committee Journal',
        'url' => 'https://lgu2committeejournal.jampzdev.com',
        'icon' => 'fa-check-to-slot',
        'color' => '#20c997'
    ],
    'journal' => [
        'name' => 'Journal',
        'url' => 'https://lgu2journal.jampzdev.com',
        'icon' => 'fa-book',
        'color' => '#fd7e14'
    ],
    'archives' => [
        'name' => 'Archive Section',
        'url' => 'https://lgu2archives.jampzdev.com',
        'icon' => 'fa-box-archive',
        'color' => '#6c757d'
    ],
    'research' => [
        'name' => 'Research Section',
        'url' => 'https://lgu.jampzdev.com',
        'icon' => 'fa-magnifying-glass-chart',
        'color' => '#0d6efd'
    ]
]);

/**
 * Check if the current user may use SSO to open the other LGU2 systems.
 * (Not limited to superadmins: admin and research staff may too.)
 */
function canUseSSO() {
    $userRole = $_SESSION['user_type'] ?? $_SESSION['role'] ?? '';
    return in_array($userRole, ['admin', 'research', 'superadmin']);
}

/** @deprecated Use canUseSSO(); kept because other systems copied this file. */
function isSuperAdmin() {
    return canUseSSO();
}

/**
 * Generate SSO JWT Token for navigation to another system
 */
function generateSSOToken($targetSystem) {
    if (!canUseSSO() || SSO_SECRET === '' || DEMO_MODE) {
        return null;
    }
    
    $header = base64UrlEncode(json_encode([
        'alg' => 'HS256',
        'typ' => 'JWT'
    ]));
    
    $payload = base64UrlEncode(json_encode([
        'user_id' => $_SESSION['user_id'] ?? 0,
        'email' => $_SESSION['email'] ?? '',
        'name' => $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin',
        'role' => $_SESSION['user_type'] ?? $_SESSION['role'] ?? 'admin',
        'user_type' => $_SESSION['user_type'] ?? 'admin',
        'target' => $targetSystem,
        'aud' => $targetSystem,
        'source' => getCurrentSystemKey(),
        'iss' => getCurrentSystemKey(),
        'jti' => bin2hex(random_bytes(16)),
        'iat' => time(),
        'exp' => time() + 60 // 60 second expiry
    ]));
    
    $signature = base64UrlEncode(
        hash_hmac('sha256', $header . '.' . $payload, SSO_SECRET, true)
    );
    
    return $header . '.' . $payload . '.' . $signature;
}

/**
 * Get SSO URL for a target system
 */
function getSSOUrl($systemKey) {
    $systems = SSO_SYSTEMS;
    
    if (!isset($systems[$systemKey])) {
        return '#';
    }

    // Our own system: no token needed
    if ($systemKey === getCurrentSystemKey()) {
        return url('dashboard.php');
    }

    // The portfolio demo never mints tokens: it opens the system's preview page instead
    if (DEMO_MODE) {
        return lgu2_preview_url($systemKey) ?? url('dashboard.php');
    }
    
    // For RACS (Laravel), use the SSO route
    if ($systemKey === 'racs') {
        $token = generateSSOToken($systemKey);
        return $systems[$systemKey]['url'] . '/sso-receive?token=' . $token;
    }
    
    // For vanilla PHP systems
    $token = generateSSOToken($systemKey);
    return $systems[$systemKey]['url'] . '/sso_login.php?token=' . $token;
}

/**
 * Get current system key based on URL or define it manually
 */
function getCurrentSystemKey() {
    // Override this in your system by defining CURRENT_SYSTEM before including this file
    if (defined('CURRENT_SYSTEM')) {
        return CURRENT_SYSTEM;
    }
    
    // Auto-detect from hostname
    $host = $_SERVER['HTTP_HOST'] ?? '';
    
    if (strpos($host, 'racs') !== false) return 'racs';
    if (strpos($host, 'ordinance') !== false) return 'ordinance';
    if (strpos($host, 'minutes') !== false) return 'minutes';
    if (strpos($host, 'agenda') !== false) return 'agenda';
    if (strpos($host, 'committeejournal') !== false) return 'committeejournal';
    if (strpos($host, 'committee') !== false) return 'committee';
    if (strpos($host, 'journal') !== false) return 'journal';
    if (strpos($host, 'archives') !== false) return 'archives';
    if (strpos($host, 'lgu.') !== false) return 'research';
    
    return 'unknown';
}

/**
 * Base64 URL-safe encode
 */
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64 URL-safe decode  
 */
function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}
