<?php
require_once __DIR__ . '/bootstrap.php';

$user = require_role(['admin']);
$pdo = db();
$tabs = ['overview', 'verify', 'prices', 'trades'];
$tab = (string) ($_GET['tab'] ?? 'overview');
if (!in_array($tab, $tabs, true)) {
    $tab = 'overview';
}
$formError = null;
$action = (string) ($_POST['action'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf('admin-dashboard.php?tab=' . $tab);
    $tabMap = ['verify_coop' => 'verify', 'toggle_active' => 'verify', 'hide_listing' => 'prices'];
    if (isset($tabMap[$action])) {
        $tab = $tabMap[$action];
    }
    try {
        switch ($action) {
            case 'verify_coop':
                $targetId = (int) ($_POST['user_id'] ?? 0);
                $decision = (string) ($_POST['decision'] ?? '');
                $note = posted('verification_note', 500);
                if (!in_array($decision, ['approved', 'rejected'], true)) {
                    throw new RuntimeException('Choose approve or reject.');
                }
                if ($decision === 'rejected' && $note === '') {
                    throw new RuntimeException('A rejected permit needs a note the cooperative can read.');
                }
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET verification_status = ?, verification_note = ?, verified_at = IF(? = 'approved', NOW(), NULL)
                     WHERE id = ? AND role = 'cooperative'"
                );
                $stmt->execute([$decision, $note !== '' ? $note : null, $decision, $targetId]);
                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('Cooperative account not found.');
                }
                flash('success', $decision === 'approved'
                    ? 'Cooperative approved. They can list products and send quotes.'
                    : 'Cooperative rejected. They will see your note on their portal.');
                redirect('admin-dashboard.php?tab=verify');

            case 'toggle_active':
                $targetId = (int) ($_POST['user_id'] ?? 0);
                if ($targetId === (int) $user['id']) {
                    throw new RuntimeException('You cannot suspend your own admin account.');
                }
                $stmt = $pdo->prepare('UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = ? AND role <> \'admin\'');
                $stmt->execute([$targetId]);
                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('That account cannot be changed.');
                }
                flash('success', 'Account access updated.');
                redirect('admin-dashboard.php?tab=verify');

            case 'hide_listing':
                $productId = (int) ($_POST['product_id'] ?? 0);
                $stmt = $pdo->prepare(
                    "SELECT p.id, p.coop_id, p.category, p.name, p.price, u.organization
                     FROM products p
                     JOIN users u ON u.id = p.coop_id
                     WHERE p.id = ? AND p.is_active = 1 AND p.category <> 'agri_supply'"
                );
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                if (!$product) {
                    throw new RuntimeException('That listing is no longer available.');
                }
                if (!price_above_market((float) $product['price'], category_market_average($pdo, (string) $product['category']))) {
                    throw new RuntimeException('That listing is within 15 percent of the Ibaan average.');
                }
                $upd = $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ? AND is_active = 1');
                $upd->execute([$productId]);
                if ($upd->rowCount() === 0) {
                    throw new RuntimeException('That listing is no longer available.');
                }
                record_price_snapshot($pdo);
                send_message(
                    $pdo,
                    $user,
                    (int) $product['coop_id'],
                    'Your listing ' . $product['name'] . ' is hidden until the price is closer to the Ibaan average.'
                );
                flash('success', 'Listing hidden. The cooperative can show it again after lowering the price.');
                redirect('admin-dashboard.php?tab=prices');

            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (RuntimeException $ex) {
        $formError = $ex->getMessage();
    } catch (Throwable $ex) {
        $formError = 'Something went wrong while saving. Please try again.';
    }
}

$chart = build_price_chart($pdo);
$board = market_board($pdo);
$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'cooperative' AND verification_status = 'pending'")->fetchColumn();
$coopCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'cooperative' AND verification_status = 'approved' AND is_active = 1")->fetchColumn();
$buyerCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'buyer' AND is_active = 1")->fetchColumn();
$tradeCount = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status <> 'cancelled'")->fetchColumn();
$gmv = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE order_status <> 'cancelled'")->fetchColumn();
$fulfilled = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'fulfilled'")->fetchColumn();

