
window.FE_VERSION='v3';
(function(){'use strict';
const $=(s,c=document)=>c.querySelector(s), $$=(s,c=document)=>[...c.querySelectorAll(s)];
let currentCat='all',searchQuery='',allDishes=[],allMart=[]; const CART_KEY='fe_cart',FAV_KEY='fe_favorites';
const getCart=()=>JSON.parse(localStorage.getItem(CART_KEY)||'[]'); const saveCart=c=>{localStorage.setItem(CART_KEY,JSON.stringify(c));renderCart();};
const getFav=()=>JSON.parse(localStorage.getItem(FAV_KEY)||'[]'); const saveFav=f=>localStorage.setItem(FAV_KEY,JSON.stringify(f));
function esc(v){return String(v??'').replace(/[&<>\"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[ch]));}
const FA_ICONS={'🛵':'fa-motorcycle','📦':'fa-box','🍽️':'fa-utensils','🍕':'fa-pizza-slice','🥟':'fa-drumstick-bite','🍜':'fa-bowl-rice','🍢':'fa-utensils','🥠':'fa-cookie','🍔':'fa-burger','🥤':'fa-mug-saucer','🥭':'fa-apple-whole','🍛':'fa-bowl-food','🥩':'fa-bacon','🍗':'fa-drumstick-bite','📞':'fa-phone','🤝':'fa-handshake','☎️':'fa-phone','🌶':'fa-pepper-hot','🏨':'fa-hotel','📍':'fa-location-dot','✉️':'fa-envelope','💳':'fa-credit-card','📝':'fa-note-sticky','🔥':'fa-fire','💚':'fa-heart','❤️':'fa-heart','✅':'fa-circle-check','❌':'fa-circle-xmark','🕐':'fa-clock','🎉':'fa-champagne-glasses','⚠️':'fa-triangle-exclamation','⚡':'fa-bolt','🌐':'fa-globe','📊':'fa-chart-simple','👥':'fa-users','👤':'fa-user','🔒':'fa-lock','🔐':'fa-key','🎂':'fa-cake-candles','⚙️':'fa-gear','🔍':'fa-magnifying-glass','🛒':'fa-cart-shopping','👋':'fa-hand','🎬':'fa-film','🧾':'fa-receipt','🚀':'fa-rocket','✨':'fa-arrows-rotate'};
const FA_ICON_LOOKUP={};Object.keys(FA_ICONS).forEach(k=>{FA_ICON_LOOKUP[k.replace(/\uFE0F/g,'')]=FA_ICONS[k];});
function faIcon(v){v=(v||'').replace(/\uFE0F/g,'').trim();if(!v)return '<i class="fa-solid fa-utensils"></i>';if(/^(fa-solid|fa-regular|fa-brands)\s+/.test(v)||/^fa-/.test(v)){const cls=/^(fa-solid|fa-regular|fa-brands)\s+/.test(v)?v:'fa-solid '+v;return '<i class="'+cls.replace(/\s+/g,' ').trim()+'"></i>';}return FA_ICON_LOOKUP[v]?'<i class="fa-solid '+FA_ICON_LOOKUP[v]+'"></i>':'<i class="fa-solid fa-utensils"></i>';}
function emojisToIcons(s){s=String(s||'').replace(/\uFE0F/g,'');return s.replace(/[\u{1F000}-\u{1FAFF}\u2600-\u27BF\u2B00-\u2BFF]/gu,m=>FA_ICON_LOOKUP[m]?'<i class="fa-solid '+FA_ICON_LOOKUP[m]+'"></i>':m);}
function toast(msg){let e=$('.toast');if(!e){e=document.createElement('div');e.className='toast';document.body.appendChild(e)}e.innerHTML=msg;e.classList.add('show');clearTimeout(e._t);e._t=setTimeout(()=>e.classList.remove('show'),2800)}

document.addEventListener('DOMContentLoaded',()=>{
  initNav();initProfileMenu();initScrollSpy();initOrderToasts();initAuthTabs();initPasswordPeek();initAuthValidation();footerYear();initCart();initAddCart();initFeaturedGrid();
  if($('#menu-grid')||$('#mart-grid')||$('#hotels-grid')||$('#contact-grid')||$('#checkoutForm')||document.body.hasAttribute('data-needs-catalog'))fetch('api.php').then(r=>r.json()).then(d=>{
    allDishes=d.dishes||[];
    allMart=d.mart||[];
    if($('#menu-grid')){renderDishes(allDishes);initMenuFilters();}
    if($('#mart-grid')){renderMart(allMart);initMartFilters();}
    if($('#hotels-grid')){renderHotels(d.hotels||[]);initHotelFilters();}
    if($('#contact-grid'))renderContacts(d.contacts||[]);
    if($('#menu-grid')||$('#mart-grid')||$('#hotels-grid')||$('#contact-grid'))startLiveCatalogSync();
    if($('#checkoutForm'))initCheckout();
  }).catch(()=>toast('<i class="fa-solid fa-triangle-exclamation"></i> Could not load catalog data.'));
});

function renderDishes(dishes){
  const grid=$('#menu-grid');if(!grid)return;const fav=getFav();
  grid.innerHTML=dishes.map(d=>{
    const id=Number(d.id),name=esc(d.name),hotel=esc(d.hotel),desc=esc(d.desc),tag=esc(d.tag),img=esc(d.img),cat=esc(d.cat),phone=esc(d.phone);
    const art=img?`<img src="${img}" alt="${name}" loading="lazy">`:`<span class="dish-art-ico"><i class="fa-solid fa-utensils"></i></span>`;
    return `<article class="dish-card reveal visible" data-id="${id}" data-cat="${cat}" data-search="${esc((d.name+' '+d.hotel+' '+d.cat+' '+d.desc).toLowerCase())}">
      <div class="dish-art">${art}
      </div>
      <div class="dish-body"><div class="dish-top"><h3>${name}</h3></div>
      <div class="dish-foot"><span class="price"><small>Rs.</small> ${Number(d.price)||0}</span>
      <button class="btn-order add-cart" data-id="${id}" type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button></div></div></article>`;
  }).join('');
  $$('#menu-grid .dish-card').forEach(c=>c.addEventListener('click',e=>{if(e.target.closest('.btn-order'))return;window.location.href='product.php?type=dish&id='+Number(c.dataset.id)}));
  applyFilters();
}
function renderHotels(hotels){const g=$('#hotels-grid');if(!g)return;g.innerHTML=hotels.map(h=>{const logo=esc(h.logo)||'';return `<div class="hotel-card reveal visible" data-search="${esc((h.name+' '+h.type+' '+(h.hotel||'')+' '+(h.location||'')).toLowerCase())}"><div class="hotel-avatar">${logo?`<img class="hotel-logo" src="${logo}" alt="${esc(h.name)}" loading="lazy">`:faIcon(h.emoji)}</div><div class="hotel-info"><h3>${esc(h.name)}</h3><p>${esc(h.type)}</p></div><a class="hotel-call" href="tel:+977${esc(h.phone)}"><i class="fa-solid fa-phone"></i> ${esc(h.phone)}</a></div>`}).join('');applyHotelFilters()}
function applyHotelFilters(){
  const cards=$$('#hotels-grid .hotel-card'),q=($('#hotelSearch')?.value||'').trim().toLowerCase();
  cards.forEach(c=>c.style.display=(!q||c.dataset.search.includes(q))?'':'none');
  if($('#hotelsEmpty'))$('#hotelsEmpty').style.display=(!q||cards.some(c=>c.style.display!=='none'))?'none':'block';
}
function initHotelFilters(){const s=$('#hotelSearch');if(s)s.addEventListener('input',applyHotelFilters)}
function renderContacts(cs){const g=$('#contact-grid');if(!g)return;g.innerHTML=cs.map(c=>`<div class="contact-card reveal visible"><span class="contact-ico">${faIcon(c.ico)}</span><h3>${esc(c.role)}</h3><p class="contact-person">${esc(c.person)}</p><a class="contact-num" href="tel:+977${esc(c.phone)}">${esc(c.phone)}</a><small>${esc(c.note)}</small></div>`).join('')}
const MART_CAT_ICONS={'vegetables':'fa-carrot','fruits':'fa-apple-whole','dairy':'fa-cow','staples':'fa-bowl-rice','oils':'fa-mortar-pestle','snacks':'fa-cookie'};
function martCatIcon(cat){const ic=MART_CAT_ICONS[cat]||'fa-basket-shopping';return '<i class="fa-solid '+ic+'"></i>';}
function renderMart(items){
  const grid=$('#mart-grid');if(!grid)return;
  grid.innerHTML=items.map(m=>{
    const id=Number(m.id),name=esc(m.name),desc=esc(m.desc),tag=esc(m.tag),img=esc(m.img),cat=esc(m.cat),unit=esc(m.unit);
    const art=img?`<img src="${img}" alt="${name}" loading="lazy">`:martCatIcon(cat);
    return `<article class="dish-card reveal visible" data-id="${id}" data-cat="${cat}" data-search="${esc((m.name+' '+m.cat+' '+m.desc+' '+m.unit).toLowerCase())}">
      <div class="dish-art mart-art">${art}
      ${tag?`<span class="dish-tag">${tag}</span>`:''}
      </div>
      <div class="dish-body"><div class="dish-top"><h3>${name}</h3></div>
      <div class="dish-foot"><span class="price"><small>Rs.</small> ${Number(m.price)||0}${unit?` <span class="unit">/ ${unit}</span>`:''}</span>
      <button class="btn-order add-cart" data-id="${id}" data-type="mart" type="button"><i class="fa-solid fa-cart-plus"></i> Buy</button></div></div></article>`;
  }).join('');
  $$('#mart-grid .dish-card').forEach(c=>c.addEventListener('click',e=>{if(e.target.closest('.btn-order'))return;window.location.href='product.php?type=mart&id='+Number(c.dataset.id)}));
  applyMartFilters();
}
function applyMartFilters(){
  const cards=$$('#mart-grid .dish-card'),sort=$('#sortMart')?.value||'default';
  let filtered=cards.filter(c=>(currentCat==='all'||c.dataset.cat===currentCat)&&(!searchQuery||c.dataset.search.includes(searchQuery)));
  if(sort==='price-low'||sort==='price-high'){
    filtered.sort((a,b)=>{
      const pa=Number(allMart.find(d=>String(d.id)===a.dataset.id)?.price)||0;
      const pb=Number(allMart.find(d=>String(d.id)===b.dataset.id)?.price)||0;
      return sort==='price-low' ? pa-pb : pb-pa;
    });
  }
  const grid=$('#mart-grid');
  if(grid){
    filtered.forEach(card=>grid.appendChild(card));
    cards.filter(c=>!filtered.includes(c)).forEach(card=>card.style.display='none');
    filtered.forEach(card=>card.style.display='');
  }
  if($('#martEmpty'))$('#martEmpty').style.display=filtered.length?'none':'block';
}
function initMartFilters(){const chips=$$('.chip[data-mcat]');chips.forEach(ch=>ch.addEventListener('click',()=>{chips.forEach(x=>x.classList.remove('active'));ch.classList.add('active');currentCat=ch.dataset.mcat;applyMartFilters()}));const s=$('#martSearch');if(s){searchQuery=s.value.trim().toLowerCase();s.addEventListener('input',e=>{searchQuery=e.target.value.trim().toLowerCase();applyMartFilters()})}$('#sortMart')?.addEventListener('change',applyMartFilters);if(searchQuery)applyMartFilters()}
function findItem(id,type){type=type||'dish';return (type==='mart'?allMart:allDishes).find(x=>Number(x.id)===Number(id))||null}
function addToCart(id,type,openDrawer){const d=findItem(id,type);if(!d)return;id=Number(id);type=type||'dish';let c=getCart(),i=c.find(x=>Number(x.id)===id&&(x.type||'dish')===type);if(i)i.qty=Math.min(20,i.qty+1);else c.push({id,type,qty:1});saveCart(c);toast('<i class="fa-solid fa-cart-shopping"></i> '+esc(d.name)+' added to cart');if(openDrawer!==false)openCart();}
function changeQty(id,type,delta){type=type||'dish';let c=getCart(),i=c.find(x=>Number(x.id)===Number(id)&&(x.type||'dish')===type);if(!i)return;id=Number(id);i.qty+=delta;if(i.qty<=0)c=c.filter(x=>!(Number(x.id)===id&&(x.type||'dish')===type));saveCart(c)}
function renderCart(){
  const box=$('#cartItems'),empty=$('#cartEmpty'),countEls=$$('.cart-count'),c=getCart();const count=c.reduce((a,x)=>a+x.qty,0);countEls.forEach(e=>e.textContent=count);if(!box)return;
  if(!c.length){box.innerHTML='';empty?.classList.add('show');$('#checkoutBtn')?.classList.add('disabled')}
  else{empty?.classList.remove('show');
    box.innerHTML=c.map(r=>{const d=findItem(r.id,r.type);if(!d)return '';const unit=esc(d.unit||'');return `<div class="cart-item"><div><strong>${esc(d.name)}</strong><small>Rs. ${d.price} ${unit?unit+' ':''}each</small></div><div class="qty"><button data-q="-1" data-id="${d.id}" data-type="${r.type||'dish'}" type="button">−</button><b>${r.qty}</b><button data-q="1" data-id="${d.id}" data-type="${r.type||'dish'}" type="button">+</button></div><strong>Rs. ${d.price*r.qty}</strong></div>`}).join('');
    $$('[data-q]').forEach(b=>b.addEventListener('click',()=>changeQty(Number(b.dataset.id),b.dataset.type,Number(b.dataset.q))));
    $('#checkoutBtn')?.classList.remove('disabled');
  }
  const sub=c.reduce((a,r)=>{const d=findItem(r.id,r.type);return a+(d?Number(d.price)*r.qty:0)},0);
  if($('#cartSubtotal'))$('#cartSubtotal').textContent='Rs. '+sub;if($('#cartTotal'))$('#cartTotal').textContent='Rs. '+(sub+(sub?50:0));
}
function openCart(){const d=$('#cartDrawer'),o=$('#cartOverlay');if(d){d.classList.add('open');o?.classList.add('open')}}
function closeCart(){$('#cartDrawer')?.classList.remove('open');$('#cartOverlay')?.classList.remove('open')}
function initCart(){document.addEventListener('click',e=>{if(e.target.closest('.cart-open-btn'))openCart();if(e.target.closest('#cartClose')||e.target.closest('#cartOverlay'))closeCart();if(e.target.closest('#clearCart')){localStorage.removeItem(CART_KEY);renderCart();toast('Cart cleared')}});renderCart()}
function initAddCart(){document.addEventListener('click',e=>{const b=e.target.closest('.add-cart');if(!b)return;addToCart(Number(b.dataset.id),b.dataset.type||'dish')})}
function initFeaturedGrid(){document.addEventListener('click',e=>{const card=e.target.closest('.home-grid .dish-card');if(!card)return;if(e.target.closest('.add-cart')||e.target.closest('a'))return;window.location.href='product.php?type='+(card.dataset.type||'dish')+'&id='+card.dataset.id})}
function toggleFav(id,b){let f=getFav();if(f.includes(id)){f=f.filter(x=>x!==id);b.classList.remove('active');b.innerHTML='<i class="fa-regular fa-heart"></i>';toast('Removed from favorites')}else{f.push(id);b.classList.add('active');b.innerHTML='<i class="fa-solid fa-heart"></i>';toast('<i class="fa-solid fa-heart"></i> Added to favorites')}saveFav(f)}
function applyFilters(){
  const cards=$$('.dish-card'),sort=$('#sortMenu')?.value||'default';
  let filtered=cards.filter(c=>(currentCat==='all'||c.dataset.cat===currentCat)&&(!searchQuery||c.dataset.search.includes(searchQuery)));

  if(sort==='price-low' || sort==='price-high'){
    filtered.sort((a,b)=>{
      const pa=Number(allDishes.find(d=>String(d.id)===a.dataset.id)?.price)||0;
      const pb=Number(allDishes.find(d=>String(d.id)===b.dataset.id)?.price)||0;
      return sort==='price-low' ? pa-pb : pb-pa;
    });
  }

  const grid=$('#menu-grid');
  if(grid){
    filtered.forEach(card=>grid.appendChild(card));
    cards.filter(c=>!filtered.includes(c)).forEach(card=>card.style.display='none');
    filtered.forEach(card=>card.style.display='');
  }

  if($('#emptyState'))$('#emptyState').style.display=filtered.length?'none':'block';
}
function startLiveCatalogSync(){
  if(window.FE_LIVE_SYNC||(!$('#menu-grid')&&!$('#mart-grid')&&!$('#hotels-grid')&&!$('#contact-grid')))return; window.FE_LIVE_SYNC=true;
  let lastSignature='';
  const sync=()=>fetch('api.php?v='+Date.now(),{cache:'no-store'}).then(r=>r.json()).then(d=>{
    const sig=JSON.stringify([d.dishes||[],d.mart||[],d.hotels||[],d.contacts||[]]);
    if(!lastSignature){lastSignature=sig;return;}
    if(sig!==lastSignature){
      lastSignature=sig;allDishes=d.dishes||[];allMart=d.mart||[];
      renderDishes(allDishes);renderMart(allMart);renderHotels(d.hotels||[]);renderContacts(d.contacts||[]);
      toast('<i class="fa-solid fa-arrows-rotate"></i> Catalog updated automatically');
    }
  }).catch(()=>{});
  setInterval(sync,5000);
}
function initMenuFilters(){const chips=$$('.chip[data-cat]');chips.forEach(ch=>ch.addEventListener('click',()=>{chips.forEach(x=>x.classList.remove('active'));ch.classList.add('active');currentCat=ch.dataset.cat;applyFilters()}));const s=$('#dishSearch');if(s){searchQuery=s.value.trim().toLowerCase();s.addEventListener('input',e=>{searchQuery=e.target.value.trim().toLowerCase();applyFilters()})}$('#sortMenu')?.addEventListener('change',applyFilters);if(searchQuery)applyFilters()}
function initCheckout(){
  const form=$('#checkoutForm');if(!form)return;let promo='';
  function update(){
    const c=getCart();let sub=0;const box=$('#checkoutItems');
    if(!c.length){$('#checkoutEmpty')?.classList.add('show');form.style.display='none';return}
    $('#checkoutEmpty')?.classList.remove('show');
    box.innerHTML=c.map(r=>{const d=findItem(r.id,r.type);if(!d)return '';const line=d.price*r.qty;sub+=line;return `<div class="checkout-item"><span>${esc(d.name)} × ${r.qty}</span><strong>Rs. ${line}</strong></div>`}).join('');
    const delivery=50,discount=(promo==='LYAIDEU'||promo==='FOODXPRESS')?50:0;$('#coSubtotal').textContent='Rs. '+sub;$('#coDelivery').textContent='Rs. '+Math.max(0,delivery-discount);$('#coTotal').textContent='Rs. '+Math.max(0,sub+delivery-discount);$('#cartJson').value=JSON.stringify(c);if($('#promoHidden'))$('#promoHidden').value=promo;
  }
  $('#promoBtn')?.addEventListener('click',()=>{promo=$('#promoInput').value.trim().toUpperCase();$('#promoMsg').innerHTML=(promo==='LYAIDEU'||promo==='FOODXPRESS')?'<i class="fa-solid fa-circle-check"></i> Free delivery applied!':'<i class="fa-solid fa-circle-xmark"></i> Invalid demo code. Try LYAIDEU.';update()});
  form.addEventListener('submit',e=>{if(!getCart().length){e.preventDefault();toast('Your cart is empty.')}$('#cartJson').value=JSON.stringify(getCart());if($('#promoHidden'))$('#promoHidden').value=promo});update();
}
function switchTab(w){$$('.tab').forEach(t=>t.classList.toggle('active',t.dataset.show===w));$$('.auth-form').forEach(f=>f.classList.toggle('active',f.id==='form-'+w))}
function initAuthTabs(){if(window.FE_TABS_INLINE||!$('.tabs'))return;document.addEventListener('click',e=>{const t=e.target.closest('[data-show]');if(t){e.preventDefault();switchTab(t.dataset.show)}})}
function initPasswordPeek(){$$('.peek').forEach(b=>b.addEventListener('click',()=>{const i=b.parentElement.querySelector('input'),show=i.type==='password';i.type=show?'text':'password';b.innerHTML=show?'<i class="fa-solid fa-eye-slash"></i>':'<i class="fa-solid fa-eye"></i>'}))}
const validators={
 username:v=>v.trim().length>=3||'Username must be your full name OR 10-digit phone.',
 name:v=>(v.trim().length>=3&&/^[\p{L}\s'.]+$/u.test(v))||'Full name: at least 3 letters, only letters and spaces.',
 email:v=>/^[^\s@]+@gmail\.com$/i.test(v)||'Email must end with @gmail.com',
 phone:v=>/^9[78]\d{8}$/.test(v.replace(/\D/g,''))||'Exactly 10 digits, starts with 97 or 98.',
 dob:v=>{if(!v)return'Please select your date of birth.';const b=new Date(v),t=new Date();if(b>t)return'Date of birth cannot be in the future.';const age=t.getFullYear()-b.getFullYear()-((t.getMonth()<b.getMonth()||(t.getMonth()===b.getMonth()&&t.getDate()<b.getDate()))?1:0);if(age<10)return'You must be at least 10 years old (you are '+age+').';if(age>80)return'Age must be 80 or younger (you are '+age+').';return true},
 strongpass(v,f){if(v.length<8)return'Password must be at least 8 characters.';if(!/[A-Z]/.test(v))return'Password must contain at least 1 capital letter.';if(!/[0-9]/.test(v))return'Password must contain at least 1 number.';if(!/[^A-Za-z0-9]/.test(v))return'Password must contain at least 1 symbol.';const n=f.querySelector('[name=name]')?.value.toLowerCase()||'',parts=n.split(/\s+/).filter(p=>p.length>=3),low=v.toLowerCase();for(const p of parts)if(low.includes(p))return"Password must NOT contain your name ('"+p+"').";const ph=f.querySelector('[name=phone]')?.value.replace(/\D/g,'')||'';if(ph&&v.includes(ph))return'Password must NOT contain your contact number.';return true},
 confirm:(v,f)=>v&&v===f.querySelector('[name=password]').value||'Passwords do not match.'
};
function updatePassRequirements(f){const p=f.querySelector('[name=password]')?.value||'',n=f.querySelector('[name=name]')?.value.toLowerCase()||'',ph=f.querySelector('[name=phone]')?.value.replace(/\D/g,'')||'',bad=n.split(/\s+/).filter(x=>x.length>=3).some(x=>p.toLowerCase().includes(x))||(ph&&p.includes(ph));const m={len:p.length>=8,cap:/[A-Z]/.test(p),num:/[0-9]/.test(p),sym:/[^A-Za-z0-9]/.test(p),info:!bad&&p.length>0};$$('.req').forEach(e=>e.classList.toggle('met',!!m[e.dataset.req]))}
function checkField(field,form){const i=field.querySelector('input'),msg=field.querySelector('.field-msg'),rule=i?.dataset.validate;if(!rule||!validators[rule])return true;const r=validators[rule](i.value,form),ok=r===true;field.classList.toggle('invalid',!ok&&i.value!=='');field.classList.toggle('valid',ok&&i.value!=='');if(msg){const hint=msg.classList.contains('field-hint');if(ok){if(!hint)msg.innerHTML=rule==='confirm'?'<i class="fa-solid fa-check"></i> Passwords match':''}else{msg.textContent=r;msg.classList.remove('field-hint')}}return ok}
function initAuthValidation(){$$('form.auth-form').forEach(f=>{f.addEventListener('input',e=>{const field=e.target.closest('.field');if(field)checkField(field,f);if(f.id==='form-signup')updatePassRequirements(f)});f.addEventListener('focusout',e=>{const field=e.target.closest('.field');if(field)checkField(field,f)});f.addEventListener('submit',e=>{let ok=true;$$('.field',f).forEach(x=>{if(!checkField(x,f))ok=false});if(!ok){e.preventDefault();toast('<i class="fa-solid fa-triangle-exclamation"></i> Please fix the highlighted fields.');f.querySelector('.field.invalid input')?.focus()}})})}
function initNav(){const t=$('#navToggle'),l=$('#navLinks');if(t&&l){t.addEventListener('click',()=>{const o=l.classList.toggle('open');t.classList.toggle('open',o)});$$('a',l).forEach(a=>a.addEventListener('click',()=>{l.classList.remove('open');t.classList.remove('open')}))}const b=$('.topbar');if(b)window.addEventListener('scroll',()=>b.classList.toggle('scrolled',scrollY>8),{passive:true})}
function initProfileMenu(){const c=$('#profileChip'),m=$('#profileMenu');if(!c||!m)return;c.addEventListener('click',e=>{e.stopPropagation();m.classList.toggle('open')});document.addEventListener('click',e=>{if(!m.contains(e.target)&&!c.contains(e.target))m.classList.remove('open')})}
function initScrollSpy(){const ss=$$('main section[id]');if(!ss.length||!('IntersectionObserver'in window))return;const links=$$('.nav-links a.nav-a[href^="#"]');if(!links.length)return;const o=new IntersectionObserver(es=>es.forEach(en=>{if(en.isIntersecting)links.forEach(a=>a.classList.toggle('active',a.getAttribute('href')==='#'+en.target.id))}),{rootMargin:'-40% 0px -55% 0px'});ss.forEach(s=>o.observe(s))}
function initOrderToasts(){document.addEventListener('click',e=>{const a=e.target.closest('a[href^="tel:"]');if(a&&a.dataset.hotel)toast('<i class="fa-solid fa-phone"></i> Calling '+esc(a.dataset.hotel)+'...')})}
function footerYear(){const y=$('#year');if(y)y.textContent=new Date().getFullYear()}
})();


/* Live catalog/order refresh without manual page reload. */
(function(){
 if(window.FE_LIVE_REFRESH_INITIALIZED)return; window.FE_LIVE_REFRESH_INITIALIZED=true;
 let last='';
 async function refresh(){try{const r=await fetch('api.php?live='+Date.now(),{cache:'no-store'});if(!r.ok)return;const d=await r.json();const sig=JSON.stringify({dishes:d.dishes||[],mart:d.mart||[],hotels:d.hotels||[],contacts:d.contacts||[]});
  if(last&&sig!==last){if(typeof allDishes!=='undefined'){allDishes=d.dishes||[];if(document.querySelector('#menu-grid'))renderDishes(allDishes);if(document.querySelector('#hotels-grid'))renderHotels(d.hotels||[]);if(document.querySelector('#contact-grid'))renderContacts(d.contacts||[]);}window.dispatchEvent(new CustomEvent('lyaideu:datachanged',{detail:d}));}
  last=sig;const el=document.querySelector('[data-live-indicator]');if(el)el.classList.add('live-on');
 }catch(e){}}
 refresh();setInterval(refresh,5000);
})();
