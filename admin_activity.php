<?php
require_once __DIR__ . '/admin_inc.php';
admin_require_login();
admin_require_page('activity');
require_once __DIR__ . '/site_config.php';
lyaideu_ensure_activity_log_table();
lyaideu_activity_purge();
$pdo = lyaideu_load_pdo();
$isSuper = admin_is_superadmin();
$meId = (int)($_SESSION['admin_id'] ?? 0);

// Filters
$action = trim((string)($_GET['action'] ?? ''));
$actor = trim((string)($_GET['actor_type'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$page = max(1,(int)($_GET['p'] ?? 1));
$per = 50;
$off = ($page-1)*$per;

// CSV export
if (isset($_GET['export']) && $_GET['export']==='csv') {
    if (!$pdo instanceof PDO) { http_response_code(500); exit('DB unavailable'); }
    $where=['1=1']; $params=[];
    if ($action !== '' && preg_match('/^[a-z0-9._-]+$/i',$action)) { $where[]='action=:a'; $params[':a']=$action; }
    if ($actor !== '' && in_array($actor,['admin','vendor','rider','user','system'],true)) { $where[]='actor_type=:at'; $params[':at']=$actor; }
    if ($q !== '') { $where[]='(actor_name LIKE :q OR action LIKE :q2 OR entity_type LIKE :q3)'; $params[':q']='%'.$q.'%'; $params[':q2']='%'.$q.'%'; $params[':q3']='%'.$q.'%'; }
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) { $where[]='created_at >= :from'; $params[':from']=$from.' 00:00:00'; }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) { $where[]='created_at <= :to'; $params[':to']=$to.' 23:59:59'; }
    if (!$isSuper) { $where[]='(actor_type != \'admin\' OR actor_id = :me)'; $params[':me']=$meId; }
    $sql='SELECT id,created_at,actor_type,actor_name,actor_role,action,entity_type,entity_id,ip,details FROM activity_log WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT 5000';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="activity_'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','w');
    fputcsv($out,['id','created_at','actor_type','actor_name','actor_role','action','entity_type','entity_id','ip','details']);
    try { $st=$pdo->prepare($sql); $st->execute($params); while($r=$st->fetch(PDO::FETCH_ASSOC)){ fputcsv($out,[$r['id'],$r['created_at'],$r['actor_type'],$r['actor_name'],$r['actor_role'],$r['action'],$r['entity_type'],$r['entity_id'],$r['ip'],$r['details']]); } } catch (Throwable $e) {}
    fclose($out); exit;
}

admin_page_start('Activity Log','activity');
$actions = [];
try { if($pdo instanceof PDO) $actions=$pdo->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN); } catch(Throwable $e){}
?>
<div class="admin-section">
  <div class="admin-section-top" style="flex-wrap:wrap;gap:.7rem">
    <div style="display:flex;align-items:center;gap:.6rem">
      <span class="admin-live-badge" id="liveBadge" style="display:inline-flex;align-items:center;gap:.35rem;background:var(--orange-100);color:var(--orange-800);font-weight:800;font-size:.78rem;padding:.32rem .7rem;border-radius:999px"><span class="live-dot" style="width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;animation:livePulse 1.6s infinite"></span> Live</span>
      <span id="liveStatus" style="font-size:.78rem;color:var(--muted);font-weight:700"></span>
    </div>
    <a class="btn btn-outline" href="admin_activity?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
  </div>
  <form method="GET" action="admin_activity" class="activity-filters">
    <input type="search" name="q" value="<?= htmlspecialchars($q,ENT_QUOTES,'UTF-8') ?>" placeholder="Search actor/action/entity..." style="min-width:180px">
    <select name="actor_type"><option value="">All actors</option><?php foreach(['admin','vendor','rider','user','system'] as $v) echo '<option value="'.$v.'"'.($actor===$v?' selected':'').'>'.ucfirst($v).'</option>'; ?></select>
    <select name="action"><option value="">All actions</option><?php foreach($actions as $a) echo '<option value="'.htmlspecialchars($a,ENT_QUOTES,'UTF-8').'"'.($action===$a?' selected':'').'>'.htmlspecialchars($a).'</option>'; ?></select>
    <input type="date" name="from" value="<?= htmlspecialchars($from,ENT_QUOTES,'UTF-8') ?>">
    <input type="date" name="to" value="<?= htmlspecialchars($to,ENT_QUOTES,'UTF-8') ?>">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
    <a class="btn btn-outline" href="admin_activity">Reset</a>
  </form>
