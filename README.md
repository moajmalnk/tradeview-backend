# TradeView API (Hostinger)

PHP 8.1+ REST API for TradeView Watch. Deployed at `https://tradeapi.finbro.cloud`.

## Setup on Hostinger

### 1. DNS (Cloudflare)

`finbro.cloud` uses Cloudflare nameservers. Creating a Hostinger subdomain alone is not enough.

In **Cloudflare → finbro.cloud → DNS**, add:

| Type | Name | Content | Proxy |
|------|------|---------|-------|
| CNAME | `tradeapi` | `finbro.cloud` | Proxied (orange) |

Wait 1–5 minutes, then open `https://tradeapi.finbro.cloud/v1/health`.

### 2. Files & document root

Subdomain folder can stay:

`/home/u524154866/domains/finbro.cloud/public_html/tradeapi`

Root `.htaccess` rewrites into `public/`. Prefer changing the subdomain directory to `.../tradeapi/public` if Hostinger allows it.

### 3. Environment

Copy `.env.example` → `.env` on the server:

- `DB_PASS="..."` — **quote** the password if it contains `=` or `&`
- `JWT_SECRET` — long random string (required for login)

### 4. Database

Import [`database/schema.sql`](database/schema.sql) in phpMyAdmin. Optional: [`database/seed.sql`](database/seed.sql).

### 5. Verify

- `GET /v1/health` → `{ "data": { "ok": true } }`
- `GET /v1/health/db` → `{ "data": { "database": "connected" } }`

Local env example:

```bash
cp .env.example .env
# edit DB_* and JWT_SECRET
composer install
php -S localhost:8081 -t public
```

## Demo login

| | |
|---|---|
| Email | `trader@tradeview` |
| Password | `demo` |

(Requires `database/seed.sql` imported.)

If login returns 500, pull the latest backend and ensure `.env` has a real `JWT_SECRET` (or the API will derive one from DB settings).

## Main endpoints

| Method | Path | Auth |
|--------|------|------|
| GET | `/v1/health` | no |
| GET/PATCH | `/v1/profile` | yes |
| GET/POST | `/v1/accounts` | yes |
| GET/PATCH/DELETE | `/v1/accounts/{id}` | yes |
| GET | `/v1/positions?account_id=` | yes |
| GET/POST | `/v1/trades` | yes |
| GET/PATCH/DELETE | `/v1/trades/{id}` | yes |
| GET/POST | `/v1/signals` | yes |
| GET | `/v1/notifications` | yes |
| GET/POST | `/v1/connector-tokens` | yes |
| DELETE | `/v1/connector-tokens/{id}` (revoke) | yes |
| DELETE | `/v1/connector-tokens/{id}/purge` (delete revoked/expired) | yes |
| DELETE | `/v1/connector-tokens/inactive` (delete all revoked/expired) | yes |
| GET/POST/DELETE | `/v1/share-links` | yes |
| GET | `/v1/share/{token}` | public |
| GET/POST | `/v1/capital-movements` | yes |
| GET/PATCH/DELETE | `/v1/capital-movements/{id}` | yes |
| GET | `/v1/capital-summary` | yes |
| POST | `/v1/uploads` | yes (multipart `file` — JPEG/PNG/WebP/GIF ≤5MB) |
| POST | `/v1/connector/sync` | connector token |

Responses: `{ "data": ... }` or `{ "error": { "code", "message" } }`.

## Connector sync

```http
POST /v1/connector/sync
Authorization: Bearer TVM-XXXX-XXXX-XXXX
Content-Type: application/json

{
  "account": { "label": "MT5 Live", "balance": 10000, "equity": 10050 },
  "positions": [{ "ticket": "1", "symbol": "EURUSD", "direction": "buy", "volume": 0.1, "entry_price": 1.08 }],
  "orders": [{ "ticket": "2", "symbol": "EURUSD", "direction": "sell", "volume": 0.7, "requested_price": 1.15774 }],
  "trades": []
}
```

MT5 Expert Advisor: see [`connectors/mt5/`](../connectors/mt5/).

## Capital / payments

Log UPI/bank transfers to the trader, then call `GET /v1/capital-summary` for funded vs trading P/L and ROI.

## Security

- Never commit `.env`
- Passwords stored with bcrypt
- Connector/share tokens stored as SHA-256 hashes only
- Prepared statements (PDO) for all queries
- Ownership enforced with `user_id` on every query
