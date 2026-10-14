<?php
declare(strict_types=1);
require_once __DIR__.'/_api_bootstrap.php';require_once __DIR__.'/_csrf.php';require_once __DIR__.'/_support_conversation.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'method']);exit;}
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);$db->set_charset('utf8mb4');$uid=current_user_id($db);
if($uid<=0){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);exit;}
require_csrf_or_403();
$in=json_decode((string)file_get_contents('php://input'),true) ?: [];
strim_support_mark_read($db,$uid,max(0,(int)($in['through']??0)));
echo json_encode(['ok'=>true,'unread'=>strim_support_unread($db,$uid)]);
