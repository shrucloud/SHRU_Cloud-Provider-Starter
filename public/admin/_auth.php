<?php

require __DIR__ . '/../../bootstrap.php';

const ADMIN_IDLE_SECONDS = 1800;
const ADMIN_LOGIN_MAX_FAILS = 8;
const ADMIN_LOGIN_WINDOW_SECONDS = 900;

// A fresh clone's config.example.php ships with admin_password_hash blank -
// every admin page (including login.php itself, which requires this file
// first) refuses to run at all until a real hash is set, rather than
// quietly falling back to some default or letting a malformed value slip
// through password_verify() unnoticed.
function adminPasswordIsConfigured(string $hash): bool
{
    return (bool) preg_match('/^\$(2y|argon2i|argon2id)\$/', $hash);
}

// Storage problems (a damaged or unwritable data file) stop the page with the
// storage layer's own short message; anything else only says where to look.
set_exception_handler(static function (Throwable $e): void {
    error_log('shru provider admin: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. The details are in the PHP error log.';
});

header_remove('X-Powered-By');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$shruProviderAdminConfig = shruProviderConfig();
if (!adminPasswordIsConfigured((string) ($shruProviderAdminConfig['admin_password_hash'] ?? ''))) {
    http_response_code(500);
    die('admin_password_hash is not set in config.php. Run "php generate-admin-password.php" on the server and paste the hash it prints. The admin panel stays locked until then.');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('shru_provider_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => shruRequestIsSecure(),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (!empty($_SESSION['shru_admin']) && time() - (int) ($_SESSION['seen_at'] ?? 0) > ADMIN_IDLE_SECONDS) {
    $_SESSION = [];
    session_regenerate_id(true);
}
if (!empty($_SESSION['shru_admin'])) {
    $_SESSION['seen_at'] = time();
}

function requireAdminLogin(): void
{
    if (empty($_SESSION['shru_admin'])) {
        header('Location: login.php');
        exit;
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function checkCsrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
        http_response_code(400);
        die('Invalid or expired form submission - go back and try again.');
    }
}

// Failed sign-ins per address, kept for ADMIN_LOGIN_WINDOW_SECONDS. Returns
// true while that address is still allowed to try.
function adminLoginAllowed(string $ip): bool
{
    return withLock('login', static function () use ($ip): bool {
        $now = time();
        $fails = array_filter(jsonRead('login-fails.json', [])[$ip] ?? [], static fn($at) => (int) $at > $now - ADMIN_LOGIN_WINDOW_SECONDS);
        return count($fails) < ADMIN_LOGIN_MAX_FAILS;
    });
}

function adminLoginRecord(string $ip, bool $success): void
{
    withLock('login', static function () use ($ip, $success): void {
        $now = time();
        $all = [];
        foreach (jsonRead('login-fails.json', []) as $addr => $times) {
            $times = array_values(array_filter((array) $times, static fn($at) => (int) $at > $now - ADMIN_LOGIN_WINDOW_SECONDS));
            if ($times) {
                $all[$addr] = $times;
            }
        }
        if ($success) {
            unset($all[$ip]);
        } else {
            $all[$ip][] = $now;
        }
        jsonWrite('login-fails.json', $all);
    });
}

function adminNav(string $active): void
{
    $links = [
        'dashboard' => ['index.php', 'Dashboard'],
        'services' => ['services.php', 'Services'],
        'keys' => ['keys.php', 'Credentials'],
        'orders' => ['orders.php', 'Orders'],
    ];
    echo '<div class="tabs">';
    foreach ($links as $key => [$href, $label]) {
        $class = $key === $active ? ' class="active"' : '';
        echo '<a href="' . h($href) . '"' . $class . '>' . h($label) . '</a>';
    }
    echo '<span class="spacer"></span>';
    echo '<form method="post" action="logout.php" class="inline-form nav-logout">';
    echo '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">';
    echo '<button type="submit">Log out</button>';
    echo '</form>';
    echo '</div>';
}
