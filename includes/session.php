<?php
declare(strict_types=1);

$cookieSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $cookieSecure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();
