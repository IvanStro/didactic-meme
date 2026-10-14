<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env
$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

/**
 * Parse Telegram text (HTML allowed) into a structured journal row.
 * Returns array with keys used by engine_journal or null if not recognized.
 */
function _journal_parse(string $text): ?array {
  $plain = trim(preg_replace('/<[^>]+>/', '', $text));

  // Адмінські звіти (site_audit, watchdog, pnl_basis audit) не є торговими подіями — не парсити.
  if (preg_match('/щогодинний\s+звіт|StrIM\s+watchdog|StrIM\s+PnL\/basis\s+audit|Критична аномалія платформи|Частина\s+\d+\s*\/\s*\d+/iu', $plain)) {
    return null;
  }
  if (preg_match('/Загальний висновок:|Торгова система|SEO \(сторінки для Google\)|Заморозка докупок/u', $plain)) {
    return null;
  }

  // symbol like ZECUSDT
  if (!preg_match('/\b([A-Z0-9]{3,}USDT)\b/', $plain, $m)) {
    return null;
  }
  $symbol = $m[1];

  $event_type = '';
  $level_idx = null;
  $side = null;

  if (preg_match('/\bBUY0\b/i', $plain)) {
    $event_type = 'BUY0';
    $side = 'BUY';
  } elseif (preg_match('/\bBUY\s+L(\d+)\b/i', $plain, $mm)) {
    $event_type = 'BUY_L';
    $level_idx = (int)$mm[1];
    $side = 'BUY';
  } elseif (preg_match('/\bSUB\s+L(\d+)\s+SELL\b/i', $plain, $mm)) {
    $event_type = 'SUB_SELL';
    $level_idx = (int)$mm[1];
    $side = 'SELL';
  } elseif (preg_match('/\bMAIN\s+SELL\s+CORE\b/i', $plain)) {
    $event_type = 'CORE_SELL';
    $side = 'SELL';
  } elseif (preg_match('/\bMAIN\s+SELL\s+ALL\b/i', $plain)) {
    $event_type = 'MAIN_SELL_ALL';
    $side = 'SELL';
  } elseif (preg_match('/\bENGINE\s+EXCEPTION\b/i', $plain)) {
    $event_type = 'EXCEPTION';
  } else {
    // Not a trading/exception message we care about
    return null;
  }

  $qty = null;
  $price = null;
  $pnl_usdt = null;
  $pnl_pct = null;

  if (preg_match('/\bQty:\s*([0-9]+(?:\.[0-9]+)?)\b/i', $plain, $mm)) {
    $qty = (float)$mm[1];
  }
  if (preg_match('/\bPrice:\s*([0-9]+(?:\.[0-9]+)?)\b/i', $plain, $mm)) {
    $price = (float)$mm[1];
  }
  if (preg_match('/\bPnL:\s*([+\-]?[0-9]+(?:\.[0-9]+)?)\b/i', $plain, $mm)) {
    $pnl_usdt = (float)$mm[1];
  }
  if (preg_match('/\(\s*([+\-]?[0-9]+(?:\.[0-9]+)?)%\s*\)/', $plain, $mm)) {
    $pnl_pct = (float)$mm[1];
  }

  return [
    'strategy_id' => 0,              // resolved later via DB lookup
    'symbol'      => $symbol,
    'event_type'  => $event_type,
    'level_idx'   => $level_idx,
    'side'        => $side,
    'qty'         => $qty,
    'price'       => $price,
    'pnl_usdt'    => $pnl_usdt,
    'pnl_pct'     => $pnl_pct,
    'message'     => $plain,
  ];
}

/**
 * Insert parsed journal row into DB (best-effort, never throws).
 * Prefer explicit strategy_id from caller (tg_notify_for_strategy). Only fall back to
 * symbol lookup when sid is missing — never steal another user's running strategy.
 */
