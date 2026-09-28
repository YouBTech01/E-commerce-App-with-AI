# MASTER PROMPT — ExamLegacy: Final Optimise · Production-Ready · LIVE

> **Kaise use karein:** Poora yeh file ek AI agent ko prompt ke roop me paste karo —
> saath me yahi repo/branch. Yeh "Final Optimise Upgrade Vision" wala master hai:
> system integration verify karega, optimize karega, aur LIVE karega — isi project par,
> dobara banaye nahi.
>
> Project: `MRSANJAYKATARA/E-commerce-App-with-AI` · Branch: `arena/01a0d12f-e-commerce-app-with-ai`
> Baseline: Blueprint v2.0.0 (`9cc1df9`) → PWA (`94cd823`) → UI/UX v3 (`ecd1547`)

---

## PROMPT (copy from here)

### 0. Mission

You are the **Final-Pass Engineer** for ExamLegacy. This exact project (same repo, same
stack, same design) must now become: **fully integrated → optimized → production-ready
→ LIVE**. You work autonomously: verify, fix, ship. Do not ask for repeated
confirmations. Do not rebuild, replace frameworks, or redesign — the look is locked
(UI/UX v3). Deliver in **one phase** (a single squashed delivery commit per workstream
batch unless a hard reason demands otherwise). Every claim needs evidence (command
output, file:line, live URL).

### 1. Project identity (SAME project — never a rewrite)

| Layer | Truth |
|---|---|
| Backend | PHP 8 `declare(strict_types=1)` front controller `api/index.php` (route + authorize only) + business logic in `api/lib/*` (Db, Http, Auth, Wallet, AiCredits, Store, Orders, Payments, Gemini, StudyAi, Support, PdfText, PdfVault, Admin, Mailer, RateLimit) |
| DB | MySQL — `migrations/schema.sql` (tables incl. unified `payment_intents`, `viewer_sessions`, ledger tables) + `migrations/seed.sql` (products, credit packs credits_starter/scholar/master, settings) |
| Frontend | Vanilla SPA — `index.html` (MathJax, `#splash`), `assets/css/app.css` (design tokens), `assets/js/ui.js` (dispatch, sheet, md, celebration, aiTyping), `assets/js/app.js` (screens/flows), `assets/js/viewer.js` (PDF, X-Viewer-Token) |
| Admin | `admin/` SPA + `admin/assets/js/admin.js` + `admin/assets/css/admin.css` ("Super Power" per-item management) |
| PWA | `manifest.json`, `sw.js` (versioned shell caches; `/api/*` NEVER cached), icons via `tools/gen_pwa_icons.py` |
| Security | `.htaccess` + `server.php` headers; `api/config.php` reads `.env` (keys server-side only) |
| Docs | `README.md`, `docs/ARCHITECTURE.md`, `docs/DEPLOYMENT.md` (go-live checklist §7), `docs/UIUX-ROADMAP.md` (executed) |

**API surface (must all respond correctly — 45 static + dynamic patterns):**
`GET /config` · `GET /products`, `GET /products/{slug}` · `GET /categories` · `GET /cover` ·
`GET /credits`, `GET /credits/packs`, `POST /credits/purchase` · `GET /library` ·
`GET|PATCH /me`, `POST /me/avatar` · `GET /notifications`, `POST /notifications/read-all`,
`POST /notifications/{id}/read` · `GET /orders`, `POST /orders`, `GET /orders/{id}` ·
`POST /payments/verify`, `POST /payments/webhook` (HMAC + idempotent) ·
`GET /vip/plans`, `POST /vip/purchase` · `GET /wallet`, `POST /wallet/recharge` ·
`POST /viewer/session`, `GET /viewer/stream` (range + watermark) ·
`POST /ai/study`, `POST /ai/support`, `POST /ai/help` (Gemini server-side) ·
`GET|POST /support`, `GET /support/{id}`, `POST /support/{id}/messages` ·
`POST /uploads` · admin: `/admin/stats|products|orders|users|settings|audit|support|notify|broadcast|vip/plans|credits/packs|products/upload-cover|products/upload-pdf` +
`/admin/users/{id}[/status|/wallet|/credits|/pdf/grant|/pdf/revoke|/vip]`,
`PATCH /admin/credits/packs/{id}` (DELETE archives).

### 2. NON-NEGOTIABLE INVARIANTS (breaking any = failure)

1. **Money:** integer paise everywhere; Store Wallet and AI Credits are separate
   ledgers; ledger rows are authoritative; server verifies every payment
   (Cashfree webhook signature, idempotent `payment_intents`); no client-side trust.
