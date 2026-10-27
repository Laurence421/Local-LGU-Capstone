<?php
require_once __DIR__ . '/../../auth.php';
require_role(['research', 'researchstaff', 'superadmin']); // same roles the sidebar shows this page to
$pageTitle = "Citation Checking Tool";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - LGU Research System</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/super_styles.css')) ?>">
    <!-- Fonts (preconnect to reduce OTS/CORS warnings) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.0/css/line.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
</head>
<body>
<?php include __DIR__ . '/../../includes/super_header.php'; ?>
<?php include __DIR__ . '/../../includes/super_sidebar.php'; ?>

<style>
    .main-content {
        margin-top: 20px;
        padding: 20px;
        min-height: calc(100vh - 100px);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .citation-iframe-container {
        width: 95vw;
        max-width: 1600px;
        height: 85vh;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        background: white;
    }

    .citation-iframe {
        width: 100%;
        height: 100%;
        border: none;
        background: white;
    }

    /* Responsive design */
    @media (max-width: 768px) {
        .main-content {
            padding: 10px;
        }
        
        .citation-iframe-container {
            width: 100%;
            height: calc(100vh - 80px);
            border-radius: 8px;
        }
    }
</style>

<div class="main-content">
    <div class="citation-iframe-container">
    <iframe
        src="https://www.chatbase.co/chatbot-iframe/yFx7nKyTjlrzRa_yc04Jh"
        class="citation-iframe"
        frameborder="0"
        loading="lazy"
        title="Citation Checking Tool"
    ></iframe>
    </div>
</div>

</body>
</html>