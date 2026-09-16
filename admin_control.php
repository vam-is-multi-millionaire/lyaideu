<?php
require_once __DIR__ . '/admin_inc.php';
admin_require_login();
admin_require_page('control');
require_once __DIR__ . '/db.php';
lyaideu_ensure_categories_table();

$allCats = lyaideu_categories();

$TYPE_LABELS = ['menu' => 'Food Menu', 'mart' => 'Mart', 'other' => 'Others', 'beverage' => 'Beverages'];
$TYPE_ICONS  = ['menu' => 'fa-utensils', 'mart' => 'fa-basket-shopping', 'other' => 'fa-gift', 'beverage' => 'fa-glass-water'];
$TABLES      = ['menu' => 'dishes', 'mart' => 'mart_items', 'other' => 'other_items', 'beverage' => 'beverage_items'];

$pdo = lyaideu_load_pdo();
lyaideu_ensure_delivery_tables();

/* Vendors / shop status (ON-OFF + opening hours + hide-all-products). */
$vendors = [];
try {
    $vendors = $pdo->query(
        'SELECT v.id, v.name, v.scope, v.is_active, v.is_open, v.products_hidden, v.open_time, v.close_time, v.discount_percent,
                h.name AS store_name
         FROM vendors v
         LEFT JOIN hotels h ON h.id = v.hotel_id
         ORDER BY v.id'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $vendors = [];
}
foreach ($vendors as &$vr) {
    $st = lyaideu_vendor_is_orderable((int)$vr['id']);
    $vr['_orderable'] = !empty($st['open']);
    $vr['_label'] = (string)($st['label'] ?? '');
}
unset($vr);
$vendorOpenCount = count(array_filter($vendors, fn($v) => !empty($v['_orderable'])));
$vendorClosedCount = count($vendors) - $vendorOpenCount;
$vendorHiddenCount = count(array_filter($vendors, fn($v) => !empty($v['products_hidden'])));

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

/* Group switch: a section counts as ON while any of its categories is on. */
$groupOn = [];
foreach (array_keys($TYPE_LABELS) as $t) {
    $groupOn[$t] = count(array_filter($allCats, fn($c) => $c['type'] === $t && !empty($c['is_active']))) > 0;
}

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/* Ordering rules: global KYC gate (ON = only approved-KYC users can order). */
$kycOn = lyaideu_kyc_required();

/* Maintenance gate: ON = nobody can add to cart or order (browsing stays). */
$maintOn = lyaideu_maintenance_on();

/* Unavailable gate: same effect as maintenance, own button + button text. */
$unavailOn = lyaideu_unavailable_on();

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
.ctrl-group-head{display:flex;align-items:center;gap:.6rem;padding:.85rem 1rem;background:linear-gradient(90deg,var(--orange-50),#fff);border-bottom:1px solid var(--orange-100);flex-wrap:wrap;}
.ctrl-group-head i{color:var(--orange-600);flex:none;}
.ctrl-group-head h3{font-size:1rem;font-weight:900;color:var(--orange-900);min-width:0;flex:0 1 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ctrl-group-head small{margin-left:auto;font-size:.72rem;font-weight:800;color:var(--muted);white-space:nowrap;}
.ctrl-group-head .ctrl-toggle{margin-left:.4rem;}
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
.ctrl-vendor-row{flex-wrap:wrap;}
.ctrl-vendor-switches{display:flex;align-items:center;gap:.7rem;flex:none;flex-wrap:wrap;}
.ctrl-switch-wrap{display:flex;align-items:center;gap:.35rem;}
.ctrl-switch-label{font-size:.64rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);white-space:nowrap;}
.ctrl-hours{display:flex;align-items:center;gap:.3rem;flex:none;flex-wrap:wrap;}
.ctrl-hours input[type="time"]{border:1px solid var(--orange-200);border-radius:8px;padding:.3rem .45rem;font-size:.78rem;font-weight:800;color:var(--orange-900);background:#fff;font-family:inherit;}
.ctrl-hours input[type="time"]:focus{outline:2px solid var(--orange-400);border-color:var(--orange-500);}
.ctrl-hours input[type="number"]{border:1px solid var(--orange-200);border-radius:8px;padding:.3rem .45rem;font-size:.78rem;font-weight:800;color:var(--orange-900);background:#fff;font-family:inherit;width:62px;}
.ctrl-hours input[type="number"]:focus{outline:2px solid var(--orange-400);border-color:var(--orange-500);}
.ctrl-hours-sep{font-size:.72rem;font-weight:800;color:var(--muted);}
.ctrl-hours-save{border:1px solid var(--orange-300);background:var(--orange-50);color:var(--orange-800);border-radius:8px;padding:.32rem .6rem;font-size:.72rem;font-weight:900;cursor:pointer;white-space:nowrap;}
.ctrl-hours-save:hover{background:var(--orange-100);}
.ctrl-hours-save:disabled{opacity:.55;cursor:wait;}
.ctrl-tabs{display:flex;gap:.5rem;overflow-x:auto;padding:.1rem .1rem .8rem;margin-bottom:.4rem;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
.ctrl-tabs::-webkit-scrollbar{display:none;}
.ctrl-tab{flex:0 0 auto;display:inline-flex;align-items:center;gap:.45rem;border:1px solid var(--orange-200);background:#fff;color:var(--orange-800);border-radius:999px;padding:.5rem .95rem;font-size:.8rem;font-weight:900;cursor:pointer;white-space:nowrap;font-family:inherit;transition:background .15s ease,border-color .15s ease,color .15s ease;}
.ctrl-tab i{color:var(--orange-600);}
.ctrl-tab:hover{border-color:var(--orange-500);}
.ctrl-tab.active{background:var(--orange-600);border-color:var(--orange-600);color:#fff;}
.ctrl-tab.active i{color:#fff;}
.ctrl-tab-count{font-size:.68rem;font-weight:900;background:var(--orange-100);color:var(--orange-800);border-radius:999px;padding:.1rem .45rem;}
.ctrl-tab.active .ctrl-tab-count{background:rgba(255,255,255,.25);color:#fff;}
.ctrl-panel{display:none;}
.ctrl-panel.active{display:block;animation:ctrlFade .18s ease;}
@keyframes ctrlFade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
.ctrl-search{display:flex;align-items:center;gap:.5rem;margin:.7rem .9rem .3rem;border:1px solid var(--orange-200);border-radius:10px;padding:.45rem .7rem;background:#fff;}
.ctrl-search:focus-within{border-color:var(--orange-500);outline:2px solid var(--orange-200);}
.ctrl-search .search-ico{color:var(--orange-500);flex:none;}
.ctrl-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:inherit;font-size:.85rem;font-weight:700;color:var(--orange-900);}
.ctrl-search input::placeholder{color:var(--muted);font-weight:700;}
.ctrl-search-clear{border:0;background:var(--orange-100);color:var(--orange-800);border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:none;font-size:.65rem;}
.ctrl-no-match{justify-content:center;color:var(--muted);font-size:.8rem;}
.ctrl-search-clear[hidden],[data-ctrl-empty][hidden]{display:none!important;}
@media (max-width:640px){
  .ctrl-path{display:none;}
  .ctrl-row{padding:.5rem .7rem;gap:.5rem;}
  .ctrl-group-head{padding:.65rem .7rem;gap:.45rem;}
  .ctrl-group-head h3{font-size:.9rem;}
  .ctrl-group-head small{font-size:.64rem;}
  .ctrl-vendor-row{align-items:flex-start;}
  .ctrl-vendor-switches{width:100%;justify-content:flex-start;}
  .ctrl-hours{width:100%;}
  .ctrl-tab{padding:.45rem .8rem;font-size:.75rem;}
}
</style>

<div class="ctrl-note"><i class="fa-solid fa-circle-info"></i> Turn a category OFF to hide it and everything inside it (subcategories + their products) from the whole website — menus, search, home page picks and store pages. Changes go live within ~5 seconds. Products without a category always stay visible.</div>

<div class="ctrl-tiles">
    <div class="ctrl-tile"><b id="ctrlTileCats"><?= $totalCats ?></b><span>Categories</span></div>
    <div class="ctrl-tile"><b id="ctrlTileLive"><?= $liveCats ?></b><span>Live categories</span></div>
    <div class="ctrl-tile ctrl-warn"><b id="ctrlTileHiddenCats"><?= $hiddenCats ?></b><span>Hidden categories</span></div>
    <div class="ctrl-tile ctrl-warn"><b id="ctrlTileHiddenItems"><?= $totalHiddenItems ?></b><span>Products hidden</span></div>
    <div class="ctrl-tile"><b id="ctrlTileVendorsOpen"><?= $vendorOpenCount ?></b><span>Vendors open</span></div>
    <div class="ctrl-tile ctrl-warn"><b id="ctrlTileVendorsClosed"><?= $vendorClosedCount ?></b><span>Vendors closed</span></div>
</div>

<?php
$typeTotalCounts = [];
foreach ($allCats as $cc) {
    $tt = (string)$cc['type'];
    $typeTotalCounts[$tt] = ($typeTotalCounts[$tt] ?? 0) + 1;
}
$ctrlTabs = array_merge(
    [
        ['key' => 'rules', 'label' => 'Rules', 'icon' => 'fa-shield-halved', 'count' => null],
        ['key' => 'vendors', 'label' => 'Vendors', 'icon' => 'fa-store', 'count' => count($vendors)],
    ],
    array_map(
        fn($t) => ['key' => $t, 'label' => $TYPE_LABELS[$t], 'icon' => $TYPE_ICONS[$t], 'count' => (int)($typeTotalCounts[$t] ?? 0)],
        array_keys($TYPE_LABELS)
    )
);
?>
<div class="ctrl-tabs" id="ctrlTabs" role="tablist" aria-label="Control Panel sections">
    <?php foreach ($ctrlTabs as $i => $tab): ?>
    <button type="button" class="ctrl-tab<?= $i === 0 ? ' active' : '' ?>" role="tab" data-tab="<?= $ce($tab['key']) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"><i class="fa-solid <?= $ce($tab['icon']) ?>"></i> <?= $ce($tab['label']) ?><?php if ($tab['count'] !== null): ?> <span class="ctrl-tab-count"><?= (int)$tab['count'] ?></span><?php endif; ?></button>
    <?php endforeach; ?>
</div>

<div class="ctrl-groups" id="ctrlGroups">
<section class="ctrl-group ctrl-panel active" data-panel="rules">
    <div class="ctrl-group-head">
        <i class="fa-solid fa-shield-halved"></i>
        <h3>Ordering Rules</h3>
        <small>Checkout requirements</small>
    </div>
    <div class="ctrl-list">
        <div class="ctrl-row" data-kyc-row>
            <div class="ctrl-main">
                <span class="ctrl-name"><i class="fa-solid fa-id-card" style="color:var(--orange-600);margin-right:.35rem;"></i> KYC verification required to order</span>
                <span class="ctrl-path">ON = only users with an approved KYC can place orders. OFF = everyone can order without verification.</span>
            </div>
            <div class="ctrl-meta">
                <span class="ctrl-pill <?= $kycOn ? 'ctrl-pill-live' : 'ctrl-pill-off' ?>" data-kyc-pill><?= $kycOn ? 'Required' : 'Optional' ?></span>
            </div>
            <button type="button" class="ctrl-toggle ctrl-kyc-toggle<?= $kycOn ? ' on' : '' ?>" id="ctrlKycToggle" data-active="<?= $kycOn ? '1' : '0' ?>" aria-pressed="<?= $kycOn ? 'true' : 'false' ?>" aria-label="Turn <?= $kycOn ? 'off' : 'on' ?> KYC verification" title="Turn KYC verification <?= $kycOn ? 'off' : 'on' ?>"><span class="ctrl-knob"></span></button>
        </div>
        <div class="ctrl-row" data-maint-row>
            <div class="ctrl-main">
                <span class="ctrl-name"><i class="fa-solid fa-screwdriver-wrench" style="color:var(--orange-600);margin-right:.35rem;"></i> LyaiDeu is Under Maintenance</span>
                <span class="ctrl-path">ON = nobody can add to cart or place orders anywhere on the website. OFF = normal ordering. Browsing always stays visible.</span>
            </div>
            <div class="ctrl-meta">
                <span class="ctrl-pill <?= $maintOn ? 'ctrl-pill-off' : 'ctrl-pill-live' ?>" data-maint-pill><?= $maintOn ? 'Maintenance' : 'Live' ?></span>
            </div>
            <button type="button" class="ctrl-toggle ctrl-maint-toggle<?= $maintOn ? ' on' : '' ?>" id="ctrlMaintToggle" data-active="<?= $maintOn ? '1' : '0' ?>" aria-pressed="<?= $maintOn ? 'true' : 'false' ?>" aria-label="Turn <?= $maintOn ? 'off' : 'on' ?> maintenance mode" title="Turn maintenance mode <?= $maintOn ? 'off' : 'on' ?>"><span class="ctrl-knob"></span></button>
        </div>
        <div class="ctrl-row" data-unavail-row>
            <div class="ctrl-main">
                <span class="ctrl-name"><i class="fa-solid fa-ban" style="color:var(--orange-600);margin-right:.35rem;"></i> LyaiDeu is Currently Unavailable</span>
                <span class="ctrl-path">ON = nobody can add to cart or place orders anywhere on the website. OFF = normal ordering. Browsing always stays visible.</span>
            </div>
            <div class="ctrl-meta">
                <span class="ctrl-pill <?= $unavailOn ? 'ctrl-pill-off' : 'ctrl-pill-live' ?>" data-unavail-pill><?= $unavailOn ? 'Unavailable' : 'Live' ?></span>
            </div>
            <button type="button" class="ctrl-toggle ctrl-unavail-toggle<?= $unavailOn ? ' on' : '' ?>" id="ctrlUnavailToggle" data-active="<?= $unavailOn ? '1' : '0' ?>" aria-pressed="<?= $unavailOn ? 'true' : 'false' ?>" aria-label="Turn <?= $unavailOn ? 'off' : 'on' ?> unavailable mode" title="Turn unavailable mode <?= $unavailOn ? 'off' : 'on' ?>"><span class="ctrl-knob"></span></button>
        </div>
    </div>
</section>
<section class="ctrl-group ctrl-panel" data-panel="vendors">
    <div class="ctrl-group-head">
        <i class="fa-solid fa-store"></i>
        <h3>Vendors / Shop Status</h3>
        <small><?= count($vendors) ?> vendors · <span id="ctrlVendorHeadCount"><?= $vendorOpenCount ?> open</span></small>
    </div>
    <div class="ctrl-list" id="ctrlVendorList">
        <div class="ctrl-note" style="margin:.7rem .9rem;"><i class="fa-solid fa-circle-info"></i> Shop ON + inside opening hours = customers can add to cart. OFF or outside hours = products stay visible with a Closed badge and Add is blocked. “Hide products” removes all of that vendor’s products from the site. Times are Nepal time (e.g. 9:00 AM, blank = open all day). Default discount % applies to all of a vendor's products unless a product has its own discount above 0.</div>
        <?php if ($vendors): ?>
        <div class="ctrl-search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" placeholder="Search vendors or stores…" aria-label="Search vendors" data-ctrl-search><button type="button" class="ctrl-search-clear" data-ctrl-clear hidden aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button></div>
        <p class="ctrl-row ctrl-no-match" data-ctrl-empty hidden>No vendors match your search.</p>
        <?php endif; ?>
        <?php if (!$vendors): ?>
            <p class="ctrl-row" style="justify-content:center;color:var(--muted);font-size:.8rem;">No vendors yet.</p>
        <?php endif; ?>
        <?php foreach ($vendors as $v):
            $vid = (int)$v['id'];
            $openSwitch = !empty($v['is_open']);
            $prodHidden = !empty($v['products_hidden']);
            $orderable = !empty($v['_orderable']);
            $scopeLabel = ['hotel' => 'Hotel', 'mart' => 'Mart', 'other' => 'Other', 'beverage' => 'Beverages'][$v['scope'] ?? 'hotel'] ?? (string)($v['scope'] ?? '');
            $storeName = trim((string)($v['store_name'] ?? '')) !== '' ? (string)$v['store_name'] : (string)$v['name'];
            $openT = $v['open_time'] ? substr((string)$v['open_time'], 0, 5) : '';
            $closeT = $v['close_time'] ? substr((string)$v['close_time'], 0, 5) : '';
            $open12 = $openT !== '' ? lyaideu_vendor_time_12h($openT) : '';
            $close12 = $closeT !== '' ? lyaideu_vendor_time_12h($closeT) : '';
            $disc = max(0, min(90, (int)($v['discount_percent'] ?? 0)));
            if ($prodHidden) { $vPillCls = 'ctrl-pill-off'; $vPillTxt = 'Products hidden'; }
            elseif ($orderable) { $vPillCls = 'ctrl-pill-live'; $vPillTxt = 'Open'; }
            else { $vPillCls = 'ctrl-pill-off'; $vPillTxt = trim((string)($v['_label'] ?? '')) !== '' ? (string)$v['_label'] : 'Closed'; }
        ?>
            <div class="ctrl-row ctrl-vendor-row" data-vendor-row="<?= $vid ?>">
                <div class="ctrl-main">
                    <span class="ctrl-name"><?= $ce($storeName) ?> <span class="ctrl-count"><?= $ce($scopeLabel) ?></span></span>
                    <span class="ctrl-path"><?= $ce($v['name']) ?><?= empty($v['is_active']) ? ' · login disabled' : '' ?><?= ($open12 !== '' || $close12 !== '') ? ' · ' . $ce($open12 !== '' ? $open12 : '—') . '–' . $ce($close12 !== '' ? $close12 : '—') : ' · 24h' ?></span>
                </div>
                <div class="ctrl-meta">
                    <span class="ctrl-pill <?= $vPillCls ?>" data-vendor-pill><?= $ce($vPillTxt) ?></span>
                </div>
                <div class="ctrl-vendor-switches">
                    <span class="ctrl-switch-wrap"><span class="ctrl-switch-label">Shop</span><button type="button" class="ctrl-toggle ctrl-vendor-toggle<?= $openSwitch ? ' on' : '' ?>" data-vendor="<?= $vid ?>" data-field="is_open" data-active="<?= $openSwitch ? '1' : '0' ?>" aria-pressed="<?= $openSwitch ? 'true' : 'false' ?>" aria-label="Turn <?= $openSwitch ? 'off' : 'on' ?> <?= $ce($storeName) ?>" title="Turn shop <?= $openSwitch ? 'off' : 'on' ?>"><span class="ctrl-knob"></span></button></span>
                    <span class="ctrl-switch-wrap"><span class="ctrl-switch-label">Products</span><button type="button" class="ctrl-toggle ctrl-vendor-toggle<?= $prodHidden ? '' : ' on' ?>" data-vendor="<?= $vid ?>" data-field="products_hidden" data-active="<?= $prodHidden ? '1' : '0' ?>" data-invert="1" aria-pressed="<?= $prodHidden ? 'false' : 'true' ?>" aria-label="<?= $prodHidden ? 'Show' : 'Hide' ?> products of <?= $ce($storeName) ?>" title="<?= $prodHidden ? 'Show' : 'Hide' ?> all products"><span class="ctrl-knob"></span></button></span>
                    <span class="ctrl-hours"><input type="time" value="<?= $ce($openT) ?>" data-vendor-open aria-label="Opening time for <?= $ce($storeName) ?>"><span class="ctrl-hours-sep">–</span><input type="time" value="<?= $ce($closeT) ?>" data-vendor-close aria-label="Closing time for <?= $ce($storeName) ?>"><button type="button" class="ctrl-hours-save" data-vendor-hours="<?= $vid ?>">Save</button></span>
                    <span class="ctrl-hours"><span class="ctrl-switch-label">Discount %</span><input type="number" min="0" max="90" step="1" value="<?= $disc ?>" data-vendor-discount aria-label="Default discount percent for <?= $ce($storeName) ?>"><button type="button" class="ctrl-hours-save" data-vendor-discount-save="<?= $vid ?>">Save</button></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php foreach ($TYPE_LABELS as $type => $label): ?>
    <?php $flat = lyaideu_categories_flat((string)$type); ?>
    <section class="ctrl-group ctrl-panel" data-panel="<?= $ce($type) ?>">
        <div class="ctrl-group-head">
            <i class="fa-solid <?= $ce($TYPE_ICONS[$type]) ?>"></i>
            <h3><?= $ce($label) ?></h3>
            <small><?= count($flat) ?> categor<?= count($flat) === 1 ? 'y' : 'ies' ?> · <?= $hiddenItems[$type] ?> products hidden</small>
            <button type="button" class="ctrl-toggle ctrl-group-toggle<?= !empty($groupOn[$type]) ? ' on' : '' ?>" data-type="<?= $ce($type) ?>" data-active="<?= !empty($groupOn[$type]) ? '1' : '0' ?>"<?= !$flat ? ' disabled' : '' ?> aria-pressed="<?= !empty($groupOn[$type]) ? 'true' : 'false' ?>" aria-label="Turn <?= !empty($groupOn[$type]) ? 'off' : 'on' ?> all <?= $ce($label) ?> categories" title="Turn all <?= $ce($label) ?> categories <?= !empty($groupOn[$type]) ? 'off' : 'on' ?>"><span class="ctrl-knob"></span></button>
        </div>
        <div class="ctrl-list" data-type="<?= $ce($type) ?>">
        <?php if ($flat): ?>
        <div class="ctrl-search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" placeholder="Search <?= $ce($label) ?> categories…" aria-label="Search <?= $ce($label) ?> categories" data-ctrl-search><button type="button" class="ctrl-search-clear" data-ctrl-clear hidden aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button></div>
        <p class="ctrl-row ctrl-no-match" data-ctrl-empty hidden>No categories match your search.</p>
        <?php else: ?>
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
    /* Sync the section (group) switches from the fresh state. */
    var groups = state.groups || {};
    document.querySelectorAll('.ctrl-group-toggle').forEach(function (gb) {
      if (!(gb.dataset.type in groups)) return;
      var on = !!groups[gb.dataset.type];
      gb.dataset.active = on ? '1' : '0';
      gb.classList.toggle('on', on);
      gb.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    /* Sync the KYC ordering-rule switch. */
    if (typeof state.kyc === 'boolean') {
      var kt = document.getElementById('ctrlKycToggle');
      if (kt) {
        kt.dataset.active = state.kyc ? '1' : '0';
        kt.classList.toggle('on', state.kyc);
        kt.setAttribute('aria-pressed', state.kyc ? 'true' : 'false');
      }
      var kp = document.querySelector('[data-kyc-pill]');
      if (kp) {
        kp.className = 'ctrl-pill ' + (state.kyc ? 'ctrl-pill-live' : 'ctrl-pill-off');
        kp.textContent = state.kyc ? 'Required' : 'Optional';
      }
    }
    /* Sync the maintenance gate switch. */
    if (typeof state.maintenance === 'boolean') {
      var mt = document.getElementById('ctrlMaintToggle');
      if (mt) {
        mt.dataset.active = state.maintenance ? '1' : '0';
        mt.classList.toggle('on', state.maintenance);
        mt.setAttribute('aria-pressed', state.maintenance ? 'true' : 'false');
      }
      var mp = document.querySelector('[data-maint-pill]');
      if (mp) {
        mp.className = 'ctrl-pill ' + (state.maintenance ? 'ctrl-pill-off' : 'ctrl-pill-live');
        mp.textContent = state.maintenance ? 'Maintenance' : 'Live';
      }
    }
    /* Sync the unavailable gate switch. */
    if (typeof state.unavailable === 'boolean') {
      var ut = document.getElementById('ctrlUnavailToggle');
      if (ut) {
        ut.dataset.active = state.unavailable ? '1' : '0';
        ut.classList.toggle('on', state.unavailable);
        ut.setAttribute('aria-pressed', state.unavailable ? 'true' : 'false');
      }
      var up = document.querySelector('[data-unavail-pill]');
      if (up) {
        up.className = 'ctrl-pill ' + (state.unavailable ? 'ctrl-pill-off' : 'ctrl-pill-live');
        up.textContent = state.unavailable ? 'Unavailable' : 'Live';
      }
    }
    /* Sync vendor shop switches, hours and pills. */
    var vendors = state.vendors || {};
    var vOpen = 0, vClosed = 0;
    Object.keys(vendors).forEach(function (key) {
      var info = vendors[key];
      var row = document.querySelector('[data-vendor-row="' + key + '"]');
      if (!row) return;
      if (info.orderable) vOpen++; else vClosed++;
      var pill = row.querySelector('[data-vendor-pill]');
      if (pill) {
        if (info.products_hidden) { pill.className = 'ctrl-pill ctrl-pill-off'; pill.textContent = 'Products hidden'; }
        else if (info.orderable) { pill.className = 'ctrl-pill ctrl-pill-live'; pill.textContent = 'Open'; }
        else { pill.className = 'ctrl-pill ctrl-pill-off'; pill.textContent = info.label || 'Closed'; }
      }
      row.querySelectorAll('.ctrl-vendor-toggle').forEach(function (tb) {
        var field = tb.dataset.field;
        if (field === 'is_open') {
          tb.dataset.active = info.open_switch ? '1' : '0';
          tb.classList.toggle('on', !!info.open_switch);
          tb.setAttribute('aria-pressed', info.open_switch ? 'true' : 'false');
        } else if (field === 'products_hidden') {
          tb.dataset.active = info.products_hidden ? '1' : '0';
          tb.classList.toggle('on', !info.products_hidden);
          tb.setAttribute('aria-pressed', info.products_hidden ? 'false' : 'true');
        }
      });
      var oI = row.querySelector('[data-vendor-open]');
      var cI = row.querySelector('[data-vendor-close]');
      if (oI && document.activeElement !== oI) oI.value = info.open_time || '';
      if (cI && document.activeElement !== cI) cI.value = info.close_time || '';
      var dI = row.querySelector('[data-vendor-discount]');
      if (dI && document.activeElement !== dI && typeof info.discount !== 'undefined') dI.value = info.discount;
    });
    var to, tc, th;
    if ((to = document.getElementById('ctrlTileVendorsOpen'))) to.textContent = vOpen;
    if ((tc = document.getElementById('ctrlTileVendorsClosed'))) tc.textContent = vClosed;
    if ((th = document.getElementById('ctrlVendorHeadCount'))) th.textContent = vOpen + ' open';
  }

  function sendToggle(payload, btn, msg) {
    if (busy.has(btn)) return;
    busy.add(btn);
    btn.disabled = true;
    fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) throw new Error(d.error || 'Could not save the toggle.');
      applyState(d.state);
      banner(msg, true);
    }).catch(function (err) {
      banner(err.message || 'Network error — try again.', false);
    }).finally(function () {
      busy.delete(btn);
      btn.disabled = false;
    });
  }

  /* Tabs: one section visible at a time. Remembers the last tab. */
  var CTRL_TABS = ['rules', 'vendors', 'menu', 'mart', 'other', 'beverage'];
  function ctrlShowTab(key, save) {
    if (CTRL_TABS.indexOf(key) === -1) key = 'rules';
    document.querySelectorAll('#ctrlGroups [data-panel]').forEach(function (p) {
      p.classList.toggle('active', p.getAttribute('data-panel') === key);
    });
    document.querySelectorAll('#ctrlTabs .ctrl-tab').forEach(function (t) {
      var on = t.getAttribute('data-tab') === key;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    if (save !== false) {
      try { localStorage.setItem('lyaideu_ctrl_tab', key); } catch (err) {}
      /* Full URL (not a bare "#..."): the page has a <base href> tag, so a
         fragment-only replaceState would resolve to the site root and a
         refresh would land on index.php instead of this page. */
      try { history.replaceState(null, '', location.href.split('#')[0] + '#ctrl=' + key); } catch (err) {}
    }
  }
  (function ctrlInitTab() {
    var start = 'rules';
    try {
      var m = (location.hash || '').match(/#ctrl=([a-z]+)/);
      if (m && m[1] && CTRL_TABS.indexOf(m[1]) !== -1) start = m[1];
      else {
        var saved = localStorage.getItem('lyaideu_ctrl_tab');
        if (saved && CTRL_TABS.indexOf(saved) !== -1) start = saved;
      }
    } catch (err) {}
    ctrlShowTab(start, false);
  })();
  var tabsBar = document.getElementById('ctrlTabs');
  if (tabsBar) {
    tabsBar.addEventListener('click', function (e) {
      var t = e.target.closest('.ctrl-tab');
      if (t) ctrlShowTab(t.getAttribute('data-tab'));
    });
  }

  /* Per-tab search: filters vendor / category rows by name as you type. */
  document.querySelectorAll('[data-ctrl-search]').forEach(function (input) {
    var panel = input.closest('[data-panel]');
    if (!panel) return;
    var empty = panel.querySelector('[data-ctrl-empty]');
    var clearBtn = panel.querySelector('[data-ctrl-clear]');
    function applySearch() {
      var q = (input.value || '').trim().toLowerCase();
      var total = 0, visible = 0;
      panel.querySelectorAll('[data-cat-row],[data-vendor-row]').forEach(function (row) {
        total++;
        var hay = ((row.querySelector('.ctrl-name') || {}).textContent || '') + ' ' +
                  ((row.querySelector('.ctrl-path') || {}).textContent || '');
        var show = !q || hay.toLowerCase().indexOf(q) !== -1;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
      });
      if (empty) empty.hidden = !(total > 0 && visible === 0);
      if (clearBtn) clearBtn.hidden = !q;
    }
    input.addEventListener('input', applySearch);
    if (clearBtn) clearBtn.addEventListener('click', function () {
      input.value = '';
      applySearch();
      input.focus();
    });
  });

  document.getElementById('ctrlGroups').addEventListener('click', function (e) {
    var hoursBtn = e.target.closest('[data-vendor-hours]');
    if (hoursBtn) {
      var hRow = hoursBtn.closest('[data-vendor-row]');
      var hId = parseInt(hoursBtn.getAttribute('data-vendor-hours'), 10);
      var oVal = hRow ? (hRow.querySelector('[data-vendor-open]') || {}).value || '' : '';
      var cVal = hRow ? (hRow.querySelector('[data-vendor-close]') || {}).value || '' : '';
      sendToggle({ vendor_id: hId, vendor_field: 'hours', open_time: oVal, close_time: cVal }, hoursBtn,
        'Opening hours saved for vendor #' + hId + '. Live across the site within ~5 seconds.');
      return;
    }
    var discBtn = e.target.closest('[data-vendor-discount-save]');
    if (discBtn) {
      var dRow = discBtn.closest('[data-vendor-row]');
      var dId = parseInt(discBtn.getAttribute('data-vendor-discount-save'), 10);
      var dInp = dRow ? dRow.querySelector('[data-vendor-discount]') : null;
      var dVal = dInp ? parseInt(dInp.value, 10) : NaN;
      if (isNaN(dVal) || dVal < 0 || dVal > 90) {
        banner('Discount must be a number between 0 and 90.', false);
        return;
      }
      sendToggle({ vendor_id: dId, vendor_field: 'discount', discount: dVal }, discBtn,
        'Default discount saved for vendor #' + dId + ' (' + dVal + '%). Live across the site within ~5 seconds.');
      return;
    }
    var vendorBtn = e.target.closest('.ctrl-vendor-toggle');
    if (vendorBtn) {
      var vId = parseInt(vendorBtn.dataset.vendor, 10);
      var vField = vendorBtn.dataset.field;
      var vNext = vendorBtn.dataset.active === '1' ? 0 : 1;
      var vName = (vendorBtn.closest('[data-vendor-row]') || {}).querySelector
        ? vendorBtn.closest('[data-vendor-row]').querySelector('.ctrl-name').textContent.trim() : ('vendor #' + vId);
      var vMsg = vField === 'is_open'
        ? ('Shop ' + (vNext ? 'OPENED' : 'CLOSED') + ' for ' + vName + '. Live across the site within ~5 seconds.')
        : ((vNext ? 'All products HIDDEN for ' : 'All products VISIBLE for ') + vName + '. Live across the site within ~5 seconds.');
      sendToggle({ vendor_id: vId, vendor_field: vField, active: vNext }, vendorBtn, vMsg);
      return;
    }
    var kycBtn = e.target.closest('.ctrl-kyc-toggle');
    if (kycBtn) {
      var kNext = kycBtn.dataset.active === '1' ? 0 : 1;
      sendToggle({ setting: 'kyc', active: kNext }, kycBtn,
        'KYC verification turned ' + (kNext ? 'ON' : 'OFF') + '. ' + (kNext ? 'Only approved-KYC users can order now.' : 'Everyone can order without verification now.'));
      return;
    }
    var maintBtn = e.target.closest('.ctrl-maint-toggle');
    if (maintBtn) {
      var mNext = maintBtn.dataset.active === '1' ? 0 : 1;
      sendToggle({ setting: 'maintenance', active: mNext }, maintBtn,
        'Maintenance mode turned ' + (mNext ? 'ON' : 'OFF') + '. ' + (mNext ? 'Nobody can add to cart or order now.' : 'Ordering is back to normal now.'));
      return;
    }
    var unavailBtn = e.target.closest('.ctrl-unavail-toggle');
    if (unavailBtn) {
      var uNext = unavailBtn.dataset.active === '1' ? 0 : 1;
      sendToggle({ setting: 'unavailable', active: uNext }, unavailBtn,
        'Unavailable mode turned ' + (uNext ? 'ON' : 'OFF') + '. ' + (uNext ? 'Nobody can add to cart or order now.' : 'Ordering is back to normal now.'));
      return;
    }
    var groupBtn = e.target.closest('.ctrl-group-toggle');
    if (groupBtn) {
      var gType = groupBtn.dataset.type;
      var gNext = groupBtn.dataset.active === '1' ? 0 : 1;
      var gLabel = groupBtn.closest('.ctrl-group-head').querySelector('h3').textContent.trim();
      sendToggle({ type: gType, active: gNext }, groupBtn,
        'All ' + gLabel + ' categories turned ' + (gNext ? 'ON' : 'OFF') + '. Live across the site within ~5 seconds.');
      return;
    }
    var btn = e.target.closest('.ctrl-toggle');
    if (!btn || busy.has(btn)) return;
    var id = parseInt(btn.dataset.id, 10);
    var next = btn.dataset.active === '1' ? 0 : 1;
    sendToggle({ id: id, active: next }, btn,
      'Category turned ' + (next ? 'ON' : 'OFF') + '. Live across the site within ~5 seconds.');
  });
})();
</script>
<?php
admin_page_end();
