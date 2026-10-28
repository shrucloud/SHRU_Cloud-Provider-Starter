<?php

require __DIR__ . '/_auth.php';
requireAdminLogin();

$orders = loadOrders();
usort($orders, fn($a, $b) => (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Orders - SHRU Cloud Provider Starter</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <div class="header-card">
        <h1>SHRU Cloud<small>Provider Starter - Admin</small></h1>
        <?php adminNav('orders'); ?>
    </div>

    <?php if (!$orders): ?>
        <div class="empty">No orders yet - once a reseller syncs your catalog and calls submit_order, they'll show up here.</div>
    <?php else: ?>
        <div class="tablebox">
        <table>
            <tr><th>ID</th><th>Reseller</th><th>Service</th><th>Input</th><th>Qty</th><th>Price</th><th>Status</th><th>Placed</th><th></th></tr>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td>#<?= (int) $o['id'] ?></td>
                    <td><?= h($o['reseller_username']) ?></td>
                    <td><?= h($o['service_name']) ?></td>
                    <td><?= h($o['input'] !== '' ? $o['input'] : '-') ?></td>
                    <td><?= (int) $o['quantity'] ?></td>
                    <td>$<?= number_format((float) $o['price'], 3) ?></td>
                    <td><b class="st-<?= h($o['status']) ?>"><?= h($o['status']) ?></b></td>
                    <td><?= h(date('Y-m-d H:i', (int) $o['created_at'])) ?></td>
                    <td><a class="action" href="order-reply.php?id=<?= (int) $o['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
