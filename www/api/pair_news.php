<?php
declare(strict_types=1);
/**
 * Останні новини за базовим активом пари (RSS CoinDesk / Cointelegraph / Decrypt / The Block),
 * фільтрація за ключовими словами, переклад заголовка та короткого опису EN→UK (MyMemory, best-effort).
 * Кеш ~30 хв на символ. Лише для авторизованих користувачів.
 */

require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

if (!defined('STRIM_PUBLIC_BASE_URL')) {
    define('STRIM_PUBLIC_BASE_URL', rtrim((string)($_ENV['PUBLIC_BASE_URL'] ?? 'https://strim.in.ua'), '/'));
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($_GET['symbol'] ?? $_POST['symbol'] ?? '')));
if ($symbol === '' || strlen($symbol) > 32) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_symbol'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$symEsc = $mysqli->real_escape_string($symbol);
$own = $mysqli->query('SELECT id FROM strategies WHERE owner_id=' . (int)$uid . " AND symbol='" . $symEsc . "' LIMIT 1");
if (!$own || !$own->fetch_assoc()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'symbol_not_owned'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array{0:int,1:string,2:string,3:string} */
function http_get(string $url, int $timeoutSec = 12): array
{
    if (!function_exists('curl_init')) {
        return [0, '', 'curl_missing', ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => $timeoutSec,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER => [
            'Accept: application/rss+xml, application/xml, text/xml, */*;q=0.8',
            'User-Agent: Mozilla/5.0 (compatible; StrIMPairNews/1.0; +' . STRIM_PUBLIC_BASE_URL . ')',
        ],
    ]);
    $body = (string)curl_exec($ch);
    $errno = curl_errno($ch);
    $err = $errno ? (string)curl_error($ch) : '';
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body, $errno ? 'curl_' . $errno : '', $err];
}

function base_asset_from_pair(string $sym): string
{
    static $quotes = ['USDT', 'USDC', 'BUSD', 'FDUSD', 'TUSD', 'BTC', 'ETH', 'BNB', 'TRY', 'EUR', 'GBP', 'BRL', 'AUD'];
    foreach ($quotes as $q) {
        $lenQ = strlen($q);
        if (strlen($sym) > $lenQ && str_ends_with($sym, $q)) {
            return substr($sym, 0, -$lenQ);
        }
    }
    return $sym;
}

/** @return list<string> */
function keyword_variants(string $base): array
{
    static $aliases = [
        'BTC' => ['bitcoin'],
        'ETH' => ['ethereum'],
        'XRP' => ['ripple'],
        'BNB' => ['binance coin', 'bnb chain'],
        'SOL' => ['solana'],
        'ADA' => ['cardano'],
        'DOGE' => ['dogecoin'],
        'DOT' => ['polkadot'],
        'MATIC' => ['polygon', 'matic'],
        'LTC' => ['litecoin'],
        'SHIB' => ['shiba inu', 'shiba'],
        'AVAX' => ['avalanche'],
        'LINK' => ['chainlink'],
        'ATOM' => ['cosmos'],
        'UNI' => ['uniswap'],
        'XLM' => ['stellar'],
        'BCH' => ['bitcoin cash'],
        'NEAR' => ['near protocol'],
        'APT' => ['aptos'],
        'ARB' => ['arbitrum'],
        'OP' => ['optimism'],
        'SUI' => ['sui'],
        'PEPE' => ['pepe'],
        'WLD' => ['worldcoin'],
        'TON' => ['toncoin', 'the open network'],
        'FIL' => ['filecoin'],
        'ICP' => ['internet computer'],
        'HBAR' => ['hedera'],
        'VET' => ['vechain'],
        'MKR' => ['makerdao', 'maker'],
        'AAVE' => ['aave'],
        'GRT' => ['the graph'],
        'ALGO' => ['algorand'],
        'FTM' => ['fantom'],
        'EOS' => ['eos'],
        'XTZ' => ['tezos'],
        'SAND' => ['sandbox'],
        'MANA' => ['decentraland'],
        'AXS' => ['axie infinity'],
        'THETA' => ['theta network'],
        'RUNE' => ['thorchain'],
        'LDO' => ['lido'],
        'CRV' => ['curve'],
        'SNX' => ['synthetix'],
        'COMP' => ['compound'],
        'INJ' => ['injective'],
        'TIA' => ['celestia'],
        'SEI' => ['sei network'],
        'STRK' => ['starknet'],
        'JUP' => ['jupiter'],
        'WIF' => ['dogwifhat'],
        'BONK' => ['bonk'],
        'FLOKI' => ['floki'],
        'PYTH' => ['pyth network'],
        'ORDI' => ['ordinals'],
        'STX' => ['stacks'],
        'IMX' => ['immutable'],
        'RENDER' => ['render network'],
        'FET' => ['fetch.ai'],
        'AGIX' => ['singularitynet'],
        'RNDR' => ['render token'],
    ];
    $b = strtoupper($base);
    $out = [strtolower($b)];
    if (isset($aliases[$b])) {
        foreach ($aliases[$b] as $a) {
            $out[] = strtolower($a);
        }
    }
    return array_values(array_unique(array_filter($out)));
}

function text_blob_match(string $blob, string $base, array $variants): bool
{
    $blob = strtolower($blob);
    foreach ($variants as $kw) {
        if ($kw === '') {
            continue;
        }
        if (strlen($kw) <= 2) {
            continue;
        }
        if (strpos($blob, $kw) !== false) {
            return true;
        }
    }
    $b = strtolower($base);
    if (strlen($b) >= 4 && preg_match('/\b' . preg_quote($b, '/') . '\b/u', $blob)) {
        return true;
    }
    return false;
}

