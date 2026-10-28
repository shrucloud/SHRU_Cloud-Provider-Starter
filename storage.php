<?php

define('SHRU_DATA_DIR', __DIR__ . '/data');

// Every change to services, credentials or orders runs inside withStore(): one
// exclusive lock held across the whole read-modify-write, with the data read
// fresh inside it. The API and the admin panel can then never overwrite each
// other's changes (a balance debit and a refund landing at the same moment
// both count).
function withStore(callable $fn)
{
    return withLock('store', $fn);
}

function withLock(string $lockName, callable $fn)
{
    ensureDataDir();
    $fh = fopen(SHRU_DATA_DIR . '/' . $lockName . '.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        throw new RuntimeException('Could not lock the data folder.');
    }
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function ensureDataDir(): void
{
    if (!is_dir(SHRU_DATA_DIR) && !mkdir(SHRU_DATA_DIR, 0770, true) && !is_dir(SHRU_DATA_DIR)) {
        throw new RuntimeException('Could not create the data folder.');
    }
}

// A file that exists but cannot be read or decoded stops the request instead
// of being treated as empty: the next save would otherwise replace every
// credential, balance or order in it with nothing.
function jsonRead(string $file, array $default): array
{
    $path = SHRU_DATA_DIR . '/' . $file;
    if (!file_exists($path)) {
        return $default;
    }
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException('Could not read data/' . $file . '.');
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('data/' . $file . ' is damaged. Restore it from a backup.');
    }
    return $decoded;
}

// Written to a temporary file and renamed over the old one, so a reader sees
// either the old file or the new one, never half of it. Any failure throws
// before the rename and leaves the old file untouched.
function jsonWrite(string $file, array $data): void
{
    ensureDataDir();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) {
        throw new RuntimeException('Could not encode data/' . $file . '.');
    }
    $path = SHRU_DATA_DIR . '/' . $file;
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
        @unlink($tmp);
        throw new RuntimeException('Could not write data/' . $file . '.');
    }
    @chmod($tmp, 0660);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace data/' . $file . '.');
    }
}

// Money is kept to 3 decimals, the precision prices are published with, so
// repeated debits and refunds never drift by a fraction of a cent.
function money($value): float
{
    return round((float) $value, 3);
}

// IDs only ever go up, even after a row is deleted, so a reseller holding an
// old service ID can never end up ordering a different, newer service. Call
// inside withStore().
function nextId(string $table, array $rows): int
{
    $counters = jsonRead('ids.json', []);
    $max = (int) ($counters[$table] ?? 0);
    foreach ($rows as $row) {
        $max = max($max, (int) ($row['id'] ?? 0));
    }
    $counters[$table] = $max + 1;
    jsonWrite('ids.json', $counters);
    return $max + 1;
}

// Remembers a signed request for longer than its timestamp stays valid, so
// the exact same request can never be accepted twice. Call inside withStore().
function requestSeenBefore(string $fingerprint): bool
{
    $now = time();
    $seen = array_filter(jsonRead('replay.json', []), static fn($at) => (int) $at > $now - 900);
    if (isset($seen[$fingerprint])) {
        return true;
    }
    $seen[$fingerprint] = $now;
    jsonWrite('replay.json', $seen);
    return false;
}

function loadServices(): array { return jsonRead('services.json', []); }
function saveServices(array $rows): void { jsonWrite('services.json', array_values($rows)); }

function loadResellers(): array { return jsonRead('resellers.json', []); }
function saveResellers(array $rows): void { jsonWrite('resellers.json', array_values($rows)); }

function loadOrders(): array { return jsonRead('orders.json', []); }
function saveOrders(array $rows): void { jsonWrite('orders.json', array_values($rows)); }

function findById(array $rows, int $id): ?array
{
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            return $row;
        }
    }
    return null;
}

function findByKeyId(array $resellers, string $keyId): ?array
{
    foreach ($resellers as $row) {
        if (hash_equals((string) ($row['key_id'] ?? ''), $keyId)) {
            return $row;
        }
    }
    return null;
}

function findByUsername(array $resellers, string $username, int $excludeId = 0): ?array
{
    foreach ($resellers as $row) {
        if ((int) ($row['id'] ?? 0) === $excludeId) {
            continue;
        }
        if (strcasecmp((string) ($row['username'] ?? ''), $username) === 0) {
            return $row;
        }
    }
    return null;
}
