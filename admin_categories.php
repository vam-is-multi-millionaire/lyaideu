<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

lyaideu_ensure_categories_table();

$allCats = lyaideu_categories();

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

function descendant_ids_of(array $cats, int $id): array {
    $skip = [];
    $frontier = [$id];
    while ($frontier) {
        $cur = array_shift($frontier);
        $skip[] = $cur;
        foreach ($cats as $c) {
            if ((int)$c['parent_id'] === $cur) {
                $frontier[] = (int)$c['id'];
            }
        }
    }
    return $skip;
}

function subtree_item_count(array $cats, int $id, array $counts): int {
    $total = (int)($counts[$id] ?? 0);
    foreach ($cats as $c) {
        if ((int)$c['parent_id'] === $id) {
            $total += subtree_item_count($cats, (int)$c['id'], $counts);
        }
    }
    return $total;
}

$dishCounts = [];
foreach ($pdo->query('SELECT category_id, COUNT(*) AS c FROM dishes GROUP BY category_id') as $r) {
    $dishCounts[(int)$r['category_id']] = (int)$r['c'];
}
$martCounts = [];
foreach ($pdo->query('SELECT category_id, COUNT(*) AS c FROM mart_items GROUP BY category_id') as $r) {
    $martCounts[(int)$r['category_id']] = (int)$r['c'];
}

$menuCats = array_values(array_filter($allCats, fn($c) => $c['type'] === 'menu'));
$martCats = array_values(array_filter($allCats, fn($c) => $c['type'] === 'mart'));
$menuFlat = tree_flat_rows($menuCats);
$martFlat = tree_flat_rows($martCats);

$ICON_OPTIONS = [
    'fa-drumstick-bite', 'fa-pizza-slice', 'fa-bowl-rice', 'fa-bowl-food', 'fa-cookie',
    'fa-mug-saucer', 'fa-mug-hot', 'fa-glass-water', 'fa-burger', 'fa-bacon', 'fa-fire',
    'fa-pepper-hot', 'fa-carrot', 'fa-apple-whole', 'fa-cow', 'fa-cheese', 'fa-mortar-pestle',
    'fa-leaf', 'fa-chocolate-bar', 'fa-basket-shopping', 'fa-utensils', 'fa-tags',
];

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function category_select_options(array $flat): string {
    global $ce;
    $html = '';
    foreach ($flat as $c) {
        $indent = str_repeat('&nbsp;&nbsp;', $c['depth']);
        $arrow = $c['depth'] > 0 ? '└ ' : '';
        $html .= '<option value="' . (int)$c['id'] . '">' . $indent . $arrow . $ce($c['name']) . '</option>';
    }
    return $html;
}

function render_category_cards(array $flat, string $type, array $counts, array $allCats): string {
    global $ce, $ICON_OPTIONS;
    $out = '';
    foreach ($flat as $i => $c) {
        $skip = descendant_ids_of($allCats, (int)$c['id']);
        $parentOptions = '';
        foreach ($flat as $pc) {
            if (in_array((int)$pc['id'], $skip, true)) {
                continue;
            }
            $sel = (int)$pc['id'] === (int)$c['parent_id'] ? ' selected' : '';
            $indent = str_repeat('&nbsp;&nbsp;', $pc['depth']);
            $arrow = $pc['depth'] > 0 ? '└ ' : '';
            $parentOptions .= '<option value="' . (int)$pc['id'] . '"' . $sel . '>' . $indent . $arrow . $ce($pc['name']) . '</option>';
        }
        $iconOptions = '';
        foreach ($ICON_OPTIONS as $ic) {
            $iconOptions .= '<option value="' . $ic . '"' . ($c['icon'] === $ic ? ' selected' : '') . '>' . $ic . '</option>';
        }
        $itemCount = subtree_item_count($allCats, (int)$c['id'], $counts);
        $indentCls = 'admin-cat-depth-' . min($c['depth'], 5);
        $out .= '<div class="admin-card ' . $indentCls . '">';
        $out .= '<h3>' . $ce($c['name']);
        $out .= '<span class="admin-cat-badges"><span class="admin-cat-type cat-type-' . $type . '">' . ($type === 'menu' ? 'Menu' : 'Mart') . '</span><span class="admin-count-badge">' . (int)$itemCount . ' items</span></span></h3>';
        $out .= '<input type="hidden" name="categories[' . $i . '][id]" value="' . (int)$c['id'] . '">';
        $out .= '<input type="hidden" name="categories[' . $i . '][type]" value="' . $type . '">';
        $out .= '<label>Category Name</label>';
        $out .= '<input type="text" name="categories[' . $i . '][name]" value="' . $ce($c['name']) . '" required>';
        $out .= '<div class="admin-field-row">';
        $out .= '<div><label>Slug (URL)</label><input type="text" name="categories[' . $i . '][slug]" value="' . $ce($c['slug']) . '" placeholder="auto from name"></div>';
        $out .= '<div><label>Sort Order</label><input type="number" min="0" name="categories[' . $i . '][sort_order]" value="' . (int)$c['sort_order'] . '"></div>';
        $out .= '</div>';
        $out .= '<label>Parent Category</label>';
        $out .= '<select name="categories[' . $i . '][parent_id]">' . $parentOptions . '</select>';
        $out .= '<label>Icon</label>';
        $out .= '<select name="categories[' . $i . '][icon]">' . $iconOptions . '</select>';
        $out .= '<label class="delete-check"><input type="checkbox" name="categories[' . $i . '][delete]" value="1"> <i class="fa-solid fa-trash-can"></i> Delete this category (items become uncategorized, sub-categories move up)</label>';
        $out .= '</div>';
    }
    return $out;
}

