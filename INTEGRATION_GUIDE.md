# Super Admin UI Integration Guide

Every logged-in page uses the same app shell: the Super Admin header (top bar, logo, profile menu with
System Lock and logout) and the role-filtered sidebar. New pages get it from one shared layout partial.

## A new page

```php
<?php
require_once __DIR__ . '/../../auth.php';          // login check + bootstrap (db, url(), e(), require_role())
require_role(['research']);                         // roles allowed besides admin
$pageTitle = 'Your Page Title';

// ... handle POST / load data here, before any HTML is sent ...

include __DIR__ . '/../../includes/layout/start.php';
?>
<div class="container">
    <div class="card card-body">
        <h2>Your Page Title</h2>
        <!-- page content -->
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/end.php'; ?>
```

- `includes/layout/start.php` writes `<head>` (Montserrat, Bootstrap 5.3.8, Font Awesome 6.5.0, Unicons,
  `super_styles.css`, `system-lock.css`), then includes `super_header.php` and `super_sidebar.php`.
  For page-specific styles, set `$pageHead = '<style>...</style>';` before including it.
- `includes/layout/end.php` loads the Bootstrap bundle and `system-lock.js` and closes the page.
- Content sits in `<div class="container">`, panels are Bootstrap cards (`card card-body`).

## Links and paths

The app runs at `/local-lgu-research` locally and at the site root in production, so never hard-code either.

| Where | Write |
|---|---|
| PHP / HTML | `<?= e(url('contents/legislative-research-section/x.php')) ?>` |
| A stored avatar | `<?= e(avatar_url($user['avatar_url'])) ?>` |
| JavaScript | `(window.APP_BASE \|\| '') + '/contents/...'` (set by `super_header.php`) |
| Another LGU2 system | `<?= e(url('sso_go.php?system=racs')) ?>` (mints the SSO token when clicked) |

## Roles and the sidebar

`super_sidebar.php` shows sections by `$_SESSION['user_type']`; `admin` sees everything. The sidebar only
hides links, so every page must also call `require_role([...])` with the same roles.

| Role | Sees |
|---|---|
| admin | everything, including Data Synchronization |
| research / researchstaff | Research Section (research also: User Management, Super Admin Panel) |
| records | Records and Correspondence, Agenda (SSO link) |
| ordinance, minutes, agenda, committee, journal, archive | an SSO link to their own LGU2 system |

## Pages not yet on the partial

The 12 pages that were moved to the Super Admin UI earlier (dashboard, the research tools, account pages,
Super Admin Panel) still write their own `<head>`. Three of them (`citationcheckingtool.php`,
`create_account.php`, `measurecomparisontool.php`) load no Bootstrap, so moving them onto the partial changes
how they look and needs a visual check first.

## System Lock

System Lock is a privacy screen in the browser: it hides the page until the password is re-entered
(5 wrong tries end the session). The session itself stays active underneath, so it is not a substitute for
logging out on a shared computer.

## Theme

Colours, spacing and radius come from the custom properties at the top of `assets/css/super_styles.css`
(`--brand-primary`, `--card-bg`, `--border-radius`, ...). Use them instead of literal values.
