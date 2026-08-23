<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
admin_require_page('sections');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

lyaideu_ensure_sections_tables();
lyaideu_ensure_categories_table();

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function tree_flat_rows(array $cats): array {
    $byParent = [];
    foreach ($cats as $c) {
        $byParent[(int)$c['parent_id']][] = $c;
    }
    $out = [];
    $walk = function (int $parentId, int $depth) use (&$walk, &$out, $byParent): void {
        foreach (($byParent[$parentId] ?? []) as $c) {
            $c['depth'] = $depth;
            $out[] = $c;
            $walk((int)$c['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $out;
}

$sections = lyaideu_custom_sections(false);
$allCats = lyaideu_categories();

$ICON_OPTIONS = [
    'fa-layer-group', 'fa-tags', 'fa-star', 'fa-heart', 'fa-fire', 'fa-crown', 'fa-bolt',
    'fa-utensils', 'fa-pizza-slice', 'fa-bowl-rice', 'fa-mug-saucer', 'fa-glass-water',
    'fa-basket-shopping', 'fa-gift', 'fa-bouquet', 'fa-candle-holder', 'fa-jar', 'fa-cake-candles',
    'fa-bag-shopping', 'fa-shirt', 'fa-mobile-screen', 'fa-headphones', 'fa-gamepad', 'fa-book',
    'fa-pen', 'fa-screwdriver-wrench', 'fa-seedling', 'fa-paw', 'fa-spa', 'fa-kitchen-set',
];

$catCountByType = [];
foreach ($allCats as $c) {
    $catCountByType[(string)$c['type']] = ($catCountByType[(string)$c['type']] ?? 0) + 1;
}

$linkCountByCat = [];
foreach ($pdo->query('SELECT category_id, COUNT(*) AS c FROM section_item_links GROUP BY category_id') as $r) {
    $linkCountByCat[(int)$r['category_id']] = (int)$r['c'];
}

$catsBySection = [];
foreach ($sections as $s) {
    $type = (string)$s['slug'];
    $catsBySection[$type] = tree_flat_rows(array_values(array_filter($allCats, fn($c) => (string)$c['type'] === $type)));
}

$pools = [
    'dish'     => ['label' => 'Menu Items',      'icon' => 'fa-utensils'],
    'mart'     => ['label' => 'Mart Items',      'icon' => 'fa-basket-shopping'],
    'beverage' => ['label' => 'Beverage Items',  'icon' => 'fa-glass-water'],
    'other'    => ['label' => 'Other Items',     'icon' => 'fa-gift'],
];
$tableOf = ['dish' => 'dishes', 'mart' => 'mart_items', 'beverage' => 'beverage_items', 'other' => 'other_items'];
foreach ($pools as $t => &$cfg) {
    $cfg['items'] = $pdo->query("SELECT id, name FROM `{$tableOf[$t]}` ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
unset($cfg);

$linkMap = [];
if ($sections) {
    $st = $pdo->query('SELECT item_type, item_id, category_id FROM section_item_links');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $linkMap[(int)$r['category_id']][] = [(string)$r['item_type'], (int)$r['item_id']];
    }
}

$itemNameMap = [];
foreach ($pools as $t => $cfg) {
    foreach ($cfg['items'] as $it) {
        $itemNameMap[$t . ':' . (int)$it['id']] = (string)$it['name'];
    }
}

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$totalAssignItems = 0;
foreach ($pools as $cfg) {
    $totalAssignItems += count($cfg['items']);
}

admin_page_start('Sections', 'sections', 'Sections');
?>
<style>
.assign-pool-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.9rem;margin-top:.9rem;align-items:start;}
.assign-pool{border:1px solid var(--orange-100);border-radius:12px;background:#fff;padding:.7rem .9rem .9rem;margin:0;max-height:420px;overflow:auto;}
.assign-pool legend{font-weight:900;font-size:.85rem;color:var(--orange-800);padding:0 .35rem;display:flex;align-items:center;gap:.4rem;}
.assign-row{display:flex;align-items:center;gap:.55rem;padding:.28rem .15rem;font-size:.86rem;cursor:pointer;border-radius:8px;}
.assign-row:hover{background:var(--orange-50);}
.assign-row input{width:auto;}
.assign-name{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:700;color:#3a2415;}
@media(max-width:640px){.assign-pool-grid{grid-template-columns:1fr;}.assign-pool{max-height:none;}}
</style>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Custom sections appear on the <strong>Categories page</strong> below the built-in Menu, Mart, Beverages &amp; Others blocks. Each section holds its own categories, and you can place <strong>any existing product</strong> from Menu / Mart / Beverages / Others into them — the product stays listed in its original section too.</p>
        <span class="admin-count-badge"><?= count($sections) ?> custom section<?= count($sections) === 1 ? '' : 's' ?></span>
    </div>
</section>

<div class="wp-cat-layout">
    <div class="wp-cat-main">
        <section class="admin-section">
            <div class="admin-section-top">
                <h2 style="margin:0"><i class="fa-solid fa-layer-group"></i> Your Sections</h2>
            </div>
            <?php if (!$sections): ?>
                <p class="wp-cat-none" style="padding:1rem;"><i class="fa-solid fa-circle-info"></i> No custom sections yet. Create your first one with the form on the right — e.g. "Party Packs", "Stationery", "Home Essentials".</p>
            <?php else: ?>
            <div class="wp-cat-list" id="sectionList">
                <div class="wp-cat-group">
                    <h3 class="wp-cat-group-title"><i class="fa-solid fa-layer-group"></i> Custom Sections <span class="wp-cat-group-count"><?= count($sections) ?></span></h3>
                    <?php
                    $secIds = array_map(fn($s) => (int)$s['id'], $sections);
                    foreach ($sections as $si => $s):
                        $sid = (int)$s['id'];
                        $slug = (string)$s['slug'];
                        $catTotal = (int)($catCountByType[$slug] ?? 0);
                        $linkTotal = 0;
                        foreach ($catsBySection[$slug] ?? [] as $cc) {
                            $linkTotal += (int)($linkCountByCat[(int)$cc['id']] ?? 0);
                        }
                        $isActive = (int)$s['is_active'] === 1;

                        $moveForm = function (array $order) use ($ce): string {
                            $f = '<form class="wp-cat-move-form" action="admin_save" method="POST">';
                            $f .= '<input type="hidden" name="csrf_token" value="' . $ce(admin_csrf_token()) . '">';
                            $f .= '<input type="hidden" name="section" value="section_reorder">';
                            foreach ($order as $oid) {
                                $f .= '<input type="hidden" name="order[]" value="' . (int)$oid . '">';
                            }
                            return $f;
                        };
                        $moveHtml = '';
                        if (count($secIds) > 1) {
                            $idx = array_search($sid, $secIds, true);
                            if ($idx === false) {
                                $idx = 0;
                            }
                            $upOrder = $secIds;
                            if ($idx > 0) {
                                [$upOrder[$idx - 1], $upOrder[$idx]] = [$upOrder[$idx], $upOrder[$idx - 1]];
                            }
                            $downOrder = $secIds;
                            if ($idx < count($secIds) - 1) {
                                [$downOrder[$idx], $downOrder[$idx + 1]] = [$downOrder[$idx + 1], $downOrder[$idx]];
                            }
                            $moveHtml .= '<span class="wp-cat-move">';
                            $moveHtml .= $moveForm($upOrder) . '<button type="submit" class="wp-cat-move-btn"' . ($idx === 0 ? ' disabled' : '') . ' title="Move up" aria-label="Move ' . $ce($s['name']) . ' up"><i class="fa-solid fa-arrow-up"></i></button></form>';
                            $moveHtml .= $moveForm($downOrder) . '<button type="submit" class="wp-cat-move-btn"' . ($idx === count($secIds) - 1 ? ' disabled' : '') . ' title="Move down" aria-label="Move ' . $ce($s['name']) . ' down"><i class="fa-solid fa-arrow-down"></i></button></form>';
                            $moveHtml .= '</span>';
                        }

                        $iconOptions = '';
                        foreach ($ICON_OPTIONS as $ic) {
                            $iconOptions .= '<option value="' . $ic . '"' . ((string)$s['icon'] === $ic ? ' selected' : '') . '>' . $ic . '</option>';
                        }
                        $confirm = 'Delete "' . $s['name'] . '"? Its ' . $catTotal . ' categor' . ($catTotal === 1 ? 'y' : 'ies') . ' and every product assignment inside it will be removed too. Products themselves are NOT deleted.';
                    ?>
                    <div class="wp-cat-row" data-search="<?= $ce(strtolower((string)$s['name'])) ?>">
                        <div class="wp-cat-item">
                            <span class="wp-cat-indent">
                                <span class="cat-icon-chip"><i class="fa-solid <?= $ce((string)$s['icon'] !== '' ? $s['icon'] : 'fa-layer-group') ?>"></i></span>
                                <span class="wp-cat-name-wrap">
                                    <span class="wp-cat-name"><?= $ce($s['name']) ?></span>
                                    <span class="wp-cat-subpath">/<?= $ce($slug) ?></span>
                                </span>
                            </span>
                            <span class="wp-cat-meta">
                                <span class="wp-cat-level-badge <?= $isActive ? 'is-top' : 'is-sub' ?>"><?= $isActive ? 'Visible' : 'Hidden' ?></span>
                                <span class="admin-count-badge"><?= $catTotal ?> categories</span>
                                <span class="admin-count-badge"><?= $linkTotal ?> products</span>
                            </span>
                            <span class="wp-cat-actions">
                                <?= $moveHtml ?>
                                <button type="button" class="wp-cat-act wp-cat-edit" data-target="qs-<?= $sid ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                                <form class="wp-cat-del-inline" action="admin_save" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                                    <input type="hidden" name="section" value="sections">
                                    <input type="hidden" name="sections[<?= $si ?>][id]" value="<?= $sid ?>">
                                    <input type="hidden" name="sections[<?= $si ?>][delete]" value="1">
                                    <button type="submit" class="wp-cat-act wp-cat-del-btn" data-confirm="<?= $ce($confirm) ?>"><i class="fa-solid fa-trash-can"></i> Delete</button>
                                </form>
                            </span>
                        </div>

                        <form class="wp-cat-quick-edit" id="qs-<?= $sid ?>" action="admin_save" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                            <input type="hidden" name="section" value="sections">
                            <input type="hidden" name="sections[<?= $si ?>][id]" value="<?= $sid ?>">
                            <div class="admin-field-row">
                                <div><label>Name</label><input type="text" name="sections[<?= $si ?>][name]" value="<?= $ce($s['name']) ?>" required maxlength="80"></div>
                                <div><label>URL slug</label><input type="text" name="sections[<?= $si ?>][slug]" value="<?= $ce($slug) ?>" placeholder="auto from name"></div>
                            </div>
                            <div class="admin-field-row">
                                <div><label>Description</label><input type="text" name="sections[<?= $si ?>][desc]" value="<?= $ce($s['desc']) ?>" maxlength="190" placeholder="Shown under the section title"></div>
                                <div><label>&nbsp;</label><label style="display:flex;align-items:center;gap:.5rem;text-transform:none;font-weight:800;color:#3a2415;"><input type="checkbox" name="sections[<?= $si ?>][is_active]" value="1" style="width:auto;"<?= $isActive ? ' checked' : '' ?>> Visible on website</label></div>
                            </div>
                            <label>Icon</label>
                            <div class="wp-cat-icon-wrap"><select name="sections[<?= $si ?>][icon]"><?= $iconOptions ?></select><span class="cat-icon-chip"><i class="fa-solid <?= $ce((string)$s['icon'] !== '' ? $s['icon'] : 'fa-layer-group') ?>"></i></span></div>
                            <div class="wp-cat-edit-actions">
                                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Update Section</button>
                                <button type="button" class="btn btn-outline wp-cat-cancel">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <section class="admin-section" id="assigner">
            <div class="admin-section-top">
                <h2 style="margin:0"><i class="fa-solid fa-boxes-stacked"></i> Put Products Into Sections</h2>
            </div>
            <?php if (!$sections): ?>
                <p class="wp-cat-none" style="padding:1rem;"><i class="fa-solid fa-circle-info"></i> Create a custom section first, then add categories to it on the <a href="admin_categories"><strong>Categories</strong></a> page. After that you can link any existing product here.</p>
            <?php else:
                $assignable = [];
                foreach ($sections as $s) {
                    foreach ($catsBySection[(string)$s['slug']] ?? [] as $cc) {
                        $assignable[] = ['id' => (int)$cc['id'], 'name' => (string)$cc['name'], 'sec' => (string)$s['name'], 'depth' => (int)$cc['depth']];
                    }
                }
            ?>
            <?php if (!$assignable): ?>
                <p class="wp-cat-none" style="padding:1rem;"><i class="fa-solid fa-circle-info"></i> Add at least one category to your sections first — use the form on the <a href="admin_categories"><strong>Categories</strong></a> page and pick your new section as the Type.</p>
            <?php else: ?>
            <form id="assignForm" action="admin_save" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                <input type="hidden" name="section" value="section_links">
                <input type="hidden" name="category_id" id="assignCatId" value="<?= (int)$assignable[0]['id'] ?>">
                <label style="display:block;font-size:.75rem;font-weight:800;color:var(--muted);margin:.4rem 0 .3rem;text-transform:uppercase;letter-spacing:.05em;">Category of your section</label>
                <select id="assignCat" style="width:100%;max-width:420px;padding:.6rem;border:1px solid var(--orange-200);border-radius:8px;font:inherit;font-size:.9rem;background:#fff;">
                    <?php foreach ($assignable as $ac): ?>
                    <option value="<?= (int)$ac['id'] ?>"<?= (int)$ac['id'] === (int)$assignable[0]['id'] ? ' selected' : '' ?>>
                        <?= str_repeat('&nbsp;&nbsp;', $ac['depth']) . ($ac['depth'] > 0 ? '└ ' : '') . $ce($ac['name']) . ' — ' . $ce($ac['sec']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="ctrl-note" style="margin-top:.7rem;"><i class="fa-solid fa-circle-info"></i> Tick the products that should appear inside this category. They stay visible in their original section as well. Switching category resets unsaved ticks — save before switching.</p>

                <div id="assignedWrap" style="margin:.8rem 0 .2rem;display:flex;flex-wrap:wrap;gap:.4rem;"></div>

                <input type="search" id="assignSearch" placeholder="Search all products…" aria-label="Search products" style="margin-top:.8rem;width:100%;max-width:340px;padding:.55rem .95rem;border:1px solid var(--orange-200);border-radius:999px;font:inherit;font-size:.9rem;background:#fff;">

                <div class="assign-pool-grid">
                    <?php foreach ($pools as $t => $cfg): ?>
                    <fieldset class="assign-pool">
                        <legend><i class="fa-solid <?= $cfg['icon'] ?>"></i> <?= $ce($cfg['label']) ?> <span class="wp-cat-group-count"><?= count($cfg['items']) ?></span></legend>
                        <?php if (!$cfg['items']): ?>
                            <p class="wp-cat-none">Nothing here yet.</p>
                        <?php else: ?>
                            <?php foreach ($cfg['items'] as $it): ?>
                            <label class="assign-row" data-search="<?= $ce(strtolower((string)$it['name'])) ?>">
                                <input type="checkbox" class="assign-check" name="assign[]" data-t="<?= $ce($t) ?>" value="<?= $ce($t) ?>:<?= (int)$it['id'] ?>">
                                <span class="assign-name"><?= $ce($it['name']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </fieldset>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:1rem;"><i class="fa-solid fa-floppy-disk"></i> Save Assignments</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <aside class="wp-cat-side">
        <section class="admin-section">
            <h2><i class="fa-solid fa-plus"></i> Add New Section</h2>
            <form action="admin_save" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                <input type="hidden" name="section" value="sections">
                <label>Name</label>
                <input type="text" name="new_section[name]" placeholder="e.g. Party Packs" required maxlength="80">
                <label>URL slug</label>
                <input type="text" name="new_section[slug]" placeholder="auto from name">
                <label>Description</label>
                <input type="text" name="new_section[desc]" placeholder="Shown under the section title" maxlength="190">
                <label>Icon</label>
                <div class="wp-cat-icon-wrap">
                    <select name="new_section[icon]">
                        <?php foreach ($ICON_OPTIONS as $ic): ?>
                        <option value="<?= $ic ?>"<?= $ic === 'fa-layer-group' ? ' selected' : '' ?>><?= $ic ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="cat-icon-chip"><i class="fa-solid fa-layer-group"></i></span>
                </div>
                <button type="submit" class="btn btn-primary btn-block" style="margin-top:1rem;"><i class="fa-solid fa-plus"></i> Add Section</button>
            </form>
            <p style="font-size:.78rem;color:var(--muted);font-weight:700;margin-top:.8rem;">Next step: open <a href="admin_categories"><strong>Categories</strong></a>, pick this section as the <em>Type</em> and build its category tree.</p>
        </section>
    </aside>
</div>

<script>
window.LY_ASSIGN_LINKS = <?= json_encode($linkMap, $jsonFlags) ?>;
window.LY_ASSIGN_NAMES = <?= json_encode($itemNameMap, $jsonFlags) ?>;
</script>
<script src="js/admin-sections.js?v=2"></script>
<?php
admin_page_end();
