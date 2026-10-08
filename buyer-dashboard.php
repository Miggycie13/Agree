<?php
require_once __DIR__ . '/bootstrap.php';

$user = require_role(['buyer']);
$pdo = db();
$buyerId = (int) $user['id'];
$tabs = ['overview', 'suppliers', 'products', 'rfq', 'quotes', 'orders'];
$tab = (string) ($_GET['tab'] ?? 'overview');
if (!in_array($tab, $tabs, true)) {
    $tab = 'overview';
}
$formError = null;
$action = (string) ($_POST['action'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf('buyer-dashboard.php?tab=' . $tab);
    $tabMap = [
        'create_rfq' => 'rfq',
        'place_order' => 'products',
        'accept_quote' => 'quotes',
        'decline_quote' => 'quotes',
        'upload_receipt' => 'orders',
        'cancel_order' => 'orders',
        'confirm_delivery' => 'orders',
        'save_review' => 'orders',
    ];
    if (isset($tabMap[$action])) {
        $tab = $tabMap[$action];
    }
    try {
        switch ($action) {
            case 'create_rfq':
                $coopId = (int) ($_POST['coop_id'] ?? 0);
                $productId = (int) ($_POST['product_id'] ?? 0);
                $category = (string) ($_POST['category'] ?? '');
                $item = posted('item_name', 150);
                $unit = posted('unit', 40);
                $qty = require_qty((string) ($_POST['quantity'] ?? ''));
                $needed = require_date((string) ($_POST['needed_by'] ?? ''), 'Needed by');
                $address = posted('delivery_address', 255);
                $notes = posted('notes', 2000);
                if ($address === '' || $item === '' || $unit === '') {
                    throw new RuntimeException('Item, unit, and delivery address are required.');
                }
                if (!isset(category_meta()[$category])) {
                    throw new RuntimeException('Choose a product category.');
                }
                $product = null;
                if ($productId > 0) {
                    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1');
                    $stmt->execute([$productId]);
                    $product = $stmt->fetch();
                    if (!$product) {
                        throw new RuntimeException('That listing is no longer available.');
                    }
                    $coopId = (int) $product['coop_id'];
                    $category = (string) $product['category'];
                    $item = (string) $product['name'];
                    $unit = (string) $product['unit'];
                    if ($qty + 0.0001 < (float) $product['moq']) {
                        throw new RuntimeException('Quantity is below the minimum order of ' . $product['moq'] . ' ' . $product['unit'] . '.');
                    }
                }
                if ($coopId > 0) {
                    $stmt = $pdo->prepare(
                        "SELECT id, organization FROM users
                         WHERE id = ? AND role = 'cooperative' AND verification_status = 'approved' AND is_active = 1"
                    );
                    $stmt->execute([$coopId]);
                    $coop = $stmt->fetch();
                    if (!$coop) {
                        throw new RuntimeException('Choose a verified Ibaan cooperative.');
                    }
                } else {
                    $coopId = null;
                    $coop = null;
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO rfqs
                        (buyer_id, coop_id, product_id, category, item_name, quantity, unit, needed_by, delivery_address, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $buyerId,
                    $coopId,
                    $product ? (int) $product['id'] : null,
                    $category,
                    $item,
                    $qty,
                    $unit,
                    $needed,
                    $address,
                    $notes,
                ]);
                $rfqId = (int) $pdo->lastInsertId();
                if ($coopId) {
                    send_message(
                        $pdo,
                        $user,
                        $coopId,
                        'New RFQ #' . $rfqId . ' for ' . $qty . ' ' . $unit . ' of ' . $item . ', needed ' . $needed . '.'
                    );
                }
                flash('success', $coopId
                    ? 'Quotation request sent to ' . $coop['organization'] . '.'
                    : 'Quotation request sent to verified cooperatives in Ibaan.');
                redirect('buyer-dashboard.php?tab=quotes');

            case 'place_order':
                $productId = (int) ($_POST['product_id'] ?? 0);
                $qty = require_qty((string) ($_POST['quantity'] ?? ''));
                $needed = require_date((string) ($_POST['delivery_date'] ?? ''), 'Delivery date');
                $address = posted('delivery_address', 255);
                if ($address === '') {
                    throw new RuntimeException('Delivery address is required.');
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "SELECT p.id, p.coop_id, p.category, p.name, p.unit, p.price, p.stock, p.moq, u.organization
                     FROM products p
                     JOIN users u ON u.id = p.coop_id
                     WHERE p.id = ? AND p.is_active = 1
                       AND u.role = 'cooperative' AND u.verification_status = 'approved' AND u.is_active = 1
                     FOR UPDATE"
                );
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                if (!$product) {
                    throw new RuntimeException('That product is no longer available.');
                }
                $moq = (float) $product['moq'];
                $stock = (float) $product['stock'];
                if ($qty + 0.0001 < $moq) {
                    throw new RuntimeException('Quantity is below the minimum order of ' . rtrim(rtrim(number_format($moq, 2), '0'), '.') . ' ' . $product['unit'] . '.');
                }
                if ($qty - 0.0001 > $stock) {
                    throw new RuntimeException('Quantity is above the available stock of ' . rtrim(rtrim(number_format($stock, 2), '0'), '.') . ' ' . $product['unit'] . '.');
                }
                $unitPrice = round((float) $product['price'], 2);
                $total = round($unitPrice * $qty, 2);
                $deposit = round($total * 0.5, 2);
                $balance = round($total - $deposit, 2);
                $rfq = $pdo->prepare(
                    "INSERT INTO rfqs
                        (buyer_id, coop_id, product_id, category, item_name, quantity, unit, needed_by, delivery_address, notes, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'accepted')"
                );
                $rfq->execute([
                    $buyerId,
                    (int) $product['coop_id'],
                    (int) $product['id'],
                    $product['category'],
                    $product['name'],
                    $qty,
                    $product['unit'],
                    $needed,
                    $address,
                    'Ordered at the posted price.',
                ]);
                $rfqId = (int) $pdo->lastInsertId();
                $quote = $pdo->prepare(
                    "INSERT INTO quotes
                        (rfq_id, coop_id, unit_price, quantity, total_amount, deposit_percent, delivery_date, valid_until, notes, status)
                     VALUES (?, ?, ?, ?, ?, 50, ?, ?, ?, 'accepted')"
                );
                $quote->execute([
                    $rfqId,
                    (int) $product['coop_id'],
                    $unitPrice,
                    $qty,
                    $total,
                    $needed,
                    $needed,
                    'Posted price. Deposit is half of the total.',
                ]);
                $quoteId = (int) $pdo->lastInsertId();
                $hold = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
                $hold->execute([$qty, (int) $product['id'], $qty]);
                if ($hold->rowCount() === 0) {
                    throw new RuntimeException('Quantity is above the available stock of ' . rtrim(rtrim(number_format($stock, 2), '0'), '.') . ' ' . $product['unit'] . '.');
                }
                $code = order_code();
                $order = $pdo->prepare(
                    'INSERT INTO orders
                        (order_code, quote_id, rfq_id, buyer_id, coop_id, product_id, item_summary, quantity, unit, unit_price,
                         total_amount, deposit_amount, balance_due, delivery_date, delivery_address, stock_deducted)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
                );
                $order->execute([
                    $code,
                    $quoteId,
                    $rfqId,
                    $buyerId,
                    (int) $product['coop_id'],
                    (int) $product['id'],
                    $product['name'],
                    $qty,
                    $product['unit'],
                    $unitPrice,
                    $total,
                    $deposit,
                    $balance,
                    $needed,
                    $address,
                ]);
                $pdo->commit();
                send_message(
                    $pdo,
                    $user,
                    (int) $product['coop_id'],
                    'New order ' . $code . ' for ' . rtrim(rtrim(number_format($qty, 2), '0'), '.') . ' ' . $product['unit']
                    . ' of ' . $product['name'] . ' at the posted price. Deposit due: ' . peso($deposit) . '.'
                );
                flash('success', 'Order placed at the posted price. Upload the deposit receipt so the cooperative can prepare it.');
                redirect('buyer-dashboard.php?tab=orders');

            case 'accept_quote':
                $quoteId = (int) ($_POST['quote_id'] ?? 0);
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "SELECT q.*, r.buyer_id, r.status AS rfq_status, r.item_name, r.unit AS rfq_unit,
                            r.product_id, r.delivery_address
                     FROM quotes q
                     JOIN rfqs r ON r.id = q.rfq_id
                     WHERE q.id = ? AND r.buyer_id = ? AND q.status = 'pending'
                     FOR UPDATE"
                );
                $stmt->execute([$quoteId, $buyerId]);
                $quote = $stmt->fetch();
                if (!$quote || !in_array($quote['rfq_status'], ['open', 'quoted'], true)) {
                    throw new RuntimeException('That quote can no longer be accepted.');
                }
                if ($quote['valid_until'] < date('Y-m-d')) {
                    throw new RuntimeException('That quote has expired.');
                }
                $accept = $pdo->prepare("UPDATE quotes SET status = 'accepted' WHERE id = ? AND status = 'pending'");
                $accept->execute([$quoteId]);
                if ($accept->rowCount() === 0) {
                    throw new RuntimeException('That quote was already updated.');
                }
                $decline = $pdo->prepare("UPDATE quotes SET status = 'declined' WHERE rfq_id = ? AND id <> ? AND status = 'pending'");
                $decline->execute([(int) $quote['rfq_id'], $quoteId]);
                $close = $pdo->prepare("UPDATE rfqs SET status = 'accepted' WHERE id = ?");
                $close->execute([(int) $quote['rfq_id']]);
                $deposit = round((float) $quote['total_amount'] * ((int) $quote['deposit_percent'] / 100), 2);
                $balance = round((float) $quote['total_amount'] - $deposit, 2);
                $stockDeducted = 0;
                if ($quote['product_id']) {
                    $productId = (int) $quote['product_id'];
                    $lock = $pdo->prepare('SELECT id, stock, unit FROM products WHERE id = ? FOR UPDATE');
                    $lock->execute([$productId]);
                    $product = $lock->fetch();
                    if (!$product) {
                        throw new RuntimeException('That product is no longer available.');
                    }
                    $quoteQty = (float) $quote['quantity'];
                    if ($quoteQty - 0.0001 > (float) $product['stock']) {
                        throw new RuntimeException('Quantity is above the available stock of ' . rtrim(rtrim(number_format((float) $product['stock'], 2), '0'), '.') . ' ' . $product['unit'] . '.');
                    }
                    $hold = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
                    $hold->execute([$quoteQty, $productId, $quoteQty]);
                    if ($hold->rowCount() === 0) {
                        throw new RuntimeException('Quantity is above the available stock of ' . rtrim(rtrim(number_format((float) $product['stock'], 2), '0'), '.') . ' ' . $product['unit'] . '.');
                    }
                    $stockDeducted = 1;
                }
                $insert = $pdo->prepare(
                    'INSERT INTO orders
                        (order_code, quote_id, rfq_id, buyer_id, coop_id, product_id, item_summary, quantity, unit, unit_price,
                         total_amount, deposit_amount, balance_due, delivery_date, delivery_address, stock_deducted)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    order_code(),
                    $quoteId,
                    (int) $quote['rfq_id'],
                    $buyerId,
                    (int) $quote['coop_id'],
                    $quote['product_id'] ? (int) $quote['product_id'] : null,
                    $quote['item_name'],
                    $quote['quantity'],
                    $quote['rfq_unit'],
                    $quote['unit_price'],
                    $quote['total_amount'],
                    $deposit,
                    $balance,
                    $quote['delivery_date'],
                    $quote['delivery_address'],
                    $stockDeducted,
                ]);
                $pdo->commit();
                send_message(
                    $pdo,
                    $user,
                    (int) $quote['coop_id'],
                    'Quote accepted for ' . $quote['item_name'] . '. Deposit due: ' . peso($deposit) . '. Balance on delivery: ' . peso($balance) . '.'
                );
                flash('success', 'Quote accepted. Upload the deposit receipt so the cooperative can prepare the order.');
                redirect('buyer-dashboard.php?tab=orders');

            case 'decline_quote':
                $quoteId = (int) ($_POST['quote_id'] ?? 0);
                $stmt = $pdo->prepare(
                    "SELECT q.id, q.rfq_id FROM quotes q
                     JOIN rfqs r ON r.id = q.rfq_id
                     WHERE q.id = ? AND r.buyer_id = ? AND q.status = 'pending'"
                );
                $stmt->execute([$quoteId, $buyerId]);
                $quote = $stmt->fetch();
                if (!$quote) {
                    throw new RuntimeException('That quote is not open.');
                }
                $upd = $pdo->prepare("UPDATE quotes SET status = 'declined' WHERE id = ?");
                $upd->execute([$quoteId]);
                $left = $pdo->prepare("SELECT COUNT(*) FROM quotes WHERE rfq_id = ? AND status = 'pending'");
                $left->execute([(int) $quote['rfq_id']]);
                if ((int) $left->fetchColumn() === 0) {
                    $reopen = $pdo->prepare("UPDATE rfqs SET status = 'open' WHERE id = ? AND status = 'quoted'");
                    $reopen->execute([(int) $quote['rfq_id']]);
                }
                flash('success', 'Quote declined.');
                redirect('buyer-dashboard.php?tab=quotes');

            case 'upload_receipt':
                $orderId = (int) ($_POST['order_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND buyer_id = ?');
                $stmt->execute([$orderId, $buyerId]);
                $order = $stmt->fetch();
                if (!$order || $order['order_status'] === 'cancelled') {
                    throw new RuntimeException('Order not found.');
                }
                if ($order['payment_status'] !== 'awaiting_deposit') {
                    throw new RuntimeException('This order is not waiting for a deposit receipt.');
                }
                $amount = require_money((string) ($_POST['amount'] ?? ''), 'Deposit amount');
                if (abs($amount - (float) $order['deposit_amount']) > 0.009) {
                    throw new RuntimeException('The receipt amount should match the deposit of ' . peso($order['deposit_amount']) . '.');
                }
                $reference = posted('reference_no', 80);
                $path = save_upload($_FILES['receipt'] ?? [], 'receipts');
                $pdo->beginTransaction();
                $ins = $pdo->prepare(
                    'INSERT INTO payments (order_id, payer_id, kind, amount, receipt_path, reference_no) VALUES (?, ?, \'deposit\', ?, ?, ?)'
                );
                $ins->execute([$orderId, $buyerId, $amount, $path, $reference !== '' ? $reference : null]);
                $upd = $pdo->prepare("UPDATE orders SET payment_status = 'deposit_review' WHERE id = ? AND payment_status = 'awaiting_deposit'");
                $upd->execute([$orderId]);
                $pdo->commit();
                send_message($pdo, $user, (int) $order['coop_id'], 'Deposit receipt uploaded for ' . $order['order_code'] . ($reference !== '' ? ' (' . $reference . ').' : '.'));
                flash('success', 'Receipt uploaded. The cooperative will check it before preparing the order.');
                redirect('buyer-dashboard.php?tab=orders');

            case 'cancel_order':
                $orderId = (int) ($_POST['order_id'] ?? 0);
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "SELECT * FROM orders
                     WHERE id = ? AND buyer_id = ? AND order_status = 'pending_payment' AND payment_status = 'awaiting_deposit'
                     FOR UPDATE"
                );
                $stmt->execute([$orderId, $buyerId]);
                $order = $stmt->fetch();
                if (!$order) {
                    throw new RuntimeException('You can cancel only before a deposit receipt is under review.');
                }
                if ((int) $order['stock_deducted'] === 1 && $order['product_id']) {
                    $restore = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
                    $restore->execute([$order['quantity'], (int) $order['product_id']]);
                }
                $upd = $pdo->prepare("UPDATE orders SET order_status = 'cancelled', stock_deducted = 0 WHERE id = ?");
                $upd->execute([$orderId]);
                $pdo->commit();
                flash('success', 'Order cancelled.');
                redirect('buyer-dashboard.php?tab=orders');

            case 'confirm_delivery':
                $orderId = (int) ($_POST['order_id'] ?? 0);
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND buyer_id = ? FOR UPDATE');
                $stmt->execute([$orderId, $buyerId]);
                $order = $stmt->fetch();
                if (!$order || $order['order_status'] !== 'in_transit') {
                    throw new RuntimeException('You can confirm delivery when the order is on the way.');
                }
                $upd = $pdo->prepare(
                    "UPDATE orders SET order_status = 'fulfilled', payment_status = 'cod_balance'
                     WHERE id = ? AND order_status = 'in_transit'"
                );
                $upd->execute([$orderId]);
                $pdo->commit();
                send_message(
                    $pdo,
                    $user,
                    (int) $order['coop_id'],
                    'Order ' . $order['order_code'] . ' was received. The remaining ' . peso($order['balance_due']) . ' is due in cash.'
                );
                flash('success', 'Delivery confirmed. Pay the rest in cash to the cooperative.');
                redirect('buyer-dashboard.php?tab=orders');

            case 'save_review':
                $orderId = (int) ($_POST['order_id'] ?? 0);
                $rating = (int) ($_POST['rating'] ?? 0);
                $comment = posted('comment', 500);
                if ($rating < 1 || $rating > 5) {
                    throw new RuntimeException('Choose a rating from 1 to 5.');
                }
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND buyer_id = ? AND order_status = 'fulfilled'");
                $stmt->execute([$orderId, $buyerId]);
                $order = $stmt->fetch();
                if (!$order) {
                    throw new RuntimeException('Reviews are available after delivery.');
                }
                try {
                    $ins = $pdo->prepare('INSERT INTO reviews (order_id, buyer_id, coop_id, rating, comment) VALUES (?, ?, ?, ?, ?)');
                    $ins->execute([$orderId, $buyerId, (int) $order['coop_id'], $rating, $comment !== '' ? $comment : null]);
                } catch (PDOException $ex) {
                    if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                        throw new RuntimeException('You already reviewed this order.');
                    }
                    throw new RuntimeException('Review could not be saved.');
                }
                flash('success', 'Thank you. Your rating is now on the cooperative.');
                redirect('buyer-dashboard.php?tab=orders');

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

$chart = $tab === 'overview' ? build_price_chart($pdo) : null;
$board = $tab === 'overview' ? market_board($pdo) : [];

$coops = apply_distance(fetch_cooperatives($pdo), $user['latitude'] !== null ? (float) $user['latitude'] : null, $user['longitude'] !== null ? (float) $user['longitude'] : null);

$categoryFilter = (string) ($_GET['category'] ?? '');
if (!isset(category_meta()[$categoryFilter])) {
    $categoryFilter = '';
}
$radius = (int) ($_GET['radius'] ?? 15);
if (!in_array($radius, [5, 10, 15, 30, 50], true)) {
    $radius = 15;
}
$sort = (string) ($_GET['sort'] ?? 'distance');
if (!in_array($sort, ['distance', 'rating', 'price'], true)) {
    $sort = 'distance';
}
$search = clip((string) ($_GET['q'] ?? ''), 80);
$directory = [];
foreach ($coops as $coop) {
    if ($search !== '') {
        $hay = strtolower($coop['organization'] . ' ' . $coop['barangay']);
        if (!str_contains($hay, strtolower($search))) {
            continue;
        }
    }
    if ($coop['distance_km'] !== null && $coop['distance_km'] > $radius) {
        continue;
    }
    $priced = [];
    foreach ($coop['products'] as $product) {
        if ($categoryFilter === '' || $product['category'] === $categoryFilter) {
            $priced[] = $product;
        }
    }
    if ($categoryFilter !== '' && !$priced) {
        continue;
    }
    $coop['match_products'] = $priced;
    $min = null;
    foreach ($priced as $product) {
        if ($product['category'] === 'agri_supply') {
            continue;
        }
        $min = $min === null ? (float) $product['price'] : min($min, (float) $product['price']);
    }
    $coop['sort_price'] = $min ?? 999999;
    $directory[] = $coop;
}
usort($directory, function ($a, $b) use ($sort) {
    if ($sort === 'rating') {
        return ((float) ($b['rating'] ?? 0)) <=> ((float) ($a['rating'] ?? 0));
    }
    if ($sort === 'price') {
        return $a['sort_price'] <=> $b['sort_price'];
    }
    return ((float) ($a['distance_km'] ?? 9999)) <=> ((float) ($b['distance_km'] ?? 9999));
});

$rfqStmt = $pdo->prepare(
    'SELECT r.*, c.organization AS coop_name
     FROM rfqs r
     LEFT JOIN users c ON c.id = r.coop_id
     WHERE r.buyer_id = ?
     ORDER BY r.id DESC'
);
$rfqStmt->execute([$buyerId]);
$rfqs = $rfqStmt->fetchAll();
$quoteStmt = $pdo->prepare(
    'SELECT q.*, u.organization AS coop_name, u.barangay
     FROM quotes q
     JOIN rfqs r ON r.id = q.rfq_id
     JOIN users u ON u.id = q.coop_id
     WHERE r.buyer_id = ?
     ORDER BY q.id DESC'
);
$quoteStmt->execute([$buyerId]);
$quotesByRfq = [];
foreach ($quoteStmt as $quote) {
    $quotesByRfq[(int) $quote['rfq_id']][] = $quote;
}

$orderStmt = $pdo->prepare(
    'SELECT o.*, c.organization AS coop_name, rv.rating, rv.comment
     FROM orders o
     JOIN users c ON c.id = o.coop_id
     LEFT JOIN reviews rv ON rv.order_id = o.id
     WHERE o.buyer_id = ?
     ORDER BY o.id DESC'
);
$orderStmt->execute([$buyerId]);
$orders = $orderStmt->fetchAll();

$selectedCoop = (int) ($action === 'create_rfq' ? ($_POST['coop_id'] ?? 0) : ($_GET['coop'] ?? 0));
$selectedCategory = (string) ($action === 'create_rfq' ? ($_POST['category'] ?? 'live_chicken') : ($_GET['category'] ?? 'live_chicken'));
if (!isset(category_meta()[$selectedCategory])) {
    $selectedCategory = 'live_chicken';
}
$coopProducts = [];
if ($selectedCoop > 0) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE coop_id = ? AND is_active = 1 ORDER BY category, name');
    $stmt->execute([$selectedCoop]);
    $coopProducts = $stmt->fetchAll();
}

