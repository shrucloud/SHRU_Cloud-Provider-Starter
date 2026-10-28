<?php

require __DIR__ . '/../../bootstrap.php';

const SHRU_MAX_BODY_BYTES = 65536;
const SHRU_MAX_INPUT_CHARS = 500;

function shruSendHeaders(): void
{
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

function shruError(string $message, int $httpCode = 400): void
{
    shruSendHeaders();
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function shruSuccess(array $data): void
{
    shruSendHeaders();
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// Any unexpected failure (a data file that cannot be written, ...) answers with
// a plain JSON error. The details go to the PHP error log, never to the caller.
set_exception_handler(static function (Throwable $e): void {
    error_log('shru provider api: ' . $e->getMessage());
    shruError('Service temporarily unavailable, please try again shortly.', 503);
});

// getallheaders() is missing on some PHP-FPM setups; the same headers are
// always in $_SERVER as HTTP_*.
function shruHeader(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$key] ?? ''));
}

// A dual-stack server (IPv6 socket accepting IPv4 connections) reports
// REMOTE_ADDR as "::ffff:1.2.3.4", not "1.2.3.4" - an exact-string or even
// inet_pton() byte-length compare against a plain IPv4 allowlist entry
// silently fails against that form, denying an IP the admin correctly
// entered. Collapsing it to plain IPv4 first fixes the comparison on both
// sides without changing what actually gets stored or displayed anywhere.
function normalizeIpForCompare(string $ip): string
{
    if (stripos($ip, '::ffff:') === 0) {
        $candidate = substr($ip, 7);
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $candidate;
        }
    }
    return $ip;
}

function ipMatchesAllowlist(string $ip, array $allowed): bool
{
    $ip = normalizeIpForCompare($ip);
    foreach ($allowed as $entry) {
        $entry = normalizeIpForCompare((string) $entry);
        if (strpos($entry, '/') === false ? $entry === $ip : ipInCidr($ip, $entry)) {
            return true;
        }
    }
    return false;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    shruError('Method not allowed', 405);
}

if (!shruRequestIsSecure()) {
    shruError('HTTPS is required');
}

$rawBody = file_get_contents('php://input', false, null, 0, SHRU_MAX_BODY_BYTES + 1);
if ($rawBody === false) {
    $rawBody = '';
}
if (strlen($rawBody) > SHRU_MAX_BODY_BYTES) {
    shruError('Request body is too large', 413);
}

$keyId = shruHeader('X-Key-Id');
$timestamp = shruHeader('X-Timestamp');
$signature = strtolower(shruHeader('X-Signature'));

if ($keyId === '' || $timestamp === '' || $signature === '') {
    shruError('Missing authentication headers', 401);
}

// time() is a Unix epoch value (seconds since 1970-01-01 UTC) - already
// timezone-independent regardless of this server's or the caller's own
// configured timezone. A rejection here almost always means clock drift
// (NTP), never a timezone mismatch.
if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
    shruError('Request timestamp expired or invalid', 401);
}

$reseller = findByKeyId(loadResellers(), $keyId);
if ($reseller === null || empty($reseller['active']) || ($reseller['hmac_secret'] ?? '') === '') {
    shruError('Invalid credentials', 401);
}

$signable = "POST\n/api/shru\n" . $timestamp . "\n" . $rawBody;
$expectedSignature = hash_hmac('sha256', $signable, (string) $reseller['hmac_secret']);
if (!hash_equals($expectedSignature, $signature)) {
    shruError('Invalid signature', 401);
}

// resolveRealClientIp() (bootstrap.php) resolves the real caller's IP even
// behind Cloudflare, instead of Cloudflare's own edge IP - see its own
// comment for the trust chain. The IP is safe to echo back below because we
// only ever reach this point after the HMAC signature above has already
// verified - only someone who genuinely holds the secret can trigger this
// message, so telling them exactly what IP this server actually saw turns
// "guess what to allowlist" into a one-shot fix instead of a mystery.
$clientIp = normalizeIpForCompare(resolveRealClientIp());
if (!ipMatchesAllowlist($clientIp, $reseller['allowed_ips'] ?? [])) {
    shruError('Unauthorized access - this server saw your request coming from IP ' . $clientIp . ', which is not on this credential\'s allowlist. Add it in Credentials - Edit.', 403);
}

