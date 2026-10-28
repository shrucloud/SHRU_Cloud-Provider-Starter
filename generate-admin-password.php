<?php

// Run from a terminal: php generate-admin-password.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function randomPassword(int $length = 20): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^&*+-=';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

$password = randomPassword();
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
$hash = password_hash($password, $algo);

echo 'Admin password helper' . PHP_EOL . PHP_EOL;
echo 'Password: ' . $password . PHP_EOL;
echo 'Hash:     ' . $hash . PHP_EOL . PHP_EOL;
echo 'Paste the hash into admin_password_hash in config.php.' . PHP_EOL;
echo 'Keep the password in a password manager: it is what you sign in with, and it is not stored anywhere.' . PHP_EOL;
