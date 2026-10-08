<?php
require_once __DIR__ . '/bootstrap.php';

$user = require_login();
$pdo = db();
$formError = null;
$buyerTypes = [
    'restaurant' => 'Restaurant',
    'institutional' => 'Institutional food service',
    'commercial' => 'Commercial buyer',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf('profile.php');
    try {
        $fullName = posted('full_name', 150);
        $phone = require_phone(posted('phone', 30));
        $organization = posted('organization', 190);
        $address = posted('address_line', 255);
        $email = require_email(posted('email', 190));
        if ($fullName === '' || $organization === '' || $address === '') {
            throw new RuntimeException('Name, organization, and address are required.');
        }

        $municipality = (string) $user['municipality'];
        $barangay = (string) $user['barangay'];
        $businessType = (string) $user['business_type'];
        if ($user['role'] === 'buyer') {
            $businessType = (string) ($_POST['business_type'] ?? '');
            if (!isset($buyerTypes[$businessType])) {
                throw new RuntimeException('Choose the kind of buying business.');
            }
            $municipality = (string) ($_POST['municipality'] ?? '');
            if (!isset(nearby_municipalities()[$municipality])) {
                throw new RuntimeException('Choose a municipality in Batangas.');
            }
            $barangay = $municipality === 'Ibaan'
                ? (string) ($_POST['barangay'] ?? '')
                : posted('barangay_other', 80);
        } else {
            $municipality = 'Ibaan';
            $barangay = (string) ($_POST['barangay'] ?? '');
        }
        if ($municipality === 'Ibaan' && !isset(iba_barangays()[$barangay])) {
            throw new RuntimeException('Choose a barangay in Ibaan.');
        }
        if ($municipality !== 'Ibaan' && strlen($barangay) < 2) {
            throw new RuntimeException('Enter the barangay or district of the business.');
        }

        $permit = $user['permit_path'];
        $status = $user['verification_status'];
        $verifiedAt = $user['verified_at'];
        $note = $user['verification_note'];
        if ($user['role'] === 'cooperative' && (($_FILES['permit']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
            $permit = save_upload($_FILES['permit'], 'permits');
            $status = 'pending';
            $verifiedAt = null;
            $note = null;
        }

        $passwordSql = '';
        $params = [
            $fullName, $phone, $organization, $businessType, $barangay, $municipality,
            $address, $email, $permit, $status, $note, $verifiedAt,
        ];
        $newPassword = (string) ($_POST['new_password'] ?? '');
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
                throw new RuntimeException('Use a password between 8 and 72 characters.');
            }
            if (!hash_equals($newPassword, (string) ($_POST['new_password_confirm'] ?? ''))) {
                throw new RuntimeException('The new passwords do not match.');
            }
            if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $user['password_hash'])) {
                throw new RuntimeException('Current password is incorrect.');
            }
            $passwordSql = ', password_hash = ?';
            $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        }
        [$lat, $lng] = coords_for($municipality, $barangay);
        $params[] = $lat;
        $params[] = $lng;
        $params[] = (int) $user['id'];

        $sql = 'UPDATE users SET full_name=?, phone=?, organization=?, business_type=?, barangay=?, municipality=?, address_line=?, email=?, permit_path=?, verification_status=?, verification_note=?, verified_at=?'
            . $passwordSql . ', latitude=?, longitude=? WHERE id=?';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $ex) {
            if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('That email is already registered.');
            }
            throw new RuntimeException('Profile could not be saved.');
        }
        flash('success', $status === 'pending' && $user['role'] === 'cooperative' && $status !== $user['verification_status']
            ? 'Profile saved. The new permit is waiting for registry review, and trading is paused.'
            : 'Profile saved.');
        redirect('profile.php');
    } catch (RuntimeException $ex) {
        $formError = $ex->getMessage();
    }
}

$pageTitle = 'Profile';
$layout = 'app';
$navKey = 'profile';
require __DIR__ . '/header.php';
$fresh = current_user() ?: $user;
?>
<div class="page-head">
    <p class="gold-kicker"><?= e(role_label((string) $fresh['role'])) ?></p>
    <h1>Profile</h1>
