<?php

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

require_once __DIR__ . '/google_config.php';
require_once __DIR__ . '/db.php';

function google_flash(string $type, string $msg, string $to = 'login'): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . $to);
    exit;
}

function google_b64url_decode(string $data): string {
    $rem = strlen($data) % 4;
    if ($rem) {
        $data .= str_repeat('=', 4 - $rem);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

if (GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com' || GOOGLE_CLIENT_SECRET === 'YOUR_GOOGLE_CLIENT_SECRET') {
    google_flash('error', 'Google sign-in is not configured yet. Add your Client ID &amp; Secret in google_config.php.');
}

/* ---- CSRF: state must match what google_login.php stored ---- */
$state    = (string)($_GET['state'] ?? '');
$expected = (string)($_SESSION['google_oauth_state'] ?? '');
unset($_SESSION['google_oauth_state']);

if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
    google_flash('error', 'Your Google sign-in session expired. Please try again.');
}

if (!empty($_GET['error'])) {
    google_flash('error', 'Google sign-in was cancelled. You can use email &amp; password instead.');
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    google_flash('error', 'Google did not return an authorization code. Please try again.');
}

/* ---- Exchange the authorization code for tokens (cURL, TLS-verified) ---- */
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
]);
$raw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($raw === false || $curlErr !== '') {
    google_flash('error', 'Could not reach Google right now. Please try again.');
}
$tokens = json_decode((string)$raw, true);
if (!is_array($tokens) || empty($tokens['id_token'])) {
    google_flash('error', 'Google sign-in failed (' . htmlspecialchars((string)$httpCode, ENT_QUOTES, 'UTF-8') . '). Please try again.');
}

/* ---- Validate the id_token claims (token received directly from Google
        over TLS with our client_secret, so aud/iss/exp checks apply) ---- */
$parts = explode('.', (string)$tokens['id_token']);
if (count($parts) !== 3) {
    google_flash('error', 'Invalid response from Google. Please try again.');
}
$claims = json_decode(google_b64url_decode($parts[1]), true);
if (!is_array($claims)) {
    google_flash('error', 'Invalid response from Google. Please try again.');
}

$issOk = isset($claims['iss']) && in_array($claims['iss'], ['accounts.google.com', 'https://accounts.google.com'], true);
$aud   = $claims['aud'] ?? '';
$audOk = is_string($aud) ? hash_equals(GOOGLE_CLIENT_ID, $aud) : (is_array($aud) && in_array(GOOGLE_CLIENT_ID, $aud, true));

if (!$issOk || !$audOk || empty($claims['exp']) || (int)$claims['exp'] < time()) {
    google_flash('error', 'Google token could not be verified. Please try again.');
}
if (empty($claims['email']) || empty($claims['sub']) || (isset($claims['email_verified']) && !$claims['email_verified'])) {
    google_flash('error', 'Your Google account does not share a verified email address.');
}

$sub       = (string)$claims['sub'];
$email     = strtolower(trim((string)$claims['email']));
$name      = trim(strip_tags((string)($claims['name'] ?? '')));
if ($name === '') {
    $name = ucfirst(strtok($email, '@'));
}

try {
    /* 1) Already linked via Google ID -> straight login. */
    $stmt = $pdo->prepare(
        'SELECT id, name, email, phone, dob, avatar, address, kyc_status
         FROM users WHERE google_sub = :sub LIMIT 1'
    );
    $stmt->execute([':sub' => $sub]);
    $u = $stmt->fetch();

    /* 2) Existing email/password account -> link this Google ID and log in. */
    if (!$u) {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, phone, dob, avatar, address, kyc_status, google_sub
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $u = $stmt->fetch();

        if ($u && empty($u['google_sub'])) {
            $link = $pdo->prepare('UPDATE users SET google_sub = :sub WHERE id = :id AND (google_sub IS NULL OR google_sub = \'\')');
            $link->execute([':sub' => $sub, ':id' => (int)$u['id']]);
        }
    }

    /* 3) Brand-new Google user -> create the account (phone/DOB completed later). */
    if (!$u) {
        $ins = $pdo->prepare(
            'INSERT INTO users (name, email, phone, dob, pass, google_sub, created_at)
             VALUES (:name, :email, NULL, NULL, \'\', :sub, :created_at)'
        );
        $ins->execute([
            ':name'       => mb_substr($name, 0, 255),
            ':email'      => $email,
            ':sub'        => $sub,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);

        $stmt = $pdo->prepare(
            'SELECT id, name, email, phone, dob, avatar, address, kyc_status
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => (int)$pdo->lastInsertId()]);
        $u = $stmt->fetch();
        $isNew = true;
    }

    if (!$u) {
        throw new RuntimeException('user not found after Google sign-in');
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'         => (int)$u['id'],
        'name'       => (string)$u['name'],
        'email'      => (string)$u['email'],
        'phone'      => (string)($u['phone'] ?? ''),
        'dob'        => (string)($u['dob'] ?? ''),
        'avatar'     => (string)($u['avatar'] ?? ''),
        'address'    => (string)($u['address'] ?? ''),
        'kyc_status' => (string)($u['kyc_status'] ?? 'none'),
    ];

    /* Missing phone or DOB (Google never provides them) -> complete profile first. */
    $incomplete = trim($_SESSION['user']['phone']) === '' || trim($_SESSION['user']['dob']) === '';
    if (!empty($isNew)) {
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Welcome to LyaiDeu, ' . htmlspecialchars($name) . '! Just add your phone &amp; date of birth to finish.'];
    } elseif ($incomplete) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please add your phone number and date of birth to finish setting up your account.'];
    }

    header('Location: ' . ($incomplete ? 'profile?complete=1' : 'index'));
    exit;
} catch (Throwable $e) {
    google_flash('error', 'Could not sign you in with Google right now. Please try again.');
}
