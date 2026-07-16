# Delta Exchange India — Trading Dashboard

Perpetual-futures trading dashboard for Delta Exchange India. Live market data,
order book, liquidity, manual buy/sell, and an auto-trade scaffold.

## Files

| File | What it is |
|------|-----------|
| `index.html` / `delta-trader.html` | The dashboard (same file). Open in browser. `index.html` loads at the site root. |
| `delta-proxy.php` | Server-side signing proxy. Holds your API key/secret and signs authenticated calls. The browser never sees your secret. |

## How it works

```
Browser (dashboard)  →  delta-proxy.php (signs)  →  Delta Exchange API
```

- **Market data** (prices, OI, funding, order book) routes through the proxy in
  "public" mode — no signature needed. This avoids mobile browser CORS blocks.
- **Account + trading** (balance, positions, place/cancel order) is signed by the
  proxy using HMAC-SHA256.

## Setup

1. **Edit `delta-proxy.php`** — fill in:
   - `$API_KEY` — your Delta India API key
   - `$API_SECRET` — your Delta India API secret
   - `$PROXY_TOKEN` — a password you invent (any long random string)
2. **Deploy** both files to your host (Hostinger).
3. **Whitelist your server IP** in Delta → API settings.
   Find it via hPanel terminal: `curl ifconfig.me`
4. Open the dashboard, tap **⚙ Proxy**, enter:
   - URL: `https://YOURSITE/delta-proxy.php`
   - Token: the same `$PROXY_TOKEN` password
   - **Save & Test** — should show "✓ N markets loaded"

## Security notes

- Keep `delta-proxy.php`'s URL private. Anyone who can POST to it with the token can trade your account.
- In production set `$ALLOWED_ORIGIN` in the PHP to your exact dashboard domain (not `*`).
- Never put your API secret in the HTML.

## Auto-trade

The auto-trade panel is a **scaffold** — it runs signals in dry-run (logs only)
until you tick both **Armed** and **Live**. Triggers included: orderbook
imbalance, funding-rate bias, price cross. Real strategy logic to be added.