// Recorded at most once a minute, inside the store lock and on a fresh copy of
// the credentials, so it can never overwrite a balance changed meanwhile.
if (time() - (int) ($reseller['last_used_at'] ?? 0) >= 60) {
    withStore(static function () use ($keyId): void {
        $rows = loadResellers();
        foreach ($rows as &$r) {
            if (hash_equals((string) ($r['key_id'] ?? ''), $keyId)) {
                $r['last_used_at'] = time();
            }
        }
        unset($r);
        saveResellers($rows);
    });
}

$requestFields = json_decode($rawBody, true);
if (!is_array($requestFields)) {
    shruError('Request body must be a JSON object');
}
$action = trim((string) ($requestFields['action'] ?? ''));

if ($action === 'account_info') {
    shruSuccess([
        'balance' => money($reseller['balance'] ?? 0),
        'currency' => (string) ($reseller['currency'] ?? 'USD'),
        'email' => (string) ($reseller['email'] ?? ''),
    ]);
}

if ($action === 'sync') {
    $list = [];
    foreach (loadServices() as $svc) {
        if (empty($svc['active'])) {
            continue;
        }
        $isQuantity = ($svc['type'] ?? 'fixed') === 'quantity';
        $quantity = null;
        if ($isQuantity) {
            $qtyMin = max(1, (int) ($svc['qty_min'] ?? 1));
            $quantity = ['min' => $qtyMin, 'max' => max($qtyMin, (int) ($svc['qty_max'] ?? 1))];
        }
        $list[] = [
            'id' => (int) $svc['id'],
            'name' => (string) $svc['name'],
            'group' => (string) ($svc['group'] ?? 'Services'),
            'type' => $isQuantity ? 'quantity' : 'fixed',
            'price' => money($svc['price'] ?? 0),
            'delivery' => (string) (($svc['delivery'] ?? '') !== '' ? $svc['delivery'] : 'Instant'),
            'input_label' => !empty($svc['input_label']) ? (string) $svc['input_label'] : null,
            'input_required' => !empty($svc['input_label']) && !empty($svc['input_required']),
            'quantity' => $quantity,
            'bulk' => !$isQuantity && !empty($svc['bulk']),
            'fields' => array_map(static fn($f) => [
                'label' => (string) $f['label'],
                'key' => (string) $f['key'],
                'type' => ($f['type'] ?? 'input') === 'textarea' ? 'textarea' : 'input',
                'required' => (bool) ($f['required'] ?? false),
            ], $svc['fields'] ?? []),
        ];
    }
    shruSuccess([
        'balance' => money($reseller['balance'] ?? 0),
        'currency' => (string) ($reseller['currency'] ?? 'USD'),
        'services' => $list,
    ]);
}

