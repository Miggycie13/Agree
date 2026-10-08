<?php
require_once __DIR__ . '/bootstrap.php';

if (current_user()) {
    redirect(dashboard_for((string) current_user()['role']));
}

$formError = null;
$email = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf('login.php');
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        $formError = 'Too many sign-in attempts. Wait a minute and try again.';
    } elseif (!try_db()) {
        $formError = 'Database connection failed. Import schema.sql and review db.php.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $account = $stmt->fetch();
        if (!$account || !password_verify($password, (string) $account['password_hash'])) {
            $_SESSION['login_fails'] = (int) ($_SESSION['login_fails'] ?? 0) + 1;
            if ((int) $_SESSION['login_fails'] >= 5) {
                $_SESSION['login_locked_until'] = time() + 60;
                $_SESSION['login_fails'] = 0;
            }
            $formError = 'Email or password is incorrect.';
        } elseif (!(int) $account['is_active']) {
            $formError = 'This account is suspended. Contact the AGREE registry.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $account['id'];
            unset($_SESSION['login_fails'], $_SESSION['login_locked_until']);
            flash('success', 'Welcome back, ' . $account['full_name'] . '.');
            redirect(dashboard_for((string) $account['role']));
        }
    }
}

$pageTitle = 'Sign in';
$layout = 'public';
require __DIR__ . '/header.php';
?>
<div class="container">
    <div class="auth-wrap">
        <section class="auth-copy">
            <p class="kicker">Welcome back</p>
            <h1>Sign in to your account.</h1>
            <p>Cooperatives update their products. Buyers compare prices and request a quote. The registry checks permits before a cooperative can sell.</p>
        </section>
        <section class="auth-card">
            <h2>Sign in</h2>
            <?php if ($formError): ?><div class="alert alert-danger" role="alert"><?= e($formError) ?></div><?php endif; ?>
            <form method="post" action="login.php" class="stack-form">
                <?= csrf_field() ?>
                <div>
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" id="email" name="email" type="email" required autocomplete="username" value="<?= e($email) ?>">
                </div>
                <div>
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password">
                </div>
                <button class="btn btn-agree" type="submit">Sign in</button>
            </form>
            <p class="mt-3 mb-2">New to AGREE? <a href="register.php">Create an account</a></p>
            <div class="table-responsive">
                <table class="table demo-table mb-0">
                    <caption class="caption-top">Demo accounts · password <strong>Agree@2026</strong></caption>
                    <thead><tr><th>Role</th><th>Email</th></tr></thead>
                    <tbody>
                        <tr><td>Admin</td><td>admin@agree.ph</td></tr>
                        <tr><td>Cooperative</td><td>coop.ibaan@agree.ph</td></tr>
                        <tr><td>Buyer</td><td>buyer.bahaykubo@agree.ph</td></tr>
                        <tr><td>Pending cooperative</td><td>coop.mabalor@agree.ph</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
