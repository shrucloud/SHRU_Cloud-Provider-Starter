<?php

require __DIR__ . '/storage.php';

function shruProviderConfig(): array
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/config.php';
        if (!file_exists($path)) {
            http_response_code(500);
            die('Missing config.php - copy config.example.php to config.php, set admin_password_hash, and fill in the rest.');
        }
        $config = require $path;
    }
    return $config;
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// The same Cloudflare-verified trust rule SHRU Cloud itself uses, proven
// against real Cloudflare-proxied traffic. A bare $_SERVER['REMOTE_ADDR'] /
// $_SERVER['HTTPS'] is only ever the true origin connection when nothing
// sits in front of this server - behind Cloudflare (as most real
// deployments are) it's Cloudflare's own edge IP and Cloudflare's own
// connection to the origin, neither of which is the actual reseller.
define('CLOUDFLARE_IPV4_RANGES', [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
]);
define('CLOUDFLARE_IPV6_RANGES', [
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
]);

function ipInCidr(string $ip, string $cidr): bool
{
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    [$subnet, $bits] = explode('/', $cidr);
    $bits = (int) $bits;
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }
    $fullBytes = intdiv($bits, 8);
    $remainderBits = $bits % 8;
    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
        return false;
    }
    if ($remainderBits > 0) {
        $mask = (~0 << (8 - $remainderBits)) & 0xFF;
        if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) {
            return false;
        }
    }
    return true;
}

// Only trusts CF-Connecting-IP after independently verifying REMOTE_ADDR
// really is a genuine Cloudflare edge IP - never trusts the header blindly,
// since it's otherwise just a client-supplied string anyone could forge.
function resolveRealClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    $fromCloudflare = false;
    foreach (array_merge(CLOUDFLARE_IPV4_RANGES, CLOUDFLARE_IPV6_RANGES) as $range) {
        if (ipInCidr($remoteAddr, $range)) {
            $fromCloudflare = true;
            break;
        }
    }

    if ($fromCloudflare && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
            return $cfIp;
        }
    }

    return $remoteAddr;
}

// Same reasoning as resolveRealClientIp() above, for the request's scheme
// instead of its IP - behind Cloudflare, the origin hop is very commonly
// plain HTTP even when the real visitor connected over HTTPS, so
// $_SERVER['HTTPS'] alone can't tell secure and insecure traffic apart
// there. Deliberately does NOT reject when Cloudflare's own signal is
// ambiguous - a false positive here would take the API down entirely,
// worse than the narrow gap it would close.
function shruRequestIsSecure(): bool
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $fromCloudflare = false;
    foreach (array_merge(CLOUDFLARE_IPV4_RANGES, CLOUDFLARE_IPV6_RANGES) as $range) {
        if (ipInCidr($remoteAddr, $range)) {
            $fromCloudflare = true;
            break;
        }
    }

    if ($fromCloudflare) {
        $cfVisitor = $_SERVER['HTTP_CF_VISITOR'] ?? '';
        if ($cfVisitor !== '' && preg_match('/"scheme"\s*:\s*"(https?)"/i', $cfVisitor, $m)) {
            return strtolower($m[1]) === 'https';
        }
        return true;
    }

    $https = $_SERVER['HTTPS'] ?? '';
    return $https !== '' && strtolower($https) !== 'off';
}

// Detected from the current request rather than a config setting a newbie
// could forget to fill in - a reseller needs the exact base URL to connect
// to, and guessing it wrong (or leaving it unset) is a common source of
// confusion. Uses the same Cloudflare-aware scheme detection as the real
// endpoint check above, so this displayed URL is correct even when the
// origin connection itself is plain HTTP behind Cloudflare's proxy.
function shruCurrentApiEndpoint(): string
{
    $scheme = shruRequestIsSecure() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'your-domain.com';
    return $scheme . '://' . $host . '/api/shru';
}

function isValidUsername(string $username): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $username);
}

// Valid UTF-8, no control characters other than tab and line breaks, and at
// most $maxChars characters.
function isCleanText(string $value, int $maxChars): bool
{
    return preg_match('//u', $value) === 1
        && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
        && preg_match_all('/./su', $value) <= $maxChars;
}

function isValidIpOrCidr(string $entry): bool
{
    if (strpos($entry, '/') === false) {
        return filter_var($entry, FILTER_VALIDATE_IP) !== false;
    }
    [$ip, $bits] = explode('/', $entry, 2);
    if (!ctype_digit($bits) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    $maxBits = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
    return (int) $bits <= $maxBits;
}

// Splits the admin's allowlist box into entries. Anything that is not an IP or
// a CIDR range comes back in 'invalid', so it can be refused instead of being
// saved as an entry that silently never matches.
function parseAllowedIps(string $raw): array
{
    $ips = [];
    $invalid = [];
    foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $entry) {
        if ($entry === '') {
            continue;
        }
        if (isValidIpOrCidr($entry)) {
            $ips[] = $entry;
        } else {
            $invalid[] = $entry;
        }
    }
    return ['ips' => array_values(array_unique($ips)), 'invalid' => $invalid];
}
