<?php
require_once __DIR__ . '/bootstrap.php';

$user = require_login();
$pdo = db();
$type = (string) ($_GET['type'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$path = null;

if ($type === 'permit' && $id > 0) {
    $stmt = $pdo->prepare('SELECT id, permit_path FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $allowed = $row && ($user['role'] === 'admin' || (int) $user['id'] === (int) $row['id']);
    $path = $allowed ? (string) ($row['permit_path'] ?? '') : null;
} elseif ($type === 'receipt' && $id > 0) {
    $stmt = $pdo->prepare(
        'SELECT p.receipt_path, p.payer_id, o.coop_id
         FROM payments p
         JOIN orders o ON o.id = p.order_id
         WHERE p.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $allowed = $row && (
        $user['role'] === 'admin'
        || (int) $user['id'] === (int) $row['payer_id']
        || (int) $user['id'] === (int) $row['coop_id']
    );
    $path = $allowed ? (string) $row['receipt_path'] : null;
}

$full = $path ? upload_abspath($path) : null;
if (!$full) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($full);
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    echo 'This file type cannot be shown.';
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($full));
header('Content-Disposition: inline; filename="' . rawurlencode(basename($full)) . '"');
header('Cache-Control: private, max-age=0, no-store');
readfile($full);
