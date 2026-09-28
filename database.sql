-- ExamLegacy — master database file (schema + seed).
-- Import once into an empty MySQL 8 / MariaDB 10.5+ database.
-- Generated from migrations/schema.sql + migrations/seed.sql — edit those files, not this one.

-- ============================================================================
-- ExamLegacy — MySQL schema (authoritative financial & access data store)
-- Engine: InnoDB, utf8mb4. Money stored as integer paise (1 INR = 100 paise).
-- This file is idempotent-ish for a fresh database (uses CREATE TABLE IF NOT EXISTS).
-- ============================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- users: one row per Firebase-authenticated user, synced server-side.
-- Balance columns are cached aggregates; the ledger tables are the source of truth.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  firebase_uid        VARCHAR(128) NOT NULL,
  email               VARCHAR(190) NOT NULL,
  email_verified      TINYINT(1) NOT NULL DEFAULT 0,
  name                VARCHAR(190) NOT NULL DEFAULT '',
  phone               VARCHAR(24) NULL,
  avatar_url          VARCHAR(500) NULL,
  role                ENUM('user','admin') NOT NULL DEFAULT 'user',
  status              ENUM('active','suspended','disabled') NOT NULL DEFAULT 'active',
  wallet_balance_paise BIGINT NOT NULL DEFAULT 0,
  ai_credit_balance    BIGINT NOT NULL DEFAULT 0,
  trial_credits_given  TINYINT(1) NOT NULL DEFAULT 0,
  vip_active           TINYINT(1) NOT NULL DEFAULT 0,
  vip_expires_at       DATETIME NULL,
  last_login_at        DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_firebase (firebase_uid),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- products: published educational PDFs/products. pdf_path is a PROTECTED
