# Super Admin UI Theme Kit for Vanilla PHP Systems

A complete superadmin panel package that enables SSO (Single Sign-On) navigation between all LGU2 systems.

## Quick Start

### 1. Copy Files to Your System

Copy the entire `super_admin_uitheme` folder to your system's root or public directory:

```
your-system/
├── super_admin_uitheme/
│   ├── super_sso.php       # SSO functions
│   ├── super_sidebar.php   # Sidebar component
│   ├── super_dashboard.php # Superadmin dashboard
│   ├── super_header.php    # Header component
│   ├── super_styles.css    # Styles
│   ├── sso_login.php       # SSO receiver template
│   └── README.md           # This file
├── sso_login.php           # Copy from super_admin_uitheme/
├── dashboard.php
└── index.php
```

### 2. Copy SSO Login Receiver

Copy `sso_login.php` to your system's root directory. This file receives SSO tokens from other systems.

### 3. Include in Your Pages

```php
<?php
session_start();

// Define which system this is (optional - auto-detects from URL)
define('CURRENT_SYSTEM', 'agenda'); // ordinance, minutes, agenda, etc.

// Include the sidebar to the superadmin dashboard
include 'super_admin_uitheme/super_sidebar.php';
?>

<!DOCTYPE html>
<html>
<head>
    <!-- Include FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Include Styles -->
    <link rel="stylesheet" href="super_admin_uitheme/super_styles.css">
</head>
<body>
    <!-- Sidebar button -->
    <button onclick="openSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Your page content -->
</body>
</html>
```

## Session Requirements

For the sidebar to work correctly, your login system must set these session variables:

```php
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['email'] = $user['email'];
$_SESSION['role'] = 'superadmin'; // REQUIRED for SSO access
```

## System Keys

| System | Key | URL |
|--------|-----|-----|
| Records and Correspondence | `racs` | https://racs.jampzdev.com |
| Ordinance and Resolution | `ordinance` | https://lgu2ordinance.jampzdev.com |
| Minutes Section | `minutes` | https://lgu2minutes.jampzdev.com |
| Agenda and Briefing | `agenda` | https://lgu2agenda.jampzdev.com |
| Committee Management | `committee` | https://lgu2committee.jampzdev.com |
| Committee Journal | `committeejournal` | https://lgu2committeejournal.jampzdev.com |
| Journal | `journal` | https://lgu2journal.jampzdev.com |
| Archive Section | `archives` | https://lgu2archives.jampzdev.com |
| Research Section | `research` | https://lgu.jampzdev.com |

## SSO Secret Key

**IMPORTANT:** All systems must use the same SSO secret key for SSO to work.

The key is set as `SSO_SECRET` in each system's `.env` file (never in code). Share it privately with the other teams.

If you change this key, you must update it in:
1. RACS `.env` file (`SSO_SECRET=`)
2. All vanilla PHP systems' `super_sso.php`
3. All vanilla PHP systems' `sso_login.php`

## How SSO Works

1. **Superadmin clicks a system link** in the sidebar
2. **JWT token is generated** containing user info (id, email, name, role)
3. **Token is valid for 60 seconds** and signed with the shared secret
4. **Target system validates token** and creates a session
5. **Superadmin is logged in** without entering credentials

## Customization

### Adding System-Specific Navigation

Edit `super_sidebar.php` and add navigation items in the "REGULAR USER VIEW" section:

```php
<?php else: ?>
<!-- ========== REGULAR USER VIEW ========== -->

<!-- Dashboard -->
<div class="nav-group">
    <a href="dashboard.php" class="nav-link-single active">
        <span class="nav-icon"><i class="fas fa-gauge-high"></i></span>
        <span>Dashboard</span>
    </a>
</div>

<!-- Add your system-specific links here -->
<div class="nav-group">
    <a href="your-page.php" class="nav-link-single">
        <span class="nav-icon"><i class="fas fa-file"></i></span>
        <span>Your Page</span>
    </a>
</div>

<?php endif; ?>
```

## Troubleshooting

### SSO Not Working

1. **Check the SSO secret key** - Must be identical across all systems
2. **Check session variables** - `$_SESSION['role']` must be `'superadmin'`
3. **Check token expiry** - Tokens expire after 60 seconds
4. **Check URLs** - System URLs in `super_sso.php` must be correct

### Sidebar Not Showing

1. Ensure `session_start()` is called before including the sidebar
2. Check that all CSS files are properly linked
3. Verify FontAwesome is loaded

## Using the Super Admin Dashboard

The `super_dashboard.php` file provides a complete superadmin dashboard with:

- **System navigation cards** - Quick SSO links to all 9 systems
- **Stats cards** - Customizable statistics display
- **Activity chart** - Visual representation of system activity
- **Quick actions** - One-click access to common tasks
- **Activity log** - Recent system activity table

### Setting Up the Dashboard

1. Copy `super_dashboard.php` to your system root
2. Update database connection settings:

```php
$db_host = 'your-host';
$db_name = 'your-database';
$db_user = 'your-username';
$db_pass = 'your-password';
```

3. Uncomment and customize the stats queries for your tables:

```php
$result = $mysqli->query("SELECT COUNT(*) as count FROM your_main_table");
$stats['total_records'] = $result->fetch_assoc()['count'];
```

4. Link to the dashboard from your login redirect:

```php
// After successful superadmin login
if ($_SESSION['role'] === 'superadmin') {
    header('Location: super_admin_uitheme/super_dashboard.php');
} else {
    header('Location: dashboard.php');
}
```

## Support

For issues or questions, contact the RACS development team.