function _journal_insert(array $row): void {
  $host = $_ENV['DB_HOST'] ?? '';
  $name = $_ENV['DB_NAME'] ?? '';
  $user = $_ENV['DB_USER'] ?? '';
  $pass = $_ENV['DB_PASS'] ?? '';
  if ($host === '' || $name === '' || $user === '') return;

  // Best-effort connect
  $db = @new mysqli($host, $user, $pass, $name);
  if ($db->connect_errno) return;
  $db->set_charset('utf8mb4');

  $strategy_id = (int)($row['strategy_id'] ?? 0);
  $sym = (string)($row['symbol'] ?? '');
  $event_type  = (string)($row['event_type'] ?? '');

  // Trading events must NEVER be attributed by symbol alone (cross-user leak when
  // several accounts run the same pair). Require explicit strategy_id from caller.
  $tradingEvents = [
    'BUY0' => true,
    'BUY_L' => true,
    'SUB_SELL' => true,
    'CORE_SELL' => true,
    'MAIN_SELL_ALL' => true,
    'SELL_MAIN' => true,
    'SELL_SUB' => true,
    'EXCEPTION' => true,
  ];
  $isTrading = isset($tradingEvents[$event_type]);

  // Fallback only for non-trading / legacy admin tg_notify without strategy_id.
  if ($strategy_id <= 0 && $sym !== '' && !$isTrading) {
    $symEsc = $db->real_escape_string($sym);
    $q = $db->query("SELECT id FROM strategies WHERE symbol='$symEsc' AND status='running' ORDER BY id DESC LIMIT 1");
    if ($q && ($r = $q->fetch_assoc())) {
      $strategy_id = (int)$r['id'];
    }
    if ($strategy_id <= 0) {
      $q2 = $db->query("SELECT id FROM strategies WHERE symbol='$symEsc' ORDER BY id DESC LIMIT 1");
      if ($q2 && ($r2 = $q2->fetch_assoc())) {
        $strategy_id = (int)$r2['id'];
      }
    }
  }

  if ($strategy_id <= 0) {
    @$db->close();
    return;
  }

  // If both sid + symbol given, ensure symbol matches that strategy (avoid cross-pair junk).
  if ($sym !== '') {
    $chk = $db->query('SELECT symbol, owner_id, user_id FROM strategies WHERE id=' . (int)$strategy_id . ' LIMIT 1');
    if ($chk && ($cr = $chk->fetch_assoc())) {
      $dbSym = strtoupper(trim((string)($cr['symbol'] ?? '')));
      if ($dbSym !== '' && $dbSym !== strtoupper($sym)) {
        @$db->close();
        return;
      }
    } elseif ($isTrading) {
      @$db->close();
      return;
    }
  }

  $symbol      = $sym;
  $level_idx   = $row['level_idx'] === null ? null : (int)$row['level_idx'];
  $side        = $row['side'] === null ? null : (string)$row['side'];
  $qty         = $row['qty'] === null ? null : (float)$row['qty'];
  $price       = $row['price'] === null ? null : (float)$row['price'];
  $pnl_usdt    = $row['pnl_usdt'] === null ? null : (float)$row['pnl_usdt'];
  $pnl_pct     = $row['pnl_pct'] === null ? null : (float)$row['pnl_pct'];
  $message     = (string)($row['message'] ?? '');

  $stmt = @$db->prepare("
    INSERT INTO engine_journal
      (strategy_id, symbol, event_type, level_idx, side, qty, price, pnl_usdt, pnl_pct, message)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  if (!$stmt) { @$db->close(); return; }

  // For nullable ints/floats in prepared statements: pass nulls as PHP null
  @$stmt->bind_param(
    "ississddds",
    $strategy_id,
    $symbol,
    $event_type,
    $level_idx,
    $side,
    $qty,
    $price,
    $pnl_usdt,
    $pnl_pct,
    $message
  );

  // bind_param doesn't accept null for numeric types in strict way in some builds,
  // so we do a fallback if it fails.
  $ok = @$stmt->execute();
  if (!$ok) {
    // Fallback: build safe SQL (best-effort)
    $symbolEsc = $db->real_escape_string($symbol);
    $typeEsc   = $db->real_escape_string($event_type);
    $sideSql   = $side === null ? "NULL" : ("'".$db->real_escape_string($side)."'");
    $msgSql    = "'".$db->real_escape_string($message)."'";
    $lvlSql    = $level_idx === null ? "NULL" : (string)$level_idx;
    $qtySql    = $qty === null ? "NULL" : number_format($qty, 8, '.', '');
    $prSql     = $price === null ? "NULL" : number_format($price, 8, '.', '');
    $pnlSql    = $pnl_usdt === null ? "NULL" : number_format($pnl_usdt, 8, '.', '');
    $pnlpSql   = $pnl_pct === null ? "NULL" : number_format($pnl_pct, 4, '.', '');

    @$db->query("
      INSERT INTO engine_journal
        (strategy_id, symbol, event_type, level_idx, side, qty, price, pnl_usdt, pnl_pct, message)
      VALUES
        ($strategy_id, '$symbolEsc', '$typeEsc', $lvlSql, $sideSql, $qtySql, $prSql, $pnlSql, $pnlpSql, $msgSql)
    ");
  }

  @$stmt->close();
  @$db->close();
}

/**
 * Send Telegram message. Returns [ok(bool), msg].
 * Needs in .env:
 *  TG_BOT_TOKEN=123456:ABC...
 *  TG_CHAT_ID=123456789
 */
function tg_notify(string $text, array $opts = []): array {
  // Best-effort: write to journal (before send is fine)
  if (empty($opts['skip_journal'])) {
    $row = _journal_parse($text);
    if ($row !== null) {
      _journal_insert($row);
    }
  } else {
    $row = null;
  }

  $token = $_ENV['TG_BOT_TOKEN'] ?? '';
  $chat  = $_ENV['TG_CHAT_ID'] ?? '';
  if ($token === '' || $chat === '') {
    return [false, 'TG_BOT_TOKEN/TG_CHAT_ID not set'];
  }

  $url = "https://api.telegram.org/bot{$token}/sendMessage";
  $payload = [
    'chat_id' => $chat,
    'text' => $text,
    'parse_mode' => $opts['parse_mode'] ?? 'HTML',
    'disable_web_page_preview' => true,
    'disable_notification' => $opts['silent'] ?? false,
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
  ]);
  $resp = curl_exec($ch);
  $errNo = curl_errno($ch);
  $err   = curl_error($ch);
  $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($errNo || $http >= 400) {
    // Also log failure as journal row if it was parsable
    if ($row !== null) {
      $row['event_type'] = 'TG_FAIL';
      $row['message'] = trim(($row['message'] ?? '')." | tg_fail http=$http curl=$errNo $err");
      _journal_insert($row);
    }
    return [false, "http=$http curl=$errNo $err resp=$resp"];
  }

  return [true, 'sent'];
}

/**
 * Send Telegram message for a specific strategy (personalized).
 * Resolves user_id -> tg_chat_id from DB.
 */
function tg_notify_for_strategy(mysqli $db, int $strategy_id, string $text, array $opts = []): array {
    $skipJournal = !empty($opts['skip_journal']);
    // Best-effort: write to journal for strategy-scoped notifications too (skip when caller already wrote via worker $db).
    $rowParsed = null;
    if (!$skipJournal) {
        $rowParsed = _journal_parse($text);
        if ($rowParsed !== null) {
            $rowParsed['strategy_id'] = (int)$strategy_id;
            _journal_insert($rowParsed);
        }
    }

    // 1) find user for strategy
    $sid = (int)$strategy_id;
    if ($sid <= 0) return [false, 'bad strategy_id'];

    $res = $db->query("
        SELECT u.tg_chat_id, u.tg_enabled
        FROM strategies s
        JOIN users u ON u.id = s.user_id
        WHERE s.id = $sid
        LIMIT 1
    ");
    if (!$res || !($row = $res->fetch_assoc())) {
        return [false, 'strategy or user not found'];
    }

    if ((int)$row['tg_enabled'] !== 1 || empty($row['tg_chat_id'])) {
        return [false, 'telegram disabled'];
    }

    $chat_id = (string)$row['tg_chat_id'];

    // 2) send via standard tg_notify but override chat_id
    $token = $_ENV['TG_BOT_TOKEN'] ?? '';
    if ($token === '') return [false, 'TG_BOT_TOKEN missing'];

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $opts['parse_mode'] ?? 'HTML',
        'disable_web_page_preview' => true,
        'disable_notification' => $opts['silent'] ?? false,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $resp = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err   = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo || $http >= 400) {
        if (!$skipJournal && $rowParsed !== null) {
            $rowParsed['event_type'] = 'TG_FAIL';
            $rowParsed['message'] = trim(($rowParsed['message'] ?? '')." | tg_fail http=$http curl=$errNo $err");
            _journal_insert($rowParsed);
        }
        return [false, "http=$http curl=$errNo $err"];
    }

    return [true, 'sent'];
}

/**
 * Надіслати повідомлення в конкретний Telegram-чат (HTML), без парсингу журналу / engine_journal.
 *
 * @return array{0:bool,1:string}
 */
function tg_send_html_raw(string $token, string $chatId, string $htmlText, array $opts = []): array
{
    $token = trim($token);
    $chatId = trim($chatId);
    if ($token === '' || $chatId === '') {
        return [false, 'missing token or chat_id'];
    }
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $parseMode = (string)($opts['parse_mode'] ?? 'HTML');
    $payload = [
        'chat_id' => $chatId,
        'text' => $htmlText,
        'disable_web_page_preview' => '1',
        'disable_notification' => !empty($opts['silent']) ? '1' : '0',
    ];
    if ($parseMode !== '') {
        $payload['parse_mode'] = $parseMode;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = (string)curl_exec($ch);
    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errNo) {
        return [false, "curl=$errNo $err"];
    }
    $j = json_decode($resp, true);
    if (is_array($j) && isset($j['ok']) && $j['ok'] === false) {
        $desc = (string)($j['description'] ?? 'unknown');
        return [false, "tg_api: $desc"];
    }
    if ($http >= 400) {
        return [false, "http=$http resp=" . mb_substr($resp, 0, 300)];
    }
    if (!is_array($j) || empty($j['ok'])) {
        return [false, 'bad_response: ' . mb_substr($resp, 0, 200)];
    }
    return [true, 'sent'];
}

/**
 * Надіслати фото в Telegram-чат (multipart). Caption до 1024 символів.
 *
 * @return array{0:bool,1:string}
 */
function tg_send_photo_raw(string $token, string $chatId, string $photoPath, string $caption = '', array $opts = []): array
{
    $token = trim($token);
    $chatId = trim($chatId);
    if ($token === '' || $chatId === '') {
        return [false, 'missing token or chat_id'];
    }
    if ($photoPath === '' || !is_file($photoPath) || !is_readable($photoPath)) {
        return [false, 'photo_missing'];
    }
    $url = "https://api.telegram.org/bot{$token}/sendPhoto";
    $mime = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($photoPath);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }
    $payload = [
        'chat_id' => $chatId,
        'photo' => new CURLFile($photoPath, $mime, basename($photoPath)),
        'disable_notification' => !empty($opts['silent']) ? '1' : '0',
    ];
    $caption = trim($caption);
    if ($caption !== '') {
        if (function_exists('mb_strlen') && mb_strlen($caption) > 1024) {
            $caption = mb_substr($caption, 0, 1024);
        } elseif (strlen($caption) > 1024) {
            $caption = substr($caption, 0, 1024);
        }
        $payload['caption'] = $caption;
        $parseMode = (string)($opts['parse_mode'] ?? '');
        if ($parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $resp = (string)curl_exec($ch);
    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errNo) {
        return [false, "curl=$errNo $err"];
    }
    $j = json_decode($resp, true);
    if (is_array($j) && isset($j['ok']) && $j['ok'] === false) {
        return [false, 'tg_api: ' . (string)($j['description'] ?? 'unknown')];
    }
    if ($http >= 400) {
        return [false, "http=$http resp=" . mb_substr($resp, 0, 300)];
    }
    if (!is_array($j) || empty($j['ok'])) {
        return [false, 'bad_response: ' . mb_substr($resp, 0, 200)];
    }
    return [true, 'sent'];
}
