# ExamLegacy

**SANJAYXLEGACY Powered By** — a premium, production-oriented digital education platform.

ExamLegacy is not a PDF-selling page. It is a complete student ecosystem: a secure PDF
store, a private digital library, a secure in-app PDF viewer, a Gemini-powered **Study AI**,
separate **Support AI** / **Help AI**, in-app **human support**, a **Store Wallet**, separate
**AI Credits**, **VIP PASS**, native-style **notifications**, account management and a full
**admin panel** — with **Cashfree** payments verified server-side.

---

## Architecture at a glance

```
Browser (HTML5 + CSS3 + Vanilla JS)
   │  Firebase Google Sign-In  →  short-lived ID token (Authorization: Bearer)
   ▼
PHP API  (api/index.php front controller  +  api/lib/*)
   │  verifies the Firebase token (students) or the dedicated admin session,
   │  maps to a MySQL user, re-checks every authorization
   ▼
MySQL  (authoritative: users, orders, wallet ledger, AI-credit ledger, PDF access, …)
   │
   ├─ Cashfree  (server-side order create + verification + signed webhook + event log)
   ├─ Gemini    (server-side Study/Support/Help AI; key never leaves the server)
   ├─ Firestore (OPTIONAL real-time mirror; service-account writes, client rules deny all)
   ├─ SMTP      (transactional email)
   └─ Protected storage (storage/pdfs, storage/uploads) — never served at a public URL
```

- **The frontend is never a security authority.** Every protected action is validated on the
  server against the verified Firebase identity + MySQL ownership.
- **MySQL is the single source of truth** for money and access. The Store Wallet and AI
  Credits are **immutable, append-only ledgers**; balances are cached aggregates updated in the
  same transaction.
- **PDFs have no public URL.** Reading goes: verified user → active `pdf_access` (from a paid
  order) → short-lived, hashed viewer-session token → server streams the bytes → in-app viewer
  renders them with a dynamic purchaser watermark. Revoked/refunded/expired access stops working
  immediately because every protected access is re-validated.

> Note on screenshots: browsers cannot guarantee 100% screenshot prevention, so ExamLegacy makes
> **no such claim**. It uses real, layered deterrence: private file access, server-side
> authorization, short-lived sessions, watermarking, access logging and download/print/copy
> controls.

---

## Tech stack

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3, Vanilla JavaScript (no framework) |
| Backend | PHP 8.1+ |
| Database | MySQL 8 (authoritative) + Firebase Authentication |
| Auth | Firebase Google Sign-In (ID tokens verified server-side) |
| Payments | Cashfree (PG) — server-side verification + webhooks |
| AI | Google Gemini API (server-side only) |
| Email | PHPMailer over SMTP |
| Storage | Local protected server storage (Firebase Storage is **not** used) |

---

## Repository layout

```
index.html                 Student app shell
admin/index.html           Admin dashboard shell
assets/css/app.css         Design system (tokens, light/dark, native-app feel)
assets/js/*.js             Front-end app (config, api, auth, ui, viewer, app)
admin/assets/*             Admin dashboard (css + js)
api/
  index.php                Front controller / router + authorization
  config.php               Env loading, constants, helpers, autoloader
  lib/*.php                Domain logic (Auth, Wallet, AiCredits, Orders, Payments,
                           PdfVault, StudyAi, Support, Notifications, Admin, Mailer, …)
migrations/
  schema.sql               Full MySQL schema
  seed.sql                 Safe defaults (settings, VIP plans, credit packs)
storage/                   PROTECTED (pdfs, uploads, public covers) — blocked by .htaccess
server.php                 Router for `php -S` local dev
docs/                      DEPLOYMENT.md, ARCHITECTURE.md
.env.example               Copy to .env and fill in (never commit .env)
```

---

## Quick start (local, PHP built-in server)

```bash
# 1. PHP 8.1+ with extensions: pdo_mysql, mbstring, curl, fileinfo, openssl, json
#    (mbstring is polyfilled in api/config.php if the extension is missing)
# 2. MariaDB/MySQL: start the server, create the database, import schema + seed
service mariadb start   # Debian/Ubuntu; use mysqld/mysqld_safe elsewhere
mysql -u root -p -e "CREATE DATABASE examlegacy CHARACTER SET utf8mb4;"
mysql -u root -p examlegacy < migrations/schema.sql
mysql -u root -p examlegacy < migrations/seed.sql
# Upgrading an EXISTING database? also run:
#   mysql -u root -p examlegacy < migrations/002_rate_limits.sql

# 3. Configure secrets (copy + edit). NEVER commit the real .env
cp .env.example .env

# 4. Install PHP dependencies (PHPMailer)
composer install

# 5. Put your Firebase *web* config in assets/js/config.js
#    and your Firebase *project id* in .env (FIREBASE_PROJECT_ID)

# 6. Run
php -S 0.0.0.0:8000 server.php
```

Open `http://localhost:8000/` (student app) and `http://localhost:8000/admin/`.

**Admin console** signs in with the dedicated email + password from `.env`
(`ADMIN_EMAIL` / `ADMIN_PASSWORD`) — zero Google dependency. Legacy
role-based Google admins still work: `UPDATE users SET role='admin' WHERE email='you@example.com';`

**Database:** `mysql … < database.sql` (schema + seed) **or** open `/install.php`
once (1-click setup; delete it + `install.lock` afterwards).

See **docs/DEPLOYMENT.md** for Apache/Nginx, webhooks, and the deployment/rollback process.

---

## What is implemented (real, not mocked)

- **Auth:** Firebase Google Sign-In; server verifies the ID token signature + claims, then maps
  to a MySQL user (created on first login). Blocked/disabled accounts are rejected server-side.
