# Delta Exchange India — Somu Trader

Perpetual-futures trading dashboard and auto-trading robot for Delta Exchange India.
Live market data, market scanner, manual buy/sell, risk guard, trade journal, and a
robot that trades on its own (TESTNET by default, LIVE when you arm it).

## Files

| File | What it is |
|------|-----------|
| `index.html` / `delta-trader.html` | The dashboard (same file — keep them identical). `index.html` loads at the site root. |
| `delta-proxy.php` | Server-side signing proxy. Holds your API key/secret and signs authenticated calls. The browser never sees your secret. |
| `key-test.php` | One-tap TESTNET key checker. Open `key-test.php?token=YOUR_PROXY_TOKEN`. |
| `delta-config.sample.php` / `delta-config-testnet.sample.php` | Templates for the real config files (which are git-ignored). |
| `eye-control.html` + `webgazer.js` + `mediapipe/` | EyePilot — separate eye/nose/hand-tracking browser-control experiment. Not used by the trader. |

## How it works

```
Browser (dashboard)  →  delta-proxy.php (signs)  →  Delta Exchange API (live or testnet)
```

- **Market data** (prices, OI, funding, order book, candles) routes through the proxy in
  "public" mode — no signature needed. This avoids mobile browser CORS blocks.
- **Account + trading** (balance, positions, place/cancel order) is signed by the
  proxy using HMAC-SHA256 and requires the proxy token.
- **Environment**: the dashboard sends `env: testnet|live`; the proxy then uses
  `delta-config-testnet.php` + the testnet API, or `delta-config.php` + the live API.
- **Settings sync**: dashboard settings are saved server-side in `somu-settings.php`
  (guarded so its URL shows nothing) so phone and PC share them.

## Setup

**Your API keys live ONLY on the server, never in this repo.**

1. **Deploy** the repo files to your host (Hostinger).
2. **Create `delta-config.php`** (live) and/or **`delta-config-testnet.php`** (testnet) by
   hand on the server, next to `delta-proxy.php`, from the sample files:
   - `$API_KEY` / `$API_SECRET` — your Delta India keys (testnet keys come from testnet.delta.exchange)
   - `$PROXY_TOKEN` — a password you invent (use the same one in both files)
   - `$ALLOWED_ORIGIN` — leave `''` if the dashboard is on the same site as the proxy.
     Only list other sites (comma-separated) if the dashboard is hosted elsewhere. `'*'` is ignored.
3. **Whitelist your server IP** in Delta → API settings.
   Find it via hPanel terminal: `curl ifconfig.me`
4. **Check the testnet key**: open `https://YOURSITE/key-test.php?token=YOUR_PROXY_TOKEN`.
5. Open the dashboard, tap **⚙ Proxy**, enter:
   - URL: `https://YOURSITE/delta-proxy.php`
   - Token: the same `$PROXY_TOKEN` password
   - **Save & Test** — should show "✓ N markets loaded"

## Auto-trading robot (SIMPLE MODE)

Turned on with the **Start Robot** toggle. Every 20 seconds it:

1. Checks for an open position. On testnet a leftover position is closed; on LIVE the
   robot never closes your positions — it waits until you close them on Delta.
2. Scans all perpetuals and picks the strongest mover (≥ 1% move).
3. Picks a direction: funding ≥ +2% → fade SHORT, funding ≤ −2% → fade LONG,
   otherwise follow momentum. (High-funding fades were the only profitable group in the
   trade journal.)
4. Sets leverage, sizes the trade as `balance × % × leverage ÷ price` (capped by **Max lots**),
   and enters with a market order.
5. Places a stop-loss and take-profit, then **verifies on the exchange** that the stop exists.
   If it can't be confirmed after one retry, the position is closed immediately.

Settings live in **Auto-Pilot Settings** (balance %, max lots, SL %, TP %, leverage, trail step).
The dashboard defaults to **TESTNET**. Arming on LIVE requires a confirmation and typing `LIVE`.
**Why no trades?** runs a one-tap diagnostic of every gate.

## Security notes

- Keep `delta-proxy.php`'s URL and the proxy token private. Anyone with both can trade your account.
- Keep `$ALLOWED_ORIGIN` empty (same site only) unless you really host the dashboard elsewhere.
- The login screen is a browser-side gate only. For real protection, also enable
  Directory Privacy (password-protected directory) in Hostinger.
- To change the login password, run `await sha256("NewPassword")` in the browser console
  and put the result into `passHash` in `index.html` (and `delta-trader.html`).
- Never put your API secret in the HTML.
