<?php
require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';
lyaideu_ensure_categories_table();

$allCats = lyaideu_categories();

$TYPE_LABELS = ['menu' => 'Food Menu', 'mart' => 'Mart', 'other' => 'Others', 'beverage' => 'Beverages'];
$TYPE_ICONS  = ['menu' => 'fa-utensils', 'mart' => 'fa-basket-shopping', 'other' => 'fa-gift', 'beverage' => 'fa-glass-water'];
$TABLES      = ['menu' => 'dishes', 'mart' => 'mart_items', 'other' => 'other_items', 'beverage' => 'beverage_items'];

$pdo = lyaideu_load_pdo();

/* Direct product count per category id (across all four product tables). */
$countMap = [];
foreach ($TABLES as $table) {
    foreach ($pdo->query("SELECT category_id, COUNT(*) c FROM `$table` GROUP BY category_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['category_id'];
        if ($cid > 0) {
            $countMap[$cid] = ($countMap[$cid] ?? 0) + (int)$r['c'];
        }
    }
}

/* Effective visibility (self + every ancestor ON) and hidden-item totals. */
$hiddenItems = ['menu' => 0, 'mart' => 0, 'other' => 0, 'beverage' => 0];
foreach ($allCats as $c) {
    $c['_eff'] = lyaideu_category_is_active((int)$c['id']);
}
foreach ($TABLES as $type => $table) {
    foreach ($pdo->query("SELECT category_id, COUNT(*) c FROM `$table` GROUP BY category_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['category_id'];
        if ($cid <= 0) {
            continue;
        }
        foreach ($allCats as $c) {
            if ((int)$c['id'] === $cid && empty($c['_eff'])) {
                $hiddenItems[$type] += (int)$r['c'];
                break;
            }
        }
    }
}
$totalHiddenItems = array_sum($hiddenItems);
$totalCats = count($allCats);
$liveCats = count(array_filter($allCats, fn($c) => !empty($c['_eff'])));
$hiddenCats = $totalCats - $liveCats;

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

admin_page_start('Control Panel', 'control', 'Control Panel');
?>
<style>
/* ---- Control Panel ---- */
.ctrl-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.8rem;margin-bottom:1.2rem;}
.ctrl-tile{background:#fff;border:1px solid var(--orange-100);border-radius:12px;padding:.9rem 1rem;display:flex;flex-direction:column;gap:.15rem;box-shadow:var(--shadow-sm);}
.ctrl-tile b{font-size:1.45rem;line-height:1.1;color:var(--orange-900);}
.ctrl-tile span{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);}
.ctrl-tile.ctrl-warn b{color:#c93a3a;}
.ctrl-note{background:var(--orange-50);border:1px solid var(--orange-200);border-radius:10px;padding:.7rem .9rem;font-size:.82rem;font-weight:700;color:var(--orange-900);margin-bottom:1.2rem;}
.ctrl-note i{margin-right:.35rem;}
.ctrl-groups{display:flex;flex-direction:column;gap:1rem;}
.ctrl-group{background:#fff;border:1px solid var(--orange-100);border-radius:14px;overflow:hidden;box-shadow:var(--shadow-sm);}
.ctrl-group-head{display:flex;align-items:center;gap:.6rem;padding:.85rem 1rem;background:linear-gradient(90deg,var(--orange-50),#fff);border-bottom:1px solid var(--orange-100);}
.ctrl-group-head i{color:var(--orange-600);}
.ctrl-group-head h3{font-size:1rem;font-weight:900;color:var(--orange-900);}
.ctrl-group-head small{margin-left:auto;font-size:.72rem;font-weight:800;color:var(--muted);}
.ctrl-list{display:flex;flex-direction:column;}
.ctrl-row{display:flex;align-items:center;gap:.8rem;padding:.55rem 1rem;border-bottom:1px dashed var(--orange-100);transition:background .15s ease;}
.ctrl-row:last-child{border-bottom:0;}
.ctrl-row:hover{background:var(--orange-50);}
.ctrl-main{display:flex;flex-direction:column;min-width:0;flex:1;}
.ctrl-name{font-weight:800;color:var(--orange-900);font-size:.92rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ctrl-path{font-size:.68rem;font-weight:700;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ctrl-meta{display:flex;align-items:center;gap:.6rem;flex:none;}
.ctrl-count{font-size:.72rem;font-weight:800;color:var(--muted);background:var(--orange-50);border:1px solid var(--orange-100);border-radius:999px;padding:.16rem .55rem;white-space:nowrap;}
.ctrl-pill{font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;border-radius:999px;padding:.22rem .6rem;white-space:nowrap;}
.ctrl-pill-live{background:#e7f7ec;color:#1d7a3a;border:1px solid #bfe6cc;}
.ctrl-pill-off{background:#fdeaea;color:#c93a3a;border:1px solid #f5c2c2;}
.ctrl-pill-parent{background:#fff4e0;color:#9a6b0b;border:1px solid #f2ddb0;}
.ctrl-toggle{position:relative;width:46px;height:26px;border-radius:999px;border:0;cursor:pointer;flex:none;background:#d9d4cc;transition:background .2s ease;padding:0;}
.ctrl-toggle .ctrl-knob{position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .2s ease;}
.ctrl-toggle.on{background:var(--orange-600);}
.ctrl-toggle.on .ctrl-knob{left:23px;}
.ctrl-toggle:disabled{opacity:.55;cursor:wait;}
@media (max-width:640px){
  .ctrl-path{display:none;}
  .ctrl-row{padding:.5rem .7rem;gap:.5rem;}
}
</style>

<div class="ctrl-note"><i class="fa-solid fa-circle-info"></i> Turn a category OFF to hide it and everything inside it (subcategories + their products) from the whole website — menus, search, home page picks and store pages. Changes go live within ~5 seconds. Products without a category always stay visible.</div>

<div class="ctrl-tiles">
    <div class="ctrl-tile"><b id="ctrlTileCats"><?= $totalCats ?></b><span>Categories</span></div>
    <div class="ctrl-tile"><b id="ctrlTileLive"><?= $liveCats ?></b><span>Live categories</span></div>
    <div class="ctrl-tile ctrl-warn"><b id="ctrlTileHiddenCats"><?= $hiddenCats ?></b><span>Hidden categories</span></div>
    <div class="ctrl-tile ctrl-warn"><b id="ctrlTileHiddenItems"><?= $totalHiddenItems ?></b><span>Products hidden</span></div>
</div>

<div class="ctrl-groups" id="ctrlGroups">
<?php foreach ($TYPE_LABELS as $type => $label): ?>
    <?php $flat = lyaideu_categories_flat((string)$type); ?>
    <section class="ctrl-group">
        <div class="ctrl-group-head">
            <i class="fa-solid <?= $ce($TYPE_ICONS[$type]) ?>"></i>
            <h3><?= $ce($label) ?></h3>
            <small><?= count($flat) ?> categor<?= count($flat) === 1 ? 'y' : 'ies' ?> · <?= $hiddenItems[$type] ?> products hidden</small>
        </div>
        <div class="ctrl-list" data-type="<?= $ce($type) ?>">
        <?php if (!$flat): ?>
            <p class="ctrl-row" style="justify-content:center;color:var(--muted);font-size:.8rem;">No categories here yet.</p>
        <?php endif; ?>
        <?php foreach ($flat as $c):
            $id = (int)$c['id'];
            $depth = (int)$c['depth'];
            $on = !empty($c['is_active']);
            $eff = !empty($c['_eff']);
            $path = lyaideu_category_path($id);
            $pathStr = count($path) > 1 ? implode(' › ', array_map(fn($p) => $p['name'], array_slice($path, 0, -1))) : '';
            if ($eff) { $pillCls = 'ctrl-pill-live'; $pillTxt = 'Live'; }
            elseif ($on) { $pillCls = 'ctrl-pill-parent'; $pillTxt = 'Hidden by parent'; }
            else { $pillCls = 'ctrl-pill-off'; $pillTxt = 'Hidden'; }
        ?>
            <div class="ctrl-row" data-cat-row="<?= $id ?>">
                <div class="ctrl-main" style="padding-left:<?= min($depth, 5) * 18 ?>px;">
                    <span class="ctrl-name"><?= $ce($c['name']) ?></span>
                    <?php if ($pathStr !== ''): ?><span class="ctrl-path"><?= $ce($pathStr) ?></span><?php endif; ?>
                </div>
                <div class="ctrl-meta">
                    <span class="ctrl-count"><?= (int)($countMap[$id] ?? 0) ?> item<?= ((int)($countMap[$id] ?? 0)) === 1 ? '' : 's' ?></span>
                    <span class="ctrl-pill <?= $pillCls ?>" data-pill><?= $ce($pillTxt) ?></span>
                </div>
                <button type="button" class="ctrl-toggle<?= $on ? ' on' : '' ?>" data-id="<?= $id ?>" data-active="<?= $on ? '1' : '0' ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>" aria-label="Turn <?= $on ? 'off' : 'on' ?> <?= $ce($c['name']) ?>"><span class="ctrl-knob"></span></button>
            </div>
        <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
</div>

<script>
(function () {
  var CSRF = '<?= $ce(admin_csrf_token()) ?>';
  var ENDPOINT = 'api/admin-control.php';
  var busy = new Set();

  function banner(msg, ok) {
    var old = document.querySelector('.ctrl-flash');
    if (old) old.remove();
    var el = document.createElement('div');
    el.className = 'flash-banner ' + (ok ? 'flash-success' : 'flash-error') + ' admin-flash ctrl-flash';
    el.innerHTML = '<i class="fa-solid ' + (ok ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i> ' + msg;
    var head = document.querySelector('.admin-page-head');
    if (head && head.parentNode) head.parentNode.insertBefore(el, head.nextSibling);
    if (ok) setTimeout(function () { el.remove(); }, 2500);
  }

  function applyState(state) {
    if (!state || !state.cats) return;
    var live = 0, hiddenCats = 0, total = 0;
    Object.keys(state.cats).forEach(function (key) {
      var id = String(key), info = state.cats[key];
      var row = document.querySelector('[data-cat-row="' + id + '"]');
      if (!row) return;
      total++;
      if (info.effective) live++; else hiddenCats++;
      var pill = row.querySelector('[data-pill]');
      var btn = row.querySelector('.ctrl-toggle');
      if (pill) {
        if (info.effective) { pill.className = 'ctrl-pill ctrl-pill-live'; pill.textContent = 'Live'; }
        else if (info.active) { pill.className = 'ctrl-pill ctrl-pill-parent'; pill.textContent = 'Hidden by parent'; }
        else { pill.className = 'ctrl-pill ctrl-pill-off'; pill.textContent = 'Hidden'; }
      }
      if (btn && String(btn.dataset.id) === id) {
        btn.dataset.active = info.active ? '1' : '0';
        btn.classList.toggle('on', !!info.active);
        btn.setAttribute('aria-pressed', info.active ? 'true' : 'false');
      }
    });
    var hidden = state.hidden || {};
    var hiddenTotal = (hidden.menu || 0) + (hidden.mart || 0) + (hidden.other || 0) + (hidden.beverage || 0);
    var t;
    if ((t = document.getElementById('ctrlTileCats'))) t.textContent = total;
    if ((t = document.getElementById('ctrlTileLive'))) t.textContent = live;
    if ((t = document.getElementById('ctrlTileHiddenCats'))) t.textContent = hiddenCats;
    if ((t = document.getElementById('ctrlTileHiddenItems'))) t.textContent = hiddenTotal;
    document.querySelectorAll('.ctrl-group-head small').forEach(function (sm) {
      var list = sm.closest('.ctrl-group') && sm.closest('.ctrl-group').querySelector('.ctrl-list');
      if (!list) return;
      var type = list.dataset.type;
      sm.textContent = sm.textContent.replace(/·\s*\d+ products? hidden$/, '· ' + (hidden[type] || 0) + ' products hidden');
    });
  }

  document.getElementById('ctrlGroups').addEventListener('click', function (e) {
    var btn = e.target.closest('.ctrl-toggle');
    if (!btn || busy.has(btn)) return;
    var id = parseInt(btn.dataset.id, 10);
    var next = btn.dataset.active === '1' ? 0 : 1;
    busy.add(btn);
    btn.disabled = true;
    fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ id: id, active: next })
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) throw new Error(d.error || 'Could not save the toggle.');
      applyState(d.state);
      banner('Category turned ' + (next ? 'ON' : 'OFF') + '. Live across the site within ~5 seconds.', true);
    }).catch(function (err) {
      banner(err.message || 'Network error — try again.', false);
    }).finally(function () {
      busy.delete(btn);
      btn.disabled = false;
    });
  });
})();
</script>
<?php
admin_page_end();