if ($action === 'submit_order') {
    $serviceId = filter_var($requestFields['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $input = is_scalar($requestFields['input'] ?? null) ? trim((string) $requestFields['input']) : '';
    $requestedQty = $requestFields['quantity'] ?? null;
    $customFields = is_array($requestFields['fields'] ?? null) ? $requestFields['fields'] : [];

    if ($serviceId === false) {
        shruError('Invalid service ID');
    }
    if (!isCleanText($input, SHRU_MAX_INPUT_CHARS)) {
        shruError('Input is too long or contains characters that are not allowed');
    }

    $fingerprint = hash('sha256', $keyId . "\n" . $timestamp . "\n" . $signature);

    $result = withStore(static function () use ($serviceId, $input, $requestedQty, $customFields, $keyId, $fingerprint) {
        if (requestSeenBefore($fingerprint)) {
            return ['error' => 'This exact request was already received', 'code' => 409];
        }

        $services = loadServices();
        $service = findById($services, $serviceId);
        if ($service === null || empty($service['active'])) {
            return ['error' => 'Service not found'];
        }

        $isQuantity = ($service['type'] ?? 'fixed') === 'quantity';
        $inputRequired = !empty($service['input_label']) && !empty($service['input_required']);
        if ($inputRequired && $input === '') {
            return ['error' => 'Input is required'];
        }

        $qty = 1;
        if ($isQuantity) {
            $qtyMin = max(1, (int) ($service['qty_min'] ?? 1));
            $qtyMax = max($qtyMin, (int) ($service['qty_max'] ?? 1));
            $qty = filter_var($requestedQty, FILTER_VALIDATE_INT);
            if ($qty === false || $qty < $qtyMin || $qty > $qtyMax) {
                return ['error' => 'Quantity must be a whole number between ' . $qtyMin . ' and ' . $qtyMax];
            }
        }

        $fieldValues = [];
        foreach ($service['fields'] ?? [] as $def) {
            $key = (string) $def['key'];
            $raw = $customFields[$key] ?? '';
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            if (!isCleanText($value, SHRU_MAX_INPUT_CHARS)) {
                return ['error' => $def['label'] . ' is too long or contains characters that are not allowed'];
            }
            if (!empty($def['required']) && $value === '') {
                return ['error' => $def['label'] . ' is required'];
            }
            $fieldValues[$key] = $value;
        }

        $resellers = loadResellers();
        $reseller = findByKeyId($resellers, $keyId);
        if ($reseller === null || empty($reseller['active'])) {
            return ['error' => 'Account not found'];
        }

        // An order that carries an input or field values, sent again while the
        // first one is still queued, gets that same order back instead of a
        // second charge - safe to retry a call that timed out.
        $orders = loadOrders();
        $orderSignature = ['input' => $input, 'quantity' => $qty, 'fields' => $fieldValues];
        $hasDetails = $input !== '' || implode('', $fieldValues) !== '';
        if ($hasDetails) {
            foreach ($orders as $o) {
                if (hash_equals((string) ($o['key_id'] ?? ''), $keyId)
                    && (int) ($o['service_id'] ?? 0) === $serviceId
                    && ($o['status'] ?? '') === 'queued'
                    && ($o['signature'] ?? null) == $orderSignature
                ) {
                    return ['order_id' => (int) $o['id'], 'status' => 'queued'];
                }
            }
        }

        $price = money((float) $service['price'] * $qty);
        $balance = money($reseller['balance'] ?? 0);
        if ($balance < $price) {
            return ['error' => 'Insufficient balance'];
        }

        foreach ($resellers as &$r) {
            if (hash_equals((string) ($r['key_id'] ?? ''), $keyId)) {
                $r['balance'] = money($balance - $price);
            }
        }
        unset($r);
        saveResellers($resellers);

        $orderId = nextId('orders', $orders);
        $orders[] = [
            'id' => $orderId,
            'key_id' => $keyId,
            'reseller_username' => (string) $reseller['username'],
            'service_id' => $serviceId,
            'service_name' => (string) $service['name'],
            'input' => $input,
            'quantity' => $qty,
            'fields' => $fieldValues,
            'signature' => $orderSignature,
            'price' => $price,
            'status' => 'queued',
            'reply' => '',
            'refunded' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ];
        saveOrders($orders);

        return ['order_id' => $orderId, 'status' => 'queued'];
    });

    if (isset($result['error'])) {
        shruError($result['error'], (int) ($result['code'] ?? 400));
    }
    shruSuccess($result);
}

if ($action === 'check_status') {
    $orderId = filter_var($requestFields['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($orderId === false) {
        shruError('Invalid order ID');
    }
    $order = null;
    foreach (loadOrders() as $o) {
        if ((int) ($o['id'] ?? 0) === $orderId && hash_equals((string) ($o['key_id'] ?? ''), $keyId)) {
            $order = $o;
            break;
        }
    }
    if ($order === null) {
        shruError('Order not found');
    }
    shruSuccess([
        'order_id' => $orderId,
        'status' => (string) $order['status'],
        'reply' => (string) $order['reply'],
    ]);
}

shruError('Unknown action');
