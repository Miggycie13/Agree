<?php
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}

$layout = $layout ?? 'public';
$pageTitle = $pageTitle ?? 'AGREE';
$navKey = $navKey ?? '';
$me = current_user();
$unread = 0;
if ($me && try_db()) {
    $unread = unread_count(db(), (int) $me['id']);
}
$navItems = $me ? navigation((string) $me['role']) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="AGREE connects agricultural cooperatives in Ibaan, Batangas with restaurants, institutional kitchens, and commercial buyers.">
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> · AGREE</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect fill='%231e5631' width='32' height='32' rx='8'/%3E%3Cpath fill='%23e2b657' d='M16 6c1 5-2 8-6 10 5 0 8 2 10 6 1-5 4-8 8-9-4-1-6-4-6-7-2 3-4 3-6 0z'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="agree.css" rel="stylesheet">
</head>
<body class="<?= e($layout) ?>-layout">
<a class="skip-link" href="#content">Skip to content</a>
<?php if ($layout === 'app' && $me): ?>
    <header class="topbar">
        <a class="brand" href="<?= e(dashboard_for((string) $me['role'])) ?>">
            <span class="brand-mark" aria-hidden="true"><i class="fa-solid fa-seedling"></i></span>
            <span>
                <strong>AGREE</strong>
                <small>Ibaan, Batangas</small>
            </span>
        </a>
        <div class="topbar-actions">
            <a class="icon-link" href="messages.php" aria-label="Messages">
                <i class="fa-solid fa-comments"></i>
                <?php if ($unread > 0): ?><em><?= (int) $unread ?></em><?php endif; ?>
            </a>
            <span class="who d-none d-md-inline"><?= e(display_name($me)) ?></span>
            <button class="btn btn-outline-agree d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-label="Open menu">
                <i class="fa-solid fa-bars" aria-hidden="true"></i> Menu
            </button>
        </div>
    </header>
    <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
        <div class="offcanvas-header">
            <h2 class="offcanvas-title" id="mobileNavLabel">AGREE</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <?php render_side_links($navItems, $navKey, $unread); ?>
            <form method="post" action="logout.php" class="mt-3">
                <?= csrf_field() ?>
                <button class="btn btn-outline-agree w-100" type="submit">Sign out</button>
            </form>
        </div>
    </div>
    <div class="app-shell">
        <aside class="side-nav">
            <div class="side-user">
                <span class="avatar"><?= e(strtoupper(substr((string) $me['full_name'], 0, 1))) ?></span>
                <div>
                    <strong><?= e($me['full_name']) ?></strong>
                    <small><?= e(role_label((string) $me['role'])) ?></small>
                </div>
            </div>
            <?php render_side_links($navItems, $navKey, $unread); ?>
            <div class="side-foot">
                <a href="index.php"><i class="fa-solid fa-house"></i> Public site</a>
                <form method="post" action="logout.php">
                    <?= csrf_field() ?>
                    <button type="submit"><i class="fa-solid fa-right-from-bracket"></i> Sign out</button>
                </form>
            </div>
        </aside>
        <div class="app-main">
            <main id="content" class="content">
                <?php render_flashes(); ?>
<?php else: ?>
    <header class="site-nav">
        <div class="container nav-row">
            <a class="brand" href="index.php">
                <span class="brand-mark" aria-hidden="true"><i class="fa-solid fa-seedling"></i></span>
                <span>
                    <strong>AGREE</strong>
                    <small>Ibaan, Batangas</small>
                </span>
            </a>
            <button class="btn btn-outline-agree d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav" aria-controls="publicNav" aria-expanded="false" aria-label="Open menu">
                <i class="fa-solid fa-bars" aria-hidden="true"></i> Menu
            </button>
            <nav class="collapse d-lg-flex public-links" id="publicNav">
                <a href="index.php#market">Market prices</a>
                <a href="index.php#coops">Cooperatives</a>
                <a href="index.php#how">How it works</a>
                <?php if ($me): ?>
                    <a class="btn btn-agree" href="<?= e(dashboard_for((string) $me['role'])) ?>">My account</a>
                <?php else: ?>
                    <a href="login.php">Sign in</a>
                    <a class="btn btn-agree" href="register.php">Create account</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    <main id="content" class="public-main">
        <?php render_flashes(); ?>
<?php endif; ?>