</div>
<?php if ($formError): ?><div class="alert alert-danger"><?= e($formError) ?></div><?php endif; ?>
<div class="row g-3">
    <div class="col-lg-7">
        <form method="post" action="profile.php" enctype="multipart/form-data" class="panel stack-form">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="full_name">Contact person</label>
                    <input class="form-control" id="full_name" name="full_name" required maxlength="150" value="<?= e($fresh['full_name']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="phone">Mobile number</label>
                    <input class="form-control" id="phone" name="phone" required maxlength="30" value="<?= e($fresh['phone']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" id="email" name="email" type="email" required maxlength="190" value="<?= e($fresh['email']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="organization">Organization</label>
                    <input class="form-control" id="organization" name="organization" required maxlength="190" value="<?= e($fresh['organization']) ?>">
                </div>
            </div>
            <?php if ($fresh['role'] === 'buyer'): ?>
                <div>
                    <label class="form-label" for="business_type">Buyer type</label>
                    <select class="form-select" id="business_type" name="business_type">
                        <?php foreach ($buyerTypes as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $fresh['business_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="municipality">Municipality</label>
                    <select class="form-select" id="municipality" name="municipality">
                        <?php foreach (array_keys(nearby_municipalities()) as $town): ?>
                            <option value="<?= e($town) ?>" <?= $fresh['municipality'] === $town ? 'selected' : '' ?>><?= e($town) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <p class="mb-0"><strong>Municipality:</strong> Ibaan, Batangas</p>
            <?php endif; ?>
            <div>
                <label class="form-label" for="barangay">Barangay in Ibaan</label>
                <select class="form-select" id="barangay" name="barangay">
                    <?php foreach (array_keys(iba_barangays()) as $name): ?>
                        <option value="<?= e($name) ?>" <?= $fresh['barangay'] === $name ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($fresh['role'] === 'buyer'): ?>
                <div>
                    <label class="form-label" for="barangay_other">Barangay if outside Ibaan</label>
                    <input class="form-control" id="barangay_other" name="barangay_other" maxlength="80" value="<?= $fresh['municipality'] !== 'Ibaan' ? e($fresh['barangay']) : '' ?>">
                </div>
            <?php endif; ?>
            <div>
                <label class="form-label" for="address_line">Street address</label>
                <input class="form-control" id="address_line" name="address_line" required maxlength="255" value="<?= e($fresh['address_line']) ?>">
            </div>
            <?php if ($fresh['role'] === 'cooperative'): ?>
                <div>
                    <label class="form-label" for="permit">Replace CDA certificate or business permit</label>
                    <input class="form-control" id="permit" name="permit" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
                    <p class="tiny muted mb-0">Uploading a new file sends the account back to review and pauses quoting.</p>
                </div>
            <?php endif; ?>
            <h3>Change password</h3>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="current_password">Current password</label>
                    <input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="new_password">New password</label>
                    <input class="form-control" id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="new_password_confirm">Confirm new password</label>
                    <input class="form-control" id="new_password_confirm" name="new_password_confirm" type="password" minlength="8" autocomplete="new-password">
                </div>
            </div>
            <button class="btn btn-agree" type="submit">Save profile</button>
        </form>
    </div>
    <div class="col-lg-5">
        <aside class="panel">
            <h2>Account status</h2>
            <p><?= status_badge((string) $fresh['verification_status']) ?></p>
            <p class="mb-1"><strong><?= e(display_name($fresh)) ?></strong></p>
            <p class="muted"><?= e($fresh['barangay']) ?>, <?= e($fresh['municipality']) ?>, Batangas</p>
            <?php if ($fresh['role'] === 'cooperative' && $fresh['verification_note']): ?>
                <div class="banner">Registry note: <?= e($fresh['verification_note']) ?></div>
            <?php endif; ?>
            <?php if ($fresh['role'] === 'cooperative' && $fresh['permit_path']): ?>
                <h3>Permit on file</h3>
                <?= file_preview('download.php?type=permit&id=' . (int) $fresh['id'], (string) $fresh['permit_path'], 'Business permit') ?>
            <?php endif; ?>
        </aside>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
