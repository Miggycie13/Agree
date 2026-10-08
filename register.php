<?php
require_once __DIR__ . '/bootstrap.php';

if (current_user()) {
    redirect(dashboard_for((string) current_user()['role']));
}

$allowedRoles = ['cooperative', 'buyer', 'admin'];
$buyerTypes = ['restaurant' => 'Restaurant', 'institutional' => 'Institutional food service', 'commercial' => 'Commercial buyer'];
$values = [
    'role' => in_array($_GET['role'] ?? '', $allowedRoles, true) ? $_GET['role'] : 'cooperative',
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'organization' => '',
    'business_type' => 'restaurant',
    'municipality' => 'Ibaan',
    'barangay' => 'Poblacion',
    'barangay_other' => '',
    'address_line' => '',
    'invite_code' => '',
];
$formError = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf('register.php');
    foreach ($values as $key => $default) {
        if ($key === 'role' && in_array($_POST['role'] ?? '', $allowedRoles, true)) {
            $values['role'] = $_POST['role'];
        } elseif ($key !== 'role') {
            $values[$key] = trim((string) ($_POST[$key] ?? ''));
        }
    }
    try {
        if (!try_db()) {
            throw new RuntimeException('Database connection failed. Import schema.sql and review db.php.');
        }
        $role = $values['role'];
        $fullName = clip($values['full_name'], 150);
        $organization = clip($values['organization'], 190);
        $email = require_email($values['email']);
        $phone = require_phone($values['phone']);
        $address = clip($values['address_line'], 255);
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if ($fullName === '' || $organization === '' || $address === '') {
            throw new RuntimeException('Name, organization, and address are required.');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            throw new RuntimeException('Use a password between 8 and 72 characters.');
        }
        if (!hash_equals($password, $confirm)) {
            throw new RuntimeException('The passwords do not match.');
        }
        if (empty($_POST['agree_terms'])) {
            throw new RuntimeException('Confirm that the business details you submit are genuine.');
        }

        $businessType = 'agricultural_cooperative';
        $municipality = 'Ibaan';
        $barangay = $values['barangay'];
        if ($role === 'buyer') {
            $businessType = $values['business_type'];
            if (!isset($buyerTypes[$businessType])) {
                throw new RuntimeException('Choose the kind of buying business.');
            }
            $municipality = $values['municipality'];
            if (!isset(nearby_municipalities()[$municipality])) {
                throw new RuntimeException('Choose a municipality in Batangas.');
            }
            if ($municipality !== 'Ibaan') {
                $barangay = clip($values['barangay_other'], 80);
                if (strlen($barangay) < 2) {
                    throw new RuntimeException('Enter the barangay or district of the business.');
                }
            }
        } elseif ($role === 'admin') {
            $businessType = 'municipal_registry';
            if (!hash_equals(ADMIN_INVITE_CODE, trim($values['invite_code']))) {
                throw new RuntimeException('The admin invite code is not valid.');
            }
        }
        if ($role !== 'buyer' || $municipality === 'Ibaan') {
            if (!isset(iba_barangays()[$barangay])) {
                throw new RuntimeException('Choose a barangay in Ibaan.');
            }
        }

        $permit = null;
        if ($role === 'cooperative') {
            $permit = save_upload($_FILES['permit'] ?? [], 'permits');
        }
        [$lat, $lng] = coords_for($municipality, $barangay);
        $pdo = db();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users
                    (role, email, password_hash, full_name, phone, organization, business_type, barangay, municipality, province, address_line, latitude, longitude, permit_path, verification_status, verified_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $role,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $fullName,
                $phone,
                $organization,
                $businessType,
                $barangay,
                $municipality,
                'Batangas',
                $address,
                $lat,
                $lng,
                $permit,
                $role === 'cooperative' ? 'pending' : 'approved',
                $role === 'cooperative' ? null : date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $ex) {
            if ($permit) {
                $stored = upload_abspath($permit);
                if ($stored) {
                    unlink($stored);
                }
            }
            if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('That email is already registered.');
            }
            throw new RuntimeException('The account could not be saved. Please try again.');
        }
        $note = $role === 'cooperative'
            ? 'Account created. You can sign in, but listing and quoting stay locked until the registry approves your permit.'
            : 'Account created. You can sign in.';
        flash('success', $note);
        redirect('login.php');
    } catch (RuntimeException $ex) {
        $formError = $ex->getMessage();
    }
}

