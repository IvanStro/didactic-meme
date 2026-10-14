<?php
header('Content-Type: application/json; charset=UTF-8');
$log = dirname(__DIR__).'/logs/tick.log';
if (!file_exists($log)) { echo json_encode(['ok'=>false,'error'=>'no log']); exit; }
$line = '';
$fp = fopen($log, 'r');
$pos = -1; $char = '';
fseek($fp, $pos, SEEK_END);
$char = fgetc($fp);
while ($char === "\n") { fseek($fp, --$pos, SEEK_END); $char = fgetc($fp); }
while ($char !== false && $char !== "\n") { $line = $char.$line; fseek($fp, --$pos, SEEK_END); $char = fgetc($fp); }
fclose($fp);
echo json_encode(['ok'=>true,'line'=>$line]);
