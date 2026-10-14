<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$_ENV['DB_NAME']);$db->set_charset('utf8mb4');
// Only optional marketing events. Never financial, trading, legal-acceptance or dispute records.
$dry=in_array('--dry-run',$argv,true);
$r=$db->query('SELECT COUNT(*) AS n FROM web_analytics_events WHERE event_at < UTC_TIMESTAMP() - INTERVAL 90 DAY');
$n=(int)$r->fetch_assoc()['n'];
if(!$dry && $n>0)$db->query('DELETE FROM web_analytics_events WHERE event_at < UTC_TIMESTAMP() - INTERVAL 90 DAY LIMIT 10000');
echo json_encode(['dry_run'=>$dry,'eligible_events'=>$n,'deleted'=>$dry?0:max(0,$db->affected_rows)])."\n";
// Rate-limit tokens contain only a daily/hourly hashed identifier; expire abandoned files.
if(!$dry)foreach(glob(sys_get_temp_dir().'/strim_consumer_*')?:[] as $f)if(is_file($f)&&filemtime($f)<time()-2*86400)@unlink($f);