2. **PDF vault:** no public PDF URLs ever — access only via `POST /viewer/session` →
   short-lived token (`VIEWER_SESSION_TTL` 7200) → `GET /viewer/stream` with
   watermark + range streaming; keys/covers outside web root or upload-guarded.
3. **Secrets:** Gemini, Cashfree, Firebase, SMTP keys server-side only (`.env`), never
   in JS, never in git.
4. **PWA:** `sw.js` must NEVER cache `/api/*` (GET-only, `isApi()` bypass), navigations
   network-first, shell caches versioned; bump `VERSION` on frontend releases.
5. **Navigation contract:** bottom nav = Home, Store, Study AI, Vault, Account;
   Support exists only at Account → Help & Support; admin under `/admin/`.
6. **Product contracts:** Study AI answers ANY study question with NO purchase;
   Support AI handles app/payment problems; purchased PDFs live only in Vault;
   admin can manage every entity (orders, users, products, packs, VIP, settings, notify).
7. **Design/UX:** Blueprint v2.0.0 screens/motion/state-matrix + UI/UX v3 (iOS 27 type
   scale, Liquid Glass 2.0 + intensity slider) are locked; `screen-enter` on every
   dispatch; `prefers-reduced-motion` honored; both themes AA contrast.
8. **Rate limits:** ai 30/min, support/help 20, viewer 30, orders 15, recharge 15 — keep.
9. **Auth:** Firebase ID token verified server-side (`aud`/`iss`) or signed session;
   authorization always server-side, frontend never a security authority.

### 3. FINAL OPTIMISE UPGRADE VISION (seven workstreams)

**W1 — Integration Integrity (sahi se integrate):**
Build a contract matrix: every `EL.api.*` / fetch in `assets/js/*` and
`admin/assets/js/admin.js` ↔ route in `api/index.php` ↔ response shape consumed.
Verify: auth lifecycle (Google sign-in → token → refresh → sign-out), catalog → PDP →
cart-less checkout (Wallet / Cashfree / Split) → verify+webhook → Vault grant, wallet
recharge with 2.5s × 60-poll verify loop, AI credit debit + `source_type` general-chat
allowlist, viewer session lifecycle incl. expiry, support thread + unread badge,
admin CRUD on all entities, notifications broadcast → user list. **Deliverable:**
`docs/INTEGRATION-AUDIT.md` with matrix rows = PASS/FAIL + fix log until all PASS.

**W2 — Performance:**
Minify/gzip (or brotli) CSS/JS; cache headers (immutable for fingerprinted assets,
`no-store` for `/api/*`); font strategy (preconnect + `display=swap` + only used
weights — Outfit 400/500/600/700/800 + system stack); images: compressed covers
(WebP/AVIF + `srcset`, width/height or `aspect-ratio` for zero CLS); critical path for
`#splash`; kill render-blocking; audit `backdrop-filter` usage = fixed chrome only;
budgets: LCP ≤ 2.0s, CLS ≤ 0.1, INP ≤ 200ms, initial JS ≤ ~120KB gzip, zero
unnecessary reflows on scroll (45–60fps on mid Android).

**W3 — Security Hardening:**
Full header set present (CSP appropriate for inline+MathJax+Cashfree, nosniff,
frame-ancestors SAMEORIGIN, Referrer-Policy, Permissions-Policy, HSTS on prod);
CORS same-origin allowlist; sweep all queries for prepared statements; payload/size
caps (`POST /uploads`, avatar ≤ 2MB, chat ≤ 4000 chars/12 turns); error leakage off
(`APP_DEBUG=0`, generic 500s, `error_log` structured); webhook timestamp tolerance;
webhook + payments idempotency re-test; `.env` perms 600, not web-served; git history
scan for secrets; rate-limit headers; OWASP ASVS L1-style checklist for the PHP
surface → `docs/SECURITY-REPORT.md`.

**W4 — Code Quality:**
`php -l` on EVERY `api/**/*.php` (real PHP runtime required — install CLI or run on
target server; the `/tmp/phpcheck.py` heuristic is NOT enough); dead code/CSS/JS
removed; no console errors/warnings in any screen; consistent error envelope; add
`GET /api/health` (db ping + version + migrations state) if missing; logging standard
(`[ExamLegacy] level msg context`).

**W5 — UX / A11y Final Pass:**
Browser matrix: iOS Safari (blur prefixes, safe-area), Chrome Android, Firefox
(`@supports` fallback path), desktop; all 8 journeys × 2 themes × mobile/desktop;
focus-visible order, aria labels on icon buttons, 44px targets, contrast spot-check;
empty/loading/error states for every async view; toasts reachable; offline banner
behavior with SW; PWA install flows (Chrome prompt + iOS sheet).

