<?php
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}

date_default_timezone_set('Asia/Manila');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header(
    "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
    . "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com data:; "
    . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
    . "connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'"
);

require_once __DIR__ . '/db.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function nl_e(?string $value): string
{
    return nl2br(e($value));
}

function redirect(string $path): void
{
    if (preg_match('#^(https?:)?//#i', $path) || str_contains($path, "\n") || str_contains($path, "\r")) {
        $path = 'index.php';
    }
    header('Location: ' . $path);
    exit;
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return is_string($value) ? $value : null;
}

function render_flashes(): void
{
    $map = ['success' => 'success', 'error' => 'danger', 'info' => 'warning'];
    foreach ($map as $key => $class) {
        $message = flash($key);
        if ($message) {
            echo '<div class="alert alert-' . $class . ' alert-dismissible fade show" role="' . ($key === 'error' ? 'alert' : 'status') . '">'
                . e($message)
                . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button></div>';
        }
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(string $fallback = 'index.php'): void
{
    $sent = $_POST['csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !is_string($known) || $known === '' || !hash_equals($known, $sent)) {
        flash('error', 'Your session expired. Please try again.');
        redirect($fallback);
    }
}

function try_db(): ?PDO
{
    try {
        return db();
    } catch (Throwable $e) {
        return null;
    }
}

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $pdo = try_db();
    if (!$pdo) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if ($user === null) {
        unset($_SESSION['user_id']);
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please sign in to continue.');
        redirect('login.php');
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        flash('error', 'That page is for a different account type.');
        redirect(dashboard_for((string) $user['role']));
    }
    return $user;
}

function dashboard_for(string $role): string
{
    switch ($role) {
        case 'cooperative':
            return 'coop-dashboard.php';
        case 'buyer':
            return 'buyer-dashboard.php';
        case 'admin':
            return 'admin-dashboard.php';
        default:
            return 'index.php';
    }
}

function peso(float|int|string|null $amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return '₱' . number_format((float) $amount, 2);
}

function category_meta(): array
{
    return [
        'live_chicken' => ['label' => 'Live chicken', 'unit' => 'head', 'icon' => 'fa-drumstick-bite'],
        'dressed_chicken' => ['label' => 'Dressed chicken', 'unit' => 'kg', 'icon' => 'fa-box-open'],
        'table_eggs' => ['label' => 'Table eggs', 'unit' => 'tray', 'icon' => 'fa-egg'],
        'agri_supply' => ['label' => 'Cooperative supply', 'unit' => 'pack', 'icon' => 'fa-wheat-awn'],
    ];
}

function category_label(string $key): string
{
    $meta = category_meta();
    return $meta[$key]['label'] ?? $key;
}

function business_label(?string $type): string
{
    switch ($type) {
        case 'restaurant':
            return 'Restaurant';
        case 'institutional':
            return 'Institutional food service';
        case 'commercial':
            return 'Commercial buyer';
        case 'agricultural_cooperative':
            return 'Agricultural cooperative';
        case 'municipal_registry':
            return 'Municipal registry';
        default:
            return 'Business';
    }
}

function role_label(string $role): string
{
    switch ($role) {
        case 'cooperative':
            return 'Cooperative';
        case 'buyer':
            return 'Buyer';
        case 'admin':
            return 'Registry';
        default:
            return $role;
    }
}

function display_name(array $user): string
{
    $org = trim((string) ($user['organization'] ?? ''));
    return $org !== '' ? $org : (string) ($user['full_name'] ?? '');
}

function iba_barangays(): array
{
    return [
        'Poblacion' => [13.8192, 121.1328],
        'Bago' => [13.8055, 121.1488],
        'Balanga' => [13.8310, 121.1185],
        'Bungahan' => [13.8422, 121.1510],
        'Calamias' => [13.8088, 121.1195],
        'Catandala' => [13.8265, 121.1602],
        'Coliat' => [13.7995, 121.1270],
        'Dayapan' => [13.8370, 121.1240],
        'Lapu-lapu' => [13.8140, 121.1455],
        'Lucsuhin' => [13.7908, 121.1388],
        'Mabalor' => [13.8510, 121.1395],
        'Malainin' => [13.8288, 121.1088],
        'Matala' => [13.8012, 121.1555],
        'Munting Tubig' => [13.8455, 121.1120],
        'Palindan' => [13.7860, 121.1215],
        'Pangao' => [13.8335, 121.1710],
        'Quilo' => [13.8125, 121.1080],
        'Sabang' => [13.7960, 121.1640],
        'Salaban I' => [13.8220, 121.1415],
        'Salaban II' => [13.8248, 121.1480],
        'San Agustin' => [13.8070, 121.1705],
        'Sandalan' => [13.8388, 121.1558],
        'Santo Niño' => [13.8166, 121.1210],
        'Talaibon' => [13.7745, 121.1460],
        'Tulay na Patpat' => [13.8480, 121.1288],
    ];
}

function nearby_municipalities(): array
{
    return [
        'Ibaan' => [13.8176, 121.1332],
        'Batangas City' => [13.7565, 121.0583],
        'Rosario' => [13.8460, 121.2060],
        'San Jose' => [13.8770, 121.1020],
        'Lipa City' => [13.9411, 121.1631],
        'Padre Garcia' => [13.8780, 121.2130],
        'Taysan' => [13.7830, 121.2050],
    ];
}

function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earth = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earth * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

function coords_for(string $municipality, string $barangay): array
{
    if ($municipality === 'Ibaan' && isset(iba_barangays()[$barangay])) {
        return iba_barangays()[$barangay];
    }
    $towns = nearby_municipalities();
    return $towns[$municipality] ?? $towns['Ibaan'];
}

function clip(string $value, int $max): string
{
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function posted(string $key, int $max = 255): string
{
    return clip((string) ($_POST[$key] ?? ''), $max);
}

function require_email(string $value): string
{
    $value = strtolower(trim($value));
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }
    return $value;
}

function require_phone(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^[0-9+\-\s()]{7,30}$/', $value)) {
        throw new RuntimeException('Enter a valid contact number.');
    }
    return $value;
}