$productCoopId = (int) ($_GET['coop'] ?? 0);
$orderProductId = (int) ($action === 'place_order' ? ($_POST['product_id'] ?? 0) : ($_GET['product'] ?? 0));
$orderProduct = null;
$orderCoop = null;
if ($orderProductId > 0) {
    foreach ($coops as $coop) {
        foreach ($coop['products'] as $product) {
            if ((int) $product['id'] === $orderProductId) {
                $orderProduct = $product;
                $orderCoop = $coop;
                break 2;
            }
        }
    }
}

$pageTitle = 'Buyer account';
$layout = 'app';
$navKey = $tab;
$needsChart = $tab === 'overview';
require __DIR__ . '/header.php';
?>
<div class="page-head">
    <p class="gold-kicker"><?= e(business_label($user['business_type'])) ?> · <?= e($user['municipality']) ?></p>
    <h1><?= e($user['organization']) ?></h1>
</div>
<?php if ($formError): ?><div class="alert alert-danger"><?= e($formError) ?></div><?php endif; ?>

<?php if ($tab === 'overview'): ?>
    <?php
    $pendingQuote = false;
    $waitingPrice = false;
    foreach ($rfqs as $rfq) {
        $hasPending = false;
        foreach ($quotesByRfq[(int) $rfq['id']] ?? [] as $quote) {
            if ($quote['status'] === 'pending' && in_array($rfq['status'], ['open', 'quoted'], true)) {
                $hasPending = true;
            }
        }
        if ($hasPending) {
            $pendingQuote = true;
        } elseif ($rfq['status'] === 'open') {
            $waitingPrice = true;
        }
    }
    $needsDeposit = false;
    $checkingDeposit = false;
    $activeDelivery = false;
    foreach ($orders as $order) {
        if ($order['order_status'] === 'cancelled' || $order['order_status'] === 'fulfilled') {
            continue;
        }
        if ($order['payment_status'] === 'awaiting_deposit') {
            $needsDeposit = true;
        } elseif ($order['payment_status'] === 'deposit_review') {
            $checkingDeposit = true;
        } else {
            $activeDelivery = true;
        }
    }
    if ($needsDeposit) {
        $nextTitle = 'Pay the deposit';
        $nextText = 'A price is accepted. Upload the deposit receipt so the cooperative can prepare the order.';
        $nextHref = 'buyer-dashboard.php?tab=orders';
        $nextButton = 'Pay the deposit';
    } elseif ($checkingDeposit) {
        $nextTitle = 'Deposit being checked';
        $nextText = 'The cooperative is checking your receipt. You can message them from the order.';
        $nextHref = 'buyer-dashboard.php?tab=orders';
        $nextButton = 'See the order';
    } elseif ($activeDelivery) {
        $nextTitle = 'Receive the order';
        $nextText = 'The deposit is confirmed. Follow the delivery, then pay the rest in cash when it arrives.';
        $nextHref = 'buyer-dashboard.php?tab=orders';
        $nextButton = 'See the order';
    } elseif ($pendingQuote) {
        $nextTitle = 'Review the price';
        $nextText = 'A cooperative sent a price. Accept it to start the order, or decline it.';
        $nextHref = 'buyer-dashboard.php?tab=quotes';
        $nextButton = 'Review the price';
    } elseif ($waitingPrice) {
        $nextTitle = 'Waiting for a price';
        $nextText = 'Your request is with the cooperatives. You will see their price here when it arrives.';
        $nextHref = 'buyer-dashboard.php?tab=quotes';
        $nextButton = 'See your request';
    } else {
        $nextTitle = 'Order a product';
        $nextText = 'Choose a product already posted by an approved cooperative in Ibaan. The order uses that listed price, then you pay the deposit.';
        $nextHref = 'buyer-dashboard.php?tab=products';
        $nextButton = 'Order a product';
    }
    ?>
    <section class="panel mb-3">
        <h2><?= e($nextTitle) ?></h2>
        <p><?= e($nextText) ?></p>
        <a class="btn btn-agree" href="<?= e($nextHref) ?>"><?= e($nextButton) ?></a>
    </section>
    <p class="muted">Average prices from approved cooperatives in Ibaan.</p>
    <?php render_price_cards($board); ?>
    <div class="chart-wrap mt-3">
        <canvas id="priceChart" height="90" aria-label="Ibaan price trend"></canvas>
    </div>
    <script type="application/json" id="price-data"><?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php elseif ($tab === 'suppliers'): ?>
    <form method="get" action="buyer-dashboard.php" class="panel filter-bar mb-3">
        <input type="hidden" name="tab" value="suppliers">
        <div>
            <label class="form-label" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Cooperative or barangay">
        </div>
        <div>
            <label class="form-label" for="category">Product</label>
            <select class="form-select" id="category" name="category">
                <option value="">All poultry</option>
                <?php foreach (category_meta() as $key => $meta): ?>
                    <?php if ($key === 'agri_supply') { continue; } ?>
                    <option value="<?= e($key) ?>" <?= $categoryFilter === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="radius">Within</label>
            <select class="form-select" id="radius" name="radius">
                <?php foreach ([5, 10, 15, 30, 50] as $km): ?>
                    <option value="<?= $km ?>" <?= $radius === $km ? 'selected' : '' ?>><?= $km ?> km</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="sort">Sort</label>
            <select class="form-select" id="sort" name="sort">
                <option value="distance" <?= $sort === 'distance' ? 'selected' : '' ?>>Nearest</option>
                <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Highest rating</option>
                <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>Lowest listed price</option>
            </select>
        </div>
        <button class="btn btn-agree" type="submit">Filter</button>
    </form>
    <?php
    $cards = $directory;
    if ($categoryFilter !== '') {
        foreach ($cards as &$card) {
            $card['products'] = $card['match_products'];
        }
        unset($card);
    }
    render_coop_cards($cards, $user);
    ?>

