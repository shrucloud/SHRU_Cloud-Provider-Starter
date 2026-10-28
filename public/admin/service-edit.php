<?php

require __DIR__ . '/_auth.php';
requireAdminLogin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$existing = $id > 0 ? findById(loadServices(), $id) : null;
if ($id > 0 && $existing === null) {
    header('Location: services.php');
    exit;
}

$error = null;

// Each extra field's label is also the key resellers send its value under, so
// two fields can never share a label (compared without case or surrounding
// spaces): the second value would silently replace the first in the order.
function parseFieldsJson(string $json): array
{
    $decoded = json_decode($json, true);
    $fields = [];
    $seen = [];
    $error = null;
    foreach (is_array($decoded) ? $decoded : [] as $entry) {
        $label = trim((string) ($entry['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        if (count($fields) >= 10) {
            $error = 'A service can have at most 10 extra fields.';
            break;
        }
        if (!isCleanText($label, 60) || strpbrk($label, "\r\n\t") !== false) {
            $error = 'Extra field labels must be one line of at most 60 characters.';
            break;
        }
        $type = ($entry['type'] ?? 'input') === 'textarea' ? 'textarea' : 'input';
        $fields[] = ['label' => $label, 'key' => $label, 'type' => $type, 'required' => !empty($entry['required'])];
        $folded = strtolower($label);
        if (isset($seen[$folded])) {
            $error = 'Two extra fields have the same label "' . $label . '". Give each field its own label.';
        }
        $seen[$folded] = true;
    }
    return ['fields' => $fields, 'error' => $error];
}

$form = $existing ?? [
    'active' => 1,
    'name' => '',
    'group' => 'Services',
    'type' => 'fixed',
    'cost' => 0,
    'price' => 0,
    'delivery' => 'Instant',
    'input_label' => '',
    'input_required' => true,
    'bulk' => false,
    'qty_min' => 1,
    'qty_max' => 1,
    'fields' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $name = trim((string) ($_POST['name'] ?? ''));
    $group = trim((string) ($_POST['group'] ?? '')) ?: 'Services';
    $type = ($_POST['type'] ?? 'fixed') === 'quantity' ? 'quantity' : 'fixed';
    $delivery = trim((string) ($_POST['delivery'] ?? '')) ?: 'Instant';
    $cost = filter_var(trim((string) ($_POST['cost'] ?? '0')) ?: '0', FILTER_VALIDATE_FLOAT);
    $price = filter_var(trim((string) ($_POST['price'] ?? '0')) ?: '0', FILTER_VALIDATE_FLOAT);
    $inputLabel = trim((string) ($_POST['input_label'] ?? ''));
    $inputRequired = !empty($_POST['input_required']);
    $bulk = $type === 'fixed' && !empty($_POST['bulk']);
    $qtyMin = max(1, (int) ($_POST['qty_min'] ?? 1));
    $qtyMax = max($qtyMin, (int) ($_POST['qty_max'] ?? 1));
    $active = !empty($_POST['active']);
    $parsed = parseFieldsJson((string) ($_POST['fields_json'] ?? '[]'));
    $fields = $parsed['fields'];

    $form = [
        'id' => $id,
        'active' => $active ? 1 : 0,
        'name' => $name,
        'group' => $group,
        'type' => $type,
        'cost' => $cost === false ? 0 : money($cost),
        'price' => $price === false ? 0 : money($price),
        'delivery' => $delivery,
        'input_label' => $inputLabel,
        'input_required' => $inputRequired,
        'bulk' => $bulk,
        'qty_min' => $qtyMin,
        'qty_max' => $qtyMax,
        'fields' => $fields,
    ];

    if ($name === '') {
        $error = 'Service name is required.';
    } elseif (!isCleanText($name, 150) || !isCleanText($group, 80) || !isCleanText($delivery, 60) || !isCleanText($inputLabel, 60)) {
        $error = 'Name, group, delivery or input label is too long or contains characters that are not allowed.';
    } elseif ($price === false || $cost === false || !is_finite($price) || !is_finite($cost) || $price < 0 || $cost < 0) {
        $error = 'Cost and price must be zero or more.';
    } elseif ($parsed['error'] !== null) {
        $error = $parsed['error'];
    } else {
        $error = withStore(static function () use ($id, $form): ?string {
            $services = loadServices();
            if ($id > 0) {
                $found = false;
                foreach ($services as &$s) {
                    if ((int) ($s['id'] ?? 0) === $id) {
                        $s = $form;
                        $found = true;
                    }
                }
                unset($s);
                if (!$found) {
                    return 'This service was deleted meanwhile.';
                }
            } else {
                $form['id'] = nextId('services', $services);
                $services[] = $form;
            }
            saveServices($services);
            return null;
        });
        if ($error === null) {
            header('Location: services.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id > 0 ? 'Edit Service' : 'New Service' ?> - SHRU Cloud Provider Starter</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <div class="header-card">
        <h1>SHRU Cloud<small>Provider Starter - Admin</small></h1>
        <?php adminNav('services'); ?>
    </div>

    <p><a class="action" href="services.php">&larr; Services</a></p>

    <?php if ($error !== null): ?>
        <div class="err"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" class="wide">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <label><span>Name</span>
            <input type="text" name="name" value="<?= h($form['name']) ?>" autofocus>
        </label>
        <label><span>Group</span>
            <input type="text" name="group" value="<?= h($form['group']) ?>">
        </label>
        <label><span>Type</span>
            <select name="type" id="serviceType">
                <option value="fixed" <?= $form['type'] === 'fixed' ? 'selected' : '' ?>>Fixed - one input per order (IMEI, SN, ECID, account ID, ...)</option>
                <option value="quantity" <?= $form['type'] === 'quantity' ? 'selected' : '' ?>>Quantity - bought by count</option>
            </select>
        </label>
        <label><span>Delivery (shown to resellers, eg. "Instant", "1-24h")</span>
            <input type="text" name="delivery" value="<?= h($form['delivery']) ?>">
        </label>
        <label><span>Cost</span>
            <input type="number" step="0.001" min="0" name="cost" value="<?= h($form['cost']) ?>">
        </label>
        <label><span>Price (what resellers pay you)</span>
            <input type="number" step="0.001" min="0" name="price" value="<?= h($form['price']) ?>">
        </label>
        <label><span>Primary input label (blank = this service takes none)</span>
            <input type="text" name="input_label" value="<?= h($form['input_label']) ?>" placeholder="eg. IMEI, SN, ECID">
        </label>
        <label class="inline"><input type="checkbox" name="input_required" <?= !empty($form['input_required']) ? 'checked' : '' ?>><span>Primary input is required</span></label>
        <label class="inline" id="bulkField"><input type="checkbox" name="bulk" <?= !empty($form['bulk']) ? 'checked' : '' ?>><span>Allow bulk ordering (resellers place one input per call, in a loop)</span></label>
        <label id="qtyRangeField"><span>Quantity range</span>
            <input type="number" class="short" name="qty_min" value="<?= (int) $form['qty_min'] ?>" min="1"> to
            <input type="number" class="short" name="qty_max" value="<?= (int) $form['qty_max'] ?>" min="1">
        </label>
        <div class="field-editor">
            <span>Extra fields</span>
            <div class="hint">Anything a reseller must send beyond the primary input above, eg. "Username" and "Email" for an account-based service. Leave empty if this service needs nothing else.</div>
            <div id="extraFieldsList"></div>
            <button type="button" id="addFieldBtn">+ Add Field</button>
            <input type="hidden" name="fields_json" id="fieldsJson">
        </div>
        <label class="inline"><input type="checkbox" name="active" <?= !empty($form['active']) ? 'checked' : '' ?>><span>Active (visible to resellers via sync)</span></label>

        <button type="submit">Save</button>
    </form>
</div>
<script>
(function () {
    var typeSelect = document.getElementById('serviceType');
    var bulkField = document.getElementById('bulkField');
    var qtyRangeField = document.getElementById('qtyRangeField');

    function applyType() {
        var isQuantity = typeSelect.value === 'quantity';
        bulkField.style.display = isQuantity ? 'none' : '';
        qtyRangeField.style.display = isQuantity ? '' : 'none';
    }

    typeSelect.addEventListener('change', applyType);
    applyType();
})();

(function () {
    var initialFields = <?= json_encode(array_map(static fn($f) => ['label' => $f['label'], 'type' => $f['type'], 'required' => (bool) $f['required']], $form['fields']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var fields = initialFields.slice();
    var list = document.getElementById('extraFieldsList');
    var jsonInput = document.getElementById('fieldsJson');

    function esc(value) {
        return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function render() {
        if (!fields.length) {
            list.innerHTML = '<p class="hint">No extra fields yet.</p>';
            return;
        }
        list.innerHTML = fields.map(function (f, i) {
            return '<div class="field-row">' +
                '<label class="field-row-label"><span>Label</span>' +
                '<input type="text" data-index="' + i + '" data-key="label" value="' + esc(f.label) + '" maxlength="60" placeholder="eg. Username"></label>' +
                '<label class="field-row-type"><span>Type</span>' +
                '<select data-index="' + i + '" data-key="type">' +
                '<option value="input"' + (f.type === 'input' ? ' selected' : '') + '>Text</option>' +
                '<option value="textarea"' + (f.type === 'textarea' ? ' selected' : '') + '>Textarea</option>' +
                '</select></label>' +
                '<label class="inline field-row-required"><input type="checkbox" data-index="' + i + '" data-key="required"' + (f.required ? ' checked' : '') + '><span>Required</span></label>' +
                '<button type="button" class="danger field-row-remove" data-index="' + i + '">Remove</button>' +
                '</div>';
        }).join('');
    }

    document.getElementById('addFieldBtn').addEventListener('click', function () {
        if (fields.length >= 10) {
            return;
        }
        fields.push({ label: '', type: 'input', required: true });
        render();
    });

    list.addEventListener('click', function (e) {
        if (e.target.matches('.field-row-remove')) {
            fields.splice(parseInt(e.target.dataset.index, 10), 1);
            render();
        }
    });

    list.addEventListener('input', function (e) {
        var index = e.target.dataset.index;
        var key = e.target.dataset.key;
        if (index === undefined || !key) {
            return;
        }
        fields[index][key] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
    });

    list.addEventListener('change', function (e) {
        if (e.target.dataset.key === 'type') {
            fields[e.target.dataset.index].type = e.target.value;
        }
        if (e.target.dataset.key === 'required') {
            fields[e.target.dataset.index].required = e.target.checked;
        }
    });

    document.querySelector('form.wide').addEventListener('submit', function () {
        jsonInput.value = JSON.stringify(fields);
    });

    render();
})();
</script>
</body>
</html>
