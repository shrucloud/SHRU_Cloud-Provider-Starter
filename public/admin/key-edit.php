<?php

require __DIR__ . '/_auth.php';
requireAdminLogin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$existing = findById(loadResellers(), $id);
if ($existing === null) {
    header('Location: keys.php');
    exit;
}

$error = null;
$newSecret = null;
$form = [
    'username' => (string) $existing['username'],
    'email' => (string) ($existing['email'] ?? ''),
    'allowed_ips' => implode(' ', $existing['allowed_ips'] ?? []),
    'active' => !empty($existing['active']),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'regenerate') {
    checkCsrf();
    $newSecret = bin2hex(random_bytes(64));
    withStore(static function () use ($id, $newSecret): void {
        $resellers = loadResellers();
        foreach ($resellers as &$r) {
            if ((int) ($r['id'] ?? 0) === $id) {
                $r['hmac_secret'] = $newSecret;
            }
        }
        unset($r);
        saveResellers($resellers);
    });
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? 'save') === 'save') {
    checkCsrf();

    $form = [
        'username' => trim((string) ($_POST['username'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'allowed_ips' => trim((string) ($_POST['allowed_ips'] ?? '')),
        'active' => !empty($_POST['active']),
    ];
    $adjustRaw = trim((string) ($_POST['adjust'] ?? ''));
    $adjust = $adjustRaw === '' ? 0.0 : filter_var($adjustRaw, FILTER_VALIDATE_FLOAT);
    $ips = parseAllowedIps($form['allowed_ips']);

    if ($form['username'] === '') {
        $error = 'Username is required.';
    } elseif (!isValidUsername($form['username'])) {
        $error = 'Username can only contain letters, numbers, and underscore ( _ ), up to 64 characters.';
    } elseif ($form['email'] !== '' && filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
        $error = 'That email address is not valid.';
    } elseif ($ips['invalid']) {
        $error = 'Not an IP address or CIDR range: ' . implode(', ', $ips['invalid']);
    } elseif ($adjust === false || !is_finite($adjust)) {
        $error = 'The balance adjustment must be a number, eg. 50 to add or -10 to take away.';
    } else {
        // The adjustment is applied to the balance as it is right now, inside
        // the store lock, never to the number shown when this page was opened:
        // an order placed meanwhile stays paid for.
        $error = withStore(static function () use ($id, $form, $ips, $adjust): ?string {
            $resellers = loadResellers();
            if (findByUsername($resellers, $form['username'], $id) !== null) {
                return 'That username is already taken by another credential.';
            }
            foreach ($resellers as &$r) {
                if ((int) ($r['id'] ?? 0) !== $id) {
                    continue;
                }
                $balance = money((float) ($r['balance'] ?? 0) + (float) $adjust);
                if ($balance < 0) {
                    return 'That adjustment would take the balance below zero.';
                }
                $r['username'] = $form['username'];
                $r['email'] = $form['email'];
                $r['balance'] = $balance;
                $r['active'] = $form['active'] ? 1 : 0;
                $r['allowed_ips'] = $ips['ips'];
            }
            unset($r);
            saveResellers($resellers);
            return null;
        });
        if ($error === null) {
            header('Location: keys.php');
            exit;
        }
    }
}

$existing = findById(loadResellers(), $id) ?? $existing;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Credential - SHRU Cloud Provider Starter</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <div class="header-card">
        <h1>SHRU Cloud<small>Provider Starter - Admin</small></h1>
        <?php adminNav('keys'); ?>
    </div>

    <p><a class="action" href="keys.php">&larr; Credentials</a></p>

    <?php if ($error !== null): ?>
        <div class="err"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($newSecret !== null): ?>
        <div class="ok">New secret generated - copy it now, it will never be shown again. The old secret stopped working immediately.</div>
        <div class="secret-box"><?= h($newSecret) ?></div>
    <?php endif; ?>

    <div class="card">
        <label><span>Endpoint</span></label>
        <div class="secret-box"><?= h(shruCurrentApiEndpoint()) ?></div>
        <label><span>Key ID</span></label>
        <div class="secret-box"><?= h($existing['key_id']) ?></div>
        <form method="post" class="inline-form" onsubmit="return confirm('Generate a new secret? The current one will stop working immediately.');">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="do" value="regenerate">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <button type="submit">Regenerate Secret</button>
        </form>
    </div>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <label><span>Username</span>
            <input type="text" name="username" value="<?= h($form['username']) ?>" maxlength="64" pattern="[A-Za-z0-9_]+" title="Letters, numbers, and underscore only">
        </label>
        <div class="hint">Letters, numbers, and underscore ( _ ) only - no spaces or other symbols.</div>
        <label><span>Contact email (optional)</span>
            <input type="email" name="email" value="<?= h($form['email']) ?>">
        </label>
        <label><span>Balance</span>
            <input type="text" value="<?= h(number_format(money($existing['balance'] ?? 0), 3, '.', '')) ?> <?= h($existing['currency'] ?? 'USD') ?>" disabled>
        </label>
        <label><span>Adjust balance (eg. 50 to add funds, -10 to take away; blank leaves it as it is)</span>
            <input type="number" step="0.001" name="adjust" value="">
        </label>
        <label><span>Allowed IPs (space or comma separated, CIDR OK - blank blocks every request)</span>
            <textarea name="allowed_ips" rows="2"><?= h($form['allowed_ips']) ?></textarea>
        </label>
        <label class="inline"><input type="checkbox" name="active" <?= $form['active'] ? 'checked' : '' ?>><span>Active</span></label>
        <button type="submit">Save</button>
    </form>
</div>
</body>
</html>
