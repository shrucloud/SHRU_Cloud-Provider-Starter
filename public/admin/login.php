<?php

require __DIR__ . '/_auth.php';

if (!empty($_SESSION['shru_admin'])) {
    header('Location: index.php');
    exit;
}

$config = shruProviderConfig();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $ip = resolveRealClientIp();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $hash = (string) ($config['admin_password_hash'] ?? '');

    if (!adminLoginAllowed($ip)) {
        $error = 'Too many failed sign-ins from this address. Try again in 15 minutes.';
    } else {
        // Both checks always run, so the answer time never tells a valid
        // username from an invalid one.
        $userOk = hash_equals((string) ($config['admin_username'] ?? 'admin'), $username);
        $passOk = password_verify($password, $hash);
        adminLoginRecord($ip, $userOk && $passOk);
        if ($userOk && $passOk) {
            session_regenerate_id(true);
            $_SESSION = ['shru_admin' => true, 'seen_at' => time()];
            header('Location: index.php');
            exit;
        }
        $error = 'Wrong username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login - SHRU Cloud Provider Starter</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <div class="login-wrap">
        <div class="header-card">
            <h1>SHRU Cloud<small>Provider Starter - Admin</small></h1>
        </div>
        <?php if ($error !== null): ?>
            <div class="err"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <label><span>Username</span>
                <input type="text" name="username" autocomplete="username" autofocus>
            </label>
            <label><span>Password</span>
                <input type="password" name="password" autocomplete="current-password">
            </label>
            <button type="submit">Log In</button>
        </form>
    </div>
</div>
</body>
</html>
