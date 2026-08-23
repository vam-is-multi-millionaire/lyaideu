<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

lyaideu_ensure_promo_table();

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$promos = lyaideu_promo_codes();
$typeLabels = [
    'percent' => 'Percent off',
    'fixed' => 'Fixed Rs. off',
    'freedelivery' => 'Free delivery',
];

function promo_value_label(array $p): string {
    if ($p['type'] === 'freedelivery') {
        return 'Delivery FREE';
    }
    if ($p['type'] === 'fixed') {
        return 'Rs. ' . number_format((int)$p['value']) . ' OFF';
    }
    return (int)$p['value'] . '% OFF';
}

function promo_expiry_label(?string $expiresAt): string {
    if ($expiresAt === null || $expiresAt === '' || $expiresAt === '0000-00-00 00:00:00') {
        return 'No expiry';
    }
    $ts = strtotime($expiresAt);
    if (!$ts) {
        return 'No expiry';
    }
    $diff = $ts - time();
    if ($diff <= 0) {
        return 'Expired';
    }
    $days = (int)floor($diff / 86400);
    $hours = (int)floor(($diff % 86400) / 3600);
    return date('M j, Y H:i', $ts) . ' · ' . ($days > 0 ? $days . 'd ' . $hours . 'h left' : $hours . 'h left');
}