$accounts = $pdo->query(
    "SELECT id, role, organization, full_name, email, barangay, municipality, business_type, permit_path,
            verification_status, verification_note, is_active, created_at
     FROM users
     WHERE role <> 'admin'
     ORDER BY FIELD(verification_status, 'pending', 'rejected', 'approved'), created_at DESC"
)->fetchAll();

$listings = $pdo->query(
    "SELECT p.*, u.organization,
            (SELECT AVG(x.price) FROM products x WHERE x.category = p.category AND x.is_active = 1) AS market_avg
     FROM products p
     JOIN users u ON u.id = p.coop_id
     WHERE p.is_active = 1 AND p.category <> 'agri_supply'
     ORDER BY p.category, p.price DESC"
)->fetchAll();

$orders = $pdo->query(
    "SELECT o.*, b.organization AS buyer_name, c.organization AS coop_name
     FROM orders o
     JOIN users b ON b.id = o.buyer_id
     JOIN users c ON c.id = o.coop_id
     ORDER BY o.id DESC
     LIMIT 40"
)->fetchAll();

$pageTitle = 'Registry';
$layout = 'app';
$navKey = $tab;
$needsChart = $tab === 'overview' || $tab === 'prices';
require __DIR__ . '/header.php';
?>
<div class="page-head">
    <p class="gold-kicker">Ibaan registry</p>
    <h1>Registry</h1>
</div>
<?php if ($formError): ?><div class="alert alert-danger"><?= e($formError) ?></div><?php endif; ?>