function require_date(string $value, string $label, bool $allowPast = false): string
{
    $value = trim($value);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        throw new RuntimeException($label . ' must be a valid date.');
    }
    if (!$allowPast && $dt < new DateTimeImmutable('today')) {
        throw new RuntimeException($label . ' cannot be in the past.');
    }
    return $dt->format('Y-m-d');
}

function require_qty(string $value, string $label = 'Quantity'): float
{
    if (!is_numeric($value)) {
        throw new RuntimeException($label . ' must be a number.');
    }
    $number = round((float) $value, 2);
    if ($number <= 0 || $number > 100000) {
        throw new RuntimeException($label . ' is out of range.');
    }
    return $number;
}

function require_money(string $value, string $label): float
{
    if (!is_numeric($value)) {
        throw new RuntimeException($label . ' must be a number.');
    }
    $number = round((float) $value, 2);
    if ($number <= 0 || $number > 1000000) {
        throw new RuntimeException($label . ' is out of range.');
    }
    return $number;
}

function save_upload(array $file, string $folder): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Please choose a file to upload.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Please try a smaller file.');
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('File must be 5 MB or smaller.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']);
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($map[$mime])) {
        throw new RuntimeException('Upload a JPG, PNG, WEBP, or PDF file.');
    }
    $prefix = $folder === 'receipts' ? 'receipt-' : 'permit-';
    $name = $prefix . bin2hex(random_bytes(16)) . '.' . $map[$mime];
    $dest = __DIR__ . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not store the uploaded file.');
    }
    return $name;
}

function upload_abspath(string $relative): ?string
{
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
        return null;
    }
    $name = basename($relative);
    if (!preg_match('/^(cda-[a-z0-9-]+|sample-deposit|permit-[a-f0-9]{32}|receipt-[a-f0-9]{32})\.(png|jpe?g|webp|pdf)$/i', $name)) {
        return null;
    }
    $root = realpath(__DIR__);
    $full = realpath(__DIR__ . DIRECTORY_SEPARATOR . $name);
    if ($root === false || $full === false || !is_file($full)) {
        return null;
    }
    $rootPrefix = rtrim($root, '\\/') . DIRECTORY_SEPARATOR;
    if (!str_starts_with($full, $rootPrefix)) {
        return null;
    }
    return $full;
}