$pageTitle = 'Register';
$layout = 'public';
require __DIR__ . '/header.php';
$role = $values['role'];
?>
<div class="container">
    <div class="auth-wrap">
        <section class="auth-copy">
            <p class="kicker">Create an account</p>
            <h1>Create your account.</h1>
            <p>Cooperatives sell live chicken, dressed chicken, eggs, and other farm supply. Buyers are restaurants, canteens, and stores in Ibaan and nearby towns.</p>
            <p>A cooperative uploads a CDA certificate or mayor's permit. Selling stays locked until the registry approves it.</p>
        </section>
        <section class="auth-card">
            <h2>Create an account</h2>
            <?php if ($formError): ?><div class="alert alert-danger"><?= e($formError) ?></div><?php endif; ?>
            <form method="post" action="register.php" enctype="multipart/form-data" class="stack-form">
                <?= csrf_field() ?>
                <fieldset>
                    <legend class="form-label">Account type</legend>
                    <div class="role-grid">
                        <label class="role-card">
                            <span><input type="radio" name="role" id="role-coop" value="cooperative" <?= $role === 'cooperative' ? 'checked' : '' ?>> Cooperative</span>
                            <small>Seller in Ibaan. Permit required.</small>
                        </label>
                        <label class="role-card">
                            <span><input type="radio" name="role" id="role-buyer" value="buyer" <?= $role === 'buyer' ? 'checked' : '' ?>> Commercial buyer</span>
                            <small>Restaurant, institution, or mart.</small>
                        </label>
                        <label class="role-card">
                            <span><input type="radio" name="role" id="role-admin" value="admin" <?= $role === 'admin' ? 'checked' : '' ?>> Admin</span>
                            <small>Registry review. Invite code required.</small>
                        </label>
                    </div>
                </fieldset>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="full_name">Contact person</label>
                        <input class="form-control" id="full_name" name="full_name" required maxlength="150" value="<?= e($values['full_name']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="phone">Mobile number</label>
                        <input class="form-control" id="phone" name="phone" required maxlength="30" value="<?= e($values['phone']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" name="email" type="email" required maxlength="190" value="<?= e($values['email']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="organization">Cooperative or business name</label>
                        <input class="form-control" id="organization" name="organization" required maxlength="190" value="<?= e($values['organization']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="password_confirm">Confirm password</label>
                        <input class="form-control" id="password_confirm" name="password_confirm" type="password" required minlength="8" autocomplete="new-password">
                    </div>
                </div>

                <div data-role-panel="buyer">
                    <label class="form-label" for="business_type">Buyer type</label>
                    <select class="form-select" id="business_type" name="business_type">
                        <?php foreach ($buyerTypes as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $values['business_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label mt-3" for="municipality">Municipality</label>
                    <select class="form-select" id="municipality" name="municipality">
                        <?php foreach (nearby_municipalities() as $town => $coords): ?>
                            <option value="<?= e($town) ?>" <?= $values['municipality'] === $town ? 'selected' : '' ?>><?= e($town) ?>, Batangas</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="iba-barangay">
                    <label class="form-label" for="barangay">Barangay in Ibaan</label>
                    <select class="form-select" id="barangay" name="barangay">
                        <?php foreach (iba_barangays() as $name => $coords): ?>
                            <option value="<?= e($name) ?>" <?= $values['barangay'] === $name ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="other-barangay">
                    <label class="form-label" for="barangay_other">Barangay outside Ibaan</label>
                    <input class="form-control" id="barangay_other" name="barangay_other" maxlength="80" value="<?= e($values['barangay_other']) ?>">
                </div>
                <div>
                    <label class="form-label" for="address_line">Street address</label>
                    <input class="form-control" id="address_line" name="address_line" required maxlength="255" value="<?= e($values['address_line']) ?>">
                </div>

                <div data-role-panel="cooperative">
                    <label class="form-label" for="permit">CDA certificate or business permit</label>
                    <input class="form-control" id="permit" name="permit" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
                    <p class="tiny muted mb-0">JPG, PNG, WEBP, or PDF. 5 MB maximum. Trading stays locked until this file is approved.</p>
                </div>
                <div data-role-panel="admin">
                    <label class="form-label" for="invite_code">Admin invite code</label>
                    <input class="form-control" id="invite_code" name="invite_code" maxlength="80" value="<?= e($values['invite_code']) ?>" autocomplete="off">
                    <p class="tiny muted mb-0">Capstone demo code: IBAN-AGREE-ADMIN</p>
                </div>
                <label class="form-check">
                    <input class="form-check-input" type="checkbox" name="agree_terms" value="1" required>
                    <span class="form-check-label">I confirm these business details are genuine and may be checked under the Internet Transactions Act (RA 11967).</span>
                </label>
                <button class="btn btn-agree" type="submit">Create account</button>
            </form>
        </section>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