<?php if ($tab === 'overview'): ?>
    <div class="stat-grid mb-3">
        <article class="stat-tile"><span>Verified cooperatives</span><strong><?= (int) $coopCount ?></strong></article>
        <article class="stat-tile"><span>Permits waiting</span><strong><?= (int) $pendingCount ?></strong></article>
        <article class="stat-tile"><span>Buyers</span><strong><?= (int) $buyerCount ?></strong></article>
        <article class="stat-tile"><span>Traded value</span><strong><?= e(peso($gmv)) ?></strong></article>
    </div>
    <p class="muted"><?= (int) $tradeCount ?> orders on record, <?= (int) $fulfilled ?> fulfilled. Prices below are the live Ibaan averages.</p>
    <?php render_price_cards($board); ?>
    <div class="chart-wrap mt-3">
        <canvas id="priceChart" height="90" aria-label="Ibaan price index"></canvas>
    </div>
    <script type="application/json" id="price-data"><?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php elseif ($tab === 'verify'): ?>
    <div class="row g-3">
        <?php foreach ($accounts as $account): ?>
            <?php if ($account['role'] !== 'cooperative' || $account['verification_status'] === 'approved') { continue; } ?>
            <div class="col-lg-6">
                <article class="panel">
                    <div class="split">
                        <h2 class="h5"><?= e($account['organization']) ?></h2>
                        <?= status_badge((string) $account['verification_status']) ?>
                    </div>
                    <p class="mb-1"><?= e($account['full_name']) ?> · <?= e($account['email']) ?></p>
                    <p class="muted"><?= e($account['barangay']) ?>, <?= e($account['municipality']) ?></p>
                    <?php if ($account['permit_path']): ?>
                        <?= file_preview('download.php?type=permit&id=' . (int) $account['id'], (string) $account['permit_path'], 'Permit for ' . $account['organization']) ?>
                    <?php else: ?>
                        <p class="banner banner-bad">No permit file was stored.</p>
                    <?php endif; ?>
                    <form method="post" action="admin-dashboard.php?tab=verify" class="stack-form mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="verify_coop">
                        <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                        <textarea class="form-control" name="verification_note" rows="2" maxlength="500" placeholder="Note, required if you reject"><?= e((string) $account['verification_note']) ?></textarea>
                        <div class="d-flex gap-2">
                            <button class="btn btn-agree" name="decision" value="approved" type="submit">Approve seller</button>
                            <button class="btn btn-outline-agree" name="decision" value="rejected" type="submit">Reject</button>
                        </div>
                    </form>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
    <section class="panel mt-3">
        <h2>Accounts</h2>
        <div class="table-responsive">
            <table class="table agree-table align-middle">
                <thead><tr><th>Organization</th><th>Role</th><th>Place</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td><?= e($account['organization']) ?><br><span class="tiny muted"><?= e($account['email']) ?></span></td>
                        <td><?= e(role_label((string) $account['role'])) ?><br><span class="tiny muted"><?= e(business_label($account['business_type'])) ?></span></td>
                        <td><?= e($account['barangay']) ?>, <?= e($account['municipality']) ?></td>
                        <td><?= status_badge((string) $account['verification_status']) ?> <?= (int) $account['is_active'] === 1 ? '' : status_badge('cancelled') ?></td>
                        <td>
                            <?php if ($account['role'] === 'cooperative' && $account['permit_path']): ?>
                                <a class="btn btn-outline-agree btn-sm" href="download.php?type=permit&amp;id=<?= (int) $account['id'] ?>">Permit</a>
                            <?php endif; ?>
                            <form method="post" action="admin-dashboard.php?tab=verify" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                <button class="btn btn-outline-agree btn-sm" type="submit"><?= (int) $account['is_active'] === 1 ? 'Suspend' : 'Restore' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($tab === 'prices'): ?>
    <?php render_price_cards($board); ?>
    <div class="chart-wrap mt-3 mb-3">
        <canvas id="priceChart" height="90" aria-label="Ibaan price index"></canvas>
    </div>
    <script type="application/json" id="price-data"><?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <section class="panel">
        <h2>Prices to review</h2>
        <p class="muted">A listing is flagged when its price is more than 15 percent above the same-day average for that product in Ibaan.</p>
        <div class="table-responsive">
            <table class="table agree-table">
                <thead><tr><th>Cooperative</th><th>Listing</th><th>Price</th><th>Ibaan average</th><th>Gap</th></tr></thead>
                <tbody>
                <?php foreach ($listings as $listing): ?>
                    <?php
                    $avg = (float) $listing['market_avg'];
                    $gap = $avg > 0 ? (((float) $listing['price'] - $avg) / $avg) * 100 : 0;
                    $flag = $gap > 15;
                    ?>
                    <tr>
                        <td><?= e($listing['organization']) ?></td>
                        <td><?= e(category_label((string) $listing['category'])) ?><br><span class="tiny muted"><?= e($listing['name']) ?></span></td>
                        <td><?= e(peso($listing['price'])) ?> / <?= e($listing['unit']) ?></td>
                        <td><?= e(peso($avg)) ?></td>
                        <td>
                            <?= $flag ? '<span class="status status-bad">+' . e(number_format($gap, 1)) . '%</span>' : e(number_format($gap, 1) . '%') ?>
                            <?php if ($flag): ?>
                                <form method="post" action="admin-dashboard.php?tab=prices" class="mt-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="hide_listing">
                                    <input type="hidden" name="product_id" value="<?= (int) $listing['id'] ?>">
                                    <button class="btn btn-outline-agree btn-sm" type="submit">Hide this listing</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php else: ?>
    <section class="panel">
        <h2>Trade log</h2>
        <div class="table-responsive">
            <table class="table agree-table">
                <thead><tr><th>Order</th><th>Buyer</th><th>Cooperative</th><th>Total</th><th>Stage</th><th>Payment</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= e($order['order_code']) ?><br><span class="tiny muted"><?= e($order['item_summary']) ?></span></td>
                        <td><?= e($order['buyer_name']) ?></td>
                        <td><?= e($order['coop_name']) ?></td>
                        <td><?= e(peso($order['total_amount'])) ?></td>
                        <td><?= status_badge((string) $order['order_status']) ?></td>
                        <td><?= status_badge((string) $order['payment_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
