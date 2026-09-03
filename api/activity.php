<?php
require_once __DIR__ . '/../admin_inc.php';
require_once __DIR__ . '/../site_config.php';
admin_require_login();
if (!admin_can('activity') && !admin_is_superadmin()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied']);
    exit;
}
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
try { lyaideu_ensure_activity_log_table(); } catch (Throwable $e) {}
$pdo = lyaideu_load_pdo();
if (!$pdo instanceof PDO) {
    echo json_encode(['items'=>[],'maxId'=>0]);
    exit;
}
$isSuper = admin_is_superadmin();
$meId = (int)($_SESSION['admin_id'] ?? 0);
$since = (int)($_GET['since'] ?? 0);
$action = trim((string)($_GET['action'] ?? ''));
$actor = trim((string)($_GET['actor_type'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$limit = 50;
$where = ['id > :since'];
$params = [':since'=>$since];
if ($action !== '' && preg_match('/^[a-z0-9._-]+$/i',$action)) { $where[]='action=:action'; $params[':action']=$action; }
if ($actor !== '' && in_array($actor,['admin','vendor','rider','user','system'],true)) { $where[]='actor_type=:atype'; $params[':atype']=$actor; }
if ($q !== '') { $where[]='(actor_name LIKE :q OR action LIKE :q2 OR entity_type LIKE :q3 OR CAST(entity_id AS CHAR) LIKE :q4)'; $params[':q']='%'.$q.'%'; $params[':q2']='%'.$q.'%'; $params[':q3']='%'.$q.'%'; $params[':q4']='%'.$q.'%'; }
if (!$isSuper) {
    $where[]='(actor_type != \'admin\' OR actor_id = :me)';
    $params[':me']=$meId;
}
$sql = 'SELECT id,actor_type,actor_id,actor_name,actor_role,action,entity_type,entity_id,details,ip,created_at FROM activity_log WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT '.$limit;
try {
    $st=$pdo->prepare($sql);
    $st->execute($params);
    $items=$st->fetchAll(PDO::FETCH_ASSOC);
    // reverse to ascending for prepend logic on client (optional, keep DESC)
    $maxId=$since;
    foreach($items as $r){ if((int)$r['id']>$maxId) $maxId=(int)$r['id']; }
    echo json_encode(['items'=>$items,'maxId'=>$maxId], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['items'=>[],'maxId'=>$since]);
}
