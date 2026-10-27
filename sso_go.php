<?php
/**
 * Open another LGU2 system. Mints the SSO token at click time (tokens live 60 seconds),
 * so links rendered in the sidebar never carry a stale token.
 * Users who may not use SSO are sent to that system's own login.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/super_admin_uitheme/super_sso.php';

$system = $_GET['system'] ?? '';
$systems = SSO_SYSTEMS;

if (!isset($systems[$system])) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

// The portfolio demo never mints tokens: it opens the system's preview page
if (DEMO_MODE) {
    $target = lgu2_preview_url($system) ?? url('dashboard.php');
} else {
    $target = canUseSSO() ? getSSOUrl($system) : $systems[$system]['url'];
}
header('Location: ' . $target);
exit;