admin_page_start('Categories', 'categories', 'Categories');
?>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Organise every product into a category tree. Sub-categories nest inside parent categories — manage them all here.</p>
        <span class="admin-count-badge"><?= count($allCats) ?> categories</span>
    </div>
</section>

<form action="admin_save" method="POST" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="section" value="categories">

    <section class="admin-section">
        <h2><i class="fa-solid fa-plus"></i> Add New Category</h2>
        <div class="admin-grid">
            <div class="admin-card admin-add-card">
                <h3><i class="fa-solid fa-tags"></i> New Category</h3>
                <div class="admin-field-row">
                    <div><label>Type</label>
                        <select name="new_category[type]" id="newCatType">
                            <option value="menu">Menu (dishes)</option>
                            <option value="mart">Mart (groceries)</option>
                        </select>
                    </div>
                    <div><label>Name</label><input type="text" name="new_category[name]" placeholder="e.g. Steamed Momos"></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Slug (URL)</label><input type="text" name="new_category[slug]" placeholder="auto from name"></div>
                    <div><label>Sort Order</label><input type="number" min="0" name="new_category[sort_order]" placeholder="1"></div>
                </div>
                <label>Parent Category <span style="text-transform:none;font-weight:700;">(optional — makes this a sub-category)</span></label>
                <select name="new_category[parent_id]" id="newCatParent">
                    <option value="0">— No parent (top level) —</option>
                    <optgroup label="Menu Categories"><?= category_select_options($menuFlat) ?></optgroup>
                    <optgroup label="Mart Categories"><?= category_select_options($martFlat) ?></optgroup>
                </select>
                <label>Icon</label>
                <select name="new_category[icon]">
                    <?php foreach ($ICON_OPTIONS as $ic): ?>
                    <option value="<?= $ic ?>"><?= $ic ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </section>

    <section class="admin-section">
        <div class="admin-section-top">
            <h2 style="margin:0"><i class="fa-solid fa-utensils"></i> Menu Categories</h2>
            <span class="admin-count-badge"><?= count($menuCats) ?> categories</span>
        </div>
        <div class="admin-grid">
            <?= render_category_cards($menuFlat, 'menu', $dishCounts, $menuCats) ?>
        </div>
    </section>

    <section class="admin-section">
        <div class="admin-section-top">
            <h2 style="margin:0"><i class="fa-solid fa-basket-shopping"></i> Mart Categories</h2>
            <span class="admin-count-badge"><?= count($martCats) ?> categories</span>
        </div>
        <div class="admin-grid">
            <?= render_category_cards($martFlat, 'mart', $martCounts, $martCats) ?>
        </div>
    </section>

    <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Category Changes</button>
</form>
<script>
(function(){
  var type=document.getElementById('newCatType'),parent=document.getElementById('newCatParent');
  if(!type||!parent)return;
  function sync(){
    var v=type.value;
    Array.prototype.forEach.call(parent.querySelectorAll('optgroup'),function(g){
      g.style.display=(g.getAttribute('label').indexOf(v==='menu'?'Menu':'Mart')>-1)?'':'none';
    });
    var sel=parent.selectedOptions[0];
    if(sel&&sel.parentNode.tagName==='OPTGROUP'&&sel.parentNode.style.display==='none'){parent.value='0';}
  }
  type.addEventListener('change',sync);
  sync();
})();
</script>
<?php
admin_page_end();
