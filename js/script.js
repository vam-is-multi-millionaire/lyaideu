
window.FE_VERSION='v3';
(function(){'use strict';
const $=(s,c=document)=>c.querySelector(s), $$=(s,c=document)=>[...c.querySelectorAll(s)];
let currentCat='all',searchQuery='',allDishes=[],allMart=[],allOthers=[],allBeverages=[]; const CART_KEY='fe_cart',FAV_KEY='fe_favorites';
const getCart=()=>JSON.parse(localStorage.getItem(CART_KEY)||'[]'); const saveCart=c=>{localStorage.setItem(CART_KEY,JSON.stringify(c));renderCart();};
const getFav=()=>JSON.parse(localStorage.getItem(FAV_KEY)||'[]'); const saveFav=f=>localStorage.setItem(FAV_KEY,JSON.stringify(f));
let DELIVERY_CFG={fee_schedule:[50,90,120,140,160,180],time_schedule:[45,50,55,60,60,60],mart_minutes:15,time_min:45,time_max:60};
function esc(v){return String(v??'').replace(/[&<>\"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[ch]));}
const FA_ICONS={'🛵':'fa-motorcycle','📦':'fa-box','🍽️':'fa-utensils','🍕':'fa-pizza-slice','🥟':'fa-drumstick-bite','🍜':'fa-bowl-rice','🍢':'fa-utensils','🥠':'fa-cookie','🍔':'fa-burger','🥤':'fa-mug-saucer','🥭':'fa-apple-whole','🍛':'fa-bowl-food','🥩':'fa-bacon','🍗':'fa-drumstick-bite','📞':'fa-phone','🤝':'fa-handshake','☎️':'fa-phone','🌶':'fa-pepper-hot','🏨':'fa-hotel','📍':'fa-location-dot','✉️':'fa-envelope','💳':'fa-credit-card','📝':'fa-note-sticky','🔥':'fa-fire','💚':'fa-heart','❤️':'fa-heart','✅':'fa-circle-check','❌':'fa-circle-xmark','🕐':'fa-clock','🎉':'fa-champagne-glasses','⚠️':'fa-triangle-exclamation','⚡':'fa-bolt','🌐':'fa-globe','📊':'fa-chart-simple','👥':'fa-users','👤':'fa-user','🔒':'fa-lock','🔐':'fa-key','🎂':'fa-cake-candles','⚙️':'fa-gear','🔍':'fa-magnifying-glass','🛒':'fa-cart-shopping','👋':'fa-hand','🎬':'fa-film','🧾':'fa-receipt','🚀':'fa-rocket','✨':'fa-arrows-rotate'};
const FA_ICON_LOOKUP={};Object.keys(FA_ICONS).forEach(k=>{FA_ICON_LOOKUP[k.replace(/\uFE0F/g,'')]=FA_ICONS[k];});
function faIcon(v){v=(v||'').replace(/\uFE0F/g,'').trim();if(!v)return '<i class="fa-solid fa-utensils"></i>';if(/^(fa-solid|fa-regular|fa-brands)\s+/.test(v)||/^fa-/.test(v)){const cls=/^(fa-solid|fa-regular|fa-brands)\s+/.test(v)?v:'fa-solid '+v;return '<i class="'+cls.replace(/\s+/g,' ').trim()+'"></i>';}return FA_ICON_LOOKUP[v]?'<i class="fa-solid '+FA_ICON_LOOKUP[v]+'"></i>':'<i class="fa-solid fa-utensils"></i>';}
function emojisToIcons(s){s=String(s||'').replace(/\uFE0F/g,'');return s.replace(/[\u{1F000}-\u{1FAFF}\u2600-\u27BF\u2B00-\u2BFF]/gu,m=>FA_ICON_LOOKUP[m]?'<i class="fa-solid '+FA_ICON_LOOKUP[m]+'"></i>':m);}
function toast(msg){let e=$('.toast');if(!e){e=document.createElement('div');e.className='toast';document.body.appendChild(e)}e.innerHTML=msg;e.classList.add('show');clearTimeout(e._t);e._t=setTimeout(()=>e.classList.remove('show'),2800)}
function cartNavActive(on){const b=document.getElementById('bottomNav');if(!b)return;const c=b.querySelector('[data-nav="cart"]');if(c)c.classList.toggle('active',!!on)}
function mobileBasePath(){try{const b=document.querySelector('base');if(b&&b.getAttribute('href'))return new URL(b.getAttribute('href'),window.location.href).pathname.replace(/\/+$/,'');}catch(e){}const p=(location.pathname||'').replace(/\/+$/,'');const i=p.lastIndexOf('/');return i>0?p.substring(0,i):'';}
function initMobileNav(){
  const body=document.body;
  if(!body||body.classList.contains('auth-body')||body.classList.contains('admin-body')||body.classList.contains('delivery-body'))return;
  if(document.getElementById('bottomNav'))return;
  const path=(location.pathname||'').replace(/\/+$/,'').toLowerCase();
  if(/\/login$/.test(path)||/\/admin($|\/)/.test(path)||/\/rider($|\/)/.test(path)||/\/vendor($|\/)/.test(path))return;
  const nav=document.createElement('nav');
  nav.id='bottomNav';
  nav.className='bottom-nav';
  nav.setAttribute('aria-label','Primary mobile navigation');
  nav.innerHTML=
    '<a class="bn-item" data-nav="home" href="index"><span class="bn-ico"><i class="fa-solid fa-house"></i></span><span class="bn-label">Home</span></a>'+
    '<a class="bn-item" data-nav="categories" href="categories"><span class="bn-ico"><i class="fa-solid fa-layer-group"></i></span><span class="bn-label">Categories</span></a>'+
    '<a class="bn-item" data-nav="stores" href="store"><span class="bn-ico"><i class="fa-solid fa-store"></i></span><span class="bn-label">Stores</span></a>'+
    '<button class="bn-item cart-open-btn" data-nav="cart" type="button" aria-label="Open cart"><span class="bn-ico"><i class="fa-solid fa-cart-shopping"></i><span class="bn-count cart-count">0</span></span><span class="bn-label">Cart</span></button>'+
    '<a class="bn-item" data-nav="profile" href="profile"><span class="bn-ico"><i class="fa-solid fa-user"></i></span><span class="bn-label">Profile</span></a>';
  document.body.appendChild(nav);
  const cartBtn=nav.querySelector('[data-nav="cart"]');
  if(cartBtn)cartBtn.addEventListener('click',e=>{if(!document.getElementById('cartDrawer')){e.preventDefault();e.stopPropagation();window.location.href='checkout';}});
  const leaf=path.split('/').pop();
  const base=mobileBasePath();
  let key='';
  if(path===base||leaf==='index'||leaf==='index.php')key='home';
  else if(leaf==='categories')key='categories';
  else if(leaf==='store'||(base&&path.indexOf(base+'/store')===0))key='stores';
  else if(leaf==='profile'||leaf==='orders')key='profile';
  if(key){const el=nav.querySelector('[data-nav="'+key+'"]');if(el)el.classList.add('active');}
}

document.addEventListener('DOMContentLoaded',()=>{
  initMobileNav();initNav();initProfileMenu();initScrollSpy();initOrderToasts();initAuthTabs();initPasswordPeek();initAuthValidation();footerYear();initCart();initAddCart();initFeaturedGrid();initHeroSlider();
  if($('#menu-grid')||$('#mart-grid')||$('#others-grid')||$('#beverages-grid')||$('#hotels-grid')||$('#contact-grid')||$('#checkoutForm')||$('#featuredDishes')||$('#featuredMart')||$('#featuredBeverages')||document.body.hasAttribute('data-needs-catalog'))fetch('api').then(r=>r.json()).then(d=>{
    allDishes=d.dishes||[];
    allMart=d.mart||[];
    allOthers=d.others||[];
    allBeverages=d.beverages||[];
    if(d.delivery)DELIVERY_CFG=d.delivery;
    try{
      renderCart();
      if($('#menu-grid')){renderDishes(allDishes);initMenuFilters();}
      if($('#mart-grid')){renderMart(allMart);initMartFilters();}
      if($('#others-grid')){renderOthers(allOthers);initOthersFilters();}
      if($('#beverages-grid')){renderBeverages(allBeverages);initBeveragesFilters();}
      if($('#hotels-grid')){renderHotels(d.hotels||[]);initHotelFilters();}
      if($('#contact-grid'))renderContacts(d.contacts||[]);
      if($('#menu-grid')||$('#mart-grid')||$('#others-grid')||$('#beverages-grid')||$('#hotels-grid')||$('#contact-grid'))startLiveCatalogSync();
      if($('#checkoutForm'))initCheckout();
    }catch(err){
      console.error('Catalog render error:',err);
      toast('<i class="fa-solid fa-triangle-exclamation"></i> Could not display catalog items.');
    }
  }).catch(()=>toast('<i class="fa-solid fa-triangle-exclamation"></i> Could not load catalog data.'));
});

function renderDishes(dishes){
  const grid=$('#menu-grid');if(!grid)return;const fav=getFav();
  grid.innerHTML=dishes.map(d=>{
    const id=Number(d.id),name=esc(d.name),hotel=esc(d.hotel),desc=esc(d.desc),tag=esc(d.tag),img=esc(d.img),cat=esc(d.cat),phone=esc(d.phone);
    const cats=(d.cats&&d.cats.length)?d.cats.map(esc):[cat];
    const slug=d.slug||slugify(d.name);
    const art=img?`<img src="${img}" alt="${name}" loading="lazy">`:`<span class="dish-art-ico"><i class="fa-solid fa-utensils"></i></span>`;
    return `<article class="dish-card reveal visible" data-id="${id}" data-slug="${slug}" data-cat="${cat}" data-cats="${cats.join(',')}" data-search="${esc((d.name+' '+d.hotel+' '+d.cat+' '+d.desc).toLowerCase())}">
      <div class="dish-art">${art}
      </div>
      <div class="dish-body"><div class="dish-top"><h3>${name}</h3></div>
      <div class="dish-foot"><span class="price"><small>Rs.</small> ${Number(d.price)||0}</span>
      <button class="btn-order add-cart" data-id="${id}" data-hotel="${hotel}"${d.has_variants?' data-has-variants="1"':''} type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button></div></div></article>`;
  }).join('');
  $$('#menu-grid .dish-card').forEach(c=>c.addEventListener('click',e=>{if(e.target.closest('.btn-order'))return;window.location.href=productUrl('dish',c.dataset.slug,(c.dataset.cats||'').split(','))}));
  applyFilters();
}
function storeKindLabel(k){k=(k||'hotel').toLowerCase();return k==='mart'?'Mart':k==='other'?'Other':k==='beverage'?'Beverages':'Hotel'}
function renderHotels(hotels){const g=$('#hotels-grid');if(!g)return;g.innerHTML=hotels.map(h=>{const logo=esc(h.logo)||'';const kind=(h.kind||'hotel').toLowerCase();const storeUrl='store/'+slugify(h.name);const vendor=kind==='hotel'&&h.vendor_name?`<p class="hotel-vendor"><i class="fa-solid fa-store"></i> Kitchen: ${esc(h.vendor_name)}</p>`:'';const call=`<div class="hotel-call-row">${h.phone?`<a class="hotel-call" href="tel:+977${esc(h.phone)}"><i class="fa-solid fa-phone"></i> Call</a>`:''}<a class="hotel-call" href="${storeUrl}"><i class="fa-solid fa-store"></i> View Store</a></div>`;return `<div class="hotel-card reveal visible" data-kind="${kind}" data-store-url="${storeUrl}" data-search="${esc((h.name+' '+h.type+' '+storeKindLabel(kind)+' '+(h.hotel||'')+' '+(h.location||'')+' '+(h.vendor_name||'')).toLowerCase())}"><div class="hotel-avatar">${logo?`<img class="hotel-logo" src="${logo}" alt="${esc(h.name)}" loading="lazy">`:faIcon(h.emoji)}</div><div class="hotel-info"><span class="hotel-kind-badge">${storeKindLabel(kind)}</span><h3>${esc(h.name)}</h3><p>${esc(h.type)}</p>${vendor}</div>${call}</div>`}).join('');applyHotelFilters()}
document.addEventListener('click',e=>{const card=e.target.closest('.hotel-card[data-store-url]');if(!card)return;if(e.target.closest('.hotel-call'))return;const u=card.dataset.storeUrl;if(u)window.location.href=u})
let currentStoreKind='all';
function applyHotelFilters(){
  const cards=$$('#hotels-grid .hotel-card'),q=($('#hotelSearch')?.value||$('.nav-search input[name=q]')?.value||'').trim().toLowerCase();
  cards.forEach(c=>c.style.display=((!q||c.dataset.search.includes(q))&&(currentStoreKind==='all'||c.dataset.kind===currentStoreKind))?'':'none');
  if($('#hotelsEmpty'))$('#hotelsEmpty').style.display=(!q||cards.some(c=>c.style.display!=='none'))?'none':'block';
}
function initHotelFilters(){const s=$('#hotelSearch')||$('.nav-search input[name=q]');if(s)s.addEventListener('input',applyHotelFilters);const chips=$$('#storeKinds .chip');chips.forEach(ch=>ch.addEventListener('click',()=>{chips.forEach(x=>x.classList.remove('active'));ch.classList.add('active');currentStoreKind=ch.dataset.skind||'all';applyHotelFilters()}))}
function renderContacts(cs){const g=$('#contact-grid');if(!g)return;g.innerHTML=cs.map(c=>`<div class="contact-card reveal visible"><span class="contact-ico">${faIcon(c.ico)}</span><h3>${esc(c.role)}</h3><p class="contact-person">${esc(c.person)}</p><a class="contact-num" href="tel:+977${esc(c.phone)}">${esc(c.phone)}</a><small>${esc(c.note)}</small></div>`).join('')}
const MART_CAT_ICONS={'vegetables':'fa-carrot','fruits':'fa-apple-whole','dairy':'fa-cow','staples':'fa-bowl-rice','oils':'fa-mortar-pestle','snacks':'fa-cookie'};
const OTHER_CAT_ICONS={'flowers':'fa-bouquet','candles':'fa-candle-holder','achar':'fa-jar','gifts':'fa-gift'};
const BEVERAGE_CAT_ICONS={'cold-drinks':'fa-glass-water','alcohol':'fa-champagne-glasses','water':'fa-faucet-drip'};
function martCatIcon(cat){const ic=MART_CAT_ICONS[cat]||'fa-basket-shopping';return '<i class="fa-solid '+ic+'"></i>';}
function otherCatIcon(cat){const ic=OTHER_CAT_ICONS[cat]||'fa-gift';return '<i class="fa-solid '+ic+'"></i>';}
function beverageCatIcon(cat){const ic=BEVERAGE_CAT_ICONS[cat]||'fa-glass-water';return '<i class="fa-solid '+ic+'"></i>';}
function renderMart(items){
  const grid=$('#mart-grid');if(!grid)return;
  grid.innerHTML=items.map(m=>{
    const id=Number(m.id),name=esc(m.name),desc=esc(m.desc),tag=esc(m.tag),img=esc(m.img),cat=esc(m.cat),unit=esc(m.unit);
    const cats=(m.cats&&m.cats.length)?m.cats.map(esc):[cat];
    const slug=m.slug||slugify(m.name);
    const art=img?`<img src="${img}" alt="${name}" loading="lazy">`:martCatIcon(cat);
    return `<article class="dish-card reveal visible" data-id="${id}" data-slug="${slug}" data-cat="${cat}" data-cats="${cats.join(',')}" data-search="${esc((m.name+' '+m.cat+' '+m.desc+' '+m.unit).toLowerCase())}">
      <div class="dish-art mart-art">${art}
      ${tag?`<span class="dish-tag">${tag}</span>`:''}
      </div>
      <div class="dish-body"><div class="dish-top"><h3>${name}</h3></div>
      <div class="dish-foot"><span class="price"><small>Rs.</small> ${Number(m.price)||0}${unit?` <span class="unit">/ ${unit}</span>`:''}</span>
      <button class="btn-order add-cart" data-id="${id}" data-type="mart" data-name="${name}" data-price="${Number(m.price)||0}" data-unit="${unit}" data-hotel="${esc(m.hotel||'')}"${m.has_variants?' data-has-variants="1"':''} type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button></div></div></article>`;
  }).join('');
  $$('#mart-grid .dish-card').forEach(c=>c.addEventListener('click',e=>{if(e.target.closest('.btn-order'))return;window.location.href=productUrl('mart',c.dataset.slug,(c.dataset.cats||'').split(','))}));
  applyMartFilters();
}
function renderOthers(items){
  const grid=$('#others-grid');if(!grid)return;
  grid.innerHTML=items.map(m=>{
    const id=Number(m.id),name=esc(m.name),desc=esc(m.desc),tag=esc(m.tag),img=esc(m.img),cat=esc(m.cat),unit=esc(m.unit);
    const cats=(m.cats&&m.cats.length)?m.cats.map(esc):[cat];
    const slug=m.slug||slugify(m.name);
    const art=img?`<img src="${img}" alt="${name}" loading="lazy">`:otherCatIcon(cat);
    return `<article class="dish-card reveal visible" data-id="${id}" data-slug="${slug}" data-cat="${cat}" data-cats="${cats.join(',')}" data-search="${esc((m.name+' '+m.cat+' '+m.desc+' '+m.unit).toLowerCase())}">
      <div class="dish-art mart-art">${art}
      ${tag?`<span class="dish-tag">${tag}</span>`:''}
      </div>
      <div class="dish-body"><div class="dish-top"><h3>${name}</h3></div>
      <div class="dish-foot"><span class="price"><small>Rs.</small> ${Number(m.price)||0}${unit?` <span class="unit">/ ${unit}</span>`:''}</span>
      <button class="btn-order add-cart" data-id="${id}" data-type="other" data-name="${name}" data-price="${Number(m.price)||0}" data-unit="${unit}" data-hotel="${esc(m.hotel||'')}"${m.has_variants?' data-has-variants="1"':''} type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button></div></div></article>`;
  }).join('');
  $$('#others-grid .dish-card').forEach(c=>c.addEventListener('click',e=>{if(e.target.closest('.btn-order'))return;window.location.href=productUrl('other',c.dataset.slug,(c.dataset.cats||'').split(','))}));
  applyOthersFilters();
}
function applyOthersFilters(){
  const cards=$$('#others-grid .dish-card'),sort=$('#sortOthers')?.value||'default';
  let filtered=cards.filter(c=>(currentCat==='all'||catMatch(c))&&(!searchQuery||c.dataset.search.includes(searchQuery)));
  if(sort==='price-low'||sort==='price-high'){
    filtered.sort((a,b)=>{
      const pa=Number(allOthers.find(d=>String(d.id)===a.dataset.id)?.price)||0;
      const pb=Number(allOthers.find(d=>String(d.id)===b.dataset.id)?.price)||0;
      return sort==='price-low' ? pa-pb : pb-pa;
    });
  }
  const grid=$('#others-grid');
  if(grid){
    filtered.forEach(card=>grid.appendChild(card));
    cards.filter(c=>!filtered.includes(c)).forEach(card=>card.style.display='none');
    filtered.forEach(card=>card.style.display='');
  }
  if($('#othersEmpty'))$('#othersEmpty').style.display=filtered.length?'none':'block';
}
function initOthersFilters(){const chips=$$('.chip[data-ocat]');chips.forEach(ch=>ch.addEventListener('click',()=>{chips.forEach(x=>x.classList.remove('active'));ch.classList.add('active');currentCat=ch.dataset.ocat;syncSubChips('other');applyOthersFilters()}));const s=$('#othersSearch')||$('.nav-search input[name=q]');if(s){searchQuery=(s.value||'').trim().toLowerCase();s.addEventListener('input',e=>{searchQuery=e.target.value.trim().toLowerCase();applyOthersFilters()})}$('#sortOthers')?.addEventListener('change',applyOthersFilters);const p=new URLSearchParams(location.search);const uq=(p.get('q')||'').trim().toLowerCase();if(uq)searchQuery=uq;const oc=p.get('ocat');if(oc){const t=document.querySelector('.chip[data-ocat="'+oc+'"]');if(t)t.click();else applyOthersFilters()}else applyOthersFilters()}
function renderBeverages(items){
  const grid=$('#beverages-grid');if(!grid)return;
  grid.innerHTML=items.map(m=>{
    const id=Number(m.id),name=esc(m.name),desc=esc(m.desc),tag=esc(m.tag),img=esc(m.img),cat=esc(m.cat),unit=esc(m.unit);
    const cats=(m.cats&&m.cats.length)?m.cats.map(esc):[cat];
    const slug=m.slug||slugify(m.name);
    const art=img?`<img src="${img}" alt="${name}" loading="lazy">`:beverageCatIcon(cat);
    return `<article class="dish-card reveal visible" data-id="${id}" data-slug="${slug}" data-cat="${cat}" data-cats="${cats.join(',')}" data-search="${esc((m.name+' '+m.cat+' '+m.desc+' '+m.unit).toLowerCase())}">
      <div class="dish-art mart-art">${art}
      ${tag?`<span class="dish-tag">${tag}</span>`:''}
      </div>
      <div class="dish-body"><div class="dish-top"><h3>${name}</h3></div>
      <div class="dish-foot"><span class="price"><small>Rs.</small> ${Number(m.price)||0}${unit?` <span class="unit">/ ${unit}</span>`:''}</span>
      <button class="btn-order add-cart" data-id="${id}" data-type="beverage" data-name="${name}" data-price="${Number(m.price)||0}" data-unit="${unit}" data-hotel="${esc(m.hotel||'')}"${m.has_variants?' data-has-variants="1"':''} type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button></div></div></article>`;
  }).join('');
  $$('#beverages-grid .dish-card').forEach(c=>c.addEventListener('click',e=>{if(e.target.closest('.btn-order'))return;window.location.href=productUrl('beverage',c.dataset.slug,(c.dataset.cats||'').split(','))}));
  applyBeveragesFilters();
}
function applyBeveragesFilters(){
  const cards=$$('#beverages-grid .dish-card'),sort=$('#sortBeverages')?.value||'default';
  let filtered=cards.filter(c=>(currentCat==='all'||catMatch(c))&&(!searchQuery||c.dataset.search.includes(searchQuery)));
  if(sort==='price-low'||sort==='price-high'){
    filtered.sort((a,b)=>{
      const pa=Number(allBeverages.find(d=>String(d.id)===a.dataset.id)?.price)||0;
      const pb=Number(allBeverages.find(d=>String(d.id)===b.dataset.id)?.price)||0;
      return sort==='price-low' ? pa-pb : pb-pa;
    });
  }
  const grid=$('#beverages-grid');
  if(grid){
    filtered.forEach(card=>grid.appendChild(card));
    cards.filter(c=>!filtered.includes(c)).forEach(card=>card.style.display='none');
    filtered.forEach(card=>card.style.display='');
  }
  if($('#beveragesEmpty'))$('#beveragesEmpty').style.display=filtered.length?'none':'block';
}
function initBeveragesFilters(){const chips=$$('.chip[data-bcat]');chips.forEach(ch=>ch.addEventListener('click',()=>{chips.forEach(x=>x.classList.remove('active'));ch.classList.add('active');currentCat=ch.dataset.bcat;syncSubChips('beverage');applyBeveragesFilters()}));const s=$('#beveragesSearch')||$('.nav-search input[name=q]');if(s){searchQuery=(s.value||'').trim().toLowerCase();s.addEventListener('input',e=>{searchQuery=e.target.value.trim().toLowerCase();applyBeveragesFilters()})}$('#sortBeverages')?.addEventListener('change',applyBeveragesFilters);const p=new URLSearchParams(location.search);const uq=(p.get('q')||'').trim().toLowerCase();if(uq)searchQuery=uq;const bc=p.get('bcat');if(bc){const t=document.querySelector('.chip[data-bcat="'+bc+'"]');if(t)t.click();else applyBeveragesFilters()}else applyBeveragesFilters()}
function catMatch(card){const s=card.dataset.cats;return s?s.split(',').includes(currentCat):card.dataset.cat===currentCat}
function slugify(s){return (s||'').toString().replace(/&amp;/g,'&').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'')||'item'}
function productUrl(type,slug,cats){type=type||'dish';cats=(cats||[]).map(x=>String(x).trim()).filter(Boolean);const p=cats.length?cats.map(x=>encodeURIComponent(x)).join('/')+'/':'';return (type==='mart'?'mart':(type==='other'?'others':(type==='beverage'?'beverages':'menu')))+'/'+p+(slug||slugify('item'))}
function applyMartFilters(){
  const cards=$$('#mart-grid .dish-card'),sort=$('#sortMart')?.value||'default';
  let filtered=cards.filter(c=>(currentCat==='all'||catMatch(c))&&(!searchQuery||c.dataset.search.includes(searchQuery)));
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
function syncSubChips(scope){
  const attr=scope==='mart'?'mcat':(scope==='other'?'ocat':(scope==='beverage'?'bcat':'cat'));
  $$('.chip[data-'+attr+'][data-parent]').forEach(ch=>{
    const parent=ch.dataset.parent;
    const pc=document.querySelector('.chip[data-'+attr+'="'+parent+'"]');
    ch.style.display=((pc&&pc.classList.contains('active'))||ch.classList.contains('active'))?'':'none';
  });
}
function initMartFilters(){const chips=$$('.chip[data-mcat]');chips.forEach(ch=>ch.addEventListener('click',()=>{chips.forEach(x=>x.classList.remove('active'));ch.classList.add('active');currentCat=ch.dataset.mcat;syncSubChips('mart');applyMartFilters()}));const s=$('#martSearch')||$('.nav-search input[name=q]');if(s){searchQuery=(s.value||'').trim().toLowerCase();s.addEventListener('input',e=>{searchQuery=e.target.value.trim().toLowerCase();applyMartFilters()})}$('#sortMart')?.addEventListener('change',applyMartFilters);const p=new URLSearchParams(location.search);const uq=(p.get('q')||'').trim().toLowerCase();if(uq)searchQuery=uq;const mc=p.get('mcat');if(mc){const t=document.querySelector('.chip[data-mcat="'+mc+'"]');if(t)t.click();else applyMartFilters()}else applyMartFilters()}
function findItem(id,type){type=type||'dish';const pool=type==='mart'?allMart:(type==='other'?allOthers:(type==='beverage'?allBeverages:allDishes));return (pool||[]).find(x=>Number(x.id)===Number(id))||null}
function shopOfItem(d){if(!d)return'Other';const h=String(d.hotel||'').trim();if(h)return h;if(d.type==='mart')return'LyaiDeu Mart';if(d.type==='other')return'LyaiDeu Others';if(d.type==='beverage')return'LyaiDeu Beverages';return'Other'}
function cartShops(c){c=c||[];return[...new Set(c.map(x=>x.shop||shopOfItem(findItem(x.id,x.type)||x)))].filter(Boolean)}
function cartHasHotel(c){c=c||[];return c.some(r=>(r.type||'dish')==='dish')}
function deliveryFeeFor(n){const f=DELIVERY_CFG.fee_schedule||[];n=Math.max(1,n|0);if(!f.length)return 50;if(n<=f.length)return f[n-1]|0;const last=f[f.length-1]|0,prev=f[f.length-2]|0,delta=last-prev;return Math.max(0,last+(n-f.length)*delta)}
function deliveryEtaFor(n,hasHotel){if(hasHotel===false)return DELIVERY_CFG.mart_minutes||15;const t=DELIVERY_CFG.time_schedule||[];n=Math.max(1,n|0);let eta;if(!t.length)eta=30;else if(n<=t.length)eta=t[n-1]|0;else{const last=t[t.length-1]|0,prev=t[t.length-2]|0,delta=last-prev;eta=Math.max(0,last+(n-t.length)*delta)}const lo=DELIVERY_CFG.time_min||45,hi=DELIVERY_CFG.time_max||60;return Math.max(lo,Math.min(hi,eta))}
function showVendorModal(n,fee,eta){
  let o=$('#vendorModalOverlay');
  if(!o){o=document.createElement('div');o.id='vendorModalOverlay';o.className='modal-overlay';o.innerHTML='<div class="modal-box"><h3 class="modal-title"><i class="fa-solid fa-store"></i> Multi-vendor order</h3><div class="modal-body"></div><div class="modal-actions"><button type="button" class="btn btn-primary" id="vendorModalOk"><i class="fa-solid fa-check"></i> Got it</button></div></div>';document.body.appendChild(o);o.addEventListener('click',e=>{if(e.target===o||e.target.closest('#vendorModalOk'))hideVendorModal()});}
  o.querySelector('.modal-body').innerHTML='<p>Your cart now includes items from <b>'+esc(n)+' different vendors</b>. Each one needs its own prep time, so the whole delivery takes longer and costs a little more.</p><ul><li><i class="fa-solid fa-clock"></i> Estimated delivery: about <b>'+eta+' minutes</b></li><li><i class="fa-solid fa-wallet"></i> Delivery fee: <b>Rs. '+fee+'</b></li></ul><p class="small-note">You can keep adding from any hotel or the Mart — we deliver it all together. Remove items anytime from your cart.</p>';
  o.classList.add('show');
}
function hideVendorModal(){const o=$('#vendorModalOverlay');if(o)o.classList.remove('show')}
function addToCart(id,type,openDrawer,btn){let d=findItem(id,type);if(!d&&btn){d={id:Number(id),name:btn.dataset.name||'Item',price:Number(btn.dataset.price)||0,unit:btn.dataset.unit||'',img:btn.dataset.img||'',hotel:btn.dataset.hotel||'',type:type||'dish'};(type==='mart'?allMart:(type==='other'?allOthers:allDishes)).push(d);}if(!d)return;id=Number(id);type=type||'dish';const variant=(btn&&btn.dataset.variant)||'';let c=getCart();const before=cartShops(c);const shop=shopOfItem(d);let i=c.find(x=>Number(x.id)===id&&(x.type||'dish')===type&&(x.variant||'')===variant);if(i){i.qty=Math.min(20,i.qty+1);i.name=d.name;i.price=Number(btn&&btn.dataset.price?btn.dataset.price:d.price)||0;i.unit=d.unit||'';i.shop=shop;}else c.push({id,type,qty:1,name:d.name,price:Number(btn&&btn.dataset.price?btn.dataset.price:d.price)||0,unit:d.unit||'',shop,variant});saveCart(c);const after=cartShops(c);if(after.length>1&&!before.includes(shop)){const n=after.length;showVendorModal(n,deliveryFeeFor(n),deliveryEtaFor(n,cartHasHotel(c)));toast('<i class="fa-solid fa-store"></i> Ordering from <b>'+n+' vendors</b> — about '+deliveryEtaFor(n,cartHasHotel(c))+' min delivery · Rs. '+deliveryFeeFor(n));}else{toast('<i class="fa-solid fa-cart-shopping"></i> '+esc(d.name)+' added to cart');}if(openDrawer!==false)openCart();}
function changeQty(id,type,delta,variant){type=type||'dish';variant=variant||'';let c=getCart(),i=c.find(x=>Number(x.id)===Number(id)&&(x.type||'dish')===type&&(x.variant||'')===variant);if(!i)return;id=Number(id);i.qty+=delta;if(i.qty<=0)c=c.filter(x=>!(Number(x.id)===id&&(x.type||'dish')===type&&(x.variant||'')===variant));saveCart(c)}
function renderCart(){
  const box=$('#cartItems'),empty=$('#cartEmpty'),countEls=$$('.cart-count'),c=getCart();const count=c.length;countEls.forEach(e=>e.textContent=count);if(!box)return;
  if(!c.length){box.innerHTML='';empty?.classList.add('show');$('#checkoutBtn')?.classList.add('disabled')}
  else{empty?.classList.remove('show');
    const shops=cartShops(c);
    const groups={};c.forEach(r=>{const d=findItem(r.id,r.type)||r;if(!d)return;const s=r.shop||shopOfItem(d);(groups[s]=groups[s]||[]).push(r)});
    let html='';
    if(shops.length>1){html+='<div class="cart-eta-note"><i class="fa-solid fa-store"></i> <b>'+shops.length+' vendors</b> about <b>'+deliveryEtaFor(shops.length,cartHasHotel(c))+' min</b> delivery · <b>Rs. '+deliveryFeeFor(shops.length)+'</b> fee</div>'}
    Object.keys(groups).forEach(s=>{
      html+='<div class="cart-shop"><i class="fa-solid fa-store"></i> '+esc(s)+'</div>';
      html+=groups[s].map(r=>{const d=findItem(r.id,r.type)||r;if(!d)return '';const unit=esc(d.unit||'');const price=Number(r.variant?r.price:d.price)||0;const variant=r.variant?` <em class="vp-variant">(${esc(r.variant)})</em>`:'';return `<div class="cart-item"><div><strong>${esc(d.name)}${variant}</strong><small>Rs. ${price} ${unit?unit+' ':''}each</small></div><div class="qty"><button data-q="-1" data-id="${d.id}" data-type="${r.type||'dish'}" data-variant="${esc(r.variant||'')}" type="button">−</button><b>${r.qty}</b><button data-q="1" data-id="${d.id}" data-type="${r.type||'dish'}" data-variant="${esc(r.variant||'')}" type="button">+</button></div><strong>Rs. ${price*r.qty}</strong></div>`}).join('');
    });
    box.innerHTML=html;
    $$('[data-q]').forEach(b=>b.addEventListener('click',()=>changeQty(Number(b.dataset.id),b.dataset.type,Number(b.dataset.q),b.dataset.variant)));
    $('#checkoutBtn')?.classList.remove('disabled');
  }
  const sub=c.reduce((a,r)=>{const d=findItem(r.id,r.type)||r;return a+(d?Number(r.variant?r.price:d.price)*r.qty:0)},0);
  const fee=c.length?deliveryFeeFor(cartShops(c).length):0;
  if($('#cartSubtotal'))$('#cartSubtotal').textContent='Rs. '+sub;
  if($('#cartDelivery'))$('#cartDelivery').textContent='Rs. '+fee;
  if($('#cartTotal'))$('#cartTotal').textContent='Rs. '+(sub+fee);
}
function cartBell(show){const b=document.getElementById('notifyBell');if(b)b.style.visibility=show?'':'hidden'}
function openCart(){const d=$('#cartDrawer'),o=$('#cartOverlay');if(d){d.classList.add('open');o?.classList.add('open');cartBell(false)}cartNavActive(!!d)}
function closeCart(){$('#cartDrawer')?.classList.remove('open');$('#cartOverlay')?.classList.remove('open');cartBell(true);cartNavActive(false)}
function initCart(){document.addEventListener('click',e=>{if(e.target.closest('.cart-open-btn'))openCart();if(e.target.closest('#cartClose')||e.target.closest('#cartOverlay'))closeCart();if(e.target.closest('#clearCart')){localStorage.removeItem(CART_KEY);renderCart();toast('Cart cleared')}});renderCart()}
function initAddCart(){document.addEventListener('click',e=>{const b=e.target.closest('.add-cart');if(!b)return;const id=Number(b.dataset.id),type=b.dataset.type||'dish';const d=findItem(id,type);const hasVariants=!!(b.dataset.hasVariants||(d&&d.has_variants));if(hasVariants&&!b.dataset.variant){const card=b.closest('.dish-card')||b.closest('.related-card');const cardUrl=card?card.dataset.url:'';let url=cardUrl;if(!url){const slug=(d&&d.slug)||b.dataset.slug||slugify(b.dataset.name||'item');url=productUrl(type,slug,(d&&d.cats)||(b.dataset.cats||'').split(','));}if(url){window.location.href=url;return;}}addToCart(id,type,undefined,b)})}
function initFeaturedGrid(){document.addEventListener('click',e=>{const card=e.target.closest('.home-grid .dish-card');if(!card)return;if(e.target.closest('.add-cart')||e.target.closest('a'))return;window.location.href=productUrl(card.dataset.type||'dish',card.dataset.slug||slugify(card.dataset.name),(card.dataset.cats||'').split(','))})}
function initHeroSlider(){
  const wrap=$('#heroSlides');if(!wrap)return;
  const slides=$$('.hero-slide',wrap),dotsBox=$('#heroDots');
  if(slides.length<2)return;
  const total=slides.length;
  const cloneLast=slides[total-1].cloneNode(true),cloneFirst=slides[0].cloneNode(true);
  wrap.insertBefore(cloneLast,slides[0]);
  wrap.appendChild(cloneFirst);
  const count=slides.length+2;
  let cur=1,timer=null,dragging=false,startX=0,deltaX=0;
  slides.forEach((_,i)=>{const b=document.createElement('button');b.type='button';b.setAttribute('aria-label','Slide '+(i+1));b.addEventListener('click',()=>{go(i+1);restart()});if(i===0)b.classList.add('active');dotsBox?.appendChild(b)});
  const dots=$$('button',dotsBox);
  function render(){wrap.style.transition=dragging?'none':'';wrap.style.transform='translateX('+(-cur*100)+'%)'+(deltaX?' translateX('+deltaX+'px)':'')}
  function snap(offset){
    wrap.style.transition='none';
    wrap.style.transform='translateX('+(-offset*100)+'%)';
    void wrap.offsetWidth;
    wrap.style.transition='';
  }
  function go(i){
    cur=i;deltaX=0;render();
    const real=(cur-1+total)%total;
    dots.forEach((d,k)=>d.classList.toggle('active',k===real));
  }
  snap(cur);
  function wrapAround(){if(dragging)return;if(cur===count-1){cur=1;snap(cur)}else if(cur===0){cur=total;snap(cur)}}
  function autoStep(){go(cur+1);setTimeout(wrapAround,700)}
  function restart(){if(timer)clearTimeout(timer);(function tick(){timer=setTimeout(()=>{autoStep();tick()},3000)})()}
  wrap.addEventListener('transitionend',wrapAround);
  wrap.addEventListener('pointerdown',e=>{
    if(e.pointerType==='mouse'&&e.button!==0)return;
    dragging=true;startX=e.clientX;deltaX=0;render();
    if(timer)clearTimeout(timer);
    try{wrap.setPointerCapture(e.pointerId)}catch(_){}
  });
  wrap.addEventListener('pointermove',e=>{
    if(!dragging)return;
    deltaX=e.clientX-startX;render();
  });
  function endDrag(){
    if(!dragging)return;
    dragging=false;
    const threshold=Math.min(60,wrap.offsetWidth*.15);
    if(Math.abs(deltaX)>threshold){go(cur+(deltaX<0?1:-1));}
    else{deltaX=0;render();}
    restart();
  }
  wrap.addEventListener('pointerup',endDrag);
  wrap.addEventListener('pointercancel',endDrag);
  $('#heroPrev')?.addEventListener('click',()=>{go(cur-1);restart()});
  $('#heroNext')?.addEventListener('click',()=>{go(cur+1);restart()});
  restart();
}
function toggleFav(id,b){let f=getFav();if(f.includes(id)){f=f.filter(x=>x!==id);b.classList.remove('active');b.innerHTML='<i class="fa-regular fa-heart"></i>';toast('Removed from favorites')}else{f.push(id);b.classList.add('active');b.innerHTML='<i class="fa-solid fa-heart"></i>';toast('<i class="fa-solid fa-heart"></i> Added to favorites')}saveFav(f)}
function applyFilters(){
  const cards=$$('.dish-card'),sort=$('#sortMenu')?.value||'default';
  let filtered=cards.filter(c=>(currentCat==='all'||catMatch(c))&&(!searchQuery||c.dataset.search.includes(searchQuery)));

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
  if(window.FE_LIVE_SYNC||(!$('#menu-grid')&&!$('#mart-grid')&&!$('#others-grid')&&!$('#beverages-grid')&&!$('#hotels-grid')&&!$('#contact-grid')))return; window.FE_LIVE_SYNC=true;
  let lastSignature='';
  const sync=()=>fetch('api?v='+Date.now(),{cache:'no-store'}).then(r=>r.json()).then(d=>{
    const sig=JSON.stringify([d.dishes||[],d.mart||[],d.others||[],d.beverages||[],d.hotels||[],d.contacts||[]]);
    if(!lastSignature){lastSignature=sig;return;}
    if(sig!==lastSignature){
      lastSignature=sig;allDishes=d.dishes||[];allMart=d.mart||[];allOthers=d.others||[];allBeverages=d.beverages||[];
      renderDishes(allDishes);renderMart(allMart);renderOthers(allOthers);renderBeverages(allBeverages);renderHotels(d.hotels||[]);renderContacts(d.contacts||[]);
      toast('<i class="fa-solid fa-arrows-rotate"></i> Catalog updated automatically');
    }
  }).catch(()=>{});
  setInterval(sync,5000);
}
  function initMenuFilters(){const chips=$$('.chip[data-cat]');chips.forEach(ch=>ch.addEventListener('click',()=>{chips.forEach(x=>x.classList.remove('active'));ch.classList.add('active');currentCat=ch.dataset.cat;syncSubChips('menu');applyFilters()}));const s=$('#dishSearch')||$('.nav-search input[name=q]');if(s){searchQuery=(s.value||'').trim().toLowerCase();s.addEventListener('input',e=>{searchQuery=e.target.value.trim().toLowerCase();applyFilters()})}$('#sortMenu')?.addEventListener('change',applyFilters);const p=new URLSearchParams(location.search);const uq=(p.get('q')||'').trim().toLowerCase();if(uq)searchQuery=uq;const cat=p.get('cat');if(cat){const t=document.querySelector('.chip[data-cat="'+cat+'"]');if(t)t.click();else applyFilters()}else applyFilters()}
function initCheckout(){
  const form=$('#checkoutForm');
  let promo='';
  function update(){
    const c=getCart();let sub=0;const box=$('#checkoutItems');
    if(!c.length){$('#checkoutEmpty')?.classList.add('show');form.style.display='none';return}
    $('#checkoutEmpty')?.classList.remove('show');
    const shops=cartShops(c);
    const groups={};c.forEach(r=>{const d=findItem(r.id,r.type)||r;if(!d)return;const s=r.shop||shopOfItem(d);(groups[s]=groups[s]||[]).push(r)});
    let html='';
    Object.keys(groups).forEach(s=>{html+='<div class="checkout-shop"><i class="fa-solid fa-store"></i> '+esc(s)+'</div>'+groups[s].map(r=>{const d=findItem(r.id,r.type)||r;if(!d)return '';const line=Number(r.variant?r.price:d.price)*r.qty;sub+=line;return `<div class="checkout-item"><span>${esc(d.name)}${r.variant?` <em class="vp-variant">(${esc(r.variant)})</em>`:''} × ${r.qty}</span><strong>Rs. ${line}</strong></div>`}).join('')});
    box.innerHTML=html;
    const hasHotel=cartHasHotel(c);
    const delivery=deliveryFeeFor(shops.length),discount=(promo==='LYAIDEU'||promo==='FOODXPRESS')?delivery:0;
    $('#coSubtotal').textContent='Rs. '+sub;
    if($('#coDelivery'))$('#coDelivery').textContent='Rs. '+Math.max(0,delivery-discount);
    if($('#coTotal'))$('#coTotal').textContent='Rs. '+Math.max(0,sub+delivery-discount);
    if($('#coEta'))$('#coEta').innerHTML=shops.length>1?'<i class="fa-solid fa-clock"></i> Estimated delivery: <b>about '+deliveryEtaFor(shops.length,hasHotel)+' minutes</b> — '+shops.length+' vendors': '<i class="fa-solid fa-clock"></i> Estimated delivery: <b>about '+deliveryEtaFor(shops.length,hasHotel)+' minutes</b>';
    const note=$('#coVendorNote');
    if(note){if(shops.length>1){note.style.display='';note.innerHTML='<i class="fa-solid fa-triangle-exclamation"></i> <b>Ordering from '+shops.length+' vendors.</b> Delivery takes about '+deliveryEtaFor(shops.length,hasHotel)+' minutes and the delivery fee is Rs. '+delivery+'. Each vendor prepares your items separately.'}else{note.style.display='none'}}
    $('#cartJson').value=JSON.stringify(c);if($('#promoHidden'))$('#promoHidden').value=promo;
  }
  $('#promoBtn')?.addEventListener('click',()=>{promo=$('#promoInput').value.trim().toUpperCase();$('#promoMsg').innerHTML=(promo==='LYAIDEU'||promo==='FOODXPRESS')?'<i class="fa-solid fa-circle-check"></i> Free delivery applied!':'<i class="fa-solid fa-circle-xmark"></i> Invalid demo code. Try LYAIDEU.';update()});
  form.addEventListener('submit',e=>{if(form.dataset.kycOk!=='1'){e.preventDefault();window.location.href='profile';return}if(!getCart().length){e.preventDefault();toast('Your cart is empty.')}$('#cartJson').value=JSON.stringify(getCart());if($('#promoHidden'))$('#promoHidden').value=promo});update();
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
 confirm:(v,f)=>v&&v===f.querySelector('[name=password]').value||'Passwords do not match.',
 terms:v=>v==='on'||'Please accept the Terms & Conditions to continue.'
};
function updatePassRequirements(f){const p=f.querySelector('[name=password]')?.value||'',n=f.querySelector('[name=name]')?.value.toLowerCase()||'',ph=f.querySelector('[name=phone]')?.value.replace(/\D/g,'')||'',bad=n.split(/\s+/).filter(x=>x.length>=3).some(x=>p.toLowerCase().includes(x))||(ph&&p.includes(ph));const m={len:p.length>=8,cap:/[A-Z]/.test(p),num:/[0-9]/.test(p),sym:/[^A-Za-z0-9]/.test(p),info:!bad&&p.length>0};$$('.req').forEach(e=>e.classList.toggle('met',!!m[e.dataset.req]))}
function checkField(field,form){const i=field.querySelector('input'),msg=field.querySelector('.field-msg'),rule=i?.dataset.validate;if(!rule||!validators[rule])return true;const r=validators[rule](i.value,form),ok=r===true,chk=i.type==='checkbox';field.classList.toggle('invalid',!ok&&(chk||i.value!==''));field.classList.toggle('valid',ok&&(chk?i.checked:i.value!==''));if(msg){const hint=msg.classList.contains('field-hint');if(ok){if(!hint)msg.innerHTML=rule==='confirm'?'<i class="fa-solid fa-check"></i> Passwords match':''}else{msg.textContent=r;msg.classList.remove('field-hint')}}return ok}
function initAuthValidation(){$$('form.auth-form').forEach(f=>{f.addEventListener('input',e=>{const field=e.target.closest('.field');if(field)checkField(field,f);if(f.id==='form-signup')updatePassRequirements(f)});f.addEventListener('focusout',e=>{const field=e.target.closest('.field');if(field)checkField(field,f)});f.addEventListener('submit',e=>{let ok=true;$$('.field',f).forEach(x=>{if(!checkField(x,f))ok=false});if(!ok){e.preventDefault();toast('<i class="fa-solid fa-triangle-exclamation"></i> Please fix the highlighted fields.');f.querySelector('.field.invalid input')?.focus()}})})}
function initNav(){const t=$('#navToggle'),l=$('#navLinks');if(t&&l){t.addEventListener('click',()=>{const o=l.classList.toggle('open');t.classList.toggle('open',o)});$$('a',l).forEach(a=>a.addEventListener('click',()=>{l.classList.remove('open');t.classList.remove('open')}))}const b=$('.topbar');if(b)window.addEventListener('scroll',()=>b.classList.toggle('scrolled',scrollY>8),{passive:true})}
function initProfileMenu(){const c=$('#profileChip'),m=$('#profileMenu');if(!c||!m)return;c.addEventListener('click',e=>{e.stopPropagation();m.classList.toggle('open')});document.addEventListener('click',e=>{if(!m.contains(e.target)&&!c.contains(e.target))m.classList.remove('open')})}
function initScrollSpy(){const ss=$$('main section[id]');if(!ss.length||!('IntersectionObserver'in window))return;const links=$$('.nav-links a.nav-a[href^="#"]');if(!links.length)return;const map={};links.forEach(a=>map[a.getAttribute('href').slice(1)]=a);const o=new IntersectionObserver(es=>es.forEach(en=>{if(!en.isIntersecting)return;const a=map[en.target.id];if(a){links.forEach(x=>x.classList.remove('active'));a.classList.add('active')}}),{rootMargin:'-40% 0px -55% 0px'});ss.forEach(s=>o.observe(s))}
function initOrderToasts(){document.addEventListener('click',e=>{const a=e.target.closest('a[href^="tel:"]');if(a&&a.dataset.hotel)toast('<i class="fa-solid fa-phone"></i> Calling '+esc(a.dataset.hotel)+'...')})}
function footerYear(){const y=$('#year');if(y)y.textContent=new Date().getFullYear()}
})();


/* Live catalog/order refresh without manual page reload. */
(function(){
 if(window.FE_LIVE_REFRESH_INITIALIZED)return; window.FE_LIVE_REFRESH_INITIALIZED=true;
 let last='';
 async function refresh(){try{const r=await fetch('api?live='+Date.now(),{cache:'no-store'});if(!r.ok)return;const d=await r.json();const sig=JSON.stringify({dishes:d.dishes||[],mart:d.mart||[],others:d.others||[],beverages:d.beverages||[],hotels:d.hotels||[],contacts:d.contacts||[]});
  if(last&&sig!==last){if(typeof allDishes!=='undefined'){allDishes=d.dishes||[];if(document.querySelector('#menu-grid'))renderDishes(allDishes);if(document.querySelector('#others-grid'))renderOthers(d.others||[]);if(document.querySelector('#beverages-grid'))renderBeverages(d.beverages||[]);if(document.querySelector('#hotels-grid'))renderHotels(d.hotels||[]);if(document.querySelector('#contact-grid'))renderContacts(d.contacts||[]);}window.dispatchEvent(new CustomEvent('lyaideu:datachanged',{detail:d}));}
  last=sig;const el=document.querySelector('[data-live-indicator]');if(el)el.classList.add('live-on');
 }catch(e){}}
 refresh();setInterval(refresh,5000);
})();

/* Shared geolocation helper: caches the user's last-known coordinates. */
window.LYAIDEU_LOC=(function(){
  var KEY='fe_loc';
  function getSaved(){try{var r=JSON.parse(localStorage.getItem(KEY)||'null');if(r&&typeof r.lat==='number'&&typeof r.lng==='number')return r;}catch(e){}return null;}
  function save(lat,lng){try{localStorage.setItem(KEY,JSON.stringify({lat:Number(lat),lng:Number(lng),at:Date.now()}));}catch(e){}}
  function request(cb){
    if(!('geolocation' in navigator)){if(cb)cb(new Error('Geolocation not supported'));return;}
    navigator.geolocation.getCurrentPosition(function(p){
      save(p.coords.latitude,p.coords.longitude);
      if(cb)cb(null,{lat:p.coords.latitude,lng:p.coords.longitude});
    },function(err){
      if(cb)cb(err||new Error('Location unavailable'));
    },{enableHighAccuracy:true,timeout:12000,maximumAge:300000});
  }
  return {getSaved:getSaved,save:save,request:request};
})();

/* "Use my current location" banner shown on first visits. */
(function(){
  var HIDE_KEY='fe_loc_banner_hidden';
  function isBannerPage(){
    var p=(location.pathname||'').replace(/\/+$/,'').toLowerCase();
    if(/\/profile$/.test(p)||/\/checkout$/.test(p)||/\/login$/.test(p))return false;
    if(/admin|rider|vendor/i.test(p))return false;
    return true;
  }
  function hidden(){try{return localStorage.getItem(HIDE_KEY)==='1';}catch(e){return true;}}
  function dismiss(){try{localStorage.setItem(HIDE_KEY,'1');}catch(e){}var b=document.getElementById('locBanner');if(b)b.remove();}
  function build(){
    if(window.LYAIDEU_LOC.getSaved()||hidden()||document.getElementById('locBanner'))return;
    var b=document.createElement('div');
    b.id='locBanner';
    b.className='loc-banner';
    b.innerHTML='<div class="loc-banner-ico"><i class="fa-solid fa-location-crosshairs"></i></div>'+
      '<div class="loc-banner-body"><strong>Find you faster</strong><span>Allow your current location so we can set your delivery spot automatically.</span></div>'+
      '<button type="button" class="btn btn-primary btn-sm" id="locBannerAllow"><i class="fa-solid fa-location-dot"></i> Use my location</button>'+
      '<button type="button" class="loc-banner-close" aria-label="Dismiss"><i class="fa-solid fa-xmark"></i></button>';
    document.body.appendChild(b);
    b.querySelector('#locBannerAllow').addEventListener('click',function(){
      var btn=b.querySelector('#locBannerAllow');
      btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Locating…';
      window.LYAIDEU_LOC.request(function(err,pos){
        btn.disabled=false;
        var strong=b.querySelector('.loc-banner-body strong'),span=b.querySelector('.loc-banner-body span');
        if(err){
          btn.innerHTML='<i class="fa-solid fa-location-dot"></i> Try again';
          span.textContent='We couldn\u2019t get your location. You can still type it at checkout.';
          return;
        }
        strong.textContent='Location set ✓';
        span.innerHTML='Lat '+pos.lat.toFixed(4)+', Lng '+pos.lng.toFixed(4)+' — <a href="profile">Save it as your home address.</a>';
        btn.remove();
        setTimeout(dismiss,8000);
      });
    });
    b.querySelector('.loc-banner-close').addEventListener('click',dismiss);
  }
  function boot(){if(isBannerPage())build();}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();

/* Per-vendor live order tracking: polls api/orders.php and re-renders order
   cards so every product shows its owning vendor and that vendor's
   progression (accept -> preparing -> ready -> on the way -> delivered). */
(function(){
'use strict';
if(window.LYAIDEU_ORDER_LIVE)return;
window.LYAIDEU_ORDER_LIVE={};
var POLL_MS=5000,FULL_MS=60000;

function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(ch){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];});}
function reltime(dt){
  if(!dt)return '';
  var ts=Date.parse(String(dt).replace(' ','T'));
  if(isNaN(ts))return dt;
  var diff=(Date.now()-ts)/1000;
  if(diff<60)return 'just now';
  if(diff<3600)return Math.max(1,Math.round(diff/60))+' min ago';
  if(diff<86400)return Math.round(diff/3600)+' hr ago';
  var d=new Date(ts);
  return ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()]+' '+d.getDate();
}
function pillClass(status){
  return ({'Pending':'pending','Confirmed':'confirmed','Accepted':'confirmed','Preparing':'preparing','Ready for pickup':'ready','Out for delivery':'delivery','Delivered':'delivered','Cancelled':'cancelled'}[status]||'pending');
}
function vendorIcon(name){
  return /mart|store/i.test(name||'')?'fa-basket-shopping':'fa-store';
}
function vpProgress(status){
  var steps=['Waiting','Accepted','Preparing','Ready'];
  var cur={'Pending':0,'Accepted':1,'Preparing':2,'Ready for pickup':3}[status];
  if(cur===undefined)cur=-1;
  var rejected=status==='Rejected';
  var h='<div class="vp-progress">';
  for(var i=0;i<steps.length;i++){
    var cls=rejected?'cancelled':(i<cur?'done':(i===cur?'active':''));
    h+='<span class="vp-step '+cls+'">'+steps[i]+'</span>';
  }
  return h+'</div>';
}
function trackHtml(status){
  if(status==='Cancelled'){
    return '<div class="order-track cancelled"><div class="track-step cancelled"><i class="fa-solid fa-ban"></i><span>Order cancelled</span></div></div>';
  }
  var cur={'Pending':0,'Accepted':1,'Preparing':1,'Ready for pickup':2,'Out for delivery':3,'Delivered':4}[status]||0;
  var steps=[['Placed','fa-circle-check'],['Preparing','fa-utensils'],['Ready','fa-box-open'],['On the way','fa-motorcycle'],['Delivered','fa-house-circle-check']];
  var h='<div class="order-track">';
  steps.forEach(function(s,i){
    var cls=i<cur?'done':(i===cur?'active':'');
    h+='<div class="track-step '+cls+(s[0]==='On the way'?'" data-short="OTW':'')+'"><i class="fa-solid '+s[1]+'"></i><span>'+s[0]+'</span></div>';
  });
  return h+'</div>';
}
function vendorHtml(v){
  var ico=vendorIcon(v.name);
  var status=v.status||'Pending';
  var h='<div class="order-vendor-row">'
    +'<div class="vendor-row-head">'
    +'<span class="vendor-ico"><i class="fa-solid '+ico+'"></i></span>'
    +'<strong class="vendor-name">'+esc(v.name)+'</strong>'
    +'<span class="order-status-pill status-'+pillClass(status)+'">'+esc(status)+'</span>'
    +'<span class="vendor-updated">updated '+reltime(v.updated_at)+'</span>'
    +'</div><div class="vendor-products">';
  (v.items||[]).forEach(function(it){
    h+='<div class="vendor-product"><div class="vp-main">'
      +'<span class="vp-vendor"><i class="fa-solid '+ico+'"></i> '+esc(v.name)+'</span>'
      +'<span class="vp-name">'+esc(it.name)+(it.variant?' <em class="vp-variant">('+esc(it.variant)+')</em>':'')+' × '+(it.qty||0)+'</span>'
      +'<span class="vp-line">Rs. '+(it.line_total||0)+'</span>'
      +'</div>'+vpProgress(status)+'</div>';
  });
  return h+'</div></div>';
}
function otherHtml(items){
  var h='<div class="order-vendor-row other"><div class="vendor-row-head"><strong class="vendor-name">Other items</strong><span class="order-status-pill status-cancelled">Not fulfilled</span></div><div class="vendor-products">';
  items.forEach(function(it){
    h+='<div class="vendor-product"><div class="vp-main"><span class="vp-name">'+esc(it.name)+(it.variant?' <em class="vp-variant">('+esc(it.variant)+')</em>':'')+' × '+(it.qty||0)+'</span><span class="vp-line">Rs. '+(it.line_total||0)+'</span></div></div>';
  });
  return h+'</div></div>';
}
function deliveryHtml(o){
  var s=o.status;
  if(s==='Cancelled')return '<div class="order-delivery cancelled"><i class="fa-solid fa-circle-xmark"></i> This order was cancelled.</div>';
  if(s==='Delivered')return '<div class="order-delivery done"><i class="fa-solid fa-circle-check"></i> Delivered'+(o.rider&&o.rider.name?' by '+esc(o.rider.name):'')+'.</div>';
  if(s==='Out for delivery')return '<div class="order-delivery onway"><i class="fa-solid fa-motorcycle"></i> '+(o.rider&&o.rider.name?esc(o.rider.name):'Your rider')+' is delivering your order — it\'s on the way!</div>';
  if(s==='Ready for pickup')return '<div class="order-delivery waiting"><span class="pulse-dot"></span> Waiting for a delivery partner… a rider will pick up your order soon.</div>';
  return '<div class="order-delivery"><i class="fa-solid fa-hourglass-half"></i> Vendors are preparing your order.</div>';
}
function cardHeadHtml(o,vendorCount){
  return '<div class="order-card-head"><div><h2>Order #'+(o.id||0)+'</h2><p>'+esc(o.created_at||'')+'</p></div>'
    +'<span class="order-status-pill status-'+pillClass(o.status)+'">'+esc(o.status)+'</span></div>';
}
function bodyHtml(o,vendorCount){
  var h=trackHtml(o.status);
  (o.vendors||[]).forEach(function(v){h+=vendorHtml(v);});
  if(o.other_items&&o.other_items.length)h+=otherHtml(o.other_items);
  h+=deliveryHtml(o);
  h+='<div class="summary-row"><span>Subtotal</span><strong>Rs. '+(o.subtotal||0)+'</strong></div>';
  h+='<div class="summary-row"><span>Delivery</span><strong>Rs. '+Math.max(0,(o.delivery_fee||0)-(o.discount||0))+'</strong></div>';
  if(o.eta_minutes)h+='<div class="summary-row"><span>Estimated delivery</span><strong>about '+(o.eta_minutes||0)+' min'+(vendorCount>1?' · '+vendorCount+' vendors':'')+'</strong></div>';
  h+='<div class="summary-row total"><span>Total</span><strong>Rs. '+(o.total||0)+'</strong></div>';
  h+='<p class="small-note"><i class="fa-solid fa-location-dot"></i> '+esc(o.address||'')+' · <i class="fa-solid fa-credit-card"></i> '+esc(o.payment||'')+'</p>';
  return h;
}
function cardHtml(o){
  var vc=(o.vendors||[]).length+(o.other_items&&o.other_items.length?1:0);
  return '<article class="order-card" data-order-id="'+(o.id||0)+'">'+cardHeadHtml(o,vc)+bodyHtml(o,vc)+'</article>';
}
function singleCardHtml(o){
  var vc=(o.vendors||[]).length+(o.other_items&&o.other_items.length?1:0);
  var h=cardHeadHtml(o,vc)+bodyHtml(o,vc);
  if(o.delivery_lat&&o.delivery_lng){
    h=h.replace('</p>',' · <a target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query='+encodeURIComponent(o.delivery_lat)+','+encodeURIComponent(o.delivery_lng)+'">Open in Maps</a></p>');
  }
  h+='<div class="success-actions"><a class="btn btn-primary" href="orders">Track My Order</a><a class="btn btn-outline" href="menu">Order More</a></div>';
  return h;
}
function sig(o){
  return (o.id||0)+':'+o.status+':'+(o.updated_at||'')+':'+((o.rider&&o.rider.name)||'')+':'+(o.vendors||[]).map(function(v){return v.vendor_id+':'+v.status+':'+(v.updated_at||'');}).join(';');
}
function flashCard(card){
  if(!card)return;
  card.classList.remove('track-flash');
  void card.offsetWidth;
  card.classList.add('track-flash');
}
function highlightFromUrl(){
  var id=new URLSearchParams(location.search).get('id');
  if(!id)return;
  var card=document.querySelector('.order-card[data-order-id="'+Number(id)+'"]');
  if(card){card.scrollIntoView({behavior:'smooth',block:'center'});flashCard(card);}
}

function init(){
  var listWrap=document.querySelector('[data-live-orders] .orders-list');
  var single=document.querySelector('[data-live-order]');
  if(!listWrap&&!single)return;
  var liveEl=document.querySelector('[data-live-indicator]');
  var lastList='',lastSingle='',listSeeded=false,singleSeeded=false,listRender=0,singleRender=0;

  function renderList(orders,forceAll){
    var wrap=listWrap;if(!wrap)return false;
    var map={};orders.forEach(function(o){map[o.id]=o;});
    var changed=false;
    wrap.querySelectorAll(':scope > .order-card').forEach(function(card){
      var o=map[Number(card.getAttribute('data-order-id'))];
      if(o&&(forceAll||sig(o)!==card._sig)){card.outerHTML=cardHtml(o);changed=true;}
    });
    var existing={};wrap.querySelectorAll(':scope > .order-card').forEach(function(c){existing[Number(c.getAttribute('data-order-id'))]=true;});
    var fresh=orders.filter(function(o){return !existing[o.id];});
    if(fresh.length){
      fresh.forEach(function(o){
        var tmp=document.createElement('div');tmp.innerHTML=cardHtml(o);
        var card=tmp.firstElementChild;card._sig=sig(o);
        wrap.insertBefore(card,wrap.firstChild);
      });
      changed=true;
    }
    wrap.querySelectorAll(':scope > .order-card').forEach(function(c){
      var o=map[Number(c.getAttribute('data-order-id'))];
      if(o)c._sig=sig(o);
    });
    return changed;
  }

  function refresh(){
    if(document.visibilityState==='hidden')return;
    fetch('api/orders.php',{cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(d){
        var orders=d.orders||[];
        var now=Date.now();
        if(listWrap){
          var ls=orders.map(sig).join('|');
          if(!listSeeded){
            listSeeded=true;lastList=ls;listRender=now;
            renderList(orders,false);
            highlightFromUrl();
          }else if(ls!==lastList||(now-listRender)>FULL_MS){
            var forceAll=(now-listRender)>FULL_MS;
            var prev={};lastList.split('|').forEach(function(s){var p=s.split(':');prev[p[0]]=p[1];});
            lastList=ls;listRender=now;
            var changed=renderList(orders,forceAll);
            if(changed){
              orders.forEach(function(o){
                if(prev[o.id]&&prev[o.id]!==o.status)flashCard(document.querySelector('.order-card[data-order-id="'+o.id+'"]'));
              });
            }
          }
        }
        if(single){
          var sid=Number(single.getAttribute('data-live-order'));
          var o=null;
          orders.forEach(function(x){if(Number(x.id)===sid)o=x;});
          if(o){
            var s=sig(o);
            var prevStatus=singleSeeded?lastSingle.split(':')[1]:null;
            if(!singleSeeded){
              singleSeeded=true;lastSingle=s;singleRender=now;
            }else if(s!==lastSingle||(now-singleRender)>FULL_MS){
              single.innerHTML=singleCardHtml(o);
              if(prevStatus&&prevStatus!==o.status)flashCard(single);
              lastSingle=s;singleRender=now;
            }
          }
        }
        if(liveEl)liveEl.classList.add('live-on');
      })
      .catch(function(){});
  }

  refresh();
  setInterval(refresh,POLL_MS);
  highlightFromUrl();
}

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
