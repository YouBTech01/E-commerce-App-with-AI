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
