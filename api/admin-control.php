<?php

/* Control Panel AJAX endpoint.
   POST {id, active} → flips categories.is_active for one category and returns
   the fresh toggle state for every category plus per-type hidden product
   counts, so the Control Panel UI can update itself in place.
   Auth: admin session + X-CSRF-Token header (same token the admin forms use). */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../site_config.php';
require_once __DIR__ . '/../admin_inc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ctrl_res(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    ctrl_res(['ok' => false, 'error' => 'POST only.'], 405);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

lyaideu_ensure_admin_users_tables();
if (!admin_can('control')) {
    ctrl_res(['ok' => false, 'error' => 'Admin login required.'], 401);
}

$headerToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals(admin_csrf_token(), $headerToken)) {
    ctrl_res(['ok' => false, 'error' => 'Invalid security token. Reload the Control Panel.'], 403);
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$id = (int)($body['id'] ?? 0);
$type = strtolower(trim((string)($body['type'] ?? '')));
$setting = strtolower(trim((string)($body['setting'] ?? '')));
$active = !empty($body['active']) ? 1 : 0;

$VALID_TYPES = ['menu', 'mart', 'other', 'beverage'];

$isKyc = $setting === 'kyc';
if ($id <= 0 && !in_array($type, $VALID_TYPES, true) && !$isKyc) {
    ctrl_res(['ok' => false, 'error' => 'Missing category id, type or setting.'], 422);
}

$pdo = lyaideu_load_pdo();
if (!$pdo instanceof PDO) {
    ctrl_res(['ok' => false, 'error' => 'Database unavailable.'], 500);
}

try {
    lyaideu_ensure_categories_table();
    if ($isKyc) {
        /* Ordering rule: require approved KYC before placing an order. */
        lyaideu_set_kyc_required(!empty($active));
    } elseif ($id > 0) {
        $st = $pdo->prepare('UPDATE categories SET is_active = :a WHERE id = :id');
        $st->execute([':a' => $active, ':id' => $id]);
        if ($st->rowCount() === 0) {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ?');
            $chk->execute([$id]);
            if (!(int)$chk->fetchColumn()) {
                ctrl_res(['ok' => false, 'error' => 'Category not found.'], 404);
            }
        }
    } else {
        /* Bulk: flip every category of one type (Control Panel group switch). */
        $st = $pdo->prepare('UPDATE categories SET is_active = :a WHERE type = :t');
        $st->execute([':a' => $active, ':t' => $type]);
    }
} catch (Throwable $e) {
    ctrl_res(['ok' => false, 'error' => 'Could not save the toggle.'], 500);
}

/* Fresh state straight from SQL — bypasses any per-request helper caches so
   the response always reflects the row we just wrote. */
function ctrl_effective_map(PDO $pdo): array {
    $byId = [];
    foreach ($pdo->query('SELECT id, parent_id, is_active FROM categories')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byId[(int)$r['id']] = $r;
    }
    $eff = [];
    $effective = function (int $id) use (&$effective, &$byId, &$eff): bool {
        if (array_key_exists($id, $eff)) {
            return $eff[$id];
        }
        $chain = [];
        $cur = $id;
        $guard = 0;
        while ($cur > 0 && isset($byId[$cur]) && $guard++ < 12) {
            $chain[] = (int)$byId[$cur]['is_active'] === 1;
            $cur = (int)$byId[$cur]['parent_id'];
        }
        return $eff[$id] = !in_array(false, $chain, true);
    };
    $out = [];
    foreach ($byId as $cid => $row) {
        $out[$cid] = ['active' => (int)$row['is_active'] === 1, 'effective' => $effective((int)$cid)];
    }
    return $out;
}

try {
    $effMap = ctrl_effective_map($pdo);
    $tables = ['menu' => 'dishes', 'mart' => 'mart_items', 'other' => 'other_items', 'beverage' => 'beverage_items'];
    $hidden = ['menu' => 0, 'mart' => 0, 'other' => 0, 'beverage' => 0];
    foreach ($tables as $type2 => $table) {
        foreach ($pdo->query("SELECT category_id, COUNT(*) c FROM `$table` GROUP BY category_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cid = (int)$r['category_id'];
            if ($cid > 0 && isset($effMap[$cid]) && !$effMap[$cid]['effective']) {
                $hidden[$type2] += (int)$r['c'];
            }
        }
    }
    /* Group switch state: a section counts as ON while any of its categories
       is still active. */
    $groups = [];
    foreach ($VALID_TYPES as $vt) {
        if (isset($groups[$vt])) {
            continue;
        }
        $q = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE type = ? AND is_active = 1');
        $q->execute([$vt]);
        $groups[$vt] = ((int)$q->fetchColumn()) > 0;
    }
    ctrl_res([
        'ok' => true,
        'id' => $id,
        'type' => $type,
        'setting' => $setting,
        'active' => $active,
        'state' => ['cats' => $effMap, 'hidden' => $hidden, 'groups' => $groups, 'kyc' => lyaideu_kyc_required()],
    ]);
} catch (Throwable $e) {
    ctrl_res(['ok' => false, 'error' => 'Toggle saved, but the refresh state could not be read.'], 500);
}
