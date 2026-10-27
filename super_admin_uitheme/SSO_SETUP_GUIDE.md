# SSO Setup Guide for LGU Research System

## Overview
This guide explains how to set up Single Sign-On (SSO) between the LGU Research System and other LGU2 systems (RACS, Ordinance, Minutes, etc.).

## Important: How SSO Works

**SSO does NOT change your user role.** When you use SSO to access another system:
- ✅ You bypass the login page (no need to enter username/password)
- ✅ Your session is automatically created in the target system
- ✅ **You maintain your original role** (admin, research, etc.)
- ❌ You do NOT become a "superadmin" - you stay as your current role

**Example:**
- You login to LGU Research System as "Jane Doe" with role "admin"
- You click "Records and Correspondence" in Super Admin Panel
- You are automatically logged into RACS as "Jane Doe" with role "admin"
- Your role stays "admin" - it doesn't change to "superadmin"

## Current Status

### ✅ LGU Research System (Your System)
- **Status**: Fully configured and working
- **URL**: https://lgu.jampzdev.com
- **SSO Files**: 
  - `super_admin_uitheme/super_sso.php` - SSO functions
  - `sso_login.php` - SSO receiver
- **Accepted Roles**: `admin`, `research`, `superadmin`
- **Session Variables**: Uses `$_SESSION['user_type']` for role checking

### ⚠️ Other LGU2 Systems
These systems need to be updated to accept SSO from your system:

1. **RACS** (https://racs.jampzdev.com)
2. **Ordinance and Resolution** (https://lgu2ordinance.jampzdev.com)
3. **Minutes Section** (https://lgu2minutes.jampzdev.com)
4. **Agenda and Briefing** (https://lgu2agenda.jampzdev.com)
5. **Committee Management** (https://lgu2committee.jampzdev.com)
6. **Committee Journal** (https://lgu2committeejournal.jampzdev.com)
7. **Journal** (https://lgu2journal.jampzdev.com)
8. **Archive Section** (https://lgu2archives.jampzdev.com)

## What Each System Needs

### For RACS (Laravel System)

**Current Error:** "SSO access is only available for superadmins"

**What this means:** RACS is checking if your role is exactly "superadmin", but your actual role is "admin" or "research". The error message is misleading - it should say "SSO access denied" instead.

**The Fix:** RACS needs to accept users with "admin" and "research" roles, not just "superadmin":

1. **Update SSO Route Handler** (likely in `app/Http/Controllers/SSOController.php` or similar):
   ```php
   // Change from:
   if ($tokenData['role'] !== 'superadmin') {
       return redirect()->route('login')->with('error', 'SSO access is only available for superadmins');
   }
   
   // To:
   $allowedRoles = ['admin', 'research', 'superadmin'];
   if (!in_array($tokenData['role'] ?? '', $allowedRoles) && 
       !in_array($tokenData['user_type'] ?? '', $allowedRoles)) {
       return redirect()->route('login')->with('error', 'Access denied');
   }
   ```

2. **Update Session Creation**:
   ```php
   session([
       'user_id' => $tokenData['user_id'],
       'username' => $tokenData['name'],
       'email' => $tokenData['email'],
       'role' => $tokenData['role'] ?? $tokenData['user_type'] ?? 'admin',
       'user_type' => $tokenData['user_type'] ?? $tokenData['role'] ?? 'admin',
       'fullname' => $tokenData['name'],
       'sso_login' => true
   ]);
   ```

### For Vanilla PHP Systems (Ordinance, Minutes, Agenda, etc.)

Each system needs their `sso_login.php` file updated:

1. **Update Role Verification** (around line 43-47):
   ```php
   // Change from:
   if (!isset($tokenData['role']) || $tokenData['role'] !== 'superadmin') {
       header('Location: ' . $login_url . '?error=sso_not_superadmin');
       exit;
   }
   
   // To:
   $allowedRoles = ['admin', 'research', 'superadmin'];
   $userRole = $tokenData['user_type'] ?? $tokenData['role'] ?? '';
   if (!in_array($userRole, $allowedRoles)) {
       header('Location: ' . $login_url . '?error=sso_access_denied');
       exit;
   }
   ```

2. **Update Session Variables** (around line 53-60):
   ```php
   // Change from:
   $_SESSION['role'] = 'superadmin';
   
   // To:
   $_SESSION['user_id'] = $tokenData['user_id'] ?? 0;
   $_SESSION['username'] = $tokenData['name'] ?? 'Admin';
   $_SESSION['email'] = $tokenData['email'] ?? '';
   $_SESSION['user_type'] = $tokenData['user_type'] ?? $tokenData['role'] ?? 'admin';
   $_SESSION['role'] = $tokenData['role'] ?? 'admin';
   $_SESSION['fullname'] = $tokenData['name'] ?? 'Admin';
   $_SESSION['sso_login'] = true;
   ```

## SSO Token Structure

When you click on another system from the Super Admin Dashboard, a JWT token is generated with:

```json
{
  "user_id": 123,
  "email": "admin@example.com",
  "name": "Jane Doe",
  "role": "admin",
  "user_type": "admin",
  "target": "racs",
  "source": "research",
  "iat": 1234567890,
  "exp": 1234567950
}
```

**Important Notes:**
- The token includes both `role` and `user_type` fields to ensure compatibility
- **The role in the token is YOUR ACTUAL ROLE** (admin, research, etc.)
- The token does NOT change your role to "superadmin"
- The receiving system should create a session with the SAME role from the token

## SSO Secret Key

All systems MUST use the same secret key. It lives only in each system's `.env` file as `SSO_SECRET=` (this system, the other vanilla PHP systems, and RACS). Never paste it into code or docs; share it privately and rotate it together.

## Testing SSO

1. **Login to LGU Research System** with an admin or research account
2. **Click "Super Admin Panel"** in the sidebar
3. **Click on any system card** (e.g., "Records and Correspondence")
4. **Expected Result**: You should be automatically logged into that system
5. **If Error**: The target system needs to be updated as described above

## Quick Fix for System Administrators

Send this to the administrators of other systems:

```
Your system needs to accept SSO tokens from users with 'admin' and 'research' roles,
not just 'superadmin'. Update your sso_login.php or SSO controller to accept these roles:

$allowedRoles = ['admin', 'research', 'superadmin'];
$userRole = $tokenData['user_type'] ?? $tokenData['role'] ?? '';
if (!in_array($userRole, $allowedRoles)) {
    // deny access
}
```

## Troubleshooting

### "No SSO token provided"
- Check that the URL includes `?token=...` parameter
- Verify the token is being generated (check browser network tab)

### "SSO access is only available for superadmins"
- The target system needs to update their role checking logic
- They need to accept 'admin' and 'research' roles

### "Invalid SSO token"
- Check that all systems use the same SSO secret key
- Verify the token hasn't expired (60 second validity)

### Token expires too quickly
- Tokens are valid for 60 seconds by default
- This is intentional for security
- If needed, can be increased in `super_sso.php` line 100

## Contact

For issues with specific systems, contact their respective administrators:
- **RACS**: RACS development team
- **Other Systems**: LGU2 system administrators
