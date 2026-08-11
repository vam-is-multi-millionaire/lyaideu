
window.FE_VERSION='v3';
(function(){'use strict';
const $=(s,c=document)=>c.querySelector(s), $$=(s,c=document)=>[...c.querySelectorAll(s)];
let currentCat='all',searchQuery='',allDishes=[]; const CART_KEY='fe_cart',FAV_KEY='fe_favorites';
const getCart=()=>JSON.parse(localStorage.getItem(CART_KEY)||'[]'); const saveCart=c=>{localStorage.setItem(CART_KEY,JSON.stringify(c));renderCart();};
const getFav=()=>JSON.parse(localStorage.getItem(FAV_KEY)||'[]'); const saveFav=f=>localStorage.setItem(FAV_KEY,JSON.stringify(f));
function esc(v){return String(v??'').replace(/[&<>\"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[ch]));}
function toast(msg){let e=$('.toast');if(!e){e=document.createElement('div');e.className='toast';document.body.appendChild(e)}e.textContent=msg;e.classList.add('show');clearTimeout(e._t);e._t=setTimeout(()=>e.classList.remove('show'),2800)}

document.addEventListener('DOMContentLoaded',()=>{
  initNav();initProfileMenu();initScrollSpy();initOrderToasts();initAuthTabs();initPasswordPeek();initAuthValidation();footerYear();initCart();
  if($('#menu-grid')||$('#checkoutForm'))fetch('data.json').then(r=>r.json()).then(d=>{
    allDishes=d.dishes||[];
    if($('#menu-grid')){renderDishes(allDishes);renderHotels(d.hotels||[]);renderContacts(d.contacts||[]);initMenuFilters();startLiveCatalogSync();}
    if($('#checkoutForm'))initCheckout();
  }).catch(()=>toast('⚠️ Could not load menu data.'));
});

function renderDishes(dishes){
  const grid=$('#menu-grid');if(!grid)return;const fav=getFav();
  grid.innerHTML=dishes.map(d=>{
    const id=Number(d.id),name=esc(d.name),hotel=esc(d.hotel),desc=esc(d.desc),tag=esc(d.tag),img=esc(d.img),cat=esc(d.cat),phone=esc(d.phone);
    return `<article class="dish-card reveal visible" data-id="${id}" data-cat="${cat}" data-search="${esc((d.name+' '+d.hotel+' '+d.cat+' '+d.desc).toLowerCase())}">
      <div class="dish-art"><img src="${img}" alt="${name}" loading="lazy">
      <button class="fav-btn ${fav.includes(id)?'active':''}" data-fav="${id}" type="button" aria-label="Favorite ${name}">${fav.includes(id)?'♥':'♡'}</button>
      ${tag?`<span class="dish-tag">${tag}</span>`:''}</div>
      <div class="dish-body"><div class="dish-top"><h3>${name}</h3></div>
      <p class="dish-hotel">🏨 ${hotel}</p><p class="dish-desc">${desc}</p>
      <div class="dish-foot"><span class="price"><small>Rs.</small> ${Number(d.price)||0}</span>
      <div class="dish-actions"><button class="btn-order add-cart" data-id="${id}" type="button">🛒 Add</button><a class="btn-call" href="tel:+977${phone}" data-hotel="${hotel}">📞</a></div></div></div></article>`;
  }).join('');
  $$('.add-cart').forEach(b=>b.addEventListener('click',()=>addToCart(Number(b.dataset.id))));
  $$('[data-fav]').forEach(b=>b.addEventListener('click',()=>toggleFav(Number(b.dataset.fav),b)));
  applyFilters();
}
function renderHotels(hotels){const g=$('#hotels-grid');if(!g)return;g.innerHTML=hotels.map(h=>`<div class="hotel-card reveal visible"><div class="hotel-avatar">${esc(h.emoji)}</div><div class="hotel-info"><h3>${esc(h.name)}</h3><p>${esc(h.type)}</p></div><a class="hotel-call" href="tel:+977${esc(h.phone)}">📞 ${esc(h.phone)}</a></div>`).join('')}
function renderContacts(cs){const g=$('#contact-grid');if(!g)return;g.innerHTML=cs.map(c=>`<div class="contact-card reveal visible"><span class="contact-ico">${esc(c.ico)}</span><h3>${esc(c.role)}</h3><p class="contact-person">${esc(c.person)}</p><a class="contact-num" href="tel:+977${esc(c.phone)}">${esc(c.phone)}</a><small>${esc(c.note)}</small></div>`).join('')}
function addToCart(id){const d=allDishes.find(x=>Number(x.id)===id);if(!d)return;let c=getCart(),r=c.find(x=>Number(x.id)===id);if(r)r.qty=Math.min(20,r.qty+1);else c.push({id,qty:1});saveCart(c);toast('🛒 '+d.name+' added to cart');openCart();}
function changeQty(id,delta){let c=getCart(),r=c.find(x=>Number(x.id)===id);if(!r)return;r.qty+=delta;if(r.qty<=0)c=c.filter(x=>Number(x.id)!==id);saveCart(c)}
function renderCart(){
  const box=$('#cartItems'),empty=$('#cartEmpty'),countEls=$$('.cart-count'),c=getCart();const count=c.reduce((a,x)=>a+x.qty,0);countEls.forEach(e=>e.textContent=count);if(!box)return;
  if(!c.length){box.innerHTML='';empty?.classList.add('show');$('#checkoutBtn')?.classList.add('disabled')}
  else{empty?.classList.remove('show');const map=new Map(allDishes.map(d=>[Number(d.id),d]));
    box.innerHTML=c.map(r=>{const d=map.get(Number(r.id));if(!d)return '';return `<div class="cart-item"><div><strong>${esc(d.name)}</strong><small>Rs. ${d.price} each</small></div><div class="qty"><button data-q="-1" data-id="${d.id}" type="button">−</button><b>${r.qty}</b><button data-q="1" data-id="${d.id}" type="button">+</button></div><strong>Rs. ${d.price*r.qty}</strong></div>`}).join('');
    $$('[data-q]').forEach(b=>b.addEventListener('click',()=>changeQty(Number(b.dataset.id),Number(b.dataset.q))));
    $('#checkoutBtn')?.classList.remove('disabled');
  }
  const sub=c.reduce((a,r)=>{const d=allDishes.find(x=>Number(x.id)===Number(r.id));return a+(d?Number(d.price)*r.qty:0)},0);
  if($('#cartSubtotal'))$('#cartSubtotal').textContent='Rs. '+sub;if($('#cartTotal'))$('#cartTotal').textContent='Rs. '+(sub+(sub?50:0));
}
function openCart(){const d=$('#cartDrawer'),o=$('#cartOverlay');if(d){d.classList.add('open');o?.classList.add('open')}}
function closeCart(){$('#cartDrawer')?.classList.remove('open');$('#cartOverlay')?.classList.remove('open')}
function initCart(){document.addEventListener('click',e=>{if(e.target.closest('.cart-open-btn'))openCart();if(e.target.closest('#cartClose')||e.target.closest('#cartOverlay'))closeCart();if(e.target.closest('#clearCart')){localStorage.removeItem(CART_KEY);renderCart();toast('Cart cleared')}});renderCart()}
function toggleFav(id,b){let f=getFav();if(f.includes(id)){f=f.filter(x=>x!==id);b.classList.remove('active');b.textContent='♡';toast('Removed from favorites')}else{f.push(id);b.classList.add('active');b.textContent='♥';toast('❤️ Added to favorites')}saveFav(f)}
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
  if(window.FE_LIVE_SYNC||!$('#menu-grid'))return; window.FE_LIVE_SYNC=true;
  let lastSignature='';
  const sync=()=>fetch('data.json?v='+Date.now(),{cache:'no-store'}).then(r=>r.json()).then(d=>{
    const sig=JSON.stringify([d.dishes||[],d.hotels||[],d.contacts||[]]);
    if(!lastSignature){lastSignature=sig;return;}
    if(sig!==lastSignature){
      lastSignature=sig;allDishes=d.dishes||[];
      renderDishes(allDishes);renderHotels(d.hotels||[]);renderContacts(d.contacts||[]);
      toast('✨ Menu updated automatically');
    }
  }).catch(()=>{});
  setInterval(sync,5000);
}
function initMenuFilters(){const chips=$$('.chip[data-cat]');chips.forEach(ch=>ch.addEventListener('click',()=>{chips.forEach(x=>x.classList.remove('active'));ch.classList.add('active');currentCat=ch.dataset.cat;applyFilters()}));$('#dishSearch')?.addEventListener('input',e=>{searchQuery=e.target.value.trim().toLowerCase();applyFilters()});$('#sortMenu')?.addEventListener('change',applyFilters)}
function initCheckout(){
  const form=$('#checkoutForm');if(!form)return;let promo='';
  function update(){
    const c=getCart(),map=new Map(allDishes.map(d=>[Number(d.id),d]));let sub=0;const box=$('#checkoutItems');
    if(!c.length){$('#checkoutEmpty')?.classList.add('show');form.style.display='none';return}
    $('#checkoutEmpty')?.classList.remove('show');
    box.innerHTML=c.map(r=>{const d=map.get(Number(r.id));if(!d)return '';const line=d.price*r.qty;sub+=line;return `<div class="checkout-item"><span>${esc(d.name)} × ${r.qty}</span><strong>Rs. ${line}</strong></div>`}).join('');
    const delivery=50,discount=(promo==='LYAIDEU'||promo==='FOODXPRESS')?50:0;$('#coSubtotal').textContent='Rs. '+sub;$('#coDelivery').textContent='Rs. '+Math.max(0,delivery-discount);$('#coTotal').textContent='Rs. '+Math.max(0,sub+delivery-discount);$('#cartJson').value=JSON.stringify(c);if($('#promoHidden'))$('#promoHidden').value=promo;
  }
  $('#promoBtn')?.addEventListener('click',()=>{promo=$('#promoInput').value.trim().toUpperCase();$('#promoMsg').textContent=(promo==='LYAIDEU'||promo==='FOODXPRESS')?'🎉 Free delivery applied!':'❌ Invalid demo code. Try LYAIDEU.';update()});
  form.addEventListener('submit',e=>{if(!getCart().length){e.preventDefault();toast('Your cart is empty.')}$('#cartJson').value=JSON.stringify(getCart());if($('#promoHidden'))$('#promoHidden').value=promo});update();
}
function switchTab(w){$$('.tab').forEach(t=>t.classList.toggle('active',t.dataset.show===w));$$('.auth-form').forEach(f=>f.classList.toggle('active',f.id==='form-'+w))}
function initAuthTabs(){if(window.FE_TABS_INLINE||!$('.tabs'))return;document.addEventListener('click',e=>{const t=e.target.closest('[data-show]');if(t){e.preventDefault();switchTab(t.dataset.show)}})}
function initPasswordPeek(){$$('.peek').forEach(b=>b.addEventListener('click',()=>{const i=b.parentElement.querySelector('input'),show=i.type==='password';i.type=show?'text':'password';b.textContent=show?'🙈':'👁'}))}
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
function checkField(field,form){const i=field.querySelector('input'),msg=field.querySelector('.field-msg'),rule=i?.dataset.validate;if(!rule||!validators[rule])return true;const r=validators[rule](i.value,form),ok=r===true;field.classList.toggle('invalid',!ok&&i.value!=='');field.classList.toggle('valid',ok&&i.value!=='');if(msg){const hint=msg.classList.contains('field-hint');if(ok){if(!hint)msg.textContent=rule==='confirm'?'✓ Passwords match':''}else{msg.textContent=r;msg.classList.remove('field-hint')}}return ok}
function initAuthValidation(){$$('form.auth-form').forEach(f=>{f.addEventListener('input',e=>{const field=e.target.closest('.field');if(field)checkField(field,f);if(f.id==='form-signup')updatePassRequirements(f)});f.addEventListener('focusout',e=>{const field=e.target.closest('.field');if(field)checkField(field,f)});f.addEventListener('submit',e=>{let ok=true;$$('.field',f).forEach(x=>{if(!checkField(x,f))ok=false});if(!ok){e.preventDefault();toast('⚠️ Please fix the highlighted fields.');f.querySelector('.field.invalid input')?.focus()}})})}
function initNav(){const t=$('#navToggle'),l=$('#navLinks');if(t&&l){t.addEventListener('click',()=>{const o=l.classList.toggle('open');t.classList.toggle('open',o)});$$('a',l).forEach(a=>a.addEventListener('click',()=>{l.classList.remove('open');t.classList.remove('open')}))}const b=$('.topbar');if(b)window.addEventListener('scroll',()=>b.classList.toggle('scrolled',scrollY>8),{passive:true})}
function initProfileMenu(){const c=$('#profileChip'),m=$('#profileMenu');if(!c||!m)return;c.addEventListener('click',e=>{e.stopPropagation();m.classList.toggle('open')});document.addEventListener('click',e=>{if(!m.contains(e.target)&&!c.contains(e.target))m.classList.remove('open')})}
function initScrollSpy(){const ss=$$('main section[id]');if(!ss.length||!('IntersectionObserver'in window))return;const o=new IntersectionObserver(es=>es.forEach(en=>{if(en.isIntersecting)$$('.nav-links a.nav-a').forEach(a=>a.classList.toggle('active',a.getAttribute('href')==='#'+en.target.id))}),{rootMargin:'-40% 0px -55% 0px'});ss.forEach(s=>o.observe(s))}
function initOrderToasts(){document.addEventListener('click',e=>{const a=e.target.closest('a[href^="tel:"]');if(a&&a.dataset.hotel)toast('📞 Calling '+a.dataset.hotel+'...')})}
function footerYear(){const y=$('#year');if(y)y.textContent=new Date().getFullYear()}
})();


/* Live catalog/order refresh without manual page reload. */
(function(){
 if(window.FE_LIVE_REFRESH_INITIALIZED)return; window.FE_LIVE_REFRESH_INITIALIZED=true;
 let last='';
 async function refresh(){try{const r=await fetch('data.json?live='+Date.now(),{cache:'no-store'});if(!r.ok)return;const d=await r.json();const sig=JSON.stringify({dishes:d.dishes||[],hotels:d.hotels||[],contacts:d.contacts||[],orders:d.orders||[]});
  if(last&&sig!==last){if(typeof allDishes!=='undefined'){allDishes=d.dishes||[];if(document.querySelector('#menu-grid'))renderDishes(allDishes);if(document.querySelector('#hotels-grid'))renderHotels(d.hotels||[]);if(document.querySelector('#contact-grid'))renderContacts(d.contacts||[]);}window.dispatchEvent(new CustomEvent('lyaideu:datachanged',{detail:d}));}
  last=sig;const el=document.querySelector('[data-live-indicator]');if(el)el.classList.add('live-on');
 }catch(e){}}
 refresh();setInterval(refresh,5000);
})();