function promo_local_input(?string $expiresAt): string {
    if ($expiresAt === null || $expiresAt === '' || $expiresAt === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($expiresAt);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

admin_page_start('Promo Codes', 'promos', 'Promo Codes');
?>
<style>
.promo-code-chip{display:inline-flex;align-items:center;gap:.45rem;background:var(--orange-900);color:#fff;font-weight:900;letter-spacing:.06em;padding:.32rem .7rem;border-radius:9px;font-size:.85rem;}
.promo-copy{border:0;background:rgba(255,255,255,.18);color:#fff;width:24px;height:24px;border-radius:6px;cursor:pointer;display:inline-grid;place-items:center;font-size:.7rem;}
.promo-copy:hover{background:rgba(255,255,255,.32);}
.promo-type-badge{display:inline-flex;align-items:center;font-size:.68rem;font-weight:900;letter-spacing:.04em;text-transform:uppercase;padding:.26rem .6rem;border-radius:999px;background:var(--orange-100);color:var(--orange-800);}
.promo-grid2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;}
@media(max-width:560px){.promo-grid2{grid-template-columns:1fr;}}
.gen-row{display:flex;gap:.5rem;}
.gen-row input{flex:1 1 auto;min-width:0;text-transform:uppercase;}
</style>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Create discount codes customers can apply on checkout. Choose <strong>Percent off</strong>, <strong>Fixed Rs. off</strong> or <strong>Free delivery</strong>, optionally require a minimum order, cap the discount, limit it to the first N customers and set an expiry. Each customer can use a given code only once.</p>
        <span class="admin-count-badge"><?= count($promos) ?> code<?= count($promos) === 1 ? '' : 's' ?></span>
    </div>
</section>

<div class="wp-cat-layout">
    <div class="wp-cat-main">
        <section class="admin-section">
            <div class="admin-section-top">
                <h2 style="margin:0"><i class="fa-solid fa-ticket"></i> Your Promo Codes</h2>
            </div>
            <?php if (!$promos): ?>
                <p class="wp-cat-none" style="padding:1rem;"><i class="fa-solid fa-circle-info"></i> No promo codes yet. Create your first one with the form on the right.</p>
            <?php else: ?>
            <div class="wp-cat-list">
                <div class="wp-cat-group">
                    <h3 class="wp-cat-group-title"><i class="fa-solid fa-ticket"></i> Codes <span class="wp-cat-group-count"><?= count($promos) ?></span></h3>
                    <?php foreach ($promos as $i => $p): $id = (int)$p['id']; ?>
                    <div class="wp-cat-row">
                        <div class="wp-cat-item">
                            <span class="wp-cat-indent">
                                <span class="promo-code-chip"><?= $ce($p['code']) ?>
                                    <button type="button" class="promo-copy" data-code="<?= $ce($p['code']) ?>" title="Copy code"><i class="fa-regular fa-copy"></i></button>
                                </span>
                                <span class="wp-cat-name-wrap">
                                    <span class="wp-cat-name"><?= $ce(promo_value_label($p)) ?></span>
                                    <span class="wp-cat-subpath"><?= $ce(ucfirst($typeLabels[$p['type']] ?? $p['type'])) ?><?= (int)$p['min_order'] > 0 ? ' · min order Rs. ' . number_format((int)$p['min_order']) : '' ?><?= $p['type'] === 'percent' && (int)$p['max_discount'] > 0 ? ' · cap Rs. ' . number_format((int)$p['max_discount']) : '' ?></span>
                                </span>
                            </span>
                            <span class="wp-cat-meta">
                                <span class="wp-cat-level-badge <?= (int)$p['is_active'] ? 'is-top' : 'is-sub' ?>"><?= (int)$p['is_active'] ? 'Active' : 'Paused' ?></span>
                                <span class="admin-count-badge"><?= $p['usage_limit'] > 0 ? $p['used_count'] . '/' . $p['usage_limit'] . ' used' : $p['used_count'] . ' used' ?></span>
                                <span class="admin-count-badge"><?= $ce(promo_expiry_label($p['expires_at'])) ?></span>
                            </span>
                            <span class="wp-cat-actions">
                                <form action="admin_save" method="POST" class="wp-cat-del-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                                    <input type="hidden" name="section" value="promos">
                                    <input type="hidden" name="promos[<?= $i ?>][id]" value="<?= $id ?>">
                                    <input type="hidden" name="promos[<?= $i ?>][toggle]" value="1">
                                    <button type="submit" class="wp-cat-act" title="<?= (int)$p['is_active'] ? 'Pause this code' : 'Activate this code' ?>"><i class="fa-solid <?= (int)$p['is_active'] ? 'fa-pause' : 'fa-play' ?>"></i> <?= (int)$p['is_active'] ? 'Pause' : 'Activate' ?></button>
                                </form>
                                <button type="button" class="wp-cat-act wp-cat-edit" data-target="qp-<?= $id ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                                <form action="admin_save" method="POST" class="wp-cat-del-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                                    <input type="hidden" name="section" value="promos">
                                    <input type="hidden" name="promos[<?= $i ?>][id]" value="<?= $id ?>">
                                    <input type="hidden" name="promos[<?= $i ?>][delete]" value="1">
                                    <button type="submit" class="wp-cat-act wp-cat-del-btn" data-confirm="<?= $ce('Delete the code "' . $p['code'] . '"? Customers will no longer be able to use it.') ?>"><i class="fa-solid fa-trash-can"></i> Delete</button>
                                </form>
                            </span>
                        </div>

                        <form class="wp-cat-quick-edit" id="qp-<?= $id ?>" action="admin_save" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                            <input type="hidden" name="section" value="promos">
                            <input type="hidden" name="promos[<?= $i ?>][id]" value="<?= $id ?>">
                            <div class="admin-field-row">
                                <div><label>Code</label><input type="text" name="promos[<?= $i ?>][code]" value="<?= $ce($p['code']) ?>" required maxlength="40"></div>
                                <div><label>Type</label>
                                    <select name="promos[<?= $i ?>][type]" class="promo-type-select">
                                        <?php foreach ($typeLabels as $tKey => $tLabel): ?>
                                        <option value="<?= $ce($tKey) ?>"<?= $p['type'] === $tKey ? ' selected' : '' ?>><?= $ce($tLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="admin-field-row">
                                <div class="promo-value-wrap"><label><?= $ce($p['type'] === 'fixed' ? 'Amount off (Rs.)' : 'Percent off (%)') ?></label><input type="number" name="promos[<?= $i ?>][value]" value="<?= (int)$p['value'] ?>" min="1" max="<?= $p['type'] === 'fixed' ? 100000 : 90 ?>"></div>
                                <div class="promo-cap-wrap"<?= $p['type'] === 'percent' ? '' : ' style="display:none"' ?>><label>Max discount cap (Rs., 0 = none)</label><input type="number" name="promos[<?= $i ?>][max_discount]" value="<?= (int)$p['max_discount'] ?>" min="0"></div>
                            </div>
                            <div class="admin-field-row">
                                <div><label>Min order (Rs., 0 = none)</label><input type="number" name="promos[<?= $i ?>][min_order]" value="<?= (int)$p['min_order'] ?>" min="0"></div>
                                <div><label>First N customers (0 = unlimited)</label><input type="number" name="promos[<?= $i ?>][usage_limit]" value="<?= (int)$p['usage_limit'] ?>" min="0"></div>
                            </div>
                            <div class="admin-field-row">
                                <div><label>Expires at (blank = never)</label><input type="datetime-local" name="promos[<?= $i ?>][expires_at]" value="<?= $ce(promo_local_input($p['expires_at'])) ?>"></div>
                                <div><label>&nbsp;</label><label style="display:flex;align-items:center;gap:.5rem;text-transform:none;font-weight:800;color:#3a2415;"><input type="checkbox" name="promos[<?= $i ?>][is_active]" value="1" style="width:auto;"<?= (int)$p['is_active'] ? ' checked' : '' ?>> Active</label></div>
                            </div>
                            <div class="wp-cat-edit-actions">
                                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Update Code</button>
                                <button type="button" class="btn btn-outline wp-cat-cancel">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="wp-cat-side">
        <section class="admin-section">
            <h2><i class="fa-solid fa-plus"></i> New Promo Code</h2>
            <form action="admin_save" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                <input type="hidden" name="section" value="promos">
                <label>Code</label>
                <div class="gen-row">
                    <input type="text" name="new_promo[code]" id="newPromoCode" placeholder="e.g. WELCOME50" required maxlength="40">
                    <button type="button" class="btn btn-outline" id="genCodeBtn" title="Generate a random code"><i class="fa-solid fa-dice"></i></button>
                </div>
                <label>Type</label>
                <select name="new_promo[type]" id="newPromoType">
                    <?php foreach ($typeLabels as $tKey => $tLabel): ?>
                    <option value="<?= $ce($tKey) ?>"><?= $ce($tLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="newPromoValueWrap">
                    <label>Percent off (%) <span style="text-transform:none;font-weight:700;">— switch Type for Rs. off</span></label>
                    <input type="number" name="new_promo[value]" id="newPromoValue" value="10" min="1" max="90">
                </div>
                <div id="newPromoCapWrap" style="display:none;">
                    <label>Max discount cap (Rs., 0 = none)</label>
                    <input type="number" name="new_promo[max_discount]" value="0" min="0">
                </div>
                <div class="promo-grid2">
                    <div><label>Min order (Rs.)</label><input type="number" name="new_promo[min_order]" value="0" min="0"></div>
                    <div><label>First N customers</label><input type="number" name="new_promo[usage_limit]" value="0" min="0" placeholder="0 = unlimited"></div>
                </div>
                <label>Expires at (blank = never)</label>
                <input type="datetime-local" name="new_promo[expires_at]">
                <button type="submit" class="btn btn-primary btn-block" style="margin-top:1rem;"><i class="fa-solid fa-plus"></i> Create Promo Code</button>
            </form>
            <p style="font-size:.78rem;color:var(--muted);font-weight:700;margin-top:.8rem;">Customers apply codes on checkout. Each customer can redeem a given code once; global “first N” limits stop exactly at N orders.</p>
        </section>
    </aside>
</div>

<script>
(function(){
  var TYPE_LABEL_VALUE={percent:'Percent off (%)',fixed:'Amount off (Rs.)',freedelivery:'Value (unused for free delivery)'};

  function bindTypeSync(typeSel,valueWrap,capWrap){
    if(!typeSel)return;
    function sync(){
      var t=typeSel.value;
      if(valueWrap){
        var lbl=valueWrap.querySelector('label');
        if(lbl&&TYPE_LABEL_VALUE[t])lbl.textContent=TYPE_LABEL_VALUE[t];
        valueWrap.style.display=t==='freedelivery'?'none':'';
      }
      if(capWrap)capWrap.style.display=t==='percent'?'':'none';
    }
    typeSel.addEventListener('change',sync);
    sync();
  }
  document.querySelectorAll('.promo-type-select').forEach(function(sel){
    var form=sel.closest('form');
    bindTypeSync(sel,form?form.querySelector('.promo-value-wrap'):null,form?form.querySelector('.promo-cap-wrap'):null);
  });
  bindTypeSync(document.getElementById('newPromoType'),document.getElementById('newPromoValueWrap'),document.getElementById('newPromoCapWrap'));

  var gen=document.getElementById('genCodeBtn'),codeInput=document.getElementById('newPromoCode');
  if(gen&&codeInput){
    gen.addEventListener('click',function(){
      var chars='ABCDEFGHJKLMNPQRSTUVWXYZ23456789',out='';
      for(var i=0;i<8;i++)out+=chars.charAt(Math.floor(Math.random()*chars.length));
      codeInput.value=out;
      codeInput.focus();
    });
  }

  document.addEventListener('click',function(e){
    var cp=e.target.closest('.promo-copy');
    if(cp){
      var code=cp.getAttribute('data-code')||'';
      var done=function(){cp.innerHTML='<i class="fa-solid fa-check"></i>';setTimeout(function(){cp.innerHTML='<i class="fa-regular fa-copy"></i>';},1400);};
      if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(code).then(done,function(){done();});}
      else{var ta=document.createElement('textarea');ta.value=code;document.body.appendChild(ta);ta.select();try{document.execCommand('copy');}catch(err){}ta.remove();done();}
      return;
    }
    var edit=e.target.closest('.wp-cat-edit');
    if(edit){
      var form=document.getElementById(edit.getAttribute('data-target'));
      var row=edit.closest('.wp-cat-row');
      if(!form||!row)return;
      var opening=form.style.display!=='block';
      document.querySelectorAll('.wp-cat-quick-edit').forEach(function(f){f.style.display='none';});
      document.querySelectorAll('.wp-cat-item').forEach(function(it){it.style.display='';});
      if(opening){row.querySelector('.wp-cat-item').style.display='none';form.style.display='block';}
      return;
    }
    var cancel=e.target.closest('.wp-cat-cancel');
    if(cancel){
      var f=cancel.closest('.wp-cat-quick-edit');
      if(f){f.style.display='none';var it=f.closest('.wp-cat-row').querySelector('.wp-cat-item');if(it)it.style.display='';}
    }
  });

  document.querySelectorAll('.wp-cat-del-inline').forEach(function(form){
    form.addEventListener('submit',function(e){
      var btn=form.querySelector('.wp-cat-del-btn');
      if(btn&&!window.confirm(btn.getAttribute('data-confirm')))e.preventDefault();
    });
  });
})();
</script>
<?php
admin_page_end();
