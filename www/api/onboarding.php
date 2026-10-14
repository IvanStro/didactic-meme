<?php
declare(strict_types=1);
require_once __DIR__.'/_api_bootstrap.php';
require_once __DIR__.'/_csrf.php';
require_once __DIR__.'/_onboarding.php';
require_once __DIR__.'/_support_conversation.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);$db->set_charset('utf8mb4');
$uid=current_user_id($db);
if($uid<=0){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);exit;}
$method=$_SERVER['REQUEST_METHOD'];
if($method==='POST') {
    require_csrf_or_403();
    $in=json_decode((string)file_get_contents('php://input'),true) ?: [];
    $field=(string)($in['action']??'');$value=(string)($in['value']??'');
    $valid=($field==='visit') || ($field==='choice' && in_array($value,['explore','help','self','later'],true)) || ($field==='barrier' && in_array($value,['api_how','api_trust','no_binance','question'],true));
    if(!$valid){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'bad_action']);exit;}
    strim_ob_record($db,$uid,$field,$value);
} elseif($method!=='GET'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'method']);exit;}
echo json_encode(['ok'=>true,'user_id'=>$uid,'state'=>strim_ob_state($db,$uid),'unread'=>strim_support_unread($db,$uid)],JSON_UNESCAPED_UNICODE);
