<?php

/* Live promo-code validation for the checkout "Apply" button.
   Login-gated (checkout itself requires a logged-in user) so codes cannot be
   probed anonymously. The authoritative re-check happens again in
   order_save.php at submit time. */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Please log in to use promo codes.']);
    exit;
}

$code = trim((string)($_GET['code'] ?? ''));
$subtotal = max(0, (int)($_GET['subtotal'] ?? 0));
$userId = (int)$_SESSION['user']['id'];

require_once __DIR__ . '/../site_config.php';

$res = lyaideu_promo_evaluate($code, $subtotal, $userId);

echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
