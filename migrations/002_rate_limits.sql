-- Migration 002 — rate limiting (abuse protection).
-- Run against EXISTING databases only; fresh installs get this from schema.sql.
--   mysql -u root -p examlegacy < migrations/002_rate_limits.sql

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key   VARCHAR(120) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start),
  KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
