(function(){
 'use strict';
 var KEY='strim_consent_v2', root;
 function read(){try{return localStorage.getItem(KEY)||'';}catch(e){return '';}}
 function clearOptional(){
  try{['strim_vid','strim_sid','strim_attr','strim_pv_boot','strim_consent_v1'].forEach(function(k){localStorage.removeItem(k);sessionStorage.removeItem(k);});}catch(e){}
  document.cookie.split(';').forEach(function(c){var n=c.split('=')[0].trim();if(/^(strim_(vid|sid|attr|reg_conv|reg_event)|_ga|_gid|_gcl|__gads|__gpi)/.test(n)){
   ['', '; Domain='+location.hostname, '; Domain=.'+location.hostname].forEach(function(d){document.cookie=n+'=; Path=/; Max-Age=0; SameSite=Lax'+d;});
  }});
 }
 function apply(choice){
  var googleOk=choice==='all';
  var firstParty=choice==='all'||choice==='essential';
  window['ga-disable-G-5G2LNXY143']=!googleOk;
  if(typeof window.gtag==='function'){
   window.gtag('consent','update',{ad_storage:googleOk?'granted':'denied',analytics_storage:googleOk?'granted':'denied',ad_user_data:googleOk?'granted':'denied',ad_personalization:googleOk?'granted':'denied'});
   window.gtag('set','ads_data_redaction',!googleOk);
  }
  if(!firstParty) clearOptional();
  if(googleOk && !document.getElementById('strim-google-consented')){
   var s=document.createElement('script');s.id='strim-google-consented';s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id=AW-18167666446';document.head.appendChild(s);
  }
  window.dispatchEvent(new CustomEvent('strim-consent-updated',{detail:{granted:googleOk, firstParty:firstParty, choice:choice}}));
 }
 function save(choice){
  try{localStorage.setItem(KEY,choice);localStorage.setItem(KEY+'_time',new Date().toISOString());}catch(e){}
  document.cookie=KEY+'='+choice+'; Path=/; Max-Age=15552000; SameSite=Lax; Secure';
  apply(choice);root.hidden=true;
 }
 function show(){
  if(root){root.hidden=false;return;}
  root=document.createElement('div');root.className='strim-consent';root.id='strim-consent-root';root.setAttribute('role','dialog');root.setAttribute('aria-label','Вибір файлів cookie');
  root.innerHTML='<div class="strim-consent__panel"><p class="strim-consent__title">Ваш вибір щодо аналітики</p><p class="strim-consent__text">Необхідні cookie: вхід, захист форм, збереження вибору та анонімна статистика відвідувань StrIM (без передачі в Google). За повним дозволом також увімкнемо Google Analytics і Google Ads. Відмова від рекламних cookie не впливає на доступ до сервісу. <a href="/privacy.html#cookies">Деталі</a>.</p><div class="strim-consent__actions"><button type="button" class="strim-consent__btn" data-consent="essential">Без Google / Ads</button><button type="button" class="strim-consent__btn" data-consent="all">Дозволити аналітику й рекламу</button></div></div>';
  root.addEventListener('click',function(e){var b=e.target.closest('[data-consent]');if(b)save(b.getAttribute('data-consent'));});document.body.appendChild(root);
 }
 window.strimOpenCookieSettings=show;
 function init(){
  var c=read();var ts;try{ts=Date.parse(localStorage.getItem(KEY+'_time')||'');}catch(e){}
  if(!ts || Date.now()-ts>180*86400000)c='';
  if(c==='all'||c==='essential'){
   document.cookie=KEY+'='+c+'; Path=/; Max-Age=15552000; SameSite=Lax; Secure';apply(c);
  }else{show();}
  if(!document.querySelector('[data-cookie-settings]')){
   var b=document.createElement('button');b.type='button';b.textContent='Налаштування cookie';b.setAttribute('data-cookie-settings','');b.className='strim-consent__btn';b.style.margin='12px';(document.querySelector('footer')||document.body).appendChild(b);
  }
  document.querySelectorAll('[data-cookie-settings]').forEach(function(b){b.addEventListener('click',show);});
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
