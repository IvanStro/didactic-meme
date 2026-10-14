<?php
http_response_code(410); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'bonus_wallet_not_supported']); exit;