function order_code(): string
{
    return 'AGR-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
}

function coop_can_trade(array $user): bool
{
    return ($user['role'] ?? '') === 'cooperative' && ($user['verification_status'] ?? '') === 'approved';
}

function record_price_snapshot(PDO $pdo): void
{
    $rows = $pdo->query(
        "SELECT p.category, ROUND(AVG(p.price), 2) AS avg_price, COUNT(*) AS sample_size
         FROM products p
         JOIN users u ON u.id = p.coop_id
         WHERE p.is_active = 1 AND p.category <> 'agri_supply'
           AND u.role = 'cooperative' AND u.verification_status = 'approved' AND u.is_active = 1
         GROUP BY p.category"
    )->fetchAll();
    $stmt = $pdo->prepare(
        'INSERT INTO price_snapshots (category, avg_price, sample_size, recorded_on)
         VALUES (?, ?, ?, CURDATE())
         ON DUPLICATE KEY UPDATE avg_price = VALUES(avg_price), sample_size = VALUES(sample_size)'
    );
    foreach ($rows as $row) {
        $stmt->execute([$row['category'], $row['avg_price'], $row['sample_size']]);
    }
}

function category_market_average(PDO $pdo, string $category): ?float
{
    if ($category === 'agri_supply' || !isset(category_meta()[$category])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT AVG(price) FROM products WHERE category = ? AND is_active = 1');
    $stmt->execute([$category]);
    $average = $stmt->fetchColumn();
    if ($average === null || $average === false) {
        return null;
    }
    return round((float) $average, 2);
}

function price_above_market(float $price, ?float $average): bool
{
    if ($average === null || $average <= 0) {
        return false;
    }
    return (($price - $average) / $average) > 0.15;
}

function market_board(PDO $pdo): array
{
    $meta = category_meta();
    unset($meta['agri_supply']);
    $current = $pdo->query(
        "SELECT p.category, ROUND(AVG(p.price), 2) AS avg_price, COUNT(*) AS samples
         FROM products p
         JOIN users u ON u.id = p.coop_id
         WHERE p.is_active = 1 AND u.verification_status = 'approved' AND u.is_active = 1
         GROUP BY p.category"
    )->fetchAll();
    $map = [];
    foreach ($current as $row) {
        $map[$row['category']] = $row;
    }
    $pastStmt = $pdo->prepare(
        'SELECT avg_price FROM price_snapshots
         WHERE category = ? AND recorded_on <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
         ORDER BY recorded_on DESC LIMIT 1'
    );
    $board = [];
    foreach ($meta as $key => $info) {
        $avg = isset($map[$key]) ? (float) $map[$key]['avg_price'] : null;
        $pastStmt->execute([$key]);
        $past = $pastStmt->fetchColumn();
        $change = ($avg !== null && $past !== false) ? $avg - (float) $past : null;
        $board[] = [
            'key' => $key,
            'label' => $info['label'],
            'unit' => $info['unit'],
            'icon' => $info['icon'],
            'avg' => $avg,
            'samples' => (int) ($map[$key]['samples'] ?? 0),
            'change' => $change,
        ];
    }
    return $board;
}

function build_price_chart(PDO $pdo): array
{
    record_price_snapshot($pdo);
    $start = new DateTimeImmutable('today');
    $start = $start->modify('-13 days');
    $labels = [];
    $keys = [];
    for ($i = 0; $i < 14; $i++) {
        $day = $start->modify('+' . $i . ' days');
        $iso = $day->format('Y-m-d');
        $keys[] = $iso;
        $labels[] = $day->format('M j');
    }
    $stmt = $pdo->prepare(
        'SELECT category, avg_price, recorded_on FROM price_snapshots WHERE recorded_on >= ?'
    );
    $stmt->execute([$keys[0]]);
    $lookup = [];
    foreach ($stmt as $row) {
        $lookup[$row['category']][$row['recorded_on']] = round((float) $row['avg_price'], 2);
    }
    $series = [];
    foreach (['live_chicken', 'dressed_chicken', 'table_eggs'] as $cat) {
        $series[$cat] = [];
        foreach ($keys as $iso) {
            $series[$cat][] = $lookup[$cat][$iso] ?? null;
        }
    }
    return ['labels' => $labels, 'series' => $series];
}

function render_price_cards(array $board): void
{
    echo '<div class="row g-3">';
    foreach ($board as $item) {
        $changeHtml = '<span class="delta flat">No week-ago index yet</span>';
        if ($item['change'] !== null) {
            $change = (float) $item['change'];
            if ($change > 0.009) {
                $cls = 'up';
                $icon = 'fa-arrow-trend-up';
            } elseif ($change < -0.009) {
                $cls = 'down';
                $icon = 'fa-arrow-trend-down';
            } else {
                $cls = 'flat';
                $icon = 'fa-minus';
            }
            $changeHtml = '<span class="delta ' . $cls . '"><i class="fa-solid ' . $icon . '"></i> '
                . e(peso(abs($change))) . ' vs last week</span>';
        }
        $avg = $item['avg'] === null ? '—' : peso($item['avg']);
        echo '<div class="col-md-4"><article class="price-card">';
        echo '<div class="price-card-icon"><i class="fa-solid ' . e($item['icon']) . '"></i></div>';
        echo '<p class="gold-kicker">' . e($item['label']) . '</p>';
        echo '<p class="price-figure">' . e($avg) . ' <small>/ ' . e($item['unit']) . '</small></p>';
        echo $changeHtml;
        echo '<p class="muted tiny">' . (int) $item['samples'] . ' active cooperative listing'
            . ((int) $item['samples'] === 1 ? '' : 's') . ' in Ibaan</p>';
        echo '</article></div>';
    }
    echo '</div>';
}

function stars(float $rating): string
{
    $full = (int) round($rating);
    $html = '<span class="stars" aria-label="' . e(number_format($rating, 1)) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $full
            ? '<i class="fa-solid fa-star"></i>'
            : '<i class="fa-regular fa-star"></i>';
    }
    return $html . '</span>';
}

