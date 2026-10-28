<?php

return [
    // Shown in the admin panel and used as the default order currency.
    'currency' => 'USD',

    // Login for /public/admin/ - never store a plain password here.
    // Fastest way to get both a strong random password and its hash:
    //   php generate-admin-password.php
    // Left blank, the admin panel refuses to run at all - it will not
    // silently accept any password, so there's no way to accidentally
    // deploy this with the panel wide open.
    'admin_username' => 'admin',
    'admin_password_hash' => '',
];