</div>

<div class="admin-section" style="padding:0;overflow:hidden">
  <div class="admin-table-wrap">
    <table class="admin-table" id="activityTable">
      <thead><tr><th>#</th><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th class="hide-sm">IP</th><th>Details</th></tr></thead>
      <tbody id="activityBody">
<?php
$where=['1=1']; $params=[];
if ($action !== '' && preg_match('/^[a-z0-9._-]+$/i',$action)) { $where[]='action=:a'; $params[':a']=$action; }
if ($actor !== '' && in_array($actor,['admin','vendor','rider','user','system'],true)) { $where[]='actor_type=:at'; $params[':at']=$actor; }
if ($q !== '') { $where[]='(actor_name LIKE :q OR action LIKE :q2 OR entity_type LIKE :q3 OR CAST(entity_id AS CHAR) LIKE :q4)'; $params[':q']='%'.$q.'%'; $params[':q2']='%'.$q.'%'; $params[':q3']='%'.$q.'%'; $params[':q4']='%'.$q.'%'; }
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) { $where[]='created_at >= :from'; $params[':from']=$from.' 00:00:00'; }
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) { $where[]='created_at <= :to'; $params[':to']=$to.' 23:59:59'; }
if (!$isSuper) { $where[]='(actor_type != \'admin\' OR actor_id = :me)'; $params[':me']=$meId; }
$count=0; $maxId=0;
try {
  if($pdo instanceof PDO){
    $c=$pdo->prepare('SELECT COUNT(*) FROM activity_log WHERE '.implode(' AND ',$where));
    $c->execute($params); $count=(int)$c->fetchColumn();
    $s=$pdo->prepare('SELECT id,actor_type,actor_id,actor_name,actor_role,action,entity_type,entity_id,details,ip,created_at FROM activity_log WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT '.$per.' OFFSET '.$off);
    $s->execute($params);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r){
      if((int)$r['id']>$maxId) $maxId=(int)$r['id'];
      $det=$r['details']; $detShort=''; try{ $j=json_decode($det,true); $detShort=$j? htmlspecialchars(json_encode($j,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8'): htmlspecialchars($det??'',ENT_QUOTES,'UTF-8'); }catch(Throwable $e){ $detShort=htmlspecialchars($det??'',ENT_QUOTES,'UTF-8'); }
      if(strlen($detShort)>120) $detShort=substr($detShort,0,120).'…';
      echo '<tr data-id="'.(int)$r['id'].'"><td>'.(int)$r['id'].'</td><td style="white-space:nowrap">'.htmlspecialchars($r['created_at']).'</td><td><span class="chip" style="font-size:.72rem;padding:.2rem .5rem">'.htmlspecialchars($r['actor_type']).'</span> '.htmlspecialchars($r['actor_name']).' <small style="color:var(--muted)">'.htmlspecialchars($r['actor_role']).'</small></td><td><span class="pm-badge">'.htmlspecialchars($r['action']).'</span></td><td>'.htmlspecialchars($r['entity_type'].'#'.($r['entity_id']??'-')).'</td><td class="hide-sm">'.htmlspecialchars($r['ip']).'</td><td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'.htmlspecialchars($r['details']??'',ENT_QUOTES,'UTF-8').'">'.$detShort.'</td></tr>';
    }
    if(!$rows) echo '<tr><td colspan="7" style="text-align:center;padding:1.2rem;color:var(--muted)">No activity yet.</td></tr>';
  }
} catch(Throwable $e){ echo '<tr><td colspan="7">DB error</td></tr>'; }
?>
      </tbody>
    </table>
  </div>
  <div style="padding:.8rem 1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem">
    <small style="color:var(--muted)">Total <?= $count ?> • Page <?= $page ?></small>
    <div style="display:flex;gap:.4rem">
      <?php if($page>1) echo '<a class="btn btn-outline" href="admin_activity?'.http_build_query(array_merge($_GET,['p'=>$page-1])).'">Prev</a>'; ?>
      <?php if($off+$per < $count) echo '<a class="btn btn-outline" href="admin_activity?'.http_build_query(array_merge($_GET,['p'=>$page+1])).'">Next</a>'; ?>
    </div>
  </div>
</div>
<style>
@keyframes livePulse{0%,100%{opacity:1}50%{opacity:.4}}
.activity-filters{display:flex;flex-wrap:wrap;gap:.55rem;margin-top:.9rem;align-items:center}
.activity-filters input,.activity-filters select{padding:.5rem .7rem;border:2px solid var(--orange-200);border-radius:999px;font:inherit;font-size:.84rem;min-width:0}
.activity-filters input[type="search"]{flex:1 1 160px}
.admin-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.admin-table{width:100%;border-collapse:collapse;font-size:.84rem}
.admin-table th,.admin-table td{padding:.6rem .7rem;border-bottom:1px solid var(--orange-100);text-align:left;white-space:nowrap}
.admin-table th{font-weight:900;background:var(--orange-50);position:sticky;top:0}
.pm-badge{display:inline-flex;background:var(--orange-100);color:var(--orange-800);font-weight:800;font-size:.72rem;padding:.2rem .5rem;border-radius:999px}
@media (max-width:700px){
  .activity-filters{flex-direction:column;align-items:stretch}
  .activity-filters input,.activity-filters select{width:100%}
  .admin-table{font-size:.78rem}
  .hide-sm{display:none}
  .admin-table th,.admin-table td{padding:.45rem .5rem}
}
</style>
<script>
(function(){
  var maxId = <?= (int)$maxId ?>;
  var statusEl = document.getElementById('liveStatus');
  var tbody = document.getElementById('activityBody');
  var q = new URLSearchParams(location.search);
  function buildUrl(since){
    var u = new URL('api/activity.php', location.href);
    u.searchParams.set('since', since);
    if(q.get('action')) u.searchParams.set('action', q.get('action'));
    if(q.get('actor_type')) u.searchParams.set('actor_type', q.get('actor_type'));
    if(q.get('q')) u.searchParams.set('q', q.get('q'));
    return u.toString();
  }
  function esc(s){ return String(s??'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function rowHtml(r){
    var det=''; try{ var j=r.details? JSON.parse(r.details):null; det=j? JSON.stringify(j): (r.details||''); }catch(e){ det=r.details||''; }
    det = esc(det); if(det.length>120) det=det.slice(0,120)+'…';
    return '<tr data-id="'+r.id+'" style="background:#fff7ed"><td>'+r.id+'</td><td style="white-space:nowrap">'+esc(r.created_at)+'</td><td><span class="chip" style="font-size:.72rem;padding:.2rem .5rem">'+esc(r.actor_type)+'</span> '+esc(r.actor_name)+' <small style="color:var(--muted)">'+esc(r.actor_role)+'</small></td><td><span class="pm-badge">'+esc(r.action)+'</span></td><td>'+esc(r.entity_type+'#'+(r.entity_id??'-'))+'</td><td class="hide-sm">'+esc(r.ip)+'</td><td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+esc(r.details||'')+'">'+det+'</td></tr>';
  }
  var timer=null;
  function poll(){
    if(document.hidden){ statusEl.textContent='paused'; return; }
    fetch(buildUrl(maxId),{headers:{'X-Requested-With':'fetch'},cache:'no-store'})
      .then(function(r){return r.json()})
      .then(function(d){
        var items=d.items||[];
        if(items.length){
          items.reverse().forEach(function(it){
            if(it.id>maxId) maxId=it.id;
            var tr=document.createElement('tbody'); tr.innerHTML=rowHtml(it);
            var row=tr.firstChild;
            if(tbody.firstChild) tbody.insertBefore(row, tbody.firstChild);
            else tbody.appendChild(row);
          });
          // keep 50 rows
          while(tbody.children.length>50) tbody.removeChild(tbody.lastChild);
          statusEl.textContent='updated '+new Date().toLocaleTimeString();
          setTimeout(function(){ statusEl.textContent='live'; }, 2000);
        } else {
          statusEl.textContent='live';
        }
      }).catch(function(){ statusEl.textContent='offline'; });
  }
  statusEl.textContent='live';
  timer=setInterval(poll,5000);
  document.addEventListener('visibilitychange', function(){ if(!document.hidden) poll(); });
})();
</script>
<?php admin_page_end(); ?>
