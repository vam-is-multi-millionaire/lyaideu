<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_id('t' . substr(bin2hex(random_bytes(6)), 0, 20));
session_start();
$_SESSION['is_admin'] = true;
$_SESSION['csrf_admin'] = str_repeat('a', 64);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin_kyc.php';
$_GET['tab'] = 'all';
$GLOBALS['pdo'] = null;
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';
ob_start();
include __DIR__ . '/admin_kyc.php';
$h = ob_get_clean();
echo 'data-search count: ' . substr_count($h, 'data-search=') . PHP_EOL;
echo 'cards rendered: ' . substr_count($h, 'admin-kyc-card') . PHP_EOL;
echo 'has kycSearch input: ' . (strpos($h, 'id="kycSearch"') !== false ? 'yes' : 'no') . PHP_EOL;
