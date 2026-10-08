<?php
require_once __DIR__ . '/bootstrap.php';

$pdo = try_db();
$board = [];
$coops = [];
$stats = ['coops' => 0, 'listings' => 0, 'fulfilled' => 0, 'buyers' => 0];
if ($pdo) {
    $chart = build_price_chart($pdo);
    $board = market_board($pdo);
    $coops = apply_distance(fetch_cooperatives($pdo), 13.8176, 121.1332);
    $stats['coops'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'cooperative' AND verification_status = 'approved' AND is_active = 1")->fetchColumn();
    $stats['listings'] = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
    $stats['fulfilled'] = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'fulfilled'")->fetchColumn();
    $stats['buyers'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'buyer' AND is_active = 1")->fetchColumn();
}

$pageTitle = 'Agricultural marketplace for Ibaan';
$layout = 'public';
$needsChart = (bool) $pdo;
require __DIR__ . '/header.php';
?>
<div class="container">
    <?php if (!$pdo): ?>
        <div class="banner banner-bad mt-4">
            <strong>Database not connected.</strong>
            <p class="mb-2">AGREE needs the MySQL database before the marketplace can load.</p>
            <ol class="mb-0">
                <li>Create a database named <code>agree_db</code> (on InfinityFree, create it in the control panel).</li>
                <li>Import <code>schema.sql</code> with phpMyAdmin.</li>
                <li>Put the host, database name, user, and password in <code>db.php</code>.</li>
            </ol>
        </div>
    <?php endif; ?>

    <section class="hero">
        <div>
            <p class="kicker">Ibaan, Batangas</p>
            <h1>Buy chicken and eggs from local cooperatives.</h1>
            <p class="lede">AGREE shows today's prices, lets a kitchen ask for a quote, and keeps the deposit and delivery on one record.</p>
            <div class="hero-actions">
                <a class="btn btn-gold" href="register.php">Create an account</a>
                <a class="btn btn-outline-agree" href="login.php">Sign in</a>
            </div>
            <p class="hero-note"><i class="fa-solid fa-certificate" aria-hidden="true"></i> A cooperative uploads a CDA certificate or mayor's permit before it can sell.</p>
        </div>
        <aside class="hero-board" aria-label="Ibaan market snapshot">
            <p class="kicker">Today in Ibaan</p>
            <?php if ($board): ?>
                <?php foreach ($board as $item): ?>
                    <div class="hero-stat">
                        <span><i class="fa-solid <?= e($item['icon']) ?>"></i> <?= e($item['label']) ?></span>
                        <strong><?= e($item['avg'] === null ? '—' : peso($item['avg'])) ?></strong>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="hero-stat"><span>Live chicken</span><strong>—</strong></div>
                <div class="hero-stat"><span>Dressed chicken</span><strong>—</strong></div>
                <div class="hero-stat"><span>Table eggs</span><strong>—</strong></div>
            <?php endif; ?>
        </aside>
    </section>

    <section class="section" aria-label="Marketplace totals">
        <div class="stat-grid">
            <article class="stat-tile"><span>Verified cooperatives</span><strong><?= (int) $stats['coops'] ?></strong></article>
            <article class="stat-tile"><span>Active listings</span><strong><?= (int) $stats['listings'] ?></strong></article>
            <article class="stat-tile"><span>Fulfilled orders</span><strong><?= (int) $stats['fulfilled'] ?></strong></article>
            <article class="stat-tile"><span>Buying businesses</span><strong><?= (int) $stats['buyers'] ?></strong></article>
        </div>
    </section>

    <section class="section" id="market">
        <div class="section-head">
            <div>
                <p class="gold-kicker">Prices today</p>
                <h2>Average prices in Ibaan</h2>
            </div>
            <p class="muted mb-0">These averages come from cooperatives that are approved and currently selling.</p>
        </div>
        <?php if ($board): ?>
            <?php render_price_cards($board); ?>
            <div class="chart-wrap mt-3">
                <canvas id="priceChart" height="90" aria-label="Fourteen day Ibaan price index"></canvas>
            </div>
            <script type="application/json" id="price-data"><?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <?php else: ?>
            <div class="empty-state">Prices appear after the database is imported and cooperatives list stock.</div>
        <?php endif; ?>
    </section>

    <section class="section" id="how">
        <div class="section-head">
            <div>
                <p class="gold-kicker">How it works</p>
                <h2>Four simple steps</h2>
            </div>
        </div>
        <div class="steps">
            <article class="step"><em>1</em><h3>Create an account</h3><p>Cooperatives in Ibaan, and kitchens or stores in Batangas, sign up.</p></article>
            <article class="step"><em>2</em><h3>Show a permit</h3><p>A cooperative uploads its certificate. The registry approves it before that cooperative can sell.</p></article>
            <article class="step"><em>3</em><h3>Ask for a price</h3><p>The buyer says what they need. The cooperative replies with a price, a deposit, and a delivery date.</p></article>
            <article class="step"><em>4</em><h3>Receive the order</h3><p>The deposit is checked first. The rest is paid in cash when the order arrives.</p></article>
        </div>
    </section>

    <section class="section">
        <div class="section-head">
            <div>
                <p class="gold-kicker">What you can order</p>
                <h2>Chicken, eggs, and farm supply</h2>
            </div>
        </div>
        <div class="focus-grid">
            <article class="focus-card"><i class="fa-solid fa-drumstick-bite"></i><h3>Live chicken</h3><p>Quoted per head, with a minimum order, and released only after the deposit is verified.</p></article>
            <article class="focus-card"><i class="fa-solid fa-box-open"></i><h3>Dressed chicken</h3><p>Quoted per kilogram for restaurants and institutional kitchens that need chilled birds.</p></article>
            <article class="focus-card"><i class="fa-solid fa-egg"></i><h3>Table eggs</h3><p>Quoted per tray so marts and canteens can compare Ibaan cooperatives on one board.</p></article>
        </div>
    </section>

    <section class="section" id="coops">
        <div class="section-head">
            <div>
                <p class="gold-kicker">Sellers</p>
                <h2>Approved cooperatives in Ibaan</h2>
            </div>
        </div>
        <?php render_coop_cards(array_slice($coops, 0, 6), current_user()); ?>
    </section>
</div>
<?php require __DIR__ . '/footer.php'; ?>