- **Admin auth:** dedicated email + password (`eladm_` HMAC-SHA256 session tokens, 30-day TTL,
  rate-limited login) — fully independent of Google; accepted alongside legacy role-based sessions.
- **Realtime mirror (optional):** `FIRESTORE_SYNC=1` dual-writes users/orders/VIP docs to
  Firestore via service-account REST calls; `firestore.rules` denies all client access.
- **Ops:** `GET /api/health` (db ping), `install.php` one-click DB setup, `database.sql` master
  import, `migrations/upgrade_v21.sql` for older databases, smart MySQL failover to 127.0.0.1.
- **Store:** browse/search/filter published products; per-user "owned" flag from real access.
- **Payments:** Cashfree orders created server-side; orders become **PAID** only after a verified
  gateway fetch or a **signature-verified webhook** (idempotent). Browser callbacks are never trusted.
- **Store Wallet:** immutable ledger; recharge, purchase, refund, bonus, admin adjustment; no
  withdrawal/transfer. Atomic + idempotent.
- **AI Credits:** separate ledger; trial credits on first login, purchasable packs, per-use
  deduction with usage records, admin add/deduct.
- **Secure PDF viewer:** ownership → short-lived hashed viewer session → byte streaming with HTTP
  Range support → PDF.js rendering with dynamic purchaser watermark; download policy per product;
  unauthorized attempts logged.
- **Study AI:** a ChatGPT/Gemini-style **all-in-one study chat** — ask *any* study question on
  *any* subject with no purchase required, with multi-turn history, answer styles (concept, MCQs,
  notes, flashcards…) and MathJax-rendered equations. Optional grounding: attach a purchased PDF
  from the **Legacy Vault**, upload a file, or paste text — authorization is re-checked
  server-side and it can never reach other users' files, unpublished PDFs, URLs or the server.
- **Support:** separate grounded **Support AI** (verified account data only — deposits, orders,
  PDF access, wallet) and general **Help AI**, plus in-app human threads with the Open → Waiting →
  Resolved → Closed workflow. Everything support-related lives in the **Account** section.
- **Notifications:** idempotent (unique `event_key`) across purchase/payment/PDF/wallet/AI/VIP/
  support/system categories; duplicates from webhooks/retries are prevented.
- **VIP PASS:** monthly / yearly / unlimited plans with transparent, stored fair-use + rate-limit +
  anti-abuse rules (no false "unlimited" promises).
- **Admin panel:** products (+ protected PDF & cover upload), users (block/unblock, enable/disable,
  wallet credit/debit, AI-credit add/deduct, PDF grant/revoke, VIP grant, notify), orders (+ refund),
  support, notifications/broadcast, **credit-pack CRUD**, settings (external links, WhatsApp-support
  toggle default OFF, AI costs), VIP plans, and an immutable audit log. Every privileged action is
  audited.
- **Navigation:** Home · Store · Study AI · **Legacy Vault** (unique name for everything you
  purchased) · Account. Help & Support (Support AI, Help AI, human tickets) is inside **Account**.
- **Motion & polish:** boot splash with the ExamLegacy logo, 240ms `screen-enter` view
  transitions on every route, spring bottom sheets with blurred scrim
  (`cubic-bezier(0.16,1,0.3,1)`), staggered entrances, chat wave-typing indicators, VIP pulse
  glow, aurora ambience, frosted topbar, tactile press feedback, and a celebratory success modal
  after purchases — all GPU-cheap and disabled under `prefers-reduced-motion`. Works on mobile,
  desktop and all modern browsers.
- **Branding assets:** `assets/img/logo.svg` (full badge + wordmark) and `assets/img/mark.svg`
  (icon) — used by the splash, favicon, topbar, auth screen and admin. To use the official PNG
  artwork instead, drop it at `assets/img/logo.png` and update the references.
- **PWA (installable + offline shell):** `manifest.json` with generated icons (192/512,
  maskable, apple-touch — reproducible via `tools/gen_pwa_icons.py`), a versioned service worker
  (`sw.js`) that caches the **app shell only**, and an **Install app** row in Account
  (native prompt on Chrome/Android, "Add to Home Screen" guidance on iOS). **`/api/*` is never
  cached** — auth, payments, viewer streams and AI always go to the network. Registration needs
  HTTPS or localhost.

---

## Security rules enforced in code

- No Gemini / Cashfree / MySQL / SMTP / Firebase-service secrets in the client. Only the public
  Firebase web config is in the browser.
- No private PDF paths or server internals are ever returned to the client.
- Product IDs, order IDs, tokens and `localStorage` are **never** trusted for authorization.
- All financial mutations are atomic (`SELECT … FOR UPDATE` inside a transaction) and idempotent.
- **Rate limiting** (MySQL fixed-window): AI endpoints 30/min per user (20/min for Support/Help),
  viewer sessions 30/min, order creation & wallet recharge 15/min — automated abuse cannot drain
  credits, mint viewer tokens or hammer the gateway. Returns `429` with a retry hint.
- Security headers: `nosniff`, `SAMEORIGIN` framing, strict referrer, `Permissions-Policy`
  (camera/mic/geo disabled) — in both `.htaccess` (Apache) and `server.php` (dev).

---

## Branding

- Product owner: **Sanjay Katara** · Brand: **SANJAYXLEGACY** · "SANJAYXLEGACY Powered By"
- Support email: sanjayxlegacysupport@gmail.com
- Social/channel links and the WhatsApp-support toggle are **admin-configured** (see Settings).
  WhatsApp Support is **OFF by default**; no personal WhatsApp number is hard-coded.

See `docs/ARCHITECTURE.md` for the data model and security flow details.
