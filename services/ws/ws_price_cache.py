import asyncio, json, os, time, signal
import mysql.connector as mysql
import websockets
from dotenv import dotenv_values

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
CONF = dotenv_values(os.path.join(ROOT, '.env'))
DB = dict(
    host=CONF.get('DB_HOST'), user=CONF.get('DB_USER'),
    password=CONF.get('DB_PASS'), database=CONF.get('DB_NAME'),
)

OUT_FILE = os.path.join(ROOT, 'www', 'data', 'ws_prices.json')
STREAM_BASE = "wss://stream.binance.com:9443/stream?streams="  # combined streams

# Після reconnect не чіпати сокет N секунд — інакше всі пари «stale» до перших тіків → death-spiral.
RECONNECT_GRACE_SEC = 45.0
# Рестарт лише якщо стрім реально мовчить (жодної події) або більшість пар застаріла.
STREAM_IDLE_RECONNECT_SEC = 90.0
MAJORITY_STALE_AGE_SEC = 120.0


def ts_ms() -> int: return int(time.time() * 1000)


async def fetch_running_symbols() -> list[str]:
    """Symbols for running strategies + paused with open qty (pause wind-down needs live prices)."""
    def _query():
        conn = mysql.connect(**DB)
        try:
            cur = conn.cursor()
            cur.execute(
                "SELECT DISTINCT UPPER(s.symbol) FROM strategies s "
                "LEFT JOIN positions p ON p.strategy_id = s.id "
                "WHERE s.symbol <> 'TEMPLATE' AND s.symbol NOT LIKE '%TEMPLATE%' "
                "AND ("
                "  s.status = 'running' "
                "  OR (s.status = 'paused' AND p.qty IS NOT NULL AND p.qty > 1e-12)"
                ")"
            )
            out = []
            for (sym,) in cur.fetchall():
                s = (sym or "").upper().strip()
                if s and s.endswith("USDT"):
                    out.append(s)
            return sorted(set(out))
        finally:
            conn.close()
    return await asyncio.to_thread(_query)


def to_streams(symbols: list[str]) -> str:
    # binance streams need lowercased symbols like dogeusdt@bookTicker
    parts = [f"{s.lower()}@bookTicker" for s in symbols]
    return STREAM_BASE + "/".join(parts) if parts else ""


def atomic_write(path: str, data: dict):
    tmp = path + ".tmp"
    with open(tmp, "w") as f:
        json.dump(data, f, separators=(",", ":"), ensure_ascii=False)
    os.replace(tmp, path)


