<?php

require __DIR__ . '/_auth.php';
requireAdminLogin();

$config = shruProviderConfig();
$created = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $balanceRaw = trim((string) ($_POST['balance'] ?? '0'));
    $balance = $balanceRaw === '' ? 0.0 : filter_var($balanceRaw, FILTER_VALIDATE_FLOAT);
    $ips = parseAllowedIps((string) ($_POST['allowed_ips'] ?? ''));

    if ($username === '') {
        $error = 'Username is required.';
    } elseif (!isValidUsername($username)) {
        $error = 'Username can only contain letters, numbers, and underscore ( _ ), up to 64 characters.';
    } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'That email address is not valid.';
    } elseif ($balance === false || !is_finite($balance) || $balance < 0) {
        $error = 'Starting balance must be zero or more.';
    } elseif ($ips['invalid']) {
        $error = 'Not an IP address or CIDR range: ' . implode(', ', $ips['invalid']);
    } else {
        // Same convention SHRU Cloud's own admin panel uses for a SHRU key:
        // "hmac_" + 8 random bytes for the Key ID, 64 random bytes (128 hex
        // chars) for the secret.
        $keyId = 'hmac_' . bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(64));

        $error = withStore(static function () use ($username, $email, $balance, $ips, $keyId, $secret, $config): ?string {
            $resellers = loadResellers();
            if (findByUsername($resellers, $username) !== null) {
                return 'That username is already taken by another credential.';
            }
            $resellers[] = [
                'id' => nextId('resellers', $resellers),
                'key_id' => $keyId,
                'hmac_secret' => $secret,
                'username' => $username,
                'email' => $email,
                'balance' => money($balance),
                'currency' => (string) ($config['currency'] ?? 'USD'),
                'allowed_ips' => $ips['ips'],
                'active' => 1,
                'created_at' => time(),
                'last_used_at' => 0,
            ];
            saveResellers($resellers);
            return null;
        });

        if ($error === null) {
            $created = ['key_id' => $keyId, 'secret' => $secret, 'endpoint' => shruCurrentApiEndpoint()];
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>New Credential - SHRU Cloud Provider Starter</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <div class="header-card">
        <h1>SHRU Cloud<small>Provider Starter - Admin</small></h1>
        <?php adminNav('keys'); ?>
    </div>

    <p><a class="action" href="keys.php">&larr; Credentials</a></p>

    <?php if ($created !== null): ?>
        <div class="ok">Credential created - copy everything below now, the secret will never be shown again.</div>
        <div class="card">
            <label><span>Endpoint</span></label>
            <div class="secret-box"><?= h($created['endpoint']) ?></div>
            <label><span>Key ID</span></label>
            <div class="secret-box"><?= h($created['key_id']) ?></div>
            <label><span>Secret</span></label>
            <div class="secret-box"><?= h($created['secret']) ?></div>
        </div>
        <p><a class="action" href="keys.php">Done - back to Credentials</a></p>
    <?php else: ?>
        <?php if ($error !== null): ?>
            <div class="err"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <label><span>Username</span>
                <input type="text" name="username" value="<?= h($_POST['username'] ?? '') ?>" autofocus placeholder="eg. acme_resale" maxlength="64" pattern="[A-Za-z0-9_]+" title="Letters, numbers, and underscore only">
            </label>
            <div class="hint">Letters, numbers, and underscore ( _ ) only - no spaces or other symbols.</div>
            <label><span>Contact email (optional)</span>
                <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>">
            </label>
            <label><span>Starting balance</span>
                <input type="number" step="0.001" min="0" name="balance" value="<?= h($_POST['balance'] ?? '0') ?>">
            </label>
            <label><span>Allowed IPs (space or comma separated, CIDR OK - leave blank to block every request until you add one)</span>
                <textarea name="allowed_ips" rows="2"><?= h($_POST['allowed_ips'] ?? '') ?></textarea>
            </label>
            <button type="submit">Create Credential</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
