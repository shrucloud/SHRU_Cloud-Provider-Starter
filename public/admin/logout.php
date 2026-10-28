<?php

require __DIR__ . '/_auth.php';

// Signing out is a POST with the CSRF token, so another site can never sign
// the admin out with a link or an image.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
checkCsrf();

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
