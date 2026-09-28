# ExamLegacy — Deployment & Operations

## Requirements

- PHP **8.1+** with extensions: `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `openssl`, `json`, `intl`.
- MySQL **8.0+** (or MariaDB 10.6+).
- Composer (for PHPMailer).
- A Firebase project with **Google Sign-In** enabled and your domain added to authorized domains.
- A **Cashfree** account (PG) and a **Gemini API** key.
- An SMTP account for PHPMailer.

## 1. Database

Option A — master file (schema + seed in one):

```bash
mysql -u root -p -e "CREATE DATABASE examlegacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p examlegacy < database.sql
```

Option B — 1-click installer (shared hosting friendly):

```bash
# visit /install.php once — imports schema+seed+upgrades, writes install.lock, then DELETE install.php
```

Option C — step by step:

```bash
mysql -u root -p examlegacy < migrations/schema.sql
mysql -u root -p examlegacy < migrations/seed.sql
```

Existing databases created before the 21-table spec alignment:

```bash
mysql -u root -p examlegacy < migrations/upgrade_v21.sql   # renames legacy tables + adds spec tables
```

## 2. Configuration

```bash
cp .env.example .env
# edit .env — set DB_*, FIREBASE_PROJECT_ID, CASHFREE_*, GEMINI_API_KEY, SMTP_*,
#             ADMIN_EMAIL + ADMIN_PASSWORD (console login), VIEWER_TOKEN_SECRET
```

- `VIEWER_TOKEN_SECRET` must be a long random string (also backs admin session signing unless `ADMIN_TOKEN_SECRET` is set).
- `ADMIN_EMAIL` / `ADMIN_PASSWORD` = the **only** credentials for `/admin` (email+password, zero Google dependency).
- `FIRESTORE_SYNC=1` + `FIRESTORE_SERVICE_ACCOUNT` = optional real-time mirror; deploy `firestore.rules` with
  `firebase deploy --only firestore:rules` (client access stays denied — server-only writes).
- `APP_ENV=production` and `APP_DEBUG=0` in production (never expose errors).
- Put the Firebase **web** config in `assets/js/config.js` (`EL.FIREBASE_CONFIG`) — students only.
- `composer install` only if you opt into PHPMailer (the bundled SMTP client works without it).
- Health probe for uptime monitors: `GET /api/health` → `{"status":"ok","db":true,...}`.

### Keep app code out of the web root (recommended)

Ideally place `api/lib`, `api/config.php`, `storage`, `migrations`, `vendor`, `.env` **outside**
the document root and point `STORAGE_PATH` there. If they must live under the web root, the
provided `.htaccess` files deny HTTP access to them (defense in depth).

## 3. Web server

### Apache (mod_rewrite)

The repository ships a root `.htaccess` that:
- denies `.env`, `vendor/`, `migrations/`, `docs/`, `storage/`, `api/lib/`, `api/config.php`;
- routes `/api/*` to `api/index.php`.

Point the vhost `DocumentRoot` at the repository root and ensure `AllowOverride All`.

### Nginx (equivalent)

```nginx
server {
  listen 443 ssl http2;
  server_name your-domain.com;
  root /var/www/examlegacy;
  index index.html;

  location ~ ^/api { try_files $uri /api/index.php?$query_string; }

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
  }

  # Never serve protected areas
  location ~ ^/(storage|vendor|migrations|docs)/ { deny all; return 404; }
  location ~ /\.(env|git) { deny all; return 404; }

  location / { try_files $uri $uri/ /index.html; }
}
```

Set PHP upload limits to allow PDF uploads:
`upload_max_filesize=64M`, `post_max_size=64M`, `memory_limit=256M`.

## 4. Cashfree

1. In `api/config.php` the client id/secret come from `.env` (`CASHFREE_APP_ID`, `CASHFREE_SECRET_KEY`).
2. Set `CASHFREE_ENV=production` and the matching API version.
3. **Webhook:** In the Cashfree dashboard set the webhook URL to
   `https://your-domain.com/api/payments/webhook` and copy the webhook secret into
   `CASHFREE_WEBHOOK_SECRET`. The server verifies the signature (HMAC-SHA256 of
   `timestamp + rawBody`) before processing, and processing is idempotent.
4. Add your domain to the allowed origins/redirect settings in Cashfree.

## 5. Firebase

1. Enable Google Sign-In.
2. Add your production domain to **Authorized domains**.
3. Put the project id in `.env` (`FIREBASE_PROJECT_ID`) — the server uses it to verify token
   `aud`/`iss` and to fetch Google's public signing certs.
4. (Optional) For any privileged server-side Firebase calls, set `FIREBASE_SERVICE_ACCOUNT` to a
   path **outside** the web root. Authentication itself does not require it.

## 6. First admin

The console at `/admin` uses a **dedicated email + password** (zero Google dependency):

1. Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env`.
2. Sign in at `/admin` with those credentials (HMAC session token, 30 days).
3. Optionally also promote a signed-in Google user for legacy role-based access:

```sql
UPDATE users SET role='admin' WHERE email='you@example.com';
```

Both paths are accepted by the API; the email+password path is the primary one.

## 7. Go-live checklist

- [ ] `.env` present with real secrets; `.env` is git-ignored and not web-served.
- [ ] `APP_DEBUG=0`, `APP_ENV=production`.
- [ ] Schema + seed imported; at least one product **published** with an uploaded PDF.
- [ ] Settings configured (support email, social links). WhatsApp Support stays OFF unless intended.
- [ ] Cashfree webhook reachable and signature verified (check `payments`/`payment_intents`).
- [ ] SMTP sending works (test a purchase receipt).
- [ ] HTTPS enforced; HSTS on — required for the **PWA service worker** registration.
- [ ] PWA verified: manifest loads, install prompt appears, app opens offline from the cached
      shell, and **no `/api/` response is present in DevTools → Cache Storage**.
- [ ] After shipping frontend changes: bump `VERSION` in `sw.js` so clients refresh the shell.

## 8. Deployment process

1. `git pull` (or deploy the release artifact) to the server.
2. `composer install --no-dev --optimize-autoloader`.
3. Run any new `migrations/*.sql` against the database.
4. Clear/rebile opcache if used; restart PHP-FPM.
5. Smoke-test: sign in, list products, make a test purchase, open the viewer, ask Study AI.

## 9. Rollback

- **Code:** redeploy the previous release/commit and re-run `composer install`.
- **Database:** migrations are additive (`CREATE TABLE IF NOT EXISTS`); to roll back a bad data
  change, restore from the most recent MySQL snapshot. Keep nightly `mysqldump` backups.
- **Payments:** the payment ledger is append-only and idempotent, so re-processing a webhook is
  safe and never double-credits.

## 10. Observability

- PHP errors are logged (never displayed) in production; check your FPM/web-server error log.
- Every privileged admin action is written to `audit_logs`.
- Every PDF access/stream/denial is written to `pdf_access_logs`.