function status_badge(string $status): string
{
    $tones = [
        'pending' => 'wait',
        'approved' => 'ok',
        'rejected' => 'bad',
        'open' => 'info',
        'quoted' => 'gold',
        'accepted' => 'ok',
        'closed' => 'muted',
        'cancelled' => 'bad',
        'declined' => 'bad',
        'withdrawn' => 'muted',
        'pending_payment' => 'gold',
        'processing' => 'info',
        'in_transit' => 'info',
        'fulfilled' => 'ok',
        'awaiting_deposit' => 'gold',
        'deposit_review' => 'wait',
        'deposit_verified' => 'ok',
        'cod_balance' => 'info',
        'settled' => 'ok',
    ];
    $class = $tones[$status] ?? 'muted';
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="status status-' . e($class) . '">' . e($label) . '</span>';
}

function plain_status(string $label, string $tone): string
{
    return '<span class="status status-' . e($tone) . '">' . e($label) . '</span>';
}

function rfq_plain_status(string $rfqStatus, bool $hasPendingQuote): string
{
    if ($rfqStatus === 'cancelled') {
        return plain_status('Cancelled', 'bad');
    }
    if ($rfqStatus === 'accepted') {
        return plain_status('Price accepted', 'ok');
    }
    if ($rfqStatus === 'quoted' || $hasPendingQuote) {
        return plain_status('Price received', 'gold');
    }
    if ($rfqStatus === 'open') {
        return plain_status('Waiting for a price', 'info');
    }
    return plain_status('Closed', 'muted');
}

