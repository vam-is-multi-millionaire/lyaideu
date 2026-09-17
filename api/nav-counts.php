<?php
require_once __DIR__ . '/../admin_inc.php';
require_once __DIR__ . '/../site_config.php';
admin_require_login();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$counts = admin_nav_badges();
foreach ($counts as $k => $v) {
    $counts[$k] = max(0, (int)$v);
}
echo json_encode(['counts' => $counts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
