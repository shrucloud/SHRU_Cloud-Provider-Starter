<?php

require __DIR__ . '/_auth.php';
requireAdminLogin();

$orders = loadOrders();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$order = findById($orders, $id);
if ($order === null) {
    header('Location: orders.php');
    exit;
}

$error = null;
$statuses = ['queued', 'processing', 'completed', 'rejected', 'refunded'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $status = in_array($_POST['status'] ?? '', $statuses, true) ? $_POST['status'] : $order['status'];
    $reply = trim((string) ($_POST['reply'] ?? ''));

    // One locked read-modify-write over the order and the reseller's balance,
    // on fresh copies of both, so a refund and an order placed through the API
    // at the same moment can never overwrite each other. The price goes back
    // once only, and an order that has been credited back stays closed: it can
    // never become queued, processing or completed again.
    $error = withStore(static function () use ($id, $status, $reply): ?string {
        $orders = loadOrders();
        $resellers = null;
        foreach ($orders as &$o) {
            if ((int) ($o['id'] ?? 0) !== $id) {
                continue;
            }
            if (!empty($o['refunded']) && !in_array($status, ['rejected', 'refunded'], true)) {
                return 'This order was already credited back to the reseller, so it can only stay Rejected or Refunded.';
            }
            $o['status'] = $status;
            $o['reply'] = $reply;
            $o['updated_at'] = time();
            if (in_array($status, ['rejected', 'refunded'], true) && empty($o['refunded'])) {
                $o['refunded'] = true;
                $resellers = loadResellers();
                foreach ($resellers as &$r) {
                    if (hash_equals((string) ($r['key_id'] ?? ''), (string) $o['key_id'])) {
                        $r['balance'] = money((float) $r['balance'] + (float) $o['price']);
                    }
                }
                unset($r);
            }
        }
        unset($o);
        if ($resellers !== null) {
            saveResellers($resellers);
        }
        saveOrders($orders);
        return null;
    });

    if ($error === null) {
        header('Location: orders.php');
        exit;
    }
    $order = findById(loadOrders(), $id) ?? $order;
    $order['reply'] = $reply;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Order #<?= (int) $id ?> - SHRU Cloud Provider Starter</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <div class="header-card">
        <h1>SHRU Cloud<small>Provider Starter - Admin</small></h1>
        <?php adminNav('orders'); ?>
    </div>

    <p><a class="action" href="orders.php">&larr; Orders</a></p>

    <div class="card">
        <p><b>Order #<?= (int) $order['id'] ?></b></p>
        <p>Reseller: <?= h($order['reseller_username']) ?><br>
        Service: <?= h($order['service_name']) ?> (#<?= (int) $order['service_id'] ?>)<br>
        Input: <?= h($order['input'] !== '' ? $order['input'] : '-') ?><br>
        Quantity: <?= (int) $order['quantity'] ?><br>
        Price: $<?= number_format((float) $order['price'], 3) ?><br>
        Placed: <?= h(date('Y-m-d H:i:s', (int) $order['created_at'])) ?></p>
        <?php if (!empty($order['fields'])): ?>
            <p>Fields:<br>
            <?php foreach ($order['fields'] as $key => $value): ?>
                <?= h($key) ?>: <?= h($value) ?><br>
            <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($error !== null): ?>
        <div class="err"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <label><span>Status</span>
            <select name="status">
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= h($s) ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="hint">Setting Rejected or Refunded credits the price back to the reseller's balance once and closes the order.</div>
        <label><span>Reply (shown to the reseller via check_status - plain text, eg. the unlock code or result)</span>
            <textarea name="reply" rows="4"><?= h($order['reply']) ?></textarea>
        </label>
        <button type="submit">Save</button>
    </form>
</div>
</body>
</html>
