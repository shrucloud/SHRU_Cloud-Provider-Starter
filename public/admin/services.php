<?php

require __DIR__ . '/_auth.php';
requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['do'] ?? '', ['delete', 'toggle'], true)) {
    checkCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $do = (string) $_POST['do'];
    withStore(static function () use ($id, $do): void {
        $rows = loadServices();
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
        saveServices($rows);
    });
    header('Location: services.php');
    exit;
}

$services = loadServices();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Services - SHRU Cloud Provider Starter</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <div class="header-card">
        <h1>SHRU Cloud<small>Provider Starter - Admin</small></h1>
        <?php adminNav('services'); ?>
    </div>

    <p><a class="action" href="service-edit.php">+ New service</a></p>

    <?php if (!$services): ?>
        <div class="empty">No services yet - add one to start selling through SHRU Cloud.</div>
    <?php else: ?>
        <div class="tablebox">
        <table>
            <tr><th>ID</th><th>Name</th><th>Group</th><th>Type</th><th>Price</th><th>Bulk</th><th>Fields</th><th>Active</th><th></th></tr>
            <?php foreach ($services as $s): ?>
                <tr>
                    <td>#<?= (int) $s['id'] ?></td>
                    <td><?= h($s['name']) ?></td>
                    <td><?= h($s['group']) ?></td>
                    <td><?= h($s['type']) ?></td>
                    <td>$<?= number_format((float) $s['price'], 3) ?></td>
                    <td><?= !empty($s['bulk']) ? 'Yes' : '-' ?></td>
                    <td><?= count($s['fields'] ?? []) ?></td>
                    <td>
                        <span class="pill <?= !empty($s['active']) ? 'pill-on' : 'pill-off' ?>"><?= !empty($s['active']) ? 'Active' : 'Off' ?></span>
                    </td>
                    <td>
                        <a class="action" href="service-edit.php?id=<?= (int) $s['id'] ?>">Edit</a>
                        &nbsp;
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="do" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button type="submit"><?= !empty($s['active']) ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this service?');">
                            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="do" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="danger">Delete</button>
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
