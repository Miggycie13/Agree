<?php
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Sign in required.']);
    exit;
}

$pdo = db();
$withId = (int) ($_REQUEST['with'] ?? 0);
if (!can_message($pdo, $user, $withId)) {
    http_response_code(403);
    echo json_encode(['error' => 'You cannot open that conversation.']);
    exit;
}

function chat_payload(array $row, int $userId): array
{
    return [
        'id' => (int) $row['id'],
        'mine' => (int) $row['sender_id'] === $userId,
        'body' => (string) $row['body'],
        'time' => short_time((string) $row['created_at']),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $sent = $_POST['csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !is_string($known) || $known === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        echo json_encode(['error' => 'Your session expired. Reload the page.']);
        exit;
    }
    try {
        $id = send_message($pdo, $user, $withId, (string) ($_POST['body'] ?? ''));
        $stmt = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        echo json_encode(['ok' => true, 'message' => chat_payload($row, (int) $user['id'])]);
    } catch (RuntimeException $ex) {
        http_response_code(422);
        echo json_encode(['error' => $ex->getMessage()]);
    }
    exit;
}

$after = (int) ($_GET['after'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT * FROM messages
     WHERE id > ? AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
     ORDER BY id ASC
     LIMIT 100'
);
$me = (int) $user['id'];
$stmt->execute([$after, $me, $withId, $withId, $me]);
$rows = $stmt->fetchAll();
if ($rows) {
    $mark = $pdo->prepare(
        'UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0 AND id > ?'
    );
    $mark->execute([$withId, $me, $after]);
}
$messages = [];
foreach ($rows as $row) {
    $messages[] = chat_payload($row, $me);
}
echo json_encode(['messages' => $messages]);