-- server-side path and is NEVER returned to the client.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug             VARCHAR(190) NOT NULL,
  title            VARCHAR(190) NOT NULL,
  subtitle         VARCHAR(255) NOT NULL DEFAULT '',
  description      TEXT,
  category         VARCHAR(120) NOT NULL DEFAULT 'General',
  language         VARCHAR(60) NOT NULL DEFAULT 'English',
  price_paise      BIGINT NOT NULL DEFAULT 0,
  mrp_paise        BIGINT NOT NULL DEFAULT 0,
  currency         VARCHAR(8) NOT NULL DEFAULT 'INR',
  thumbnail_path   VARCHAR(500) NULL,      -- public-safe cover image path (under /storage/public or CDN)
  pdf_path         VARCHAR(500) NOT NULL,  -- PROTECTED path, server-only
  pdf_sha256       CHAR(64) NULL,
  page_count       INT NOT NULL DEFAULT 0,
  file_size_bytes  BIGINT NOT NULL DEFAULT 0,
  download_allowed TINYINT(1) NOT NULL DEFAULT 0, -- controlled download policy
  is_published     TINYINT(1) NOT NULL DEFAULT 0,
  is_vip           TINYINT(1) NOT NULL DEFAULT 0,
  access_duration_days INT NOT NULL DEFAULT 0,   -- 0 = lifetime
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_published (is_published),
  KEY idx_products_category (category),
  KEY idx_products_vip (is_vip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- orders: a purchase intent. Marked PAID only after server-side verification.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_code         VARCHAR(40) NOT NULL,
  user_id            BIGINT UNSIGNED NOT NULL,
  status             ENUM('pending','paid','failed','refunded','cancelled') NOT NULL DEFAULT 'pending',
  payment_method     ENUM('cashfree','wallet','split') NOT NULL DEFAULT 'cashfree',
  subtotal_paise     BIGINT NOT NULL DEFAULT 0,
  wallet_applied_paise BIGINT NOT NULL DEFAULT 0,
  gateway_amount_paise BIGINT NOT NULL DEFAULT 0,
  total_paise        BIGINT NOT NULL DEFAULT 0,
  currency           VARCHAR(8) NOT NULL DEFAULT 'INR',
  provider           VARCHAR(30) NULL,          -- 'cashfree' when gateway used
  provider_order_id  VARCHAR(120) NULL,
  failure_reason     VARCHAR(255) NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at            DATETIME NULL,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_code (order_code),
  KEY idx_orders_user (user_id),
  KEY idx_orders_status (status),
  KEY idx_orders_provider (provider_order_id),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id       BIGINT UNSIGNED NOT NULL,
  product_id     BIGINT UNSIGNED NOT NULL,
  title_snapshot VARCHAR(190) NOT NULL,
  price_paise    BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_items_order (order_id),
  KEY idx_items_product (product_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- payment_intents: ONE verified gateway flow for every paid action
-- (product order, wallet recharge, AI credit pack, VIP PASS).
-- Browser callbacks are never authority; only a verified gateway settle marks
-- these 'paid'. Idempotent by idempotency_key; reference_id links to ledgers.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_intents (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            BIGINT UNSIGNED NOT NULL,
  kind               ENUM('order','recharge','credit_pack','vip') NOT NULL,
  ref_id             BIGINT UNSIGNED NULL,          -- order_id when kind='order'
  amount_paise       BIGINT NOT NULL DEFAULT 0,
  currency           VARCHAR(8) NOT NULL DEFAULT 'INR',
  status             ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
  provider           VARCHAR(30) NOT NULL DEFAULT 'cashfree',
  provider_order_id  VARCHAR(120) NULL,
  idempotency_key    VARCHAR(160) NOT NULL,
  meta               JSON NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at            DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_intent_idem (idempotency_key),
  KEY idx_intent_user (user_id, id),
  KEY idx_intent_provider_order (provider_order_id),
  CONSTRAINT fk_intent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- credit_packs: purchasable AI credit bundles (separate from Store Wallet).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS credit_packs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(40) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  credits       BIGINT NOT NULL DEFAULT 0,
  bonus_credits BIGINT NOT NULL DEFAULT 0,
  price_paise   BIGINT NOT NULL DEFAULT 0,
  currency      VARCHAR(8) NOT NULL DEFAULT 'INR',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_credit_pack_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- wallet_transactions: IMMUTABLE ledger. Store Wallet only — no withdrawals.
-- amount_paise is signed (+ credit, - debit). balance_after is a running cache.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_transactions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  type            ENUM('recharge','purchase','refund','bonus','adjustment') NOT NULL,
  amount_paise    BIGINT NOT NULL,             -- signed
  balance_after_paise BIGINT NOT NULL,
  currency        VARCHAR(8) NOT NULL DEFAULT 'INR',
  order_id        BIGINT UNSIGNED NULL,
  payment_id      BIGINT UNSIGNED NULL,
  reference_id    VARCHAR(120) NULL,           -- external/gateway reference
  idempotency_key VARCHAR(160) NOT NULL,
  description     VARCHAR(255) NOT NULL DEFAULT '',
  created_by      BIGINT UNSIGNED NULL,        -- admin id for manual adjustments
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallet_idem (idempotency_key),
  KEY idx_wallet_user (user_id, id),
  KEY idx_wallet_order (order_id),
  CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ai_credit_transactions: SEPARATE ledger for AI credits (not money).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_credit_transactions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  type            ENUM('trial','purchase','usage','adjustment') NOT NULL,
  amount          BIGINT NOT NULL,             -- signed credits
  balance_after   BIGINT NOT NULL,
  usage_id        BIGINT UNSIGNED NULL,
  reference_id    VARCHAR(120) NULL,
  idempotency_key VARCHAR(160) NOT NULL,
  description     VARCHAR(255) NOT NULL DEFAULT '',
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_aicredit_idem (idempotency_key),
  KEY idx_aicredit_user (user_id, id),
  CONSTRAINT fk_aicredit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- pdf_access: proof of ownership. One active access per (user, product).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pdf_access (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  product_id    BIGINT UNSIGNED NOT NULL,
  order_id      BIGINT UNSIGNED NULL,
  status        ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  granted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NULL,
  revoked_at    DATETIME NULL,
  revoked_reason VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_access_user_product (user_id, product_id),
  KEY idx_access_user (user_id),
  KEY idx_access_product (product_id),
  CONSTRAINT fk_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_access_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- viewer_sessions: short-lived, hashed tokens for the in-app PDF viewer.
-- The raw token is returned to the client once; only its hash is stored.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS viewer_sessions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  access_id   BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  issued_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  revoked     TINYINT(1) NOT NULL DEFAULT 0,
  ip          VARBINARY(16) NULL,
  user_agent  VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_viewer_token (token_hash),
  KEY idx_viewer_user (user_id),
  KEY idx_viewer_access (access_id),
  CONSTRAINT fk_viewer_access FOREIGN KEY (access_id) REFERENCES pdf_access(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- pdf_access_logs: audit trail of grants, streams and denied attempts.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pdf_access_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,
  product_id  BIGINT UNSIGNED NULL,
  access_id   BIGINT UNSIGNED NULL,
  event       ENUM('granted','session_issued','stream','range','denied','revoked_attempt','expired') NOT NULL,
  ip          VARBINARY(16) NULL,
  user_agent  VARCHAR(255) NULL,
  meta        JSON NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pdflog_user (user_id, id),
  KEY idx_pdflog_product (product_id, id),
  KEY idx_pdflog_event (event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ai_documents: user-uploaded files & cached text of purchased PDFs for Study AI.
-- storage_path is PROTECTED. Only the owning user (or admin) may use it.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_documents (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  kind         ENUM('upload','purchased') NOT NULL,
  product_id   BIGINT UNSIGNED NULL,
  filename     VARCHAR(255) NOT NULL,
  mime         VARCHAR(120) NOT NULL,
  size_bytes   BIGINT NOT NULL DEFAULT 0,
  storage_path VARCHAR(500) NULL,             -- PROTECTED
  extracted_text LONGTEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aidoc_user (user_id, id),
  CONSTRAINT fk_aidoc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ai_usage: every AI request tracked; drives credit deduction.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_usage (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  kind          ENUM('study','support','help') NOT NULL,
  document_id   BIGINT UNSIGNED NULL,
  product_id    BIGINT UNSIGNED NULL,
  credits_spent BIGINT NOT NULL DEFAULT 0,
  input_tokens  INT NOT NULL DEFAULT 0,
  output_tokens INT NOT NULL DEFAULT 0,
  status        ENUM('ok','error') NOT NULL DEFAULT 'ok',
  meta          JSON NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aiusage_user (user_id, id),
  CONSTRAINT fk_aiusage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- notifications: in-app, idempotent via event_key.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NULL,             -- NULL = broadcast to all users
  category   ENUM('account','purchase','payment','order','pdf','support','product','system') NOT NULL DEFAULT 'system',
  title      VARCHAR(190) NOT NULL,
  body       TEXT,
  data       JSON NULL,
  event_key  VARCHAR(190) NULL,                -- idempotency guard
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notif_event (event_key),
  KEY idx_notif_user (user_id, id),
  KEY idx_notif_read (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- support: human support threads & messages (in-app).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS support_threads (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  subject         VARCHAR(190) NOT NULL DEFAULT '',
  status          ENUM('open','waiting_admin','waiting_user','resolved','closed') NOT NULL DEFAULT 'open',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_message_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_threads_user (user_id, id),
  KEY idx_threads_status (status),
  CONSTRAINT fk_threads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id     BIGINT UNSIGNED NOT NULL,
  sender        ENUM('user','admin','ai') NOT NULL,
  body          TEXT NOT NULL,
  read_by_user  TINYINT(1) NOT NULL DEFAULT 0,
  read_by_admin TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_messages_thread (thread_id, id),
  CONSTRAINT fk_messages_thread FOREIGN KEY (thread_id) REFERENCES support_threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- site_settings: admin-controlled key/value (external support links, toggles).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`      VARCHAR(120) NOT NULL,
  value      TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- admin_audit_logs: immutable record of privileged admin actions.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_audit_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_role    VARCHAR(20) NOT NULL DEFAULT 'admin',
  action        VARCHAR(120) NOT NULL,
  entity        VARCHAR(60) NOT NULL DEFAULT '',
  entity_id     VARCHAR(60) NULL,
  before_json   JSON NULL,
  after_json    JSON NULL,
  ip            VARBINARY(16) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_actor (actor_user_id, id),
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- rate_limits: fixed-window counters for abuse protection (AI, orders, viewer).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key   VARCHAR(120) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start),
  KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- VIP PASS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vip_plans (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40) NOT NULL,
  name        VARCHAR(120) NOT NULL,
  interval    ENUM('monthly','yearly','unlimited') NOT NULL,
  price_paise BIGINT NOT NULL DEFAULT 0,
  currency    VARCHAR(8) NOT NULL DEFAULT 'INR',
  benefits    JSON NULL,
  fair_use    JSON NULL,                       -- transparent fair-use / rate-limit rules
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vip_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vip_subscriptions (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  plan_id    BIGINT UNSIGNED NOT NULL,
  status     ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,                    -- NULL = unlimited (no expiry)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vip_user (user_id, id),
  CONSTRAINT fk_vip_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vip_plan FOREIGN KEY (plan_id) REFERENCES vip_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- study_topics: categorized syllabus topic summaries used by Study AI context.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS study_topics (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category    VARCHAR(120) NOT NULL,
  topic_name  VARCHAR(190) NOT NULL,
  summary     TEXT,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_study_topics_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- user_notes: personal scratchpad notes anchored to a product page (viewer).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_notes (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  page_number INT NOT NULL DEFAULT 1,
  note_text   TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_notes_user_product (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- user_bookmarks: page bookmarks saved inside the secure viewer.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_bookmarks (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  page_number INT NOT NULL DEFAULT 1,
  label       VARCHAR(190) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_bookmarks_user_product (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- webhook_events: idempotency/audit log for payment gateway webhooks.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id     VARCHAR(191) NOT NULL,
  source       VARCHAR(40) NOT NULL DEFAULT 'cashfree',
  payload_hash CHAR(64) NOT NULL DEFAULT '',
  status       VARCHAR(30) NOT NULL DEFAULT 'received',
  processed_at DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhook_events_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ExamLegacy — seed data (safe defaults; NO secrets, NO personal numbers).
-- External support destinations are admin-configured. WhatsApp support is OFF.
-- ============================================================================
SET NAMES utf8mb4;

-- Admin-controlled external support links (edit in Admin Panel > Settings).
INSERT INTO site_settings (`key`, value) VALUES
  ('support_email', 'sanjayxlegacysupport@gmail.com'),
  ('telegram', 'https://t.me/sanjayx_legacy01'),
  ('telegram_channel', 'https://t.me/examlegacy'),
  ('instagram', 'https://instagram.com/sanjayx_legacy01'),
  ('youtube', 'https://youtube.com/@sanjayxlagacy'),
  ('whatsapp_channel', 'https://whatsapp.com/channel/0029VbDBSLU65yDGKoSljB3v'),
  ('whatsapp_support_enabled', '0'),
  ('whatsapp_support_link', ''),
  ('brand_powered_by', 'SANJAYXLEGACY'),
  ('trial_ai_credits', '50'),
  ('ai_credit_cost_study', '2'),
  ('ai_credit_cost_support', '1'),
  ('currency', 'INR')
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- VIP PASS plans. Unlimited plan carries explicit transparent fair-use rules.
INSERT INTO vip_plans (code, name, interval, price_paise, currency, benefits, fair_use, is_active) VALUES
  ('vip_monthly', 'VIP PASS Monthly', 'monthly', 19900, 'INR',
     JSON_OBJECT('premium_ai', true, 'vip_pdfs', true, 'member_discount_pct', 10, 'vip_badge', true, 'priority_support', true, 'early_access', true),
     JSON_OBJECT('note', 'Fair-use: reasonable personal study use. Rate limits apply to prevent abuse.'),
     1),
  ('vip_yearly', 'VIP PASS Yearly', 'yearly', 99900, 'INR',
     JSON_OBJECT('premium_ai', true, 'vip_pdfs', true, 'member_discount_pct', 15, 'vip_badge', true, 'priority_support', true, 'early_access', true),
     JSON_OBJECT('note', 'Fair-use: reasonable personal study use. Rate limits apply to prevent abuse.'),
     1),
  ('vip_unlimited', 'VIP PASS Unlimited', 'unlimited', 499900, 'INR',
     JSON_OBJECT('premium_ai', true, 'vip_pdfs', true, 'member_discount_pct', 20, 'vip_badge', true, 'priority_support', true, 'early_access', true),
     JSON_OBJECT(
        'unlimited_scope', 'Unlimited applies to included VIP PDFs and standard AI usage within fair-use limits.',
        'rate_limit', JSON_OBJECT('study_ai_per_minute', 20, 'burst', 40),
        'anti_abuse', 'Automated scraping, resale, or credential sharing violates fair use and may suspend access.',
        'note', 'Not truly infinite: security, rate-limits and anti-abuse rules always apply.'
     ),
     1)
ON DUPLICATE KEY UPDATE name = VALUES(name), price_paise = VALUES(price_paise), benefits = VALUES(benefits), fair_use = VALUES(fair_use), is_active = VALUES(is_active);

-- AI credit packs (separate from Store Wallet).
INSERT INTO credit_packs (code, name, credits, bonus_credits, price_paise, currency, is_active) VALUES
  ('credits_starter', 'Starter Pack', 100, 0, 4900, 'INR', 1),
  ('credits_scholar', 'Scholar Pack', 350, 0, 14900, 'INR', 1),
  ('credits_master',  'Master Pack',  1000, 150, 39900, 'INR', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), credits = VALUES(credits), bonus_credits = VALUES(bonus_credits), price_paise = VALUES(price_paise), is_active = VALUES(is_active);
