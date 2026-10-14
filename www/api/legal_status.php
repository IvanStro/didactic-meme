<?php
declare(strict_types=1);
require_once __DIR__.'/../auth/_session_user.php';
require_once __DIR__.'/_legal.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);
$uid=current_user_id($db);
if($uid<=0){http_response_code(401);echo '{"ok":false}';exit;}
echo json_encode(['ok'=>true,'accepted'=>strim_legal_has_acceptance($db,$uid),'version'=>strim_legal_manifest()['version']]);
