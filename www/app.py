import os
from flask import Flask, request, jsonify
from dotenv import load_dotenv
import ccxt

load_dotenv()
app = Flask(__name__)

@app.get("/api/health")
def health():
    return {"ok": True, "service": "martin-backend"}

@app.post("/api/exchange/connect")
def connect_exchange():
    data = request.get_json(force=True)
    name    = (data.get("exchange") or "binance").lower()
    api_key = data.get("api_key") or ""
    secret  = data.get("api_secret") or ""
    testnet = bool(data.get("testnet"))

    try:
        klass = getattr(ccxt, name)
        ex = klass({"apiKey": api_key, "secret": secret, "enableRateLimit": True, "options": {"defaultType":"spot"}})
        if name == "binance" and testnet:
            ex.set_sandbox_mode(True)
        ex.load_markets()
        bal = ex.fetch_balance()
        total = bal.get("total") or {}
        return jsonify({"ok": True, "summary": {k: v for k,v in total.items() if v}})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 400

if __name__ == "__main__":
    app.run()
