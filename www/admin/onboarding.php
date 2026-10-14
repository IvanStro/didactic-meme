<?php
declare(strict_types=1);
require_once __DIR__.'/../api/_api_bootstrap.php';require_once __DIR__.'/../api/_csrf.php';
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);$db->set_charset('utf8mb4');$uid=current_user_id($db);
if($uid<=0){header('Location: /auth/login.php?next='.urlencode('/admin/onboarding.php'));exit;}
$r=$db->query('SELECT is_admin FROM users WHERE id='.(int)$uid)->fetch_assoc();
if(!$r || (int)$r['is_admin']!==1){http_response_code(403);echo '403 Forbidden';exit;}
$csrf=ensure_csrf_token();header('Cache-Control: no-store');
?>
<!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Новачки та підтримка — StrIM</title>
<link rel="stylesheet" href="/admin/admin-panel.css?v=20261013live1">
<style>
body{margin:0;background:#0b1220;color:#e5e7eb;font:14px/1.5 system-ui}
main{max-width:1400px;margin:auto;padding:22px}
a{color:#60a5fa}
button,input,textarea{font:inherit}
input,textarea{background:#111827;color:#e5e7eb;border:1px solid #334155;border-radius:8px;padding:9px}
button{background:#17283d;color:#e5e7eb;border:1px solid #334155;border-radius:8px;padding:9px;cursor:pointer}
button:disabled{opacity:.5;cursor:wait}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;border-bottom:1px solid #334155;padding:9px;vertical-align:top}
th{font-size:12px;color:#94a3b8}
.muted{color:#94a3b8}
.grid{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:18px}
.scroll{overflow:auto}
.chat{background:#111827;padding:18px;border-radius:12px}
.msg{white-space:pre-wrap;overflow-wrap:anywhere;padding:10px;border-bottom:1px solid #334155}
.msg-support{border-left:3px solid #60a5fa}
.msg-user-new{border-left:3px solid #fbbf24;background:rgba(251,191,36,.08)}
#history{max-height:50vh;overflow:auto}
textarea{box-sizing:border-box;width:100%}
.row{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0;align-items:center}
tr.needs-reply{background:linear-gradient(90deg,rgba(245,158,11,.22),rgba(245,158,11,.06) 55%,transparent);box-shadow:inset 3px 0 0 #f59e0b}
tr.needs-reply td{border-bottom-color:rgba(245,158,11,.35)}
tr.needs-reply.is-selected{outline:1px solid rgba(96,165,250,.55)}
.badge-wait{
  display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:4px 10px;border-radius:999px;
  background:#f59e0b;color:#111827;font-weight:700;font-size:12px;letter-spacing:.02em;
  animation:pulse-wait 1.4s ease-in-out infinite;
}
.live-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 0 rgba(34,197,94,.7);animation:live-ping 1.6s ease-out infinite}
.live-hint{display:inline-flex;align-items:center;gap:8px;font-size:12px;color:#86efac}
@keyframes pulse-wait{0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(245,158,11,.55)}50%{transform:scale(1.03);box-shadow:0 0 0 8px rgba(245,158,11,0)}}
@keyframes live-ping{0%{box-shadow:0 0 0 0 rgba(34,197,94,.55)}70%{box-shadow:0 0 0 8px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
#awaitCount{font-weight:700;color:#fbbf24}
@media(max-width:1000px){.grid{grid-template-columns:1fr}main{padding:12px}}
</style></head><body><main>
<div class="row"><a href="/admin/users.php">← Адмінка</a><a href="/dashboard_saas.html">Кабінет</a></div>
<h1>Новачки та підтримка</h1>
<p class="muted">Останній крок, причина зупинки та розмова в кабінеті. Рядки з новим повідомленням підсвічені. Розмова оновлюється онлайн.</p>
<div class="row">
  <input id="search" type="search" placeholder="Email, нік або ID" aria-label="Пошук користувача">
  <button id="refresh">Оновити список</button>
  <button id="reset">Перші 100</button>
  <button id="next" disabled>Наступні 100</button>
  <span class="live-hint" title="Список і відкрита розмова оновлюються автоматично"><span class="live-dot" aria-hidden="true"></span>Онлайн</span>
</div>
<p id="status" class="muted" role="status"></p>
<div class="grid"><div class="scroll"><table><thead><tr><th>Людина</th><th>Крок / активність</th><th>Запит допомоги</th><th>Підключення</th><th></th></tr></thead><tbody id="users"></tbody></table></div>
<section class="chat">
  <h2 id="who">Розмова</h2>
  <p class="muted">Оберіть людину. Відповідь збережеться в її кабінеті й з’явиться там одразу (без «Оновити»). Позначка відкриття — лише після того, як користувач відкриє розмову.</p>
  <div id="history"></div>
  <label for="reply">Ваше повідомлення</label>
  <textarea id="reply" rows="5" maxlength="3500" placeholder="Допоможіть людині зробити наступний крок. API-ключі не запитуйте."></textarea>
  <label><input id="notify" type="checkbox" checked> Додатково сповістити через підключений Telegram; якщо недоступний — email</label>
  <div class="row"><button id="send" disabled>Надіслати відповідь</button></div>
  <p id="replyStatus" role="status"></p>
</section></div></main>
<script>
const csrf=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const $=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const barriers={api_how:'Як створити API',api_trust:'Сумніви щодо доступу',no_binance:'Немає Binance',question:'Запитання'};
const choices={explore:'Переглянути приклад',help:'Потрібна допомога',self:'Самостійне налаштування',later:'Пізніше'};
const TITLE_BASE='Новачки та підтримка — StrIM';
let selected=null,ticket=null,before=0,next=null,pending=null;
let usersCache=[],listLoading=false,chatLoading=false,chatFingerprint='',awaitIds=new Set();
let titleFlash=null;

async function api(query={},body=null){
  const u=new URL('/auth/api_gate.php',location.origin);
  u.searchParams.set('path','admin_onboarding.php');
  Object.entries(query).forEach(([k,v])=>u.searchParams.set(k,v));
  const r=await fetch(u,{
    method:body?'POST':'GET',
    credentials:'include',
    cache:'no-store',
    headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},
    body:body?JSON.stringify(body):null
  });
  const j=await r.json();
  if(!r.ok||!j.ok) throw Error(j.error||'Не вдалося завантажити');
  return j;
}

function setTitleFlash(on){
  if(!on){
    if(titleFlash){clearInterval(titleFlash);titleFlash=null;}
    document.title=TITLE_BASE;
    return;
  }
  if(titleFlash || !document.hidden) return;
  let flip=false;
  titleFlash=setInterval(()=>{
    flip=!flip;
    document.title=flip?'● Нове повідомлення — StrIM':TITLE_BASE;
  },900);
}

function renderUsers(users){
  const sorted=[...users].sort((a,b)=>{
    const aw=a.needs_reply?1:0,bw=b.needs_reply?1:0;
    if(aw!==bw) return bw-aw;
    return (Number(b.id)||0)-(Number(a.id)||0);
  });
  const waiting=sorted.filter(u=>u.needs_reply).length;
  $('users').innerHTML=sorted.map(u=>{
    const wait=!!u.needs_reply;
    const sel=selected && Number(selected.id)===Number(u.id);
    return `<tr class="${wait?'needs-reply':''}${sel?' is-selected':''}" data-uid="${u.id}">
      <td><b>${esc(u.nickname||'Користувач')} #${u.id}</b><br>${esc(u.email)}<br><span class="muted">Зареєстровано ${esc(u.created_at)}</span></td>
      <td>${esc(u.stage)}<br><span class="muted">Останній вхід: ${esc(u.last_seen_at||'ще не зафіксовано')}<br>${esc(choices[u.choice]||'')}</span></td>
      <td>${esc(barriers[u.barrier]||'—')}${wait?'<div class="badge-wait">Нове повідомлення</div>':''}</td>
      <td>API: ${esc(u.api_status|| (u.api_saved?'збережено, не перевірено':'немає'))}<br><span class="muted">Перевірка: ${esc(u.api_checked_at||'—')}</span><br>Бот: ${u.tg_linked?'підключено':'не підключено'}<br>Нік: ${esc(u.telegram||'—')}</td>
      <td><button type="button" data-user="${u.id}">Розмова</button></td>
    </tr>`;
  }).join('');
  $('users').querySelectorAll('[data-user]').forEach(b=>{
    b.onclick=()=>{
      selected=usersCache.find(u=>Number(u.id)===Number(b.dataset.user))||null;
      pending=null;
      chatFingerprint='';
      $('reply').value='';
      $('who').textContent=(selected.nickname||selected.email)+' #'+selected.id;
      $('send').disabled=false;
      renderUsers(usersCache);
      loadChat(true);
    };
  });
  $('status').innerHTML=waiting
    ? `Показано ${sorted.length} · <span id="awaitCount">${waiting} очікує відповіді</span>`
    : `Показано ${sorted.length} користувачів`;
  document.title=waiting?`(${waiting}) ${TITLE_BASE}`:TITLE_BASE;
  setTitleFlash(waiting>0 && document.hidden);
}

async function load(silent=false){
  if(listLoading) return;
  listLoading=true;
  if(!silent) $('status').textContent='Завантаження…';
  try{
    const j=await api({q:$('search').value,before});
    next=j.next_before;
    $('next').disabled=!next;
    usersCache=j.users||[];
    const nowAwait=new Set(usersCache.filter(u=>u.needs_reply).map(u=>Number(u.id)));
    const arrived=[...nowAwait].filter(id=>!awaitIds.has(id));
    awaitIds=nowAwait;
    if(arrived.length && document.hidden) setTitleFlash(true);
    if(selected){
      const fresh=usersCache.find(u=>Number(u.id)===Number(selected.id));
      if(fresh) selected=fresh;
    }
    renderUsers(usersCache);
  }catch(e){
    if(!silent) $('status').textContent=e.message;
  }finally{
    listLoading=false;
  }
}

function renderChat(messages){
  const lastUserId=messages.filter(m=>m.kind==='user').map(m=>m.id).pop();
  $('history').innerHTML=messages.map(m=>{
    const isUser=m.kind==='user';
    const isLatestUser=isUser && Number(m.id)===Number(lastUserId) && selected?.needs_reply;
    return `<div class="msg ${m.kind==='support'?'msg-support':''}${isLatestUser?' msg-user-new':''}">
      <b>${isUser?'Користувач':'Підтримка'}</b> · ${esc(m.created_at)}<br>${esc(m.body)}
      ${!isUser?'<br><small class="muted">'+(m.read_at?'Розмову відкрито: '+esc(m.read_at):'Розмову ще не відкрито')+(m.notification_channel?' · '+esc(m.notification_channel)+': '+esc(m.notification_status):'')+'</small>':''}
    </div>`;
  }).join('')||'<p class="muted">Повідомлень ще немає.</p>';
  $('history').scrollTop=$('history').scrollHeight;
}

async function loadChat(force=false){
  if(!selected || chatLoading) return;
  const uid=selected.id;
  chatLoading=true;
  try{
    const j=await api({user_id:uid});
    if(!selected||selected.id!==uid) return;
    ticket=null;
    (j.messages||[]).forEach(m=>{if(m.kind==='user') ticket=m.ticket_id;});
    const sig=JSON.stringify(j.messages||[]);
    if(force || sig!==chatFingerprint){
      chatFingerprint=sig;
      renderChat(j.messages||[]);
    }
  }catch(e){
    if(force) $('replyStatus').textContent=e.message;
  }finally{
    chatLoading=false;
  }
}

$('send').onclick=async()=>{
  if(!selected) return;
  const body=$('reply').value.trim();
  if(!body){$('replyStatus').textContent='Напишіть повідомлення.';return;}
  const uid=selected.id;
  if(!pending||pending.body!==body||pending.uid!==uid) pending={body,uid,key:crypto.randomUUID()};
  $('send').disabled=true;
  $('replyStatus').textContent='Збереження…';
  try{
    const j=await api({}, {user_id:uid,ticket_id:ticket,message:body,request_id:pending.key,notify:$('notify').checked});
    if(selected?.id!==uid) return;
    pending=null;
    $('reply').value='';
    $('replyStatus').textContent='Збережено в кабінеті. Сповіщення: '+j.notification.channel+' / '+j.notification.status;
    await loadChat(true);
    await load(true);
  }catch(e){
    $('replyStatus').textContent=e.message;
  }finally{
    $('send').disabled=!selected;
  }
};

$('refresh').onclick=()=>load(false);
$('reset').onclick=()=>{before=0;load(false);};
$('next').onclick=()=>{before=next||0;load(false);};
$('search').onkeydown=e=>{if(e.key==='Enter'){before=0;load(false);}};
document.addEventListener('visibilitychange',()=>{
  if(!document.hidden){
    setTitleFlash(false);
    document.title=awaitIds.size?`(${awaitIds.size}) ${TITLE_BASE}`:TITLE_BASE;
    void load(true);
    if(selected) void loadChat(true);
  }
});

setInterval(()=>{if(!document.hidden) void load(true);},5000);
setInterval(()=>{if(!document.hidden && selected) void loadChat(false);},2500);
load(false);
</script></body></html>
