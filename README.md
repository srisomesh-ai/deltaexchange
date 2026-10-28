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
2. Picks a coin and direction:
   - **Smart entries (default ON):** only coins with funding ≥ 3% are faded (funding ≥ +3% →
     SHORT, ≤ −3% → LONG), and only when the live order book leans the same way. This is the
     best-performing pattern in the trade journal (small sample). Otherwise it waits.
   - **Smart entries OFF:** strongest mover (≥ 1% move); funding ≥ ±2% → fade, otherwise
     follow momentum.
4. Sets your leverage (on LIVE, if Delta refuses it the trade is skipped), sizes the trade as
   `money per trade × leverage ÷ price of 1 lot` (capped by **Max lots**), and enters with a
   market order. Coins where even 1 lot is bigger than that are skipped — it never rounds up.
5. Places a stop-loss and take-profit, then **verifies on the exchange** that the stop exists.
   If it can't be confirmed after one retry, the position is closed immediately.
6. **Profit-lock trailing** while the trade is open:
   - nothing moves until the trade is up by the **Trailing gap %** plus fees (room to breathe);
   - then the stop jumps into profit and follows the price one gap behind — it never moves back;
   - when the price gets within one gap of the take-profit, the TP is pushed further out, so a
     trend keeps running and the trailing stop decides the exit.
   New stop/TP orders are always placed before the old ones are cancelled.

**Money Control panel** (everything you control by hand):

- **Delta balance** — your real available balance, read from Delta every 15s and before every trade.
- **Money per trade ($)** — the margin each trade uses. The robot won't trade if it is $0 or more than
  your available balance.
- **Leverage (x)** — position size = money × leverage (e.g. $5 × 2x = $10 position).
- **Stop-loss % / Take-profit %** — the panel shows what each one means in dollars, fees included (estimate).
- **Max loss per day ($)** — required for LIVE; the robot switches itself off when today's loss reaches it.
- **Max lots per trade** — extra cap on size (lots, not dollars: on cheap coins a low cap can make
  the trade much smaller than money × leverage).
- **Trailing gap % (profit-lock distance)** — see step 6.
- **Smart entries** — see step 2.

On LIVE the robot refuses to trade (and to arm) while the settings are unsafe: no max loss per day,
a max loss per day at or above your whole balance, or a stop-loss so wide that Delta would
liquidate the position first at your leverage.

The LIVE confirmation shows your real balance and these numbers in dollars before you type `LIVE`.

**Max loss per day** (Money Control panel): before every new entry the robot checks today's PnL —
wallet balance + open-position PnL compared with the first reading of the day (closed trades and
fees count). When the loss reaches the limit it switches itself **off** and blocks manual orders;
an open trade keeps its SL/TP. If the check can't read your balance, the robot skips the entry
rather than trading blind. Deposits/withdrawals during the day move the number too. Set it to 0 to disable.

The dashboard defaults to **TESTNET**. Arming on LIVE requires a confirmation and typing `LIVE`.
**🔍 Why no trades?** (under the robot status) runs every check the robot makes, without placing
an order, and shows which one is stopping it and which coin it would trade next.

## Security notes

- Keep `delta-proxy.php`'s URL and the proxy token private. Anyone with both can trade your account.
- Keep `$ALLOWED_ORIGIN` empty (same site only) unless you really host the dashboard elsewhere.
- The login screen is a browser-side gate only. For real protection, also enable
  Directory Privacy (password-protected directory) in Hostinger.
- To change the login password, run `await sha256("NewPassword")` in the browser console
  and put the result into `passHash` in `index.html` (and `delta-trader.html`).
- Never put your API secret in the HTML.