**W6 — Production Deploy & LIVE (the actual go-live):**
Follow `docs/DEPLOYMENT.md` as source of truth. Provision: LAMP (PHP 8.1+, MySQL 8,
Apache mod_rewrite / or Nginx+PHP-FPM), `.env` with real secrets, import
`schema.sql` + `seed.sql`, publish ≥1 product with real PDF, HTTPS (certbot) +
HSTS, correct vhost (block `/api/config.php` direct serve, deny dotfiles, allow
`/uploads` only as intended), base-path correctness for `/api` + SPA fallback +
`/admin`. Then **smoke-test LIVE** with a test user: sign-in → browse → wallet
recharge (test mode) → buy → Vault opens PDF → Study AI answers → Support creates
ticket → admin grants/revokes → payment webhook recorded. Deploy method: rsync/git
pull on server, no dev files, rollback = previous commit tag. Evidence: live URLs +
terminal outputs in `docs/GO-LIVE-REPORT.md`.

**W7 — Observability & Stability:**
PHP error log rotation + `display_errors=off`; uptime monitor on `/api/health`;
payment reconciliation SQL (orders vs `payment_intents` vs ledger) in docs; admin
audit-trail spot check; backup plan (daily DB dump + `/uploads` sync) documented;
48h watch period with issue log.

### 4. Definition of Done (acceptance — all must be TRUE with evidence)

| # | Criterion | Evidence |
|---|---|---|
| 1 | Contract matrix all PASS | `docs/INTEGRATION-AUDIT.md` |
| 2 | `php -l` 0 errors on all API files (real PHP) | command output |
| 3 | Frontend syntax clean: `node --check` app/ui/viewer/admin/sw | command output |
| 4 | Lighthouse (mobile, prod-like): Perf ≥ 90, A11y ≥ 95, Best ≥ 95 | report |
| 5 | Smoke flow passes on LIVE https URL | `docs/GO-LIVE-REPORT.md` |
| 6 | DevTools → Cache Storage contains NO `/api/` response; offline shell works | screenshot/log |
| 7 | Security report closed (no high/critical open) | `docs/SECURITY-REPORT.md` |
| 8 | Both themes + intensity slider + reduced-motion verified | manual log |
| 9 | Zero console errors on all 8 journeys | console log |
| 10 | Go-live checklist `docs/DEPLOYMENT.md` §7 fully ticked | checked list |
| 11 | Docs updated (README status, CHANGELOG entry), pushed to session branch | git log |

### 5. Working rules

- **Same project only:** no framework swap, no rewrite, no redesign, no dependency
  added without a concrete need it solves.
- **Verify → claim:** every "done" backed by an artifact; if unverifiable in current
  environment, mark BLOCKED with exact command the server must run.
- **Autonomy:** fix what you find; decide and document; ask AT MOST once, only for
  true blockers (credentials, DNS, payment keys).
- **Git:** work only on `arena/01a0d12f-e-commerce-app-with-ai`; commit with clear
  messages; push after each green checkpoint; keep history clean (squash per phase —
  owner prefers one-phase delivery).
- **Never break invariant §2** — treat it as tests that must always pass.
- If the harness resets HEAD, recover: `git add -A` → `git reset --soft origin/arena/...`
  → verify delta → commit → push (known issue; tree is source of truth).

### 6. Execution order

1. Baseline evidence (branch state, lint, serve, current checklist)
2. W1 Integration audit → fix until matrix green
3. W4 quality (php -l real runtime) + W3 security sweep
4. W2 performance pass + W5 UX/a11y pass
5. W6 staging deploy → smoke → production cutover → LIVE
6. W7 observability + 48h watch
7. Final report (DoD table filled, all docs pushed)

### 7. Context pack (read before touching code)

`README.md` → `docs/ARCHITECTURE.md` → `docs/DEPLOYMENT.md` (§3 web server, §7 checklist)
→ `docs/UIUX-ROADMAP.md` (executed, locked) → `api/index.php` → `api/lib/*.php` →
`migrations/schema.sql` + `seed.sql` → `assets/js/app.js` (route/flow map) →
`assets/js/ui.js` (UI contracts) → `sw.js` → `.htaccess` + `server.php` → `api/config.php`.

**Start now: Phase 1 = baseline evidence + W1 contract matrix. Deliver, do not ask.**

## PROMPT ends here
