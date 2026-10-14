<?php
declare(strict_types=1);
require_once __DIR__.'/_api_bootstrap.php';require_once __DIR__.'/_csrf.php';require_once __DIR__.'/_support_conversation.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);$db->set_charset('utf8mb4');$admin=current_user_id($db);
if($admin<=0){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);exit;}
$r=$db->query('SELECT is_admin FROM users WHERE id='.(int)$admin)->fetch_assoc();
if(!$r || (int)$r['is_admin']!==1){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'forbidden']);exit;}
if($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf_or_403();
    $in=json_decode((string)file_get_contents('php://input'),true) ?: [];
    $uid=(int)($in['user_id']??0);$body=trim((string)($in['message']??''));$nonce=(string)($in['request_id']??'');
    if($uid<=0 || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/',$nonce) || mb_strlen($body)<1 || mb_strlen($body)>3500){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'bad_request']);exit;}
    $ticket=(int)($in['ticket_id']??0);
    try {
        $reply=strim_support_add_reply($db,$uid,$ticket>0?$ticket:null,$admin,$body,'admin','admin:'.$admin.':'.$nonce);
        $notification=['channel'=>'none','status'=>'not_requested'];
        if($reply['created'] && !empty($in['notify'])) $notification=strim_support_notify_reply($db,$uid,$reply['id'],$body);
        echo json_encode(['ok'=>true,'reply_id'=>$reply['id'],'created'=>$reply['created'],'notification'=>$notification]);
    } catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
    exit;
}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'method']);exit;}
if(isset($_GET['user_id'])) {
    $uid=max(0,(int)$_GET['user_id']);
    echo json_encode(['ok'=>true,'messages'=>strim_support_history($db,$uid)],JSON_UNESCAPED_UNICODE);exit;
}
$q=mb_substr(trim((string)($_GET['q']??'')),0,100);$like='%'.$q.'%';$before=max(0,(int)($_GET['before']??0));
$sql="SELECT u.id,u.nickname,u.email,u.telegram,u.created_at,
 (u.api_key_enc IS NOT NULL AND u.api_key_enc<>'' AND u.api_secret_enc IS NOT NULL AND u.api_secret_enc<>'') AS api_saved,
 (u.tg_enabled=1 AND u.tg_chat_id IS NOT NULL AND u.tg_chat_id<>0) AS tg_linked,
 o.choice,o.barrier,o.last_seen_at,o.help_requested_at,o.api_status,o.api_checked_at,o.api_verified_at,
 (SELECT MIN(created_at) FROM strategies s WHERE s.user_id=u.id AND UPPER(s.symbol)<>'TEMPLATE') AS strategy_created_at,
 (SELECT MIN(created_at) FROM trade_ledger t WHERE t.user_id=u.id AND t.side IN ('BUY','SELL')) AS first_trade_at,
 (SELECT MAX(created_at) FROM support_messages m WHERE m.user_id=u.id) AS last_question_at,
 (SELECT MAX(created_at) FROM strim_support_replies p WHERE p.user_id=u.id) AS last_reply_at
 FROM users u LEFT JOIN strim_onboarding_state o ON o.user_id=u.id
 WHERE COALESCE(u.is_admin,0)=0 AND (?='' OR u.email LIKE ? OR u.nickname LIKE ? OR CAST(u.id AS CHAR)=?) AND (?=0 OR u.id<?)
 ORDER BY u.id DESC LIMIT 100";
$s=$db->prepare($sql);$s->bind_param('ssssii',$q,$like,$like,$q,$before,$before);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
foreach($rows as &$u) {
    $u['stage']=$u['first_trade_at']?'Перша операція':($u['strategy_created_at']?'Стратегію створено':($u['api_status']==='CONNECTED'?'API перевірено':($u['api_saved']?'Ключі збережено':($u['tg_linked']?'Telegram підключено':($u['choice']?'Обрано крок':'Реєстрація')))));
    $u['needs_reply']=!empty($u['last_question_at']) && (empty($u['last_reply_at']) || $u['last_reply_at']<$u['last_question_at']);
}unset($u);
echo json_encode(['ok'=>true,'users'=>$rows,'next_before'=>count($rows)===100?(int)end($rows)['id']:null],JSON_UNESCAPED_UNICODE);
