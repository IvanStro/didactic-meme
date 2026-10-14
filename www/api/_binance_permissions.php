<?php
declare(strict_types=1);
/** Read-only permission check; never emits a trade or logs credentials. */
function strim_binance_permissions(string $apiKey,string $secret): array {
 $q=http_build_query(['timestamp'=>(int)(microtime(true)*1000),'recvWindow'=>10000]);
 $q.='&signature='.hash_hmac('sha256',$q,$secret);
 $ch=curl_init('https://api.binance.com/sapi/v1/account/apiRestrictions?'.$q);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['X-MBX-APIKEY: '.$apiKey],CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>6]);
 $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 $j=is_string($raw)?json_decode($raw,true):null;
 if($status!==200 || !is_array($j) || !array_key_exists('enableWithdrawals',$j)) return ['ok'=>false,'error'=>'Не вдалося перевірити дозволи Binance. Перевірте ключ, IP-обмеження та повторіть. Ключ не збережено.'];
 if($j['enableWithdrawals']!==false || !empty($j['permitsUniversalTransfer']) || !empty($j['enableInternalTransfer']) || !empty($j['enableMargin']) || !empty($j['enableFutures']) || !empty($j['enableVanillaOptions']) || !empty($j['enablePortfolioMarginTrading'])) return ['ok'=>false,'error'=>'Залиште лише Reading та Spot Trading. Вимкніть виведення, перекази, Margin, Futures та інші зайві дозволи.'];
 if(empty($j['enableReading']) || empty($j['enableSpotAndMarginTrading']) || empty($j['ipRestrict'])) return ['ok'=>false,'error'=>'Потрібні Reading, Spot Trading та обмеження за IP сервісу.'];
 return ['ok'=>true,'withdrawals'=>false,'ip_restricted'=>true];
}
