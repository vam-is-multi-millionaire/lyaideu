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

function category_select_options(array $flat, string $type = ''): string {
    global $ce;
    $html = '';
    foreach ($flat as $c) {
        $indent = str_repeat('&nbsp;&nbsp;', (int)$c['depth']);
        $arrow = (int)$c['depth'] > 0 ? '└ ' : '';
        $html .= '<option value="' . (int)$c['id'] . '" data-type="' . $ce($type) . '">' . $indent . $arrow . $ce($c['name']) . '</option>';
    }
    return $html;
}

$dishCounts = [];
foreach ($pdo->query('SELECT category_id, COUNT(*) AS c FROM dishes GROUP BY category_id') as $r) {
    $dishCounts[(int)$r['category_id']] = (int)$r['c'];
}
$martCounts = [];
foreach ($pdo->query('SELECT category_id, COUNT(*) AS c FROM mart_items GROUP BY category_id') as $r) {
    $martCounts[(int)$r['category_id']] = (int)$r['c'];
}
$otherCounts = [];
foreach ($pdo->query('SELECT category_id, COUNT(*) AS c FROM other_items GROUP BY category_id') as $r) {
    $otherCounts[(int)$r['category_id']] = (int)$r['c'];
}
$beverageCounts = [];
foreach ($pdo->query('SELECT category_id, COUNT(*) AS c FROM beverage_items GROUP BY category_id') as $r) {
    $beverageCounts[(int)$r['category_id']] = (int)$r['c'];
}

$menuCats = array_values(array_filter($allCats, fn($c) => $c['type'] === 'menu'));
$martCats = array_values(array_filter($allCats, fn($c) => $c['type'] === 'mart'));
$otherCats = array_values(array_filter($allCats, fn($c) => $c['type'] === 'other'));
$beverageCats = array_values(array_filter($allCats, fn($c) => $c['type'] === 'beverage'));
$menuFlat = tree_flat_rows($menuCats);
$martFlat = tree_flat_rows($martCats);
$otherFlat = tree_flat_rows($otherCats);
$beverageFlat = tree_flat_rows($beverageCats);

$ICON_OPTIONS = [
    'fa-drumstick-bite', 'fa-pizza-slice', 'fa-bowl-rice', 'fa-bowl-food', 'fa-cookie',
    'fa-mug-saucer', 'fa-mug-hot', 'fa-glass-water', 'fa-burger', 'fa-bacon', 'fa-fire',
    'fa-pepper-hot', 'fa-carrot', 'fa-apple-whole', 'fa-cow', 'fa-cheese', 'fa-mortar-pestle',
    'fa-leaf', 'fa-chocolate-bar', 'fa-basket-shopping', 'fa-utensils', 'fa-tags',
    'fa-bouquet', 'fa-candle-holder', 'fa-jar', 'fa-gift',
    'fa-champagne-glasses', 'fa-faucet-drip', 'fa-wine-bottle', 'fa-mug-saucer',
];

