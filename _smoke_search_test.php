<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_id('smoketest' . substr(bin2hex(random_bytes(6)), 0, 20));
session_start();
$_SESSION['is_admin'] = true;
$_SESSION['csrf_admin'] = str_repeat('a', 64);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin_kyc.php';
$results = [];

function smoke_render(string $file, string $scriptName, array $checks): void {
    global $results, $pdo;
    $_SERVER['SCRIPT_NAME'] = $scriptName;
    ob_start();
    include $file;
    $html = ob_get_clean();
    foreach ($checks as $label => $needle) {
        $ok = is_string($needle) ? strpos($html, $needle) !== false : !$needle;
        $results[] = [$file . ' :: ' . $label, $ok];
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

smoke_render(__DIR__ . '/admin_kyc.php', '/admin_kyc.php', [
    'search input' => 'id="kycSearch"',
    'data-search on card' => 'data-search="',
    'count badge id' => 'id="kycShown"',
    'search empty' => 'id="kycSearchEmpty"',
    'filter script' => 'kycSearch',
]);

smoke_render(__DIR__ . '/admin_messages.php', '/admin_messages.php', [
    'search input' => 'id="messageSearch"',
    'data-search on card' => 'data-search="',
    'count badge id' => 'id="messageShown"',
    'search empty' => 'id="messageSearchEmpty"',
    'filter script' => 'messageSearch',
]);

foreach ($results as $r) {
    echo ($r[1] ? 'PASS' : 'FAIL') . ' :: ' . $r[0] . PHP_EOL;
}
