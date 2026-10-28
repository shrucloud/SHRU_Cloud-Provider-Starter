<?php

require __DIR__ . '/_auth.php';
requireAdminLogin();

$services = loadServices();
$resellers = loadResellers();
$orders = loadOrders();

$activeServices = count(array_filter($services, fn($s) => !empty($s['active'])));
$activeResellers = count(array_filter($resellers, fn($r) => !empty($r['active'])));
$totalBalance = array_sum(array_map(fn($r) => (float) ($r['balance'] ?? 0), $resellers));
$currency = (string) (shruProviderConfig()['currency'] ?? 'USD');

$needsAttention = count(array_filter($orders, fn($o) => in_array($o['status'] ?? '', ['queued', 'processing'], true)));
$completed = count(array_filter($orders, fn($o) => ($o['status'] ?? '') === 'completed'));

$recent = $orders;
usort($recent, fn($a, $b) => (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0));
$recent = array_slice($recent, 0, 8);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard - SHRU Cloud Provider Starter</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <div class="header-card">
        <h1>SHRU Cloud<small>Provider Starter - Admin</small></h1>
        <?php adminNav('dashboard'); ?>
    </div>

    <div class="summary">
        <div class="sumbox"><div class="lbl">Services</div>
            <div class="val"><?= count($services) ?></div>
            <div class="cnt"><?= $activeServices ?> active</div></div>
        <div class="sumbox"><div class="lbl">Credentials</div>
            <div class="val"><?= count($resellers) ?></div>
            <div class="cnt"><?= $activeResellers ?> active</div></div>
        <div class="sumbox"><div class="lbl">Reseller balance</div>
            <div class="val"><?= number_format($totalBalance, 2) ?></div>
            <div class="cnt"><?= h($currency) ?> total across all credentials</div></div>
        <div class="sumbox"><div class="lbl">Needs attention</div>
            <div class="val"><?= $needsAttention ?></div>
            <div class="cnt">queued or processing</div></div>
        <div class="sumbox"><div class="lbl">Completed</div>
            <div class="val"><?= $completed ?></div>
            <div class="cnt">of <?= count($orders) ?> total orders</div></div>
    </div>

    <?php if (!$services): ?>
        <div class="empty">No services yet - <a class="action" href="service-edit.php">add one</a> to start selling through SHRU Cloud.</div>
    <?php elseif (!$resellers): ?>
        <div class="empty">No reseller credentials yet - <a class="action" href="key-new.php">create one</a> so a SHRU Cloud platform can connect and sync your catalog.</div>
    <?php endif; ?>

    <h2>Recent orders</h2>
    <?php if (!$recent): ?>
        <div class="empty">No orders yet.</div>
    <?php else: ?>
        <div class="tablebox">
        <table>
            <tr><th>ID</th><th>Reseller</th><th>Service</th><th>Price</th><th>Status</th><th>Placed</th><th></th></tr>
            <?php foreach ($recent as $o): ?>
                <tr>
                    <td>#<?= (int) $o['id'] ?></td>
                    <td><?= h($o['reseller_username']) ?></td>
                    <td><?= h($o['service_name']) ?></td>
                    <td>$<?= number_format((float) $o['price'], 3) ?></td>
                    <td><b class="st-<?= h($o['status']) ?>"><?= h($o['status']) ?></b></td>
                    <td><?= h(date('Y-m-d H:i', (int) $o['created_at'])) ?></td>
                    <td><a class="action" href="order-reply.php?id=<?= (int) $o['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <p><a class="action" href="orders.php">View all orders</a></p>
    <?php endif; ?>
</div>
</body>
</html>
