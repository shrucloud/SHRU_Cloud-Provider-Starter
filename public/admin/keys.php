<?php

require __DIR__ . '/_auth.php';
requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['do'] ?? '', ['delete', 'toggle'], true)) {
    checkCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $do = (string) $_POST['do'];
    withStore(static function () use ($id, $do): void {
        $rows = loadResellers();
        if ($do === 'delete') {
            $rows = array_filter($rows, static fn($row) => (int) ($row['id'] ?? 0) !== $id);
        } else {
            foreach ($rows as &$row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    $row['active'] = empty($row['active']) ? 1 : 0;
                }
            }
            unset($row);
        }
        saveResellers($rows);
    });
    header('Location: keys.php');
    exit;
}

$resellers = loadResellers();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Credentials - SHRU Cloud Provider Starter</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <div class="header-card">
        <h1>SHRU Cloud<small>Provider Starter - Admin</small></h1>
        <?php adminNav('keys'); ?>
    </div>

    <p><a class="action" href="key-new.php">+ New credential</a></p>

    <?php if (!$resellers): ?>
        <div class="empty">No reseller credentials yet - create one so another SHRU Cloud platform can connect and sync your catalog.</div>
    <?php else: ?>
        <div class="tablebox">
        <table>
            <tr><th>Username</th><th>Key ID</th><th>Balance</th><th>Allowed IPs</th><th>Active</th><th></th></tr>
            <?php foreach ($resellers as $r): ?>
                <tr>
                    <td><?= h($r['username']) ?></td>
                    <td><?= h($r['key_id']) ?></td>
                    <td><?= number_format((float) $r['balance'], 2) ?> <?= h($r['currency']) ?></td>
                    <td><?= count($r['allowed_ips'] ?? []) ?: 'none (blocked)' ?></td>
                    <td>
                        <span class="pill <?= !empty($r['active']) ? 'pill-on' : 'pill-off' ?>"><?= !empty($r['active']) ? 'Active' : 'Off' ?></span>
                    </td>
                    <td>
                        <a class="action" href="key-edit.php?id=<?= (int) $r['id'] ?>">Edit</a>
                        &nbsp;
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="do" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit"><?= !empty($r['active']) ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="post" class="inline-form" onsubmit="return confirm('Revoke this credential? Anyone using it will lose access immediately.');">
                            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="do" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="danger">Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
