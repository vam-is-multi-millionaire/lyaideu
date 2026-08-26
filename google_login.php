<?php

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

require_once __DIR__ . '/google_config.php';

if (GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com' || GOOGLE_CLIENT_SECRET === 'YOUR_GOOGLE_CLIENT_SECRET') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Google sign-in is not configured yet. Add your Client ID &amp; Secret in google_config.php.'];
    header('Location: login');
    exit;
}

/* CSRF protection: random state stored in the session, verified on the callback. */
$_SESSION['google_oauth_state'] = bin2hex(random_bytes(16));

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $_SESSION['google_oauth_state'],
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
