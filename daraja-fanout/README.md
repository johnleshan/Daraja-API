# Daraja Fanout — Same Paybill, Two Systems (Identical Clones)

One Safaricom paybill + one set of Daraja credentials, feeding **two identical, lightweight
PHP systems** (System A and System B) through a broadcast middleware.

```
        Safaricom M-Pesa
              │  (single HTTPS POST)
              ▼
        Middleware  :9000
        { on payload → fan out to both, failed ones queued + retried }
        │                      │
        ▼                      ▼
   System A :9001          System B :9002
```

Daraja only allows ONE confirmed/validated URL per paybill and registering a new one
overwrites the previous — so the middleware URL is the single registered URL.

## Files

| App        | Endpoints |
|------------|-----------|
| middleware | `POST /callback` (register this on Daraja), `GET /health`, `POST /register-urls`, `GET /retry` |
| system a/b | `GET /` dashboard (shows **SYSTEM A / SYSTEM B** badge), `GET /transactions` JSON, `POST /stkpush`, `POST /webhook/mpesa`, `GET /health`, `GET /csv` |

Data: each system writes `data/transactions.json` + `data/transactions.csv` — no DB needed.
Both are idempotent by `TransID` / `CheckoutRequestID`, so retries never double-count.

## .env (already pre-filled with your Daraja keys)

You still must set in all three `.env`:

- `PAYBILL=` your paybill number (placeholder `000000` right now)
- `STK_CALLBACK_URL=` the public middleware URL + `/callback`
- `TARGET_URLS=` (middleware only) the two public system URLs + `/webhook/mpesa`
- `DASH_USER` / `DASH_PASS` → simple auth on dashboard & admin endpoints

`MPESA_ENV=` switch to `production` when live.

## Run locally (PHP not on this machine — use Docker)

```bash
cd daraja-fanout
docker build -t fan-m middleware && docker run -p 9000:9000 -e PORT=9000 fan-m &
docker build -t fan-a system-a && docker run -p 9001:9001 -e PORT=9001 fan-a &
docker build -t fan-b system-b && docker run -p 9002:9002 -e PORT=9002 fan-b &
```

## Cloud (Railway / Render)

Each folder deploys as a separate service (Docker is auto-detected by both).
Render binds `PORT` automatically; the Dockerfiles read it.
Railway: Deploy from repo, add each folder as a service, set public domains,
then update `TARGET_URLS` + `STK_CALLBACK_URL` in the middleware `.env`.

## Tunnels OK with M-Pesa (ngrok is blacklisted)

For dev/tests: **Cloudflare Tunnel** (needs a domain, free tier),
**zrok**, or **pinggy** (`pinggy.io -p 443 tunnel`). Deploy on Render/Railway
is the more reliable option for real transactions anyway (no laptop dependency).

## Wall of caution

- TheDaraja consumer keys/secret/passkey were shared in plaintext — treat them as burned;
  rotate via Daraja portal when convenient, then just edit the `.env` files.
- Raw incoming payloads are always logged under `middleware/logs/in_YYYY-MM-DD.json`
  — the source of truth for reconciliation.
- HMAC signing between middleware and each system uses `HMAC_SECRET` (generated,
  shared in each app's `.env`, .gitignored so it never lands in the repo).
