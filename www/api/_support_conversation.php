<?php
declare(strict_types=1);

function strim_support_history(mysqli $db, int $uid): array {
    $items=[];
    $s=$db->prepare('SELECT id,body,created_at FROM support_messages WHERE user_id=? ORDER BY id DESC LIMIT 100');
    $s->bind_param('i',$uid);$s->execute();$res=$s->get_result();
    while($r=$res->fetch_assoc()) $items[]=['id'=>(int)$r['id'],'kind'=>'user','ticket_id'=>(int)$r['id'],'body'=>$r['body'],'created_at'=>$r['created_at']];
    $s->close();
    $s=$db->prepare('SELECT id,ticket_id,body,created_at,read_at,notification_channel,notification_status FROM strim_support_replies WHERE user_id=? ORDER BY id DESC LIMIT 100');
    $s->bind_param('i',$uid);$s->execute();$res=$s->get_result();
    while($r=$res->fetch_assoc()) { $r['id']=(int)$r['id'];$r['kind']='support';$items[]=$r; }
    $s->close();
    usort($items,static fn($a,$b)=>strcmp($a['created_at'],$b['created_at']) ?: (($a['kind']===$b['kind']) ? ($a['id']<=>$b['id']) : ($a['kind']==='user' ? -1 : 1)));
    return $items;
}

function strim_support_unread(mysqli $db,int $uid): int {
    $s=$db->prepare('SELECT COUNT(*) FROM strim_support_replies WHERE user_id=? AND read_at IS NULL');
    $s->bind_param('i',$uid);$s->execute();$n=(int)$s->get_result()->fetch_row()[0];$s->close();return $n;
}

/** Persist first; a failed optional notification must never lose the reply. */
function strim_support_add_reply(mysqli $db,int $uid,?int $ticketId,?int $authorId,string $body,string $source,string $key): array {
    if(mb_strlen(trim($body))<1 || mb_strlen($body)>3500) throw new InvalidArgumentException('message_length');
    if(!in_array($source,['admin','telegram'],true) || !preg_match('/^[a-zA-Z0-9:_-]{1,96}$/',$key)) throw new InvalidArgumentException('source_key');
    $s=$db->prepare('SELECT id FROM users WHERE id=?');$s->bind_param('i',$uid);$s->execute();$exists=$s->get_result()->fetch_assoc();$s->close();
    if(!$exists) throw new InvalidArgumentException('user_missing');
    if($ticketId!==null) {
        $s=$db->prepare('SELECT id FROM support_messages WHERE id=? AND user_id=?');$s->bind_param('ii',$ticketId,$uid);$s->execute();$owned=$s->get_result()->fetch_assoc();$s->close();
        if(!$owned) throw new InvalidArgumentException('ticket_missing');
    }
    $s=$db->prepare('INSERT IGNORE INTO strim_support_replies(ticket_id,user_id,author_user_id,body,source,source_key,created_at) VALUES(?,?,?,?,?,?,NOW())');
    $s->bind_param('iiisss',$ticketId,$uid,$authorId,$body,$source,$key);$s->execute();$created=$s->affected_rows===1;$s->close();
    $s=$db->prepare('SELECT id,user_id FROM strim_support_replies WHERE source_key=?');$s->bind_param('s',$key);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
    if(!$r || (int)$r['user_id']!==$uid) throw new InvalidArgumentException('request_conflict');
    return ['id'=>(int)$r['id'],'created'=>$created];
}

function strim_support_mark_read(mysqli $db,int $uid,int $through): void {
    if($through<=0)return;
    $s=$db->prepare('UPDATE strim_support_replies SET read_at=NOW() WHERE user_id=? AND id<=? AND read_at IS NULL');
    $s->bind_param('ii',$uid,$through);$s->execute();$s->close();
}

function strim_support_notify_reply(mysqli $db,int $uid,int $replyId,string $body): array {
    $s=$db->prepare('SELECT email,tg_chat_id,tg_enabled FROM users WHERE id=?');$s->bind_param('i',$uid);$s->execute();$u=$s->get_result()->fetch_assoc();$s->close();
    $channel='none';$status='failed';
    try {
        $token=trim((string)($_ENV['TG_BOT_TOKEN']??''));
        if($u && (int)$u['tg_enabled']===1 && !empty($u['tg_chat_id']) && $token!=='') {
            require_once __DIR__.'/_tg_notify.php';
            $html="<b>Підтримка StrIM</b>\n\n".htmlspecialchars($body,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n\nІсторія розмови: https://strim.in.ua/dashboard_saas.html#support";
            [$ok]=tg_send_html_raw($token,(string)$u['tg_chat_id'],$html,[]);
            if($ok){$channel='telegram';$status='sent';}
        }
        if($status!=='sent' && $u && filter_var($u['email'],FILTER_VALIDATE_EMAIL)) {
            require_once __DIR__.'/_mail_send.php';
            $result=strim_mail_send($u['email'],'Відповідь підтримки StrIM',$body."\n\nІсторія розмови та відповідь: https://strim.in.ua/dashboard_saas.html#support\n\nAPI-ключі та паролі не надсилайте в повідомленнях.",'support@strim.in.ua');
            $channel='email';$status=!empty($result['ok'])?'accepted':'failed';
        }
    } catch(Throwable $e) {error_log('strim_support_notification failed; code='.(int)$e->getCode());}
    $s=$db->prepare('UPDATE strim_support_replies SET notification_channel=?,notification_status=? WHERE id=? AND user_id=?');
    $s->bind_param('ssii',$channel,$status,$replyId,$uid);$s->execute();$s->close();
    return ['channel'=>$channel,'status'=>$status];
}
