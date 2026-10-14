(function () {
  'use strict';
  const labels = {api_how:'Не знаю, як створити API', api_trust:'Не готовий давати доступ', no_binance:'Ще немає Binance', question:'Хочу поставити запитання'};
  const $ = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let state={}, uid=0, choice='', barrier='', unread=0, loaded=false, loading=false, fingerprint='', panelApi=false, modal=null, historyFingerprint='', historyLoading=false, lastVisit=0, opening=false, mutationEpoch=0;
  const model = () => typeof SAAS_MODEL !== 'undefined' ? SAAS_MODEL : null;
  const apiStatus = () => typeof __apiExchangeStatus !== 'undefined' ? __apiExchangeStatus : '';
  const cookie = name => {const m=document.cookie.match(new RegExp('(?:^|; )'+name+'=([^;]*)')); return m?decodeURIComponent(m[1]):'';};
  async function request(path,body=null) {
    const r=await fetchWithAuth(saasApi(path),{method:body?'POST':'GET',headers:body?{'Content-Type':'application/json','X-CSRF-Token':cookie('csrf_token')}:{},body:body?JSON.stringify(body):undefined},20000);
    const j=await r.json();if(!r.ok || !j.ok) throw Error(j.error||'Тимчасово недоступно');return j;
  }
  function notice(message) {const el=$('obNotice'); if(el){el.textContent=message;el.hidden=false;}}
  function updateBadge(){
    const b=$('btnSupportTop');
    if(!b)return;
    b.textContent=unread?'Підтримка · '+unread:'Підтримка';
    b.classList.toggle('chip-support-unread', !!unread);
    b.setAttribute('aria-label', unread ? ('Підтримка, нових відповідей: '+unread) : 'Підтримка');
  }
  function applyState(j) {state=j.state||{};uid=Number(j.user_id)||uid;choice=state.choice||'';barrier=state.barrier||'';unread=Number(j.unread)||0;loaded=true;updateBadge();}
  async function loadState(visit=false){if(loading)return;loading=true;const epoch=mutationEpoch;try{const j=await request('onboarding.php',visit?{action:'visit'}:null);if(epoch===mutationEpoch){applyState(j);render();}if(visit)lastVisit=Date.now();}catch(e){notice('Історію кроків не вдалося завантажити. Можна продовжити або написати support@strim.in.ua.');}finally{loading=false;}}
  async function select(value){const epoch=++mutationEpoch;choice=value;panelApi=false;fingerprint='';render();try{const j=await request('onboarding.php',{action:'choice',value});if(epoch===mutationEpoch)applyState(j);}catch(e){notice('Ваш вибір не збережено. Спробуйте ще раз — сторінку можна переглядати далі.');}}
  async function selectBarrier(value){const epoch=++mutationEpoch;barrier=value;fingerprint='';render();try{const j=await request('onboarding.php',{action:'barrier',value});if(epoch===mutationEpoch)applyState(j);}catch(e){notice('Не вдалося зберегти вибір. Поставте запитання у підтримці.');}}
  function openApi(){++mutationEpoch;panelApi=true;choice='self';fingerprint='';render();strimFocusCabinetApi();void request('onboarding.php',{action:'choice',value:'self'}).catch(()=>notice('Вибір не збережено; підключення можна продовжити.'));}
  function render(){
    const banner=$('newUserSupportBanner'), m=model();if(!banner||!m)return;
    const current=Number(m.user_id)||0;
    if(uid && current && uid!==current){state={};choice='';barrier='';loaded=false;uid=current;fingerprint='';}
    if(!loaded && !loading)void loadState(true);
    const hasStrategy=(m.strategies||[]).some(s=>s && String(s.symbol||'').toUpperCase()!=='TEMPLATE');
    const hasRun=(m.strategies||[]).some(s=>s && s.status==='running' && String(s.symbol||'').toUpperCase()!=='TEMPLATE');
    const connected=apiStatus()==='CONNECTED';
    const focus=!hasStrategy&&!connected&&choice!=='later';
    document.body.classList.toggle('strim-onboarding-focus',focus);
    document.body.classList.toggle('strim-onboarding-api',focus&&panelApi);
    updateBadge();
    if(hasRun){banner.style.display='none';banner.innerHTML='';fingerprint='';return;}
    const u=m.user||{}, trial=u.billing_model==='percent'
      ? 'Тест до першого поповнення: до 3 пар, перша покупка до 10 USDT. Перед запуском ознайомтеся з умовами комісії.'
      : (u.trial_until?'Тестовий доступ до '+esc(String(u.trial_until).slice(0,16)) : 'Умови доступу дивіться в тарифах.');
    const sig=JSON.stringify([choice,barrier,focus,connected,hasStrategy,panelApi,apiStatus(),trial]);
    banner.style.display='';if(sig===fingerprint)return;fingerprint=sig;
    if(connected || hasStrategy){
      banner.innerHTML='<h2 class="obTitle">'+(connected?'Binance підключено. Наступний крок — ваша перша пара.':'Потрібна допомога з налаштуванням?')+'</h2><p class="obText">'+(connected?'Оберіть пару й суму в «Швидкому старті». Торгівля почнеться лише після вашого натискання «Старт».':'Поставте запитання в кабінеті; Telegram для цього не обов’язковий.')+'</p><div class="obActions"><button class="btn primary" data-ob="quick">До швидкого старту</button><button class="btn" data-ob="support">Поставити запитання</button></div><p class="muted obSmall">'+trial+'</p>';
    } else {
      let content='';
      if(choice==='explore')content='<div class="obDetail"><h3>Як працює StrIM — без складних термінів</h3><ol><li>Ви обираєте пару та розмір першої покупки.</li><li>Сервіс виконує покупки й продажі за правилами стратегії. Між угодами може бути очікування.</li><li>Ви бачите відкриті позиції та результат закритих угод окремо.</li></ol><p>Кошти залишаються на вашому Binance. Доступ до торгівлі дозволяє операції з ризиком збитку; прибуток не гарантований.</p><p>Пауза припиняє нові покупки. Продажі відкритих позицій можуть тривати. Повне припинення доступу — відкликання API-ключа в Binance.</p><div class="obActions"><a class="btn" href="/instruction.html#video-binance-api" target="_blank" rel="noopener">Подивитися відео</a><button class="btn primary" data-ob="api">Перейти до налаштування</button><button class="btn" data-ob="support">Є запитання</button></div></div>';
      if(choice==='help')content='<div class="obDetail"><h3>Що саме зупиняє?</h3><div class="obActions">'+Object.entries(labels).map(([k,v])=>'<button class="btn '+(barrier===k?'primary':'')+'" data-barrier="'+k+'" aria-pressed="'+(barrier===k)+'">'+v+'</button>').join('')+'</div>'+(barrier?'<p>'+({api_how:'Допоможемо пройти інструкцію крок за кроком. Почніть із запитання нижче.',api_trust:'API дозволяє сервісу торгувати. Виведення коштів має бути вимкнено, але торговий ризик залишається. Можна спершу все обговорити.',no_binance:'Без акаунта Binance підключити торгівлю не вийде. Поки можна ознайомитися з прикладом і поставити запитання.',question:'Напишіть, що хочете уточнити. Відповідь залишиться в цьому кабінеті.'}[barrier]||'')+'</p>':'')+'<div class="obActions"><button class="btn primary" data-ob="support">Написати в кабінеті</button><button class="btn" data-ob="telegram">Отримувати відповіді в Telegram</button></div><p class="muted obSmall">Telegram необов’язковий. Нікнейм у профілі ще не означає підключення бота. Не надсилайте API-ключі чи паролі в повідомленнях.</p></div>';
      if(choice==='self' || panelApi)content='<div class="obDetail"><h3>Підключення — один крок за раз</h3><p>1. Створіть API-ключ за <a href="/instruction.html#video-binance-api" target="_blank" rel="noopener">відео-інструкцією</a>. Права: Reading + Spot Trading. Withdrawals — вимкнено.</p><p>2. Збережіть ключі у формі Binance API. Якщо використовуєте IP whitelist, додайте <code>185.104.44.194</code>.</p><p>3. Дочекайтеся «З’єднання з біржею OK». Підключення саме по собі не запускає торгівлю.</p><div class="obActions"><button class="btn primary" data-ob="api">Відкрити форму API</button><button class="btn" data-ob="support">Допоможіть із цим кроком</button></div>'+(apiStatus()==='INVALID'?'<p role="alert">Ключі збережено, але Binance їх не підтвердив. Перевірте права та IP або напишіть підтримці.</p>':'')+'</div>';
      if(choice==='later')content='<p>Повертайтеся, коли буде зручно. Підказка й підтримка залишаються тут.</p>';
      banner.innerHTML='<div class="obEyebrow">Початок роботи зі StrIM</div><h2 class="obTitle">Вітаємо! З чого вам зручніше почати?</h2><p class="obText">Спочатку можна розібратися та поставити запитання. Підключати Binance одразу не потрібно.</p><div class="obActions"><button class="btn '+(choice==='explore'?'primary':'')+'" data-choice="explore">Показати, як це працює</button><button class="btn '+(choice==='help'?'primary':'')+'" data-choice="help">Допомогти підключити Binance</button><button class="btn '+(choice==='self'?'primary':'')+'" data-choice="self">Налаштую самостійно</button></div>'+content+'<p id="obNotice" role="status" hidden></p><p class="muted obSmall">'+trial+' <a href="/tariffs.html" target="_blank" rel="noopener">Умови сервісу</a></p><div class="obActions"><button class="xbtn" data-choice="later">Перейти до звичайного кабінету</button><a href="mailto:support@strim.in.ua">support@strim.in.ua</a></div>';
    }
    banner.querySelectorAll('[data-choice]').forEach(b=>b.onclick=()=>select(b.dataset.choice));
    banner.querySelectorAll('[data-barrier]').forEach(b=>b.onclick=()=>selectBarrier(b.dataset.barrier));
    banner.querySelectorAll('[data-ob]').forEach(b=>b.onclick=()=>{const act=b.dataset.ob;if(act==='support')openSupport();else if(act==='api')openApi();else if(act==='telegram')strimFocusTelegram();else if(act==='quick')strimFocusQuickStart();});
  }
  function closeSupport(){if(modal)modal.remove();modal=null;historyFingerprint='';}
  async function loadHistory(){
    if(!modal || historyLoading || document.hidden)return;historyLoading=true;
    try{const j=await request('support_history.php');if(!modal)return;const container=$('obHistory');const sig=JSON.stringify(j.messages);if(sig!==historyFingerprint){historyFingerprint=sig;container.innerHTML=j.messages.length?j.messages.map(m=>'<div class="obMessage '+(m.kind==='support'?'obMessageSupport':'')+'"><div class="muted obSmall">'+(m.kind==='support'?'Підтримка':'Ви')+' · '+esc(m.created_at)+'</div><div class="obMessageBody">'+esc(m.body)+'</div></div>').join(''):'<p class="muted">Тут буде ваша розмова з підтримкою.</p>';container.scrollTop=container.scrollHeight;}
      if(!document.hidden && modal){const through=Math.max(0,...j.messages.filter(m=>m.kind==='support').map(m=>Number(m.id)));if(through){const r=await request('support_read.php',{through});unread=r.unread||0;updateBadge();}}
    }catch(e){if($('obSupportStatus'))$('obSupportStatus').textContent='Історію не вдалося завантажити. Спробуйте оновити або напишіть support@strim.in.ua.';}finally{historyLoading=false;}
  }
  function openSupport(){
    if(modal)return;
    if(typeof closeNewsModal==='function')closeNewsModal();
    const previous=document.activeElement;
    modal=document.createElement('div');modal.id='supportModal';modal.className='obModal';modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.setAttribute('aria-labelledby','obSupportTitle');
    modal.innerHTML='<section class="obModalPanel"><header class="obModalHead"><h2 id="obSupportTitle">Підтримка StrIM</h2><button class="xbtn" id="obSupportClose" aria-label="Закрити розмову">Закрити</button></header><div class="obModalBody"><p>Розмова оновлюється онлайн. Якщо Telegram підключено — отримаєте також сповіщення там; інакше на email. Не надсилайте API-ключі та паролі.</p><div id="obHistory" aria-live="polite"></div><p class="muted obSmall obLiveHint"><span class="obLiveDot" aria-hidden="true"></span>Онлайн · відповіді з’являються самі</p><label for="obSupportText">Ваше запитання</label><textarea id="obSupportText" rows="4" maxlength="4000" placeholder="Що не виходить або що хочете уточнити?"></textarea><p id="obSupportStatus" role="status"></p><button class="btn primary" id="obSupportSend">Надіслати</button></div></section>';
    document.body.appendChild(modal);$('obSupportText').value=barrier?'Хочу отримати допомогу: '+labels[barrier]+'. ':'';
    const close=()=>{closeSupport();if(previous&&previous.isConnected)previous.focus();};
    $('obSupportClose').onclick=close;modal.onclick=e=>{if(e.target===modal)close();};
    modal.onkeydown=e=>{if(e.key==='Escape')close();if(e.key==='Tab'){const items=modal.querySelectorAll('button:not([disabled]),textarea');const first=items[0],last=items[items.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}};
    $('obSupportSend').onclick=async()=>{const input=$('obSupportText'),button=$('obSupportSend'),status=$('obSupportStatus'),text=input.value.trim();if(text.length<12){status.textContent='Напишіть трохи докладніше — від 12 символів.';return;}button.disabled=true;status.textContent='Надсилання…';try{await request('support_send.php',{message:text});input.value='';status.textContent='Надіслано. Підтримка побачить це одразу; відповідь з’явиться тут автоматично.';await loadHistory();}catch(e){status.textContent=e.message==='rate_limited'?'Забагато повідомлень за годину. Спробуйте пізніше.':'Не вдалося зберегти звернення. Текст залишився у формі; спробуйте ще раз.';}finally{if(button.isConnected)button.disabled=false;}};
    $('obSupportClose').focus();void loadHistory();
  }
  window.StrIMOnboarding={render,openSupport,closeSupport};
  function boot(){render();const b=$('btnSupportTop');if(b)b.onclick=openSupport;if(location.hash==='#support'){opening=true;void loadState(true).finally(()=>{openSupport();opening=false;});}}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
  window.addEventListener('hashchange',()=>{if(location.hash==='#support')openSupport();});
  document.addEventListener('visibilitychange',()=>{
    if(document.hidden || opening) return;
    if(modal) void loadHistory();
    else if(model()) void loadState(false);
  });
  // Live chat while modal open; unread badge while browsing the cabinet.
  setInterval(()=>{if(document.hidden || opening)return;if(modal)void loadHistory();},2500);
  setInterval(()=>{if(document.hidden || opening || modal)return;if(model())void loadState(Date.now()-lastVisit>300000);},10000);
})();
