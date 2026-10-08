<?php
require_once __DIR__ . '/bootstrap.php';

$user = require_role(['cooperative']);
$pdo = db();
$coopId = (int) $user['id'];
$tabs = ['overview', 'inventory', 'rfqs', 'orders', 'payments'];
$tab = (string) ($_GET['tab'] ?? 'overview');
if (!in_array($tab, $tabs, true)) {
    $tab = 'overview';
}
$formError = null;
$action = (string) ($_POST['action'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf('coop-dashboard.php?tab=' . $tab);
    $tabMap = [
        'save_product' => 'inventory',
        'archive_product' => 'inventory',
        'delete_product' => 'inventory',
        'send_quote' => 'rfqs',
        'advance_order' => 'orders',
        'confirm_cod' => 'orders',
        'cancel_order' => 'orders',
        'review_payment' => 'payments',
    ];
    if (isset($tabMap[$action])) {
        $tab = $tabMap[$action];
    }
    try {
        switch ($action) {
            case 'save_product':
                if (!coop_can_trade($user)) {
                    throw new RuntimeException('Your cooperative must be verified before listing products.');
                }
                $productId = (int) ($_POST['product_id'] ?? 0);
                $category = (string) ($_POST['category'] ?? '');
                if (!isset(category_meta()[$category])) {
                    throw new RuntimeException('Choose a valid product category.');
                }
                $name = posted('name', 150);
                $description = posted('description', 2000);
                $unit = posted('unit', 40);
                $price = require_money((string) ($_POST['price'] ?? ''), 'Price');
                if (!is_numeric($_POST['stock'] ?? null)) {
                    throw new RuntimeException('Stock must be a number.');
                }
                $stock = round((float) $_POST['stock'], 2);
                if ($stock < 0 || $stock > 1000000) {
                    throw new RuntimeException('Stock is out of range.');
                }
                $moq = require_qty((string) ($_POST['moq'] ?? ''), 'Minimum order');
                if ($name === '' || $unit === '') {
                    throw new RuntimeException('Enter a product name and unit.');
                }
                $visible = price_above_market($price, category_market_average($pdo, $category)) ? 0 : 1;
                if ($productId > 0) {
                    $own = $pdo->prepare('SELECT id FROM products WHERE id = ? AND coop_id = ?');
                    $own->execute([$productId, $coopId]);
                    if (!$own->fetch()) {
                        throw new RuntimeException('That product is not on your catalog.');
                    }
                    $stmt = $pdo->prepare(
                        'UPDATE products
                         SET category = ?, name = ?, description = ?, unit = ?, price = ?, stock = ?, moq = ?, is_active = ?
                         WHERE id = ? AND coop_id = ?'
                    );
                    $stmt->execute([$category, $name, $description, $unit, $price, $stock, $moq, $visible, $productId, $coopId]);
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO products (coop_id, category, name, description, unit, price, stock, moq, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$coopId, $category, $name, $description, $unit, $price, $stock, $moq, $visible]);
                }
                record_price_snapshot($pdo);
                flash('success', $visible === 1
                    ? 'Product saved. The listing is visible again.'
                    : 'Product saved. The listing stays hidden until the price is closer to the Ibaan average.');
                redirect('coop-dashboard.php?tab=inventory');

            case 'archive_product':
                $productId = (int) ($_POST['product_id'] ?? 0);
                $own = $pdo->prepare('SELECT id, category, price, is_active FROM products WHERE id = ? AND coop_id = ?');
                $own->execute([$productId, $coopId]);
                $product = $own->fetch();
                if (!$product) {
                    throw new RuntimeException('That product is not on your catalog.');
                }
                $turningOn = (int) $product['is_active'] === 0;
                if ($turningOn && price_above_market((float) $product['price'], category_market_average($pdo, (string) $product['category']))) {
                    throw new RuntimeException('This listing stays hidden until the price is closer to the Ibaan average.');
                }
                $stmt = $pdo->prepare('UPDATE products SET is_active = IF(is_active = 1, 0, 1) WHERE id = ? AND coop_id = ?');
                $stmt->execute([$productId, $coopId]);
                record_price_snapshot($pdo);
                flash('success', 'Catalog visibility updated.');
                redirect('coop-dashboard.php?tab=inventory');

            case 'delete_product':
                $productId = (int) ($_POST['product_id'] ?? 0);
                $stmt = $pdo->prepare('DELETE FROM products WHERE id = ? AND coop_id = ?');
                $stmt->execute([$productId, $coopId]);
                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('That product is not on your catalog.');
                }
                record_price_snapshot($pdo);
                flash('success', 'Product deleted. It no longer appears in the catalog or the Ibaan price index.');
                redirect('coop-dashboard.php?tab=inventory');

            case 'send_quote':
                if (!coop_can_trade($user)) {
                    throw new RuntimeException('Verification is still pending, so quotes stay locked.');
                }
                $rfqId = (int) ($_POST['rfq_id'] ?? 0);
                $rfqStmt = $pdo->prepare(
                    "SELECT * FROM rfqs
                     WHERE id = ? AND status IN ('open', 'quoted') AND (coop_id IS NULL OR coop_id = ?)"
                );
                $rfqStmt->execute([$rfqId, $coopId]);
                $rfq = $rfqStmt->fetch();
                if (!$rfq) {
                    throw new RuntimeException('That request is not open for your cooperative.');
                }
                $unitPrice = require_money((string) ($_POST['unit_price'] ?? ''), 'Unit price');
                $percent = (int) ($_POST['deposit_percent'] ?? 50);
                if ($percent < 30 || $percent > 70) {
                    throw new RuntimeException('Deposit share must be between 30 and 70 percent.');
                }
                $delivery = require_date((string) ($_POST['delivery_date'] ?? ''), 'Delivery date');
                $validUntil = require_date((string) ($_POST['valid_until'] ?? ''), 'Quote validity');
                $notes = posted('notes', 2000);
                $dup = $pdo->prepare(
                    "SELECT id FROM quotes WHERE rfq_id = ? AND coop_id = ? AND status IN ('pending', 'accepted')"
                );
                $dup->execute([$rfqId, $coopId]);
                if ($dup->fetch()) {
                    throw new RuntimeException('You already have an active quote on this request.');
                }
                $qty = (float) $rfq['quantity'];
                $total = round($unitPrice * $qty, 2);
                $pdo->beginTransaction();
                $insert = $pdo->prepare(
                    'INSERT INTO quotes
                        (rfq_id, coop_id, unit_price, quantity, total_amount, deposit_percent, delivery_date, valid_until, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([$rfqId, $coopId, $unitPrice, $qty, $total, $percent, $delivery, $validUntil, $notes]);
                $touch = $pdo->prepare("UPDATE rfqs SET status = 'quoted' WHERE id = ? AND status = 'open'");
                $touch->execute([$rfqId]);
                $pdo->commit();
                send_message(
                    $pdo,
                    $user,
                    (int) $rfq['buyer_id'],
                    'Quote posted for ' . $rfq['item_name'] . ': ' . peso($unitPrice) . ' per ' . $rfq['unit']
                    . ' (' . $percent . '% deposit, delivery ' . $delivery . ').'
                );
                flash('success', 'Binding quote sent to the buyer.');
                redirect('coop-dashboard.php?tab=rfqs');

            case 'advance_order':
                $orderId = (int) ($_POST['order_id'] ?? 0);
                $next = (string) ($_POST['next_status'] ?? '');
                if ($next !== 'in_transit') {
                    throw new RuntimeException('Choose a valid delivery step.');
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND coop_id = ? FOR UPDATE');
                $stmt->execute([$orderId, $coopId]);
                $order = $stmt->fetch();
                if (!$order || $order['order_status'] !== 'processing') {
                    throw new RuntimeException('That order is not ready for this step.');
                }
                $upd = $pdo->prepare("UPDATE orders SET order_status = 'in_transit' WHERE id = ?");
                $upd->execute([$orderId]);
                $pdo->commit();
                flash('success', 'Order marked on the way. The buyer confirms when it arrives.');
                redirect('coop-dashboard.php?tab=orders');

            case 'confirm_cod':
                $orderId = (int) ($_POST['order_id'] ?? 0);
                $stmt = $pdo->prepare(
                    "UPDATE orders SET payment_status = 'settled'
                     WHERE id = ? AND coop_id = ? AND order_status = 'fulfilled' AND payment_status = 'cod_balance'"
                );
                $stmt->execute([$orderId, $coopId]);
                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('COD can be confirmed after the order is fulfilled.');
                }
                flash('success', 'Cash on delivery recorded. The order is settled.');
                redirect('coop-dashboard.php?tab=orders');

            case 'cancel_order':
                $orderId = (int) ($_POST['order_id'] ?? 0);
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND coop_id = ? FOR UPDATE');
                $stmt->execute([$orderId, $coopId]);
                $order = $stmt->fetch();
                if (!$order || !in_array($order['order_status'], ['pending_payment', 'processing'], true)) {
                    throw new RuntimeException('This order can no longer be cancelled.');
                }
                if ((int) $order['stock_deducted'] === 1 && $order['product_id']) {
                    $restore = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ? AND coop_id = ?');
                    $restore->execute([$order['quantity'], $order['product_id'], $coopId]);
                }
                $upd = $pdo->prepare("UPDATE orders SET order_status = 'cancelled', stock_deducted = 0 WHERE id = ?");
                $upd->execute([$orderId]);
                $pdo->commit();
                flash('success', 'Order cancelled.');
                redirect('coop-dashboard.php?tab=orders');

            case 'review_payment':
                $paymentId = (int) ($_POST['payment_id'] ?? 0);
                $decision = (string) ($_POST['decision'] ?? '');
                $note = posted('reviewer_note', 500);
                if (!in_array($decision, ['approved', 'rejected'], true)) {
                    throw new RuntimeException('Choose approve or reject.');
                }
                if ($decision === 'rejected' && $note === '') {
                    throw new RuntimeException('Add a note when rejecting a receipt.');
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'SELECT p.*, o.product_id, o.quantity, o.stock_deducted, o.order_status
                     FROM payments p
                     JOIN orders o ON o.id = p.order_id
                     WHERE p.id = ? AND o.coop_id = ? AND p.status = \'pending\'
                     FOR UPDATE'
                );
                $stmt->execute([$paymentId, $coopId]);
                $payment = $stmt->fetch();
                if (!$payment) {
                    throw new RuntimeException('That receipt is not waiting for review.');
                }
                $updPay = $pdo->prepare(
                    'UPDATE payments SET status = ?, reviewer_id = ?, reviewer_note = ?, reviewed_at = NOW() WHERE id = ?'
                );
                $updPay->execute([$decision, $coopId, $note !== '' ? $note : null, $paymentId]);
                if ($decision === 'approved') {
                    if (!(int) $payment['stock_deducted'] && $payment['product_id']) {
                        $stock = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND coop_id = ? AND stock >= ?');
                        $stock->execute([$payment['quantity'], $payment['product_id'], $coopId, $payment['quantity']]);
                        if ($stock->rowCount() === 0) {
                            throw new RuntimeException('Stock is lower than this order. Update inventory before approving.');
                        }
                    }
                    $updOrder = $pdo->prepare(
                        "UPDATE orders
                         SET payment_status = 'deposit_verified', order_status = 'processing', stock_deducted = 1
                         WHERE id = ?"
                    );
                    $updOrder->execute([(int) $payment['order_id']]);
                } else {
                    if ((int) $payment['stock_deducted'] === 1 && $payment['product_id']) {
                        $restore = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ? AND coop_id = ?');
                        $restore->execute([$payment['quantity'], $payment['product_id'], $coopId]);
                    }
                    $updOrder = $pdo->prepare(
                        "UPDATE orders
                         SET payment_status = 'awaiting_deposit', order_status = 'pending_payment', stock_deducted = 0
                         WHERE id = ?"
                    );
                    $updOrder->execute([(int) $payment['order_id']]);
                }
                $pdo->commit();
                flash('success', $decision === 'approved'
                    ? 'Deposit verified. The order is released for slaughter or packing.'
                    : 'Receipt rejected. The buyer can upload another proof of payment.');
                redirect('coop-dashboard.php?tab=payments');

            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $formError = $ex->getMessage();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $formError = 'Something went wrong while saving. Please try again.';
    }
}

$products = $pdo->prepare('SELECT * FROM products WHERE coop_id = ? ORDER BY is_active DESC, category, name');
$products->execute([$coopId]);
$products = $products->fetchAll();

$rfqStmt = $pdo->prepare(
    "SELECT r.*, b.organization AS buyer_name, b.business_type, b.barangay AS buyer_barangay, b.municipality AS buyer_municipality
     FROM rfqs r
     JOIN users b ON b.id = r.buyer_id
     WHERE r.status IN ('open', 'quoted') AND (r.coop_id IS NULL OR r.coop_id = ?)
     ORDER BY r.needed_by"
);
$rfqStmt->execute([$coopId]);
$rfqs = $rfqStmt->fetchAll();
$quoteStmt = $pdo->prepare('SELECT * FROM quotes WHERE coop_id = ?');
$quoteStmt->execute([$coopId]);
$myQuotes = [];
foreach ($quoteStmt as $quote) {
    $myQuotes[(int) $quote['rfq_id']] = $quote;
}

$orderStmt = $pdo->prepare(
    'SELECT o.*, b.organization AS buyer_name
     FROM orders o
     JOIN users b ON b.id = o.buyer_id
     WHERE o.coop_id = ?
     ORDER BY o.delivery_date, o.id DESC'
);
$orderStmt->execute([$coopId]);
$orders = $orderStmt->fetchAll();

$payStmt = $pdo->prepare(
    'SELECT p.*, o.order_code, o.item_summary, o.deposit_amount, o.balance_due, o.order_status, o.payment_status,
            b.organization AS buyer_name
     FROM payments p
     JOIN orders o ON o.id = p.order_id
     JOIN users b ON b.id = o.buyer_id
     WHERE o.coop_id = ?
     ORDER BY p.id DESC'
);
$payStmt->execute([$coopId]);
$payments = $payStmt->fetchAll();

$edit = null;
if ((int) ($_GET['edit'] ?? 0) > 0) {
    foreach ($products as $product) {
        if ((int) $product['id'] === (int) $_GET['edit']) {
            $edit = $product;
        }
    }
}
$productPost = $formError && $action === 'save_product';
$field = function (string $key, string $default = '') use ($productPost, $edit): string {
    if ($productPost) {
        return (string) ($_POST[$key] ?? $default);
    }
    return (string) ($edit[$key] ?? $default);
};

$pageTitle = 'Cooperative account';
$layout = 'app';
$navKey = $tab;
require __DIR__ . '/header.php';
?>
<div class="page-head">
    <p class="gold-kicker"><?= e($user['barangay']) ?>, Ibaan</p>
    <h1><?= e($user['organization']) ?></h1>
</div>
<?php if ($user['verification_status'] !== 'approved'): ?>
    <div class="banner <?= $user['verification_status'] === 'rejected' ? 'banner-bad' : '' ?>">
        <strong><?= $user['verification_status'] === 'rejected' ? 'Permit rejected.' : 'Permit waiting for review.' ?></strong>
        <p class="mb-0">Listing and quoting stay locked until the AGREE registry approves the CDA certificate or business permit<?php if ($user['verification_note']): ?>: <?= e($user['verification_note']) ?><?php endif; ?>.</p>
    </div>
<?php endif; ?>
<?php if ($formError): ?><div class="alert alert-danger"><?= e($formError) ?></div><?php endif; ?>

<?php if ($tab === 'overview'): ?>
    <?php
    $needsReply = false;
    $waitingBuyer = false;
    foreach ($rfqs as $rfq) {
        $mine = $myQuotes[(int) $rfq['id']] ?? null;
        if (!$mine || !in_array($mine['status'], ['pending', 'accepted'], true)) {
            $needsReply = true;
        } elseif ($mine['status'] === 'pending') {
            $waitingBuyer = true;
        }
    }
    $receiptsToCheck = 0;
    foreach ($payments as $payment) {
        if ($payment['status'] === 'pending') {
            $receiptsToCheck++;
        }
    }
    $deliveryWaiting = false;
    foreach ($orders as $order) {
        if (!in_array($order['order_status'], ['cancelled', 'fulfilled'], true)) {
            $deliveryWaiting = true;
        }
    }
    if (!coop_can_trade($user)) {
        $nextTitle = 'Permit still being checked';
        $nextText = 'You can list products and send prices after the registry approves your certificate.';
        $nextHref = 'profile.php';
        $nextButton = 'See your permit';
    } elseif ($receiptsToCheck > 0) {
        $nextTitle = 'Check the deposit';
        $nextText = 'A buyer uploaded a receipt. Confirm it before you prepare the order.';
        $nextHref = 'coop-dashboard.php?tab=payments';
        $nextButton = 'Check the deposit';
    } elseif ($needsReply) {
        $nextTitle = 'Send a price';
        $nextText = 'A buyer is waiting. Reply with your price, the deposit, and the delivery date.';
        $nextHref = 'coop-dashboard.php?tab=rfqs';
        $nextButton = 'Send a price';
    } elseif ($waitingBuyer) {
        $nextTitle = 'Waiting for the buyer';
        $nextText = 'Your price was sent. The order starts when the buyer accepts it.';
        $nextHref = 'coop-dashboard.php?tab=rfqs';
        $nextButton = 'See the request';
    } elseif ($deliveryWaiting) {
        $nextTitle = 'Update the delivery';
        $nextText = 'Mark the order on the way. The buyer confirms when it arrives, then you record the cash.';
        $nextHref = 'coop-dashboard.php?tab=orders';
        $nextButton = 'See orders';
    } else {
        $nextTitle = 'Waiting for a request';
        $nextText = 'Approved buyers can see your products and ask for a price. Keep your list up to date.';
        $nextHref = 'coop-dashboard.php?tab=inventory';
        $nextButton = 'Update products';
    }
    ?>
    <section class="panel mb-3">
        <h2><?= e($nextTitle) ?></h2>
        <p><?= e($nextText) ?></p>
        <a class="btn btn-agree" href="<?= e($nextHref) ?>"><?= e($nextButton) ?></a>
    </section>
    <?php
    $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');
    $monthEnd = (new DateTimeImmutable('first day of next month'))->format('Y-m-d 00:00:00');
    $salesStmt = $pdo->prepare(
        "SELECT COUNT(*) AS order_count,
                COALESCE(SUM(total_amount), 0) AS sales,
                COALESCE(SUM(CASE
                    WHEN payment_status = 'settled' THEN 0
                    WHEN payment_status IN ('deposit_verified', 'cod_balance') THEN balance_due
                    ELSE total_amount
                END), 0) AS unpaid
         FROM orders
         WHERE coop_id = ? AND order_status <> 'cancelled' AND created_at >= ? AND created_at < ?"
    );
    $salesStmt->execute([$coopId, $monthStart, $monthEnd]);
    $sales = $salesStmt->fetch() ?: ['order_count' => 0, 'sales' => 0, 'unpaid' => 0];
    $topStmt = $pdo->prepare(
        "SELECT item_summary, SUM(quantity) AS sold
         FROM orders
         WHERE coop_id = ? AND order_status <> 'cancelled' AND created_at >= ? AND created_at < ?
         GROUP BY item_summary
         ORDER BY sold DESC
         LIMIT 1"
    );
    $topStmt->execute([$coopId, $monthStart, $monthEnd]);
    $topProduct = $topStmt->fetch();
    ?>
    <section class="panel mb-3">
        <h2>This month</h2>
        <?php if ((int) $sales['order_count'] === 0): ?>
            <p class="mb-0">No orders this month.</p>
        <?php else: ?>
            <div class="stat-grid">
                <article class="stat-tile"><span>Orders</span><strong><?= (int) $sales['order_count'] ?></strong></article>
                <article class="stat-tile"><span>Total sales</span><strong><?= e(peso($sales['sales'])) ?></strong></article>
                <article class="stat-tile"><span>Still unpaid</span><strong><?= e(peso($sales['unpaid'])) ?></strong></article>
            </div>
            <?php if ($topProduct): ?>
                <p class="mb-0 mt-3">Most sold: <?= e($topProduct['item_summary']) ?> (<?= e(rtrim(rtrim(number_format((float) $topProduct['sold'], 2), '0'), '.')) ?>).</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
    $activeListings = 0;
    foreach ($products as $product) {
        if ((int) $product['is_active'] === 1) {
            $activeListings++;
        }
    }
    $openRfqCount = count($rfqs);
    $transit = 0;
    $pendingPay = 0;
    foreach ($orders as $order) {
        if ($order['order_status'] === 'in_transit') {
            $transit++;
        }
        if ($order['payment_status'] === 'deposit_review') {
            $pendingPay++;
        }
    }
    ?>
    <div class="stat-grid mb-3">
        <article class="stat-tile"><span>Active listings</span><strong><?= (int) $activeListings ?></strong></article>
        <article class="stat-tile"><span>Open requests</span><strong><?= (int) $openRfqCount ?></strong></article>
        <article class="stat-tile"><span>In transit</span><strong><?= (int) $transit ?></strong></article>
        <article class="stat-tile"><span>Receipts to check</span><strong><?= (int) $pendingPay ?></strong></article>
    </div>
    <div class="row g-3">
        <div class="col-lg-7">
            <section class="panel">
                <h2>Upcoming deliveries</h2>
                <?php $upcoming = array_filter($orders, function ($order) {
                    return !in_array($order['order_status'], ['cancelled', 'fulfilled'], true);
                }); ?>
                <?php if (!$upcoming): ?>
                    <div class="empty-state">No open deliveries.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table agree-table">
                            <thead><tr><th>Order</th><th>Buyer</th><th>When</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($upcoming as $order): ?>
                                <tr>
                                    <td><?= e($order['order_code']) ?><br><span class="tiny muted"><?= e($order['item_summary']) ?></span></td>
                                    <td><?= e($order['buyer_name']) ?></td>
                                    <td><?= e(date('M j', strtotime($order['delivery_date']))) ?></td>
                                    <td><?= order_plain_status($order) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="panel">
                <h2>How payment works</h2>
                <p class="mb-0">The buyer pays a deposit and uploads the receipt. Check it before you prepare the order. They pay the rest in cash when it arrives.</p>
            </section>
        </div>
    </div>

<?php elseif ($tab === 'inventory'): ?>
    <div class="row g-3">
        <div class="col-lg-4">
            <form method="post" action="coop-dashboard.php?tab=inventory" class="panel stack-form" id="product-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_product">
                <input type="hidden" name="product_id" value="<?= (int) ($productPost ? ($_POST['product_id'] ?? 0) : ($edit['id'] ?? 0)) ?>">
                <h2><?= ($edit || $productPost && (int) ($_POST['product_id'] ?? 0) > 0) ? 'Edit listing' : 'Add listing' ?></h2>
                <div>
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select" id="category" name="category" required>
                        <?php foreach (category_meta() as $key => $meta): ?>
                            <option value="<?= e($key) ?>" <?= $field('category', 'live_chicken') === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="name">Product name</label>
                    <input class="form-control" id="name" name="name" required maxlength="150" value="<?= e($field('name')) ?>">
                </div>
                <div>
                    <label class="form-label" for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3" maxlength="2000"><?= e($field('description')) ?></textarea>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label" for="unit">Unit</label>
                        <input class="form-control" id="unit" name="unit" required maxlength="40" list="units" value="<?= e($field('unit', 'head')) ?>">
                        <datalist id="units">
                            <option value="head"><option value="kg"><option value="tray"><option value="dozen"><option value="sack">
                        </datalist>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="price">Unit price (₱)</label>
                        <input class="form-control" id="price" name="price" type="number" min="0.01" step="0.01" required value="<?= e($field('price')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="stock">Stock</label>
                        <input class="form-control" id="stock" name="stock" type="number" min="0" step="0.01" required value="<?= e($field('stock', '0')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="moq">Minimum order</label>
                        <input class="form-control" id="moq" name="moq" type="number" min="0.01" step="0.01" required value="<?= e($field('moq', '1')) ?>">
                    </div>
                </div>
                <button class="btn btn-agree" type="submit" <?= coop_can_trade($user) ? '' : 'disabled' ?>>Save product</button>
            </form>
        </div>
        <div class="col-lg-8">
            <div class="panel">
                <h2>Catalog</h2>
                <div class="table-responsive">
                    <table class="table agree-table align-middle">
                        <thead><tr><th>Product</th><th>Price</th><th>Stock</th><th>MOQ</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td>
                                    <?= e($product['name']) ?><br>
                                    <span class="tiny muted"><?= e(category_label((string) $product['category'])) ?> · <?= e($product['unit']) ?></span>
                                    <?= (int) $product['is_active'] === 1 ? '' : ' ' . status_badge('closed') ?>
                                </td>
                                <td><?= e(peso($product['price'])) ?></td>
                                <td><?= e(rtrim(rtrim(number_format((float) $product['stock'], 2), '0'), '.')) ?></td>
                                <td><?= e(rtrim(rtrim(number_format((float) $product['moq'], 2), '0'), '.')) ?></td>
                                <td class="text-nowrap">
                                    <a class="btn btn-outline-agree btn-sm" href="coop-dashboard.php?tab=inventory&amp;edit=<?= (int) $product['id'] ?>">Edit</a>
                                    <form method="post" action="coop-dashboard.php?tab=inventory" class="d-inline" data-confirm="Change visibility of this listing?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="archive_product">
                                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                        <button class="btn btn-outline-agree btn-sm" type="submit"><?= (int) $product['is_active'] === 1 ? 'Hide' : 'Show' ?></button>
                                    </form>
                                    <form method="post" action="coop-dashboard.php?tab=inventory" class="d-inline" data-confirm="Delete this product from the database? Past orders keep their history. The listing itself is removed.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                        <button class="btn btn-outline-agree btn-sm" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$products): ?>
                            <tr><td colspan="5">No products yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'rfqs'): ?>
    <?php if (!$rfqs): ?>
        <div class="empty-state"><i class="fa-solid fa-file-signature"></i><p>No open requests from buyers right now.</p></div>
    <?php endif; ?>
    <div class="row g-3">
        <?php foreach ($rfqs as $rfq): ?>
            <?php $mine = $myQuotes[(int) $rfq['id']] ?? null; ?>
            <div class="col-lg-6">
                <article class="panel h-100">
                    <div class="split">
                        <h2 class="h4"><?= e($rfq['item_name']) ?></h2>
                        <?php
                        $sent = $myQuotes[(int) $rfq['id']] ?? null;
                        if ($sent && $sent['status'] === 'accepted') {
                            echo plain_status('Price accepted', 'ok');
                        } elseif ($sent && $sent['status'] === 'pending') {
                            echo plain_status('Waiting for the buyer', 'gold');
                        } else {
                            echo plain_status('Waiting for your price', 'info');
                        }
                        ?>
                    </div>
                    <p class="mb-1"><?= e(number_format((float) $rfq['quantity'], 2)) ?> <?= e($rfq['unit']) ?> · needed <?= e(date('M j, Y', strtotime($rfq['needed_by']))) ?></p>
                    <p class="muted mb-1"><?= e($rfq['buyer_name']) ?> · <?= e(business_label($rfq['business_type'])) ?> · <?= e($rfq['buyer_barangay']) ?>, <?= e($rfq['buyer_municipality']) ?></p>
                    <p class="tiny mb-2"><?= $rfq['coop_id'] ? 'Directed to your cooperative.' : 'Open to verified Ibaan cooperatives.' ?></p>
                    <?php if ($rfq['notes']): ?><p><?= nl_e($rfq['notes']) ?></p><?php endif; ?>
                    <?php if ($mine && in_array($mine['status'], ['pending', 'accepted'], true)): ?>
                        <div class="banner banner-ok">
                            Your quote: <?= e(peso($mine['unit_price'])) ?> / <?= e($rfq['unit']) ?>
                            · total <?= e(peso($mine['total_amount'])) ?>
                            · <?= (int) $mine['deposit_percent'] ?>% deposit
                            · deliver <?= e(date('M j', strtotime($mine['delivery_date']))) ?>
                            <?= status_badge((string) $mine['status']) ?>
                        </div>
                    <?php elseif (coop_can_trade($user)): ?>
                        <form method="post" action="coop-dashboard.php?tab=rfqs" class="stack-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="send_quote">
                            <input type="hidden" name="rfq_id" value="<?= (int) $rfq['id'] ?>">
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label">Unit price (₱)</label>
                                    <input class="form-control" name="unit_price" type="number" min="0.01" step="0.01" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Deposit %</label>
                                    <input class="form-control" name="deposit_percent" type="number" min="30" max="70" value="50" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Delivery date</label>
                                    <input class="form-control" name="delivery_date" type="date" required value="<?= e($rfq['needed_by']) ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Valid until</label>
                                    <input class="form-control" name="valid_until" type="date" required value="<?= e(date('Y-m-d', strtotime('+3 days'))) ?>">
                                </div>
                            </div>
                            <textarea class="form-control" name="notes" rows="2" maxlength="2000" placeholder="Binding terms, packing, or slaughter notes"></textarea>
                            <button class="btn btn-agree" type="submit">Send this price</button>
                        </form>
                    <?php endif; ?>
                </article>
            </div>
        <?php endforeach; ?>
    </div>

<?php elseif ($tab === 'orders'): ?>
    <section class="panel mb-3">
        <h2>Delivery calendar</h2>
        <div class="calendar-grid">
            <?php foreach (delivery_calendar($orders) as $cell): ?>
                <div class="day-cell <?= $cell['today'] ? 'today' : '' ?>">
                    <strong><?= e($cell['label']) ?> <?= e($cell['num']) ?></strong>
                    <?php foreach ($cell['orders'] as $order): ?>
                        <?php if ($order['order_status'] === 'cancelled') { continue; } ?>
                        <span class="chip"><?= e($order['order_code']) ?> · <?= e($order['buyer_name']) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="panel">
        <h2>Orders</h2>
        <div class="table-responsive">
            <table class="table agree-table align-middle">
                <thead><tr><th>Order</th><th>Amount</th><th>Delivery</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>
                            <strong><?= e($order['order_code']) ?></strong><br>
                            <?= e($order['item_summary']) ?> · <?= e(number_format((float) $order['quantity'], 2)) ?> <?= e($order['unit']) ?><br>
                            <span class="tiny muted"><?= e($order['buyer_name']) ?></span>
                        </td>
                        <td>
                            <?= e(peso($order['total_amount'])) ?><br>
                            <span class="tiny">Deposit <?= e(peso($order['deposit_amount'])) ?> · COD <?= e(peso($order['balance_due'])) ?></span>
                        </td>
                        <td><?= e(date('M j, Y', strtotime($order['delivery_date']))) ?><br><span class="tiny muted"><?= e($order['delivery_address']) ?></span></td>
                        <td>
                            <?= order_plain_status($order) ?><br>
                            <a href="messages.php?with=<?= (int) $order['buyer_id'] ?>">Message the buyer</a>
                        </td>
                        <td>
                            <?php if ($order['order_status'] === 'processing'): ?>
                                <form method="post" action="coop-dashboard.php?tab=orders">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="advance_order">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="next_status" value="in_transit">
                                    <button class="btn btn-agree btn-sm" type="submit">Mark on the way</button>
                                </form>
                            <?php elseif ($order['order_status'] === 'in_transit'): ?>
                                <p class="tiny muted mb-0">Waiting for the buyer to confirm delivery.</p>
                            <?php elseif ($order['order_status'] === 'fulfilled' && $order['payment_status'] === 'cod_balance'): ?>
                                <form method="post" action="coop-dashboard.php?tab=orders" data-confirm="Confirm the cash balance was collected?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="confirm_cod">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button class="btn btn-gold btn-sm" type="submit">Cash was paid</button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array($order['order_status'], ['pending_payment', 'processing'], true)): ?>
                                <form method="post" action="coop-dashboard.php?tab=orders" class="mt-1" data-confirm="Cancel this order?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel_order">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button class="btn btn-outline-agree btn-sm" type="submit">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?><tr><td colspan="5">No orders yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php else: ?>
    <div class="row g-3">
        <?php
        $pending = array_filter($payments, function ($payment) {
            return $payment['status'] === 'pending';
        });
        ?>
        <?php if (!$pending): ?>
            <div class="col-12"><div class="empty-state">No receipts are waiting for review.</div></div>
        <?php endif; ?>
        <?php foreach ($pending as $payment): ?>
            <div class="col-lg-6">
                <article class="panel">
                    <h2 class="h5"><?= e($payment['order_code']) ?> · <?= e($payment['buyer_name']) ?></h2>
                    <p><?= e($payment['item_summary']) ?></p>
                    <p>Deposit due <?= e(peso($payment['deposit_amount'])) ?> · uploaded <?= e(peso($payment['amount'])) ?>
                        <?php if ($payment['reference_no']): ?> · ref <?= e($payment['reference_no']) ?><?php endif; ?></p>
                    <?= file_preview('download.php?type=receipt&id=' . (int) $payment['id'], (string) $payment['receipt_path'], 'Deposit receipt') ?>
                    <form method="post" action="coop-dashboard.php?tab=payments" class="stack-form mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="review_payment">
                        <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                        <textarea class="form-control" name="reviewer_note" rows="2" maxlength="500" placeholder="Note to the buyer"></textarea>
                        <div class="d-flex gap-2">
                            <button class="btn btn-agree" name="decision" value="approved" type="submit">Approve and release</button>
                            <button class="btn btn-outline-agree" name="decision" value="rejected" type="submit">Reject receipt</button>
                        </div>
                    </form>
                </article>
            </div>
        <?php endforeach; ?>
        <div class="col-12">
            <section class="panel">
                <h2>Receipt history</h2>
                <div class="table-responsive">
                    <table class="table agree-table">
                        <thead><tr><th>When</th><th>Order</th><th>Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= e(short_time((string) $payment['created_at'])) ?></td>
                                <td><?= e($payment['order_code']) ?></td>
                                <td><?= e(peso($payment['amount'])) ?></td>
                                <td><?= status_badge((string) $payment['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