function order_plain_status(array $order): string
{
    $stage = (string) ($order['order_status'] ?? '');
    $pay = (string) ($order['payment_status'] ?? '');
    if ($stage === 'cancelled') {
        return plain_status('Cancelled', 'bad');
    }
    if ($pay === 'awaiting_deposit') {
        return plain_status('Pay the deposit', 'gold');
    }
    if ($pay === 'deposit_review') {
        return plain_status('Deposit being checked', 'wait');
    }
    if ($stage === 'in_transit') {
        return plain_status('On the way', 'info');
    }
    if ($stage === 'fulfilled' && $pay === 'cod_balance') {
        return plain_status('Pay the rest on delivery', 'info');
    }
    if ($stage === 'fulfilled' || $pay === 'settled') {
        return plain_status('Finished', 'ok');
    }
    if ($pay === 'deposit_verified' || $stage === 'processing') {
        return plain_status('Preparing the order', 'ok');
    }
    return plain_status('In progress', 'info');
}

function short_time(string $dt): string
{
    $ts = strtotime($dt);
    if ($ts === false) {
        return '';
    }
    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('g:i A', $ts);
    }
    return date('M j, g:i A', $ts);
}

function unread_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function fetch_cooperatives(PDO $pdo): array
{
    $coops = $pdo->query(
        "SELECT u.id, u.organization, u.full_name, u.barangay, u.municipality, u.province,
                u.latitude, u.longitude, u.phone, u.verification_status,
                (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.coop_id = u.id) AS rating,
                (SELECT COUNT(*) FROM reviews r WHERE r.coop_id = u.id) AS rating_count,
                (SELECT COUNT(*) FROM orders o WHERE o.coop_id = u.id AND o.order_status = 'fulfilled') AS fulfilled_orders
         FROM users u
         WHERE u.role = 'cooperative' AND u.verification_status = 'approved' AND u.is_active = 1
         ORDER BY u.organization"
    )->fetchAll();

    $products = $pdo->query(
        'SELECT id, coop_id, category, name, description, unit, price, stock, moq
         FROM products WHERE is_active = 1 ORDER BY price'
    )->fetchAll();
    $byCoop = [];
    foreach ($products as $product) {
        $byCoop[(int) $product['coop_id']][] = $product;
    }
    foreach ($coops as &$coop) {
        $coop['products'] = $byCoop[(int) $coop['id']] ?? [];
        $coop['distance_km'] = null;
    }
    unset($coop);
    return $coops;
}

function apply_distance(array $coops, ?float $lat, ?float $lng): array
{
    foreach ($coops as &$coop) {
        if ($lat !== null && $lng !== null && $coop['latitude'] !== null && $coop['longitude'] !== null) {
            $coop['distance_km'] = haversine($lat, $lng, (float) $coop['latitude'], (float) $coop['longitude']);
        }
    }
    unset($coop);
    return $coops;
}