$TYPE_LABELS = ['menu' => 'Menu', 'mart' => 'Mart', 'other' => 'Other', 'beverage' => 'Beverages'];
$TYPE_ICONS  = ['menu' => 'fa-utensils', 'mart' => 'fa-basket-shopping', 'other' => 'fa-gift', 'beverage' => 'fa-glass-water'];

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function render_category_group(array $flat, string $type, array $counts, array $typeCats, array $ICON_OPTIONS): string {
    global $ce, $TYPE_LABELS, $TYPE_ICONS;
    /* id => row map, used to show each row's full place in the tree so a
       top-level category can never be mistaken for a sub (or vice versa). */
    $byId = [];
    foreach ($flat as $c) {
        $byId[(int)$c['id']] = $c;
    }
    /* Sibling ids (same type + same parent) in their current display order,
       used to build the up/down reordering forms. */
    $sibIds = [];
    foreach ($typeCats as $tc) {
        $sibIds[(int)$tc['parent_id']][] = (int)$tc['id'];
    }
    $pathNames = function (array $c) use ($byId): array {
        $names = [];
        $cur = $c;
        $guard = 0;
        while ($cur && $guard++ < 10) {
            array_unshift($names, (string)$cur['name']);
            $pid = (int)($cur['parent_id'] ?? 0);
            $cur = ($pid > 0 && isset($byId[$pid])) ? $byId[$pid] : null;
        }
        return $names;
    };
    $html = '<div class="wp-cat-group">';
    $html .= '<h3 class="wp-cat-group-title"><i class="fa-solid ' . $TYPE_ICONS[$type] . '"></i> ' . $TYPE_LABELS[$type] . ' <span class="wp-cat-group-count">' . count($flat) . '</span></h3>';
    if (!$flat) {
        $html .= '<p class="wp-cat-none">No ' . strtolower($TYPE_LABELS[$type]) . ' categories yet. Add one on the right.</p>';
    }
    foreach ($flat as $i => $c) {
        $id = (int)$c['id'];
        $depth = min((int)$c['depth'], 5);
        $isTop = (int)$c['depth'] === 0;
        $names = $pathNames($c);
        $parentName = count($names) > 1 ? $names[count($names) - 2] : '';
        $skip = descendant_ids_of($typeCats, $id);

        $parentOptions = '<option value="0">— No parent (top level) —</option>';
        foreach ($flat as $pc) {
            if (in_array((int)$pc['id'], $skip, true)) {
                continue;
            }
            $sel = (int)$pc['id'] === (int)$c['parent_id'] ? ' selected' : '';
            $indent = str_repeat('&nbsp;&nbsp;', (int)$pc['depth']);
            $arrow = (int)$pc['depth'] > 0 ? '└ ' : '';
            $parentOptions .= '<option value="' . (int)$pc['id'] . '"' . $sel . '>' . $indent . $arrow . $ce($pc['name']) . '</option>';
        }

        $iconOptions = '';
        foreach ($ICON_OPTIONS as $ic) {
            $iconOptions .= '<option value="' . $ic . '"' . ($c['icon'] === $ic ? ' selected' : '') . '>' . $ic . '</option>';
        }

        $itemCount = subtree_item_count($typeCats, $id, $counts);
        $confirm = 'Delete "' . $c['name'] . '"? Items in it will become uncategorized, and its sub-categories will move up one level.';

        /* Reordering: each arrow button posts the full sibling order with this
           category already swapped into its new place, so the server simply
           writes clean sequential sort values — no drift, ever. */
        $sibs = $sibIds[(int)$c['parent_id']] ?? [$id];
        $sidx = array_search($id, $sibs, true);
        if ($sidx === false) {
            $sidx = 0;
        }
        $sibCount = count($sibs);
        $moveForm = function (array $order) use ($ce): string {
            $f = '<form class="wp-cat-move-form" action="admin_save" method="POST">';
            $f .= '<input type="hidden" name="csrf_token" value="' . $ce(admin_csrf_token()) . '">';
            $f .= '<input type="hidden" name="section" value="category_reorder">';
            foreach ($order as $oid) {
                $f .= '<input type="hidden" name="order[]" value="' . (int)$oid . '">';
            }
            return $f;
        };
        $moveHtml = '';
        if ($sibCount > 1) {
            $upOrder = $sibs;
            if ($sidx > 0) {
                [$upOrder[$sidx - 1], $upOrder[$sidx]] = [$upOrder[$sidx], $upOrder[$sidx - 1]];
            }
            $downOrder = $sibs;
            if ($sidx < $sibCount - 1) {
                [$downOrder[$sidx], $downOrder[$sidx + 1]] = [$downOrder[$sidx + 1], $downOrder[$sidx]];
            }
            $moveHtml .= '<span class="wp-cat-move">';
            $moveHtml .= $moveForm($upOrder) . '<button type="submit" class="wp-cat-move-btn"' . ($sidx === 0 ? ' disabled' : '') . ' title="Move up" aria-label="Move ' . $ce($c['name']) . ' up"><i class="fa-solid fa-arrow-up"></i></button></form>';
            $moveHtml .= $moveForm($downOrder) . '<button type="submit" class="wp-cat-move-btn"' . ($sidx === $sibCount - 1 ? ' disabled' : '') . ' title="Move down" aria-label="Move ' . $ce($c['name']) . ' down"><i class="fa-solid fa-arrow-down"></i></button></form>';
            $moveHtml .= '</span>';
        }

        $html .= '<div class="wp-cat-row" data-search="' . $ce(strtolower((string)$c['name'])) . '">';
        $html .= '<div class="wp-cat-item">';
        $html .= '<span class="wp-cat-indent" style="' . ($depth > 0 ? 'padding-left:' . ($depth * 1.6) . 'rem;' : '') . '">';
        $listImg = (string)($c['image'] ?? '');
        if ($listImg !== '') {
            /* Show the attached category image; the FA icon is only the fallback. */
            $html .= '<span class="cat-icon-chip cat-icon-chip-img"><img src="' . $ce($listImg) . '" alt="' . $ce($c['name']) . '" loading="lazy"></span>';
        } else {
            $html .= '<span class="cat-icon-chip"><i class="fa-solid ' . $ce($c['icon'] !== '' ? $c['icon'] : 'fa-tags') . '"></i></span>';
        }
        $html .= '<span class="wp-cat-name-wrap"><span class="wp-cat-name">' . $ce($c['name']) . '</span>';
        if (!$isTop) {
            $html .= '<span class="wp-cat-subpath">' . $ce(implode(' › ', $names)) . '</span>';
        }
        $html .= '</span>';
        $html .= '</span>';
        $html .= '<span class="wp-cat-meta">';
        $html .= $isTop
            ? '<span class="wp-cat-level-badge is-top">Top level</span>'
            : '<span class="wp-cat-level-badge is-sub">Sub of ' . $ce($parentName) . '</span>';
        $html .= '<span class="admin-count-badge" title="Display position among its sibling categories">#' . ($sidx + 1) . '</span>';
        $html .= '<span class="admin-count-badge">' . (int)$itemCount . ' items</span>';
        $html .= '</span>';
        $html .= '<span class="wp-cat-actions">';
        $html .= $moveHtml;
        $html .= '<button type="button" class="wp-cat-act wp-cat-edit" data-target="qe-' . $type . '-' . $id . '"><i class="fa-solid fa-pen"></i> Edit</button>';
        $html .= '<form class="wp-cat-del-inline" action="admin_save" method="POST">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $ce(admin_csrf_token()) . '">';
        $html .= '<input type="hidden" name="section" value="categories">';
        $html .= '<input type="hidden" name="categories[' . $i . '][id]" value="' . $id . '">';
        $html .= '<input type="hidden" name="categories[' . $i . '][delete]" value="1">';
        $html .= '<button type="submit" class="wp-cat-act wp-cat-del-btn" data-confirm="' . $ce($confirm) . '"><i class="fa-solid fa-trash-can"></i> Delete</button>';
        $html .= '</form>';
        $html .= '</span>';
        $html .= '</div>';

        $html .= '<form class="wp-cat-quick-edit" id="qe-' . $type . '-' . $id . '" action="admin_save" method="POST" enctype="multipart/form-data">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $ce(admin_csrf_token()) . '">';
        $html .= '<input type="hidden" name="section" value="categories">';
        $html .= '<input type="hidden" name="categories[' . $i . '][id]" value="' . $id . '">';
        $html .= '<input type="hidden" name="categories[' . $i . '][type]" value="' . $type . '">';
        $html .= '<div class="admin-field-row">';
        $html .= '<div><label>Name</label><input type="text" name="categories[' . $i . '][name]" value="' . $ce($c['name']) . '" required></div>';
        $html .= '<div><label>Slug (URL)</label><input type="text" name="categories[' . $i . '][slug]" value="' . $ce($c['slug']) . '" placeholder="auto from name"></div>';
        $html .= '</div>';
        $html .= '<div class="admin-field-row">';
        $html .= '<div><label>Parent Category</label><select name="categories[' . $i . '][parent_id]">' . $parentOptions . '</select></div>';
        $html .= '<div><label>Sort Order</label><input type="number" min="0" name="categories[' . $i . '][sort_order]" value="' . (int)$c['sort_order'] . '"></div>';
        $html .= '</div>';
        $html .= '<label>Icon</label>';
        $html .= '<div class="wp-cat-icon-wrap"><select name="categories[' . $i . '][icon]">' . $iconOptions . '</select><span class="cat-icon-chip"><i class="fa-solid ' . $ce($c['icon'] !== '' ? $c['icon'] : 'fa-tags') . '"></i></span></div>';
        $catImg = (string)($c['image'] ?? '');
        $html .= '<input type="hidden" name="categories[' . $i . '][image]" value="' . $ce($catImg) . '">';
        $html .= '<label>Image <span style="text-transform:none;font-weight:700;">(optional — shown on mobile category cards)</span></label>';
        $html .= '<div class="pm-img-field wp-cat-img-field">';
        $html .= '<span class="pm-img-preview' . ($catImg !== '' ? '' : ' wp-cat-img-hidden') . '"><img src="' . $ce($catImg) . '" alt="" data-preview></span>';
        $html .= '<input type="file" name="categories[' . $i . '][image_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">';
        $html .= '</div>';
        if ($catImg !== '') {
            $html .= '<label class="pm-remove-img"><input type="checkbox" name="categories[' . $i . '][remove_img]" value="1"> <i class="fa-solid fa-trash-can"></i> Remove image</label>';
        }
        $html .= '<div class="wp-cat-edit-actions">';
        $html .= '<button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Update Category</button>';
        $html .= '<button type="button" class="btn btn-outline wp-cat-cancel">Cancel</button>';
        $html .= '</div>';
        $html .= '</form>';

        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

admin_page_start('Categories', 'categories', 'Categories');
?>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Organise every product into a category tree, just like WordPress. Use the <strong>arrow buttons</strong> to reorder categories (top to bottom on the website), <strong>Edit</strong> to change any category, <strong>Delete</strong> to remove one, and the form on the right to add new categories.</p>
        <span class="admin-count-badge"><?= count($allCats) ?> categories</span>
    </div>
</section>

<div class="wp-cat-layout">
    <div class="wp-cat-main">
        <section class="admin-section">
            <div class="admin-section-top">
                <h2 style="margin:0"><i class="fa-solid fa-sitemap"></i> All Categories</h2>
                <input type="search" class="wp-cat-search" id="catSearch" placeholder="Search categories…" aria-label="Search categories">
            </div>

            <div class="wp-cat-list" id="catList">
                <?= render_category_group($menuFlat, 'menu', $dishCounts, $menuCats, $ICON_OPTIONS) ?>
                <?= render_category_group($martFlat, 'mart', $martCounts, $martCats, $ICON_OPTIONS) ?>
                <?= render_category_group($beverageFlat, 'beverage', $beverageCounts, $beverageCats, $ICON_OPTIONS) ?>
                <?= render_category_group($otherFlat, 'other', $otherCounts, $otherCats, $ICON_OPTIONS) ?>
            </div>
            <p class="wp-cat-empty" id="catEmpty" style="display:none"><i class="fa-solid fa-magnifying-glass"></i> No categories match your search.</p>
        </section>
    </div>

    <aside class="wp-cat-side">
        <section class="admin-section">
            <h2><i class="fa-solid fa-plus"></i> Add New Category</h2>
            <form action="admin_save" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="section" value="categories">
                <label>Type</label>
                <select name="new_category[type]" id="newCatType">
                    <option value="menu">Menu (dishes)</option>
                    <option value="mart">Mart (groceries)</option>
                    <option value="beverage">Beverages (cold drinks, alcohol &amp; water)</option>
                    <option value="other">Other (gifts, decor &amp; achar)</option>
                </select>
                <label>Name</label>
                <input type="text" name="new_category[name]" placeholder="e.g. Steamed Momos" required>
                <div class="admin-field-row">
                    <div><label>Slug (URL)</label><input type="text" name="new_category[slug]" placeholder="auto from name"></div>
                    <div><label>Sort Order</label><input type="number" min="0" name="new_category[sort_order]" placeholder="1"></div>
                </div>
                <label>Parent Category <span style="text-transform:none;font-weight:700;">(optional)</span></label>
                <select name="new_category[parent_id]" id="newCatParent">
                    <option value="0">— No parent (top level) —</option>
                    <optgroup label="Menu Categories" data-type="menu"><?= category_select_options($menuFlat, 'menu') ?></optgroup>
                    <optgroup label="Mart Categories" data-type="mart"><?= category_select_options($martFlat, 'mart') ?></optgroup>
                    <optgroup label="Beverage Categories" data-type="beverage"><?= category_select_options($beverageFlat, 'beverage') ?></optgroup>
                    <optgroup label="Other Categories" data-type="other"><?= category_select_options($otherFlat, 'other') ?></optgroup>
                </select>
                <label>Icon</label>
                <div class="wp-cat-icon-wrap">
                    <select name="new_category[icon]">
                        <?php foreach ($ICON_OPTIONS as $ic): ?>
                        <option value="<?= $ic ?>"<?= $ic === 'fa-tags' ? ' selected' : '' ?>><?= $ic ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="cat-icon-chip"><i class="fa-solid fa-tags"></i></span>
                </div>
                <label>Image <span style="text-transform:none;font-weight:700;">(optional)</span></label>
                <div class="pm-img-field wp-cat-img-field">
                    <input type="file" name="new_category[image_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                </div>
                <button type="submit" class="btn btn-primary btn-block" style="margin-top:1rem;"><i class="fa-solid fa-plus"></i> Add New Category</button>
            </form>
        </section>
    </aside>
</div>

<script>
(function(){
  var type=document.getElementById('newCatType'),parent=document.getElementById('newCatParent');
  /* Only parents of the selected type stay selectable. Wrong-type options are
     disabled (not just hidden) so native mobile pickers can never pick them —
     a picked parent can therefore never be silently dropped on save. */
  function sync(){
    if(!type||!parent)return;
    var v=type.value;
    Array.prototype.forEach.call(parent.querySelectorAll('optgroup'),function(g){
      var match=g.getAttribute('data-type')===v;
      g.disabled=!match;
      g.style.display=match?'':'none';
    });
    Array.prototype.forEach.call(parent.options,function(o){
      if(o.value==='0'||o.value==='')return;
      var match=o.getAttribute('data-type')===v;
      o.disabled=!match;
      o.hidden=!match;
    });
    var sel=parent.selectedOptions[0];
    if(sel&&sel.disabled){parent.value='0';}
  }
  if(type)type.addEventListener('change',sync);
  sync();

  function initIconPreviews(){
    document.querySelectorAll('.wp-cat-icon-wrap').forEach(function(wrap){
      var sel=wrap.querySelector('select'),chip=wrap.querySelector('.cat-icon-chip i');
      if(sel&&chip)chip.className='fa-solid '+sel.value;
    });
  }
  document.addEventListener('change',function(e){
    var sel=e.target;
    if(!sel||!sel.name||sel.name.indexOf('[icon]')===-1)return;
    var wrap=sel.closest('.wp-cat-icon-wrap');
    if(!wrap)return;
    var chip=wrap.querySelector('.cat-icon-chip i');
    if(chip)chip.className='fa-solid '+sel.value;
  });
  initIconPreviews();

  var search=document.getElementById('catSearch');
  if(search){
    search.addEventListener('input',function(){
      var q=search.value.trim().toLowerCase(),any=false;
      document.querySelectorAll('.wp-cat-row').forEach(function(row){
        var show=!q||(row.getAttribute('data-search')||'').indexOf(q)!==-1;
        row.style.display=show?'':'none';
        if(show)any=true;
      });
      var empty=document.getElementById('catEmpty');
      if(empty)empty.style.display=any?'none':'block';
    });
  }

  document.addEventListener('click',function(e){
    var edit=e.target.closest('.wp-cat-edit');
    if(edit){
      var form=document.getElementById(edit.getAttribute('data-target'));
      var row=edit.closest('.wp-cat-row');
      if(!form||!row)return;
      var opening=form.style.display==='none';
      document.querySelectorAll('.wp-cat-quick-edit').forEach(function(f){f.style.display='none';});
      document.querySelectorAll('.wp-cat-item').forEach(function(it){it.style.display='';});
      if(opening){
        row.querySelector('.wp-cat-item').style.display='none';
        form.style.display='block';
      }
      return;
    }
    var cancel=e.target.closest('.wp-cat-cancel');
    if(cancel){
      var f=cancel.closest('.wp-cat-quick-edit');
      if(f){
        f.style.display='none';
        var it=f.closest('.wp-cat-row').querySelector('.wp-cat-item');
        if(it)it.style.display='';
      }
    }
  });

  document.querySelectorAll('.wp-cat-del-inline').forEach(function(form){
    form.addEventListener('submit',function(e){
      var btn=form.querySelector('.wp-cat-del-btn');
      if(btn&&!window.confirm(btn.getAttribute('data-confirm')))e.preventDefault();
    });
  });

  document.querySelectorAll('.wp-cat-img-field input[type=file]').forEach(function(inp){
    inp.addEventListener('change',function(){
      var wrap=inp.closest('.wp-cat-img-field');
      var prev=wrap?wrap.querySelector('[data-preview]'):null;
      if(!inp.files||!inp.files[0]||!prev)return;
      var reader=new FileReader();
      reader.onload=function(){prev.src=reader.result;prev.classList.remove('wp-cat-img-hidden');};
      reader.readAsDataURL(inp.files[0]);
    });
  });
})();
</script>
<?php
admin_page_end();