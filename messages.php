<?php
require_once __DIR__ . '/bootstrap.php';

$user = require_login();
$pdo = db();
$me = (int) $user['id'];
$withId = (int) ($_GET['with'] ?? $_POST['with'] ?? 0);
$formError = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf('messages.php' . ($withId ? '?with=' . $withId : ''));
    $withId = (int) ($_POST['with'] ?? 0);
    try {
        send_message($pdo, $user, $withId, (string) ($_POST['body'] ?? ''));
        redirect('messages.php?with=' . $withId);
    } catch (RuntimeException $ex) {
        $formError = $ex->getMessage();
    }
}

$partner = null;
if ($withId > 0) {
    if (!can_message($pdo, $user, $withId)) {
        flash('error', 'You cannot open that conversation.');
        redirect('messages.php');
    }
    $stmt = $pdo->prepare('SELECT id, organization, full_name, role, verification_status, barangay, municipality FROM users WHERE id = ?');
    $stmt->execute([$withId]);
    $partner = $stmt->fetch() ?: null;
    $mark = $pdo->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0');
    $mark->execute([$withId, $me]);
}

$stmt = $pdo->prepare(
    'SELECT m.*, IF(m.sender_id = ?, m.receiver_id, m.sender_id) AS other_id
     FROM messages m
     WHERE m.sender_id = ? OR m.receiver_id = ?
     ORDER BY m.id DESC'
);
$stmt->execute([$me, $me, $me]);
$conversations = [];
foreach ($stmt as $row) {
    $other = (int) $row['other_id'];
    if (!isset($conversations[$other])) {
        $conversations[$other] = ['last' => $row, 'unread' => 0];
    }
    if ((int) $row['sender_id'] !== $me && !(int) $row['is_read']) {
        $conversations[$other]['unread']++;
    }
}
if ($withId && !isset($conversations[$withId]) && $partner) {
    $conversations = [$withId => ['last' => ['body' => '', 'created_at' => date('Y-m-d H:i:s'), 'sender_id' => $me], 'unread' => 0]] + $conversations;
}

$people = [];
$ids = array_keys($conversations);
if ($ids) {
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $lookup = $pdo->prepare("SELECT id, organization, full_name, role, verification_status FROM users WHERE id IN ($marks)");
    $lookup->execute($ids);
    foreach ($lookup as $person) {
        $people[(int) $person['id']] = $person;
    }
}

$thread = [];
if ($partner) {
    $stmt = $pdo->prepare(
        'SELECT * FROM messages
         WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
         ORDER BY id DESC LIMIT 80'
    );
    $stmt->execute([$me, $withId, $withId, $me]);
    $thread = array_reverse($stmt->fetchAll());
}
$latestId = $thread ? (int) $thread[count($thread) - 1]['id'] : 0;
$candidates = message_candidates($pdo, $user);

$pageTitle = 'Messages';
$layout = 'app';
$navKey = 'messages';
require __DIR__ . '/header.php';
?>
<div class="page-head">
    <p class="gold-kicker">Negotiation</p>
    <h1>Messages</h1>
    <p class="muted">Coordinate delivery windows and quote details with Ibaan cooperatives and buying businesses. The thread refreshes every few seconds.</p>
</div>
<?php if ($formError): ?><div class="alert alert-danger"><?= e($formError) ?></div><?php endif; ?>
<form method="get" action="messages.php" class="panel mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-8">
            <label class="form-label" for="with">Start a conversation</label>
            <select class="form-select" id="with" name="with" required>
                <option value="">Choose an account</option>
                <?php foreach ($candidates as $candidate): ?>
                    <option value="<?= (int) $candidate['id'] ?>"><?= e(display_name($candidate)) ?> · <?= e(role_label((string) $candidate['role'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <button class="btn btn-agree" type="submit">Open</button>
        </div>
    </div>
</form>
<div class="chat-layout" id="chatApp" data-with="<?= $withId ? (int) $withId : '' ?>">
    <div class="chat-list">
        <?php if (!$conversations): ?>
            <div class="empty-state">No conversations yet.</div>
        <?php endif; ?>
        <?php foreach ($conversations as $otherId => $convo): ?>
            <?php $person = $people[$otherId] ?? null; if (!$person) { continue; } ?>
            <a class="<?= (int) $otherId === $withId ? 'active' : '' ?>" href="messages.php?with=<?= (int) $otherId ?>">
                <strong><?= e(display_name($person)) ?></strong>
                <?php if ((int) $convo['unread'] > 0): ?><em class="count"><?= (int) $convo['unread'] ?></em><?php endif; ?>
                <span class="tiny muted d-block"><?= e(clip((string) $convo['last']['body'], 80)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="chat-thread">
        <?php if (!$partner): ?>
            <div class="empty-state"><i class="fa-solid fa-comments"></i><p>Select a conversation or start one with a verified cooperative or buyer.</p></div>
        <?php else: ?>
            <div class="p-3 border-bottom">
                <strong><?= e(display_name($partner)) ?></strong>
                <span class="tiny muted d-block"><?= e(role_label((string) $partner['role'])) ?> · <?= e($partner['barangay']) ?>, <?= e($partner['municipality']) ?></span>
            </div>
            <div class="bubbles" id="chatThread" data-after="<?= (int) $latestId ?>">
                <?php if (!$thread): ?><div class="empty-state" id="chatEmpty">No messages yet. Send the first note.</div><?php endif; ?>
                <?php foreach ($thread as $message): ?>
                    <div class="bubble <?= (int) $message['sender_id'] === $me ? 'mine' : '' ?>" data-id="<?= (int) $message['id'] ?>">
                        <p><?= e($message['body']) ?></p>
                        <time><?= e(short_time((string) $message['created_at'])) ?></time>
                    </div>
                <?php endforeach; ?>
            </div>
            <form class="chat-form" id="chatForm" method="post" action="messages.php?with=<?= (int) $withId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="with" value="<?= (int) $withId ?>">
                <label class="visually-hidden" for="chatBody">Message</label>
                <textarea class="form-control" id="chatBody" name="body" rows="2" maxlength="1000" placeholder="Write a message" required></textarea>
                <button class="btn btn-agree" type="submit">Send</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