async def run():
    prices = {}  # { "DOGEUSDT": {"bid", "ask", "mid", "ts" (unix sec)}, ... }
    current_symbols: set[str] = set()
    ws = None
    last_symbols_check = 0.0
    last_file_write = 0.0
    last_heartbeat = 0.0
    connected_at = 0.0
    last_msg_at = 0.0
    last_reconnect_at = 0.0

    async def connect(url: str):
        return await websockets.connect(
            url,
            ping_interval=20,
            ping_timeout=20,
            close_timeout=5,
            open_timeout=25,
        )

    def write_cache():
        if not prices:
            return
        now_sec = int(time.time())
        payload = dict(prices)
        payload["_meta"] = {
            "updated_at": now_sec,
            "symbols": len(prices),
            "subscribed": len(current_symbols),
            "heartbeat": 1,
        }
        atomic_write(OUT_FILE, payload)

    async def open_streams(wanted: set[str]):
        nonlocal ws, current_symbols, prices, connected_at, last_msg_at, last_reconnect_at
        if ws:
            try:
                await ws.close()
            except Exception:
                pass
            ws = None
        url = to_streams(list(wanted))
        if not url:
            current_symbols = set()
            prices = {}
            return
        ws = await connect(url)
        now = time.time()
        current_symbols = set(wanted)
        connected_at = now
        last_reconnect_at = now
        last_msg_at = now
        # Після reconnect лишаємо останні mid (щоб UI/engine не бачили «дірку»),
        # але не вважаємо їх свіжими для рішення про reconnect (див. should_reconnect).
        prices = {k: v for k, v in prices.items() if k in wanted}

    def symbol_ages() -> dict[str, float]:
        now = time.time()
        ages = {}
        for s in current_symbols:
            row = prices.get(s) or {}
            ts = float(row.get("ts") or 0)
            ages[s] = (now - ts) if ts > 0 else 1e9
        return ages

    def should_reconnect() -> bool:
        """Не рестартити через 1 «тиху» пару — лише мертвий стрім / більшість stale."""
        if ws is None or not current_symbols:
            return False
        now = time.time()
        if (now - connected_at) < RECONNECT_GRACE_SEC:
            return False
        # Жодного bookTicker занадто довго — сокет «живий», але даних немає.
        if last_msg_at > 0 and (now - last_msg_at) >= STREAM_IDLE_RECONNECT_SEC:
            return True
        ages = symbol_ages()
        if not ages:
            return False
        stale_n = sum(1 for a in ages.values() if a >= MAJORITY_STALE_AGE_SEC)
        # ≥ половина підписок застаріла → reconnect
        if stale_n >= max(2, (len(ages) + 1) // 2):
            return True
        return False

    async def subscribe_if_needed():
        """Refresh symbol list periodically; always reconnect if socket is down."""
        nonlocal ws, current_symbols, last_symbols_check
        now = time.time()
        need_symbol_refresh = (now - last_symbols_check) >= 30
        if not need_symbol_refresh and ws is not None:
            return
        if need_symbol_refresh:
            last_symbols_check = now
            wanted = set(await fetch_running_symbols())
        else:
            wanted = set(current_symbols) if current_symbols else set(await fetch_running_symbols())
        if not wanted:
            current_symbols = set()
            if ws:
                try:
                    await ws.close()
                except Exception:
                    pass
                ws = None
            return
        # Reconnect when: no socket, or running symbols changed
        if ws is None or wanted != current_symbols:
            # Анти-шторм: не частіше ніж раз на 20с при зміні набору
            if ws is not None and (now - last_reconnect_at) < 20.0 and wanted != current_symbols:
                return
            await open_streams(wanted)

    while True:
        try:
            await subscribe_if_needed()
            if not ws:
                await asyncio.sleep(1.0)
                continue

            # Прибрати символи, що більше не running
            if current_symbols:
                prices = {k: v for k, v in prices.items() if k in current_symbols}

            if should_reconnect():
                ws = None
                await asyncio.sleep(1.0)
                continue

            if time.time() - last_heartbeat >= 10.0:
                write_cache()
                last_heartbeat = time.time()
            msg = await asyncio.wait_for(ws.recv(), timeout=15)
            last_msg_at = time.time()
            j = json.loads(msg)
            data = j.get("data") or {}
            s = (data.get("s") or "").upper()
            b = float(data.get("b") or 0.0)
            a = float(data.get("a") or 0.0)
            if s and b > 0 and a > 0:
                mid = (a + b) / 2.0
                now_sec = int(time.time())
                prices[s] = {"bid": b, "ask": a, "mid": mid, "ts": now_sec}
                # файл — не частіше ~5 разів/с (усі символи разом)
                if time.time() - last_file_write >= 0.2:
                    write_cache()
                    last_file_write = time.time()
                    last_heartbeat = time.time()
        except (asyncio.TimeoutError, websockets.ConnectionClosedError, websockets.InvalidStatusCode):
            await asyncio.sleep(1.0)
            ws = None
        except Exception:
            # не валимось — даємо паузу й далі
            await asyncio.sleep(1.0)
            ws = None


def main():
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    for sig in (signal.SIGINT, signal.SIGTERM):
        try: loop.add_signal_handler(sig, loop.stop)
        except: pass
    loop.run_until_complete(run())


if __name__ == "__main__":
    main()