function render_coop_cards(array $coops, ?array $viewer): void
{
    if (!$coops) {
        echo '<div class="empty-state"><i class="fa-solid fa-store"></i><p>No verified cooperatives match this filter.</p></div>';
        return;
    }
    echo '<div class="row g-3">';
    foreach ($coops as $coop) {
        echo '<div class="col-md-6 col-xl-4"><article class="coop-card h-100">';
        echo '<header class="coop-card-head">';
        echo '<div><p class="gold-kicker">Ibaan cooperative</p><h3>' . e($coop['organization']) . '</h3>';
        echo '<p class="meta"><i class="fa-solid fa-location-dot"></i> '
            . e($coop['barangay'] . ', ' . $coop['municipality']) . '</p></div>';
        echo '<div class="rating-block">';
        if ($coop['rating'] !== null) {
            echo stars((float) $coop['rating']);
            echo '<span>' . e(number_format((float) $coop['rating'], 1)) . '</span>';
            echo '<small>' . (int) $coop['rating_count'] . ' review'
                . ((int) $coop['rating_count'] === 1 ? '' : 's') . '</small>';
        } else {
            echo '<small>No reviews yet</small>';
        }
        echo '</div></header>';
        if ($coop['distance_km'] !== null) {
            echo '<p class="distance"><i class="fa-solid fa-route"></i> '
                . e(number_format((float) $coop['distance_km'], 1)) . ' km from your business</p>';
        }
        echo '<ul class="price-pills">';
        $shown = 0;
        foreach ($coop['products'] as $product) {
            if ($product['category'] === 'agri_supply') {
                continue;
            }
            $meta = category_meta()[$product['category']] ?? null;
            $icon = $meta['icon'] ?? 'fa-tag';
            echo '<li><i class="fa-solid ' . e($icon) . '"></i><span>' . e(category_label($product['category']))
                . '</span><strong>' . e(peso($product['price'])) . '</strong><em>/ ' . e($product['unit'])
                . ' · MOQ ' . e(rtrim(rtrim(number_format((float) $product['moq'], 2), '0'), '.')) . '</em></li>';
            $shown++;
            if ($shown >= 3) {
                break;
            }
        }
        if ($shown === 0) {
            echo '<li><span>No poultry listings yet</span></li>';
        }
        echo '</ul>';
        echo '<footer class="coop-card-foot"><span class="verified-pill"><i class="fa-solid fa-certificate"></i> Permit verified</span>';
        echo '<span class="tiny muted">' . (int) $coop['fulfilled_orders'] . ' fulfilled</span>';
        if (!$viewer) {
            echo '<a class="btn btn-agree btn-sm" href="register.php?role=buyer">Register to request a quote</a>';
        } elseif ($viewer['role'] === 'buyer') {
            echo '<a class="btn btn-agree btn-sm" href="buyer-dashboard.php?tab=products&amp;coop='
                . (int) $coop['id'] . '">See products</a>';
        }
        echo '</footer></article></div>';
    }
    echo '</div>';
}

function navigation(string $role): array
{
    $messages = ['messages', 'messages.php', 'fa-comments', 'Messages'];
    $profile = ['profile', 'profile.php', 'fa-user', 'Profile'];
    if ($role === 'cooperative') {
        return [
            ['overview', 'coop-dashboard.php', 'fa-gauge-high', 'Home'],
            ['inventory', 'coop-dashboard.php?tab=inventory', 'fa-boxes-stacked', 'Products'],
            ['rfqs', 'coop-dashboard.php?tab=rfqs', 'fa-file-signature', 'Price requests'],
            ['orders', 'coop-dashboard.php?tab=orders', 'fa-truck', 'Orders'],
            ['payments', 'coop-dashboard.php?tab=payments', 'fa-receipt', 'Payments'],
            $messages,
            $profile,
        ];
    }
    if ($role === 'buyer') {
        return [
            ['overview', 'buyer-dashboard.php', 'fa-chart-line', 'Prices'],
            ['suppliers', 'buyer-dashboard.php?tab=suppliers', 'fa-store', 'Cooperatives'],
            ['products', 'buyer-dashboard.php?tab=products', 'fa-basket-shopping', 'Products'],
            ['rfq', 'buyer-dashboard.php?tab=rfq', 'fa-file-circle-plus', 'Request a price'],
            ['quotes', 'buyer-dashboard.php?tab=quotes', 'fa-comments-dollar', 'Quotes'],
            ['orders', 'buyer-dashboard.php?tab=orders', 'fa-box', 'Orders'],
            $messages,
            $profile,
        ];
    }
    return [
        ['overview', 'admin-dashboard.php', 'fa-chart-pie', 'Summary'],
        ['verify', 'admin-dashboard.php?tab=verify', 'fa-user-check', 'Check permits'],
        ['prices', 'admin-dashboard.php?tab=prices', 'fa-scale-balanced', 'Check prices'],
        ['trades', 'admin-dashboard.php?tab=trades', 'fa-handshake', 'Orders'],
        $messages,
        $profile,
    ];
}

function render_side_links(array $items, string $active, int $unread): void
{
    echo '<nav class="side-links" aria-label="Account">';
    foreach ($items as $item) {
        [$key, $href, $icon, $label] = $item;
        $class = $key === $active ? ' class="active"' : '';
        $current = $key === $active ? ' aria-current="page"' : '';
        echo '<a' . $class . ' href="' . e($href) . '"' . $current . '>';
        echo '<i class="fa-solid ' . e($icon) . '"></i><span>' . e($label) . '</span>';
        if ($key === 'messages' && $unread > 0) {
            echo '<em class="count">' . (int) $unread . '</em>';
        }
        echo '</a>';
    }
    echo '</nav>';
}

