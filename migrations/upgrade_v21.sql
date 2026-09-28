-- ============================================================================
-- ExamLegacy — upgrade v2.1 (existing installs → spec-aligned schema)
-- Adds the 4 spec tables and renames legacy tables to their spec names.
-- Fresh installs do NOT need this file: import schema.sql + seed.sql instead.
-- Safe to run more than once (IF NOT EXISTS / conditional renames below).
-- ============================================================================

-- 1) Spec-name alignment (skip if already renamed) ----------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'settings');
SET @sql := IF(@has > 0,
  'RENAME TABLE `settings` TO `site_settings`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'audit_logs');
SET @sql := IF(@has > 0,
  'RENAME TABLE `audit_logs` TO `admin_audit_logs`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'vip_memberships');
SET @sql := IF(@has > 0,
  'RENAME TABLE `vip_memberships` TO `vip_subscriptions`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Spec tables that may not exist yet --------------------------------------
CREATE TABLE IF NOT EXISTS study_topics (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category    VARCHAR(120) NOT NULL,
  topic_name  VARCHAR(190) NOT NULL,
  summary     TEXT,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_study_topics_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
