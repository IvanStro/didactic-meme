<?php
require __DIR__ . '/../auth/api_gate_json.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'user'=>$auth_user], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