<?php elseif ($tab === 'products'): ?>
    <?php
    $fmtQty = static function ($amount): string {
        return rtrim(rtrim(number_format((float) $amount, 2), '0'), '.');
    };
    $productQuery = ['tab' => 'products', 'q' => $search, 'category' => $categoryFilter, 'radius' => (string) $radius];
    if ($productCoopId > 0) {
        $productQuery['coop'] = (string) $productCoopId;
    }
    $productsBase = 'buyer-dashboard.php?' . http_build_query($productQuery);
    $catalog = [];
    $productCoopName = '';
    foreach ($coops as $coop) {
        if ((int) $coop['id'] === $productCoopId) {
            $productCoopName = (string) $coop['organization'];
        }
    }
    $needle = strtolower($search);
    foreach ($coops as $coop) {
        if ($productCoopId > 0 && (int) $coop['id'] !== $productCoopId) {
            continue;
        }
        if ($productCoopId === 0 && $coop['distance_km'] !== null && $coop['distance_km'] > $radius) {
            continue;
        }
        $coopNamed = $needle === '' || str_contains(strtolower($coop['organization'] . ' ' . $coop['barangay']), $needle);
        $items = [];
        foreach ($coop['products'] as $product) {
            if ($categoryFilter !== '' && $product['category'] !== $categoryFilter) {
                continue;
            }
            $productNamed = $needle !== '' && str_contains(strtolower((string) $product['name']), $needle);
            if ($needle !== '' && !$coopNamed && !$productNamed) {
                continue;
            }
            $items[] = $product;
        }
        if (!$items) {
            continue;
        }
        $coop['catalog_products'] = $items;
        $catalog[] = $coop;
    }
    $orderQty = $action === 'place_order' ? (string) ($_POST['quantity'] ?? '') : ($orderProduct ? number_format((float) $orderProduct['moq'], 2, '.', '') : '');
    $orderDate = $action === 'place_order' ? (string) ($_POST['delivery_date'] ?? '') : '';
    $orderAddress = $action === 'place_order' ? (string) ($_POST['delivery_address'] ?? $user['address_line']) : (string) $user['address_line'];
    ?>
    <form method="get" action="buyer-dashboard.php" class="panel filter-bar mb-3">
        <input type="hidden" name="tab" value="products">
        <?php if ($productCoopId > 0): ?><input type="hidden" name="coop" value="<?= $productCoopId ?>"><?php endif; ?>
        <div>
            <label class="form-label" for="product-q">Search</label>
            <input class="form-control" id="product-q" name="q" value="<?= e($search) ?>" placeholder="Product, cooperative, or barangay">
        </div>
        <div>
            <label class="form-label" for="product-category">Product</label>
            <select class="form-select" id="product-category" name="category">
                <option value="">All products</option>
                <?php foreach (category_meta() as $key => $meta): ?>
                    <option value="<?= e($key) ?>" <?= $categoryFilter === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="product-radius">Within</label>
            <select class="form-select" id="product-radius" name="radius">
                <?php foreach ([5, 10, 15, 30, 50] as $km): ?>
                    <option value="<?= $km ?>" <?= $radius === $km ? 'selected' : '' ?>><?= $km ?> km</option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-agree" type="submit">Filter</button>
    </form>
    <?php if ($productCoopName !== ''): ?>
        <p class="muted">Products from <?= e($productCoopName) ?>. <a href="buyer-dashboard.php?tab=products">Show every cooperative</a></p>
    <?php endif; ?>
    <?php if ($orderProduct && $orderCoop): ?>
        <?php if ((float) $orderProduct['stock'] <= 0): ?>
            <section class="panel mb-3">
                <h2><?= e($orderProduct['name']) ?></h2>
                <p class="mb-0">This product is out of stock.</p>
            </section>
        <?php else: ?>
            <?php
            $qtyNumber = is_numeric($orderQty) ? round((float) $orderQty, 2) : (float) $orderProduct['moq'];
            $lineTotal = round((float) $orderProduct['price'] * $qtyNumber, 2);
            $lineDeposit = round($lineTotal * 0.5, 2);
            $orderAction = $productsBase . (str_contains($productsBase, '?') ? '&' : '?') . 'product=' . (int) $orderProduct['id'];
            ?>
            <form method="post" action="<?= e($orderAction) ?>" class="panel stack-form mb-3" id="order-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="place_order">
                <input type="hidden" name="product_id" value="<?= (int) $orderProduct['id'] ?>">
                <h2>Order <?= e($orderProduct['name']) ?></h2>
                <p class="mb-0"><?= e($orderCoop['organization']) ?> · <?= e($orderCoop['barangay']) ?></p>
                <p>Posted price <strong><?= e(peso($orderProduct['price'])) ?></strong> / <?= e($orderProduct['unit']) ?>. Stock <?= e($fmtQty($orderProduct['stock'])) ?>. Minimum order <?= e($fmtQty($orderProduct['moq'])) ?>.</p>
                <?php if (!empty($orderProduct['description'])): ?><p><?= nl_e((string) $orderProduct['description']) ?></p><?php endif; ?>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label" for="order_quantity">Quantity</label>
                        <input class="form-control" id="order_quantity" name="quantity" type="number" required
                            min="<?= e(number_format((float) $orderProduct['moq'], 2, '.', '')) ?>" max="<?= e(number_format((float) $orderProduct['stock'], 2, '.', '')) ?>" step="0.01"
                            data-price="<?= e(number_format((float) $orderProduct['price'], 2, '.', '')) ?>"
                            value="<?= e($orderQty) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="delivery_date">Delivery date</label>
                        <input class="form-control" id="delivery_date" name="delivery_date" type="date" required value="<?= e($orderDate) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="order_address">Delivery address</label>
                        <input class="form-control" id="order_address" name="delivery_address" required maxlength="255" value="<?= e($orderAddress) ?>">
                    </div>
                </div>
                <p class="mb-0">Total <strong id="order-total"><?= e(peso($lineTotal)) ?></strong>. Deposit (half) <strong id="order-deposit"><?= e(peso($lineDeposit)) ?></strong>. The rest is cash on delivery.</p>
                <button class="btn btn-agree" type="submit">Place order</button>
            </form>
            <script>
            (function () {
                var qty = document.getElementById('order_quantity');
                var total = document.getElementById('order-total');
                var deposit = document.getElementById('order-deposit');
                if (!qty || !total || !deposit) return;
                var price = parseFloat(qty.getAttribute('data-price') || '0');
                function money(amount) {
                    return '₱' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                }
                function refresh() {
                    var count = parseFloat(qty.value);
                    if (!isFinite(count) || count <= 0) count = 0;
                    var sum = Math.round(price * count * 100) / 100;
                    total.textContent = money(sum);
                    deposit.textContent = money(Math.round(sum * 50) / 100);
                }
                qty.addEventListener('input', refresh);
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (!$catalog): ?>
        <div class="empty-state"><i class="fa-solid fa-basket-shopping"></i><p>No products match this filter.</p></div>
    <?php endif; ?>
    <div class="row g-3">
        <?php foreach ($catalog as $coop): ?>
            <div class="col-lg-6">
                <article class="panel h-100">
                    <h2 class="h4"><?= e($coop['organization']) ?></h2>
                    <p class="muted"><?= e($coop['barangay']) ?>, <?= e($coop['municipality']) ?><?php if ($coop['distance_km'] !== null): ?> · <?= e(number_format((float) $coop['distance_km'], 1)) ?> km<?php endif; ?></p>
                    <?php foreach ($coop['catalog_products'] as $product): ?>
                        <?php $inStock = (float) $product['stock'] > 0; ?>
                        <div class="banner">
                            <div class="split">
                                <strong><?= e($product['name']) ?></strong>
                                <span><?= e(peso($product['price'])) ?> / <?= e($product['unit']) ?></span>
                            </div>
                            <p class="tiny mb-2"><?= e(category_label($product['category'])) ?> · stock <?= e($fmtQty($product['stock'])) ?> · minimum <?= e($fmtQty($product['moq'])) ?></p>
                            <?php if (!empty($product['description'])): ?><p class="mb-2"><?= nl_e((string) $product['description']) ?></p><?php endif; ?>
                            <?php if ($inStock): ?>
                                <a class="btn btn-agree btn-sm" href="<?= e($productsBase . '&product=' . (int) $product['id']) ?>">Order</a>
                            <?php else: ?>
                                <p class="tiny muted mb-0">Out of stock</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </article>
            </div>
        <?php endforeach; ?>
    </div>

<?php elseif ($tab === 'rfq'): ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <form method="post" action="buyer-dashboard.php?tab=rfq" class="panel stack-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_rfq">
                <h2>Request a quotation</h2>
                <div>
                    <label class="form-label" for="coop_id">Cooperative</label>
                    <select class="form-select" id="coop_id" name="coop_id" onchange="window.location = 'buyer-dashboard.php?tab=rfq&amp;coop=' + encodeURIComponent(this.value);">
                        <option value="0">All verified cooperatives in Ibaan</option>
                        <?php foreach ($coops as $coop): ?>
                            <option value="<?= (int) $coop['id'] ?>" <?= $selectedCoop === (int) $coop['id'] ? 'selected' : '' ?>><?= e($coop['organization']) ?> · <?= e($coop['barangay']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($coopProducts): ?>
                    <div>
                        <label class="form-label" for="product_id">Listing</label>
                        <select class="form-select" id="product_id" name="product_id">
                            <option value="0">Describe the item yourself</option>
                            <?php foreach ($coopProducts as $product): ?>
                                <option value="<?= (int) $product['id'] ?>" data-category="<?= e($product['category']) ?>" data-name="<?= e($product['name']) ?>" data-unit="<?= e($product['unit']) ?>" data-moq="<?= e($product['moq']) ?>"><?= e($product['name']) ?> · <?= e(peso($product['price'])) ?> / <?= e($product['unit']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="tiny muted mb-0" id="moq-hint">Choosing a listing locks the name, unit, and minimum order.</p>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="product_id" value="0">
                <?php endif; ?>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label" for="category">Category</label>
                        <select class="form-select" id="category" name="category">
                            <?php foreach (category_meta() as $key => $meta): ?>
                                <option value="<?= e($key) ?>" <?= $selectedCategory === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="item_name">Item</label>
                        <input class="form-control" id="item_name" name="item_name" required maxlength="150" value="<?= e($action === 'create_rfq' ? ($_POST['item_name'] ?? '') : '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="quantity">Quantity</label>
                        <input class="form-control" id="quantity" name="quantity" type="number" min="0.01" step="0.01" required value="<?= e($action === 'create_rfq' ? ($_POST['quantity'] ?? '') : '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="unit">Unit</label>
                        <input class="form-control" id="unit" name="unit" required maxlength="40" value="<?= e($action === 'create_rfq' ? ($_POST['unit'] ?? 'head') : 'head') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="needed_by">Needed by</label>
                        <input class="form-control" id="needed_by" name="needed_by" type="date" required value="<?= e($action === 'create_rfq' ? ($_POST['needed_by'] ?? '') : '') ?>">
                    </div>
                </div>
                <div>
                    <label class="form-label" for="delivery_address">Delivery address</label>
                    <input class="form-control" id="delivery_address" name="delivery_address" required maxlength="255" value="<?= e($action === 'create_rfq' ? ($_POST['delivery_address'] ?? $user['address_line']) : $user['address_line']) ?>">
                </div>
                <div>
                    <label class="form-label" for="notes">Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="2000"><?= e($action === 'create_rfq' ? ($_POST['notes'] ?? '') : '') ?></textarea>
                </div>
                <button class="btn btn-agree" type="submit">Send request</button>
            </form>
        </div>
        <div class="col-lg-5">
            <aside class="panel">
                <h2>What happens next</h2>
                <p class="mb-0">The cooperative replies with a price and a deposit. You accept it, upload the deposit receipt, and pay the rest when the order arrives.</p>
            </aside>
        </div>
    </div>

<?php elseif ($tab === 'quotes'): ?>
    <?php if (!$rfqs): ?>
        <div class="empty-state"><i class="fa-solid fa-comments-dollar"></i><p>You have not sent a request yet.</p></div>
    <?php endif; ?>
    <div class="row g-3">
        <?php foreach ($rfqs as $rfq): ?>
            <div class="col-lg-6">
                <article class="panel h-100">
                    <div class="split">
                        <h2 class="h5"><?= e($rfq['item_name']) ?></h2>
                        <?php
                        $hasPending = false;
                        foreach ($quotesByRfq[(int) $rfq['id']] ?? [] as $pendingQuoteRow) {
                            if ($pendingQuoteRow['status'] === 'pending') {
                                $hasPending = true;
                            }
                        }
                        echo rfq_plain_status((string) $rfq['status'], $hasPending);
                        ?>
                    </div>
                    <p><?= e(number_format((float) $rfq['quantity'], 2)) ?> <?= e($rfq['unit']) ?> · needed <?= e(date('M j, Y', strtotime($rfq['needed_by']))) ?></p>
                    <p class="muted"><?= $rfq['coop_name'] ? e($rfq['coop_name']) : 'All verified Ibaan cooperatives' ?></p>
                    <?php foreach ($quotesByRfq[(int) $rfq['id']] ?? [] as $quote): ?>
                        <div class="banner">
                            <strong><?= e($quote['coop_name']) ?></strong>
                            <?php
                            $quoteLabel = ['pending' => ['Ready to accept', 'gold'], 'accepted' => ['Accepted', 'ok'], 'declined' => ['Declined', 'bad'], 'withdrawn' => ['Withdrawn', 'muted']];
                            $quoteTone = $quoteLabel[$quote['status']] ?? ['In progress', 'info'];
                            echo plain_status($quoteTone[0], $quoteTone[1]);
                            ?>
                            <p class="mb-1"><?= e(peso($quote['unit_price'])) ?> / <?= e($rfq['unit']) ?> · total <?= e(peso($quote['total_amount'])) ?></p>
                            <p class="tiny mb-2">Deposit <?= (int) $quote['deposit_percent'] ?>% (<?= e(peso(round((float) $quote['total_amount'] * $quote['deposit_percent'] / 100, 2))) ?>) · deliver <?= e(date('M j, Y', strtotime($quote['delivery_date']))) ?> · valid until <?= e(date('M j', strtotime($quote['valid_until']))) ?></p>
                            <?php if ($quote['notes']): ?><p><?= nl_e($quote['notes']) ?></p><?php endif; ?>
                            <?php if ($quote['status'] === 'pending' && in_array($rfq['status'], ['open', 'quoted'], true)): ?>
                                <div class="d-flex gap-2">
                                    <form method="post" action="buyer-dashboard.php?tab=quotes" data-confirm="Accept this binding quote and create the order?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="accept_quote">
                                        <input type="hidden" name="quote_id" value="<?= (int) $quote['id'] ?>">
                                        <button class="btn btn-agree btn-sm" type="submit">Accept this price</button>
                                    </form>
                                    <form method="post" action="buyer-dashboard.php?tab=quotes">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="decline_quote">
                                        <input type="hidden" name="quote_id" value="<?= (int) $quote['id'] ?>">
                                        <button class="btn btn-outline-agree btn-sm" type="submit">Decline</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($quotesByRfq[(int) $rfq['id']])): ?>
                        <p class="tiny muted">Waiting for a cooperative to price this request.</p>
                    <?php endif; ?>
                </article>
            </div>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <?php if (!$orders): ?>
        <div class="empty-state"><i class="fa-solid fa-box"></i><p>Accepted quotes will show up here as orders.</p></div>
    <?php endif; ?>
    <div class="row g-3">
        <?php foreach ($orders as $order): ?>
            <div class="col-lg-6">
                <article class="panel h-100">
                    <div class="split">
                        <h2 class="h5"><?= e($order['order_code']) ?></h2>
                        <?= order_plain_status($order) ?>
                    </div>
                    <p class="mb-1"><?= e($order['item_summary']) ?> · <?= e(number_format((float) $order['quantity'], 2)) ?> <?= e($order['unit']) ?></p>
                    <p class="muted"><?= e($order['coop_name']) ?> · deliver <?= e(date('M j, Y', strtotime($order['delivery_date']))) ?></p>
                    <p>Total <?= e(peso($order['total_amount'])) ?> · deposit <?= e(peso($order['deposit_amount'])) ?> · pay on delivery <?= e(peso($order['balance_due'])) ?></p>
                    <p><a class="btn btn-outline-agree btn-sm" href="messages.php?with=<?= (int) $order['coop_id'] ?>">Message the cooperative</a></p>
                    <?php if ($order['order_status'] === 'in_transit'): ?>
                        <form method="post" action="buyer-dashboard.php?tab=orders" class="mb-3" data-confirm="Confirm that this order arrived?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="confirm_delivery">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <button class="btn btn-agree" type="submit">I received this order</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($order['payment_status'] === 'awaiting_deposit' && $order['order_status'] === 'pending_payment'): ?>
                        <form method="post" action="buyer-dashboard.php?tab=orders" enctype="multipart/form-data" class="stack-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="upload_receipt">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <div>
                                <label class="form-label">Deposit amount</label>
                                <input class="form-control" name="amount" type="number" step="0.01" required value="<?= e($order['deposit_amount']) ?>">
                            </div>
                            <div>
                                <label class="form-label">Bank reference</label>
                                <input class="form-control" name="reference_no" maxlength="80" placeholder="Transfer reference">
                            </div>
                            <div>
                                <label class="form-label">Proof of deposit</label>
                                <input class="form-control" name="receipt" type="file" required accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
                            </div>
                            <button class="btn btn-agree" type="submit">Upload receipt</button>
                        </form>
                        <form method="post" action="buyer-dashboard.php?tab=orders" class="mt-2" data-confirm="Cancel this order?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel_order">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <button class="btn btn-outline-agree btn-sm" type="submit">Cancel order</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($order['order_status'] === 'fulfilled' && $order['rating'] === null): ?>
                        <form method="post" action="buyer-dashboard.php?tab=orders" class="stack-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_review">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <label class="form-label">Rate this delivery</label>
                            <select class="form-select" name="rating" required>
                                <option value="5">5 — excellent</option>
                                <option value="4">4 — good</option>
                                <option value="3">3 — okay</option>
                                <option value="2">2 — poor</option>
                                <option value="1">1 — not acceptable</option>
                            </select>
                            <textarea class="form-control" name="comment" rows="2" maxlength="500" placeholder="What should the next buyer know?"></textarea>
                            <button class="btn btn-gold" type="submit">Save rating</button>
                        </form>
                    <?php elseif ($order['rating'] !== null): ?>
                        <p class="mb-0"><?= stars((float) $order['rating']) ?> <?= e((string) $order['comment']) ?></p>
                    <?php endif; ?>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
