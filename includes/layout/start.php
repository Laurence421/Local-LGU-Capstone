<?php
/**
 * Shared app-shell layout, part 1: <head>, header and sidebar. Close the page with end.php.
 *
 *   require_once __DIR__ . '/../../auth.php';
 *   $pageTitle = 'Measure Docketing';
 *   include __DIR__ . '/../../includes/layout/start.php';
 *   ... page content inside <div class="container"> ...
 *   include __DIR__ . '/../../includes/layout/end.php';
 *
 * Optional: $pageHead — extra markup for <head> (page-specific <style> or <link>).
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'LGU Research System') ?> - LGU Research System</title>

    <!-- Fonts (preconnect to reduce OTS/CORS warnings) -->
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
    <?= $pageHead ?? '' ?>
</head>
<body>
    <!-- Super Admin Header -->
    <?php include __DIR__ . '/../super_header.php'; ?>

    <!-- Super Admin Sidebar -->
    <?php include __DIR__ . '/../super_sidebar.php'; ?>