function can_message(PDO $pdo, array $user, int $otherId): bool
{
    if ($otherId <= 0 || $otherId === (int) $user['id']) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT id, role, verification_status, is_active FROM users WHERE id = ?');
    $stmt->execute([$otherId]);
    $other = $stmt->fetch();
    if (!$other || !(int) $other['is_active']) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM messages
         WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)'
    );
    $stmt->execute([(int) $user['id'], $otherId, $otherId, (int) $user['id']]);
    if ((int) $stmt->fetchColumn() > 0) {
        return true;
    }
    if ($user['role'] === 'admin' || $other['role'] === 'admin') {
        return true;
    }
    if ($user['role'] === 'buyer' && $other['role'] === 'cooperative' && $other['verification_status'] === 'approved') {
        return true;
    }
    if ($user['role'] === 'cooperative' && $user['verification_status'] === 'approved' && $other['role'] === 'buyer') {
        return true;
    }
    return false;
}

function send_message(PDO $pdo, array $sender, int $receiverId, string $body): int
{
    $body = trim($body);
    if ($body === '') {
        throw new RuntimeException('Write a message first.');
    }
    if (function_exists('mb_strlen') ? mb_strlen($body) > 1000 : strlen($body) > 1000) {
        throw new RuntimeException('Messages are limited to 1000 characters.');
    }
    if (!can_message($pdo, $sender, $receiverId)) {
        throw new RuntimeException('You cannot message that account.');
    }
    $stmt = $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)');
    $stmt->execute([(int) $sender['id'], $receiverId, $body]);
    return (int) $pdo->lastInsertId();
}

function message_candidates(PDO $pdo, array $user): array
{
    if ($user['role'] === 'admin') {
        $sql = "SELECT id, organization, full_name, role FROM users
                WHERE id <> ? AND is_active = 1 ORDER BY organization";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([(int) $user['id']]);
        return $stmt->fetchAll();
    }
    if ($user['role'] === 'buyer') {
        $stmt = $pdo->query(
            "SELECT id, organization, full_name, role FROM users
             WHERE role = 'cooperative' AND verification_status = 'approved' AND is_active = 1
             ORDER BY organization"
        );
        return $stmt->fetchAll();
    }
    if ($user['verification_status'] !== 'approved') {
        $stmt = $pdo->query(
            "SELECT id, organization, full_name, role FROM users WHERE role = 'admin' AND is_active = 1"
        );
        return $stmt->fetchAll();
    }
    $stmt = $pdo->query(
        "SELECT id, organization, full_name, role FROM users
         WHERE role IN ('buyer', 'admin') AND is_active = 1 ORDER BY organization"
    );
    return $stmt->fetchAll();
}

function file_preview(string $url, string $path, string $alt): string
{
    if (!upload_abspath($path)) {
        return '<p class="muted tiny">The file is no longer on the server.</p>';
    }
    if (str_ends_with(strtolower($path), '.pdf')) {
        return '<iframe class="doc-frame" src="' . e($url) . '" title="' . e($alt) . '"></iframe>';
    }
    return '<img class="doc-preview" src="' . e($url) . '" alt="' . e($alt) . '">';
}

function delivery_calendar(array $orders, int $days = 14): array
{
    $map = [];
    foreach ($orders as $order) {
        $map[$order['delivery_date']][] = $order;
    }
    $cells = [];
    $start = new DateTimeImmutable('today');
    for ($i = 0; $i < $days; $i++) {
        $day = $start->modify('+' . $i . ' days');
        $iso = $day->format('Y-m-d');
        $cells[] = [
            'iso' => $iso,
            'label' => $day->format('D'),
            'num' => $day->format('j'),
            'today' => $i === 0,
            'orders' => $map[$iso] ?? [],
        ];
    }
    return $cells;
}