/** @return list<array{title:string,link:string,summary:string,ts:int,source:string}> */
function parse_rss_channel(string $body, string $sourceLabel, int $maxItems): array
{
    $out = [];
    $sx = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
    if ($sx === false) {
        return $out;
    }
    if (isset($sx->channel->item)) {
        $items = $sx->channel->item;
    } elseif (isset($sx->item)) {
        $items = $sx->item;
    } else {
        return $out;
    }
    $n = 0;
    foreach ($items as $it) {
        if ($n >= $maxItems) {
            break;
        }
        $title = trim(html_entity_decode(strip_tags((string)$it->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $link = '';
        if (isset($it->link)) {
            $lnk = $it->link;
            if (is_object($lnk) && isset($lnk['href'])) {
                $link = (string)$lnk['href'];
            } else {
                $link = trim((string)$lnk);
            }
        }
        if ($link === '' || $title === '') {
            continue;
        }
        $desc = '';
        if (isset($it->description)) {
            $desc = (string)$it->description;
        }
        if ($desc === '') {
            $enc = $it->children('http://purl.org/rss/1.0/modules/content/', true);
            if (isset($enc->encoded)) {
                $desc = (string)$enc->encoded;
            }
        }
        $desc = trim(html_entity_decode(strip_tags($desc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $pub = isset($it->pubDate) ? strtotime((string)$it->pubDate) : 0;
        if ($pub <= 0) {
            $pub = time();
        }
        $out[] = [
            'title' => $title,
            'link' => $link,
            'summary' => $desc !== '' ? mb_substr($desc, 0, 420) : '',
            'ts' => $pub,
            'source' => $sourceLabel,
        ];
        $n++;
    }
    return $out;
}

/** @return array{text:string,ok:bool} */
function translate_en_uk(string $text): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return ['text' => '', 'ok' => true];
    }
    if (mb_strlen($text) > 420) {
        $text = mb_substr($text, 0, 417) . '…';
    }
    $url = 'https://api.mymemory.translated.net/get?' . http_build_query([
        'q' => $text,
        'langpair' => 'en|uk',
    ], '', '&', PHP_QUERY_RFC3986);
    [$code, $body] = http_get($url, 10);
    if ($code !== 200 || $body === '') {
        return ['text' => $text, 'ok' => false];
    }
    $j = json_decode($body, true);
    if (!is_array($j)) {
        return ['text' => $text, 'ok' => false];
    }
    $tr = (string)($j['responseData']['translatedText'] ?? '');
    $st = (string)($j['responseStatus'] ?? '');
    if ($tr === '' || stripos($tr, 'MYMEMORY WARNING') !== false || stripos($st, '429') !== false) {
        return ['text' => $text, 'ok' => false];
    }
    return ['text' => $tr, 'ok' => true];
}

$base = base_asset_from_pair($symbol);
$variants = keyword_variants($base);

$cacheDir = sys_get_temp_dir();
$cacheKey = 'ld_pair_news_' . md5(strtoupper($symbol)) . '_' . (string)intdiv(time(), 1800);
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';

if (is_readable($cacheFile) && (time() - (int)filemtime($cacheFile)) < 1900) {
    $cached = (string)file_get_contents($cacheFile);
    if ($cached !== '') {
        echo $cached;
        exit;
    }
}

$feeds = [
    ['https://www.coindesk.com/arc/outboundfeeds/rss/?outputType=xml', 'CoinDesk'],
    ['https://cointelegraph.com/rss', 'Cointelegraph'],
    ['https://decrypt.co/feed', 'Decrypt'],
    ['https://www.theblock.co/rss.xml', 'The Block'],
];

$merged = [];
foreach ($feeds as [$u, $label]) {
    [$code, $body] = http_get($u, 14);
    if ($code !== 200 || $body === '') {
        continue;
    }
    foreach (parse_rss_channel($body, $label, 22) as $row) {
        $blob = $row['title'] . ' ' . $row['summary'];
        if (!text_blob_match($blob, $base, $variants)) {
            continue;
        }
        $key = md5($row['link']);
        if (isset($merged[$key])) {
            continue;
        }
        $merged[$key] = $row;
    }
}

usort($merged, static function ($a, $b) {
    return ($b['ts'] <=> $a['ts']);
});
$merged = array_slice(array_values($merged), 0, 7);

$items = [];
foreach ($merged as $idx => $row) {
    if ($idx > 0) {
        usleep(120000);
    }
    $t1 = translate_en_uk($row['title']);
    $sum = $row['summary'] !== '' ? $row['summary'] : mb_substr($row['title'], 0, 200);
    $t2 = translate_en_uk($sum);
    $items[] = [
        'title_uk' => $t1['text'],
        'summary_uk' => $t2['text'],
        'link' => $row['link'],
        'source' => $row['source'],
        'published' => gmdate('c', $row['ts']),
        'translation_ok' => $t1['ok'] && $t2['ok'],
        'title_en' => $row['title'],
    ];
}

$payload = [
    'ok' => true,
    'symbol' => $symbol,
    'base' => $base,
    'cached_until' => gmdate('c', intdiv(time(), 1800) * 1800 + 1800),
    'disclaimer' => 'Матеріали зібрані з публічних RSS-стрічок незалежних медіа (англійською). Переклад автоматичний (MyMemory) і може бути неточним; юридично значущі формулювання завжди звіряйте з оригіналом за посиланням.',
    'sources' => ['CoinDesk', 'Cointelegraph', 'Decrypt', 'The Block'],
    'items' => $items,
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json !== false) {
    @file_put_contents($cacheFile, $json, LOCK_EX);
}

echo $json !== false ? $json : json_encode(['ok' => false, 'error' => 'encode'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
