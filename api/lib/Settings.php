<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Admin-controlled key/value settings (external support links, toggles, pricing hints).
 * Cached per-request. Only expose non-secret values to the client.
 */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::all('SELECT `key`, value FROM site_settings') as $row) {
                self::$cache[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        Db::run(
            'INSERT INTO site_settings (`key`, value) VALUES (?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$key, $value]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = (string) $value;
        }
    }

    /** Public, non-secret subset safe to send to any client. */
    public static function publicConfig(): array
    {
        return [
            'currency'                => self::get('currency', 'INR'),
            'support_email'           => self::get('support_email', ''),
            'telegram'                => self::get('telegram', ''),
            'telegram_channel'        => self::get('telegram_channel', ''),
            'instagram'               => self::get('instagram', ''),
            'youtube'                 => self::get('youtube', ''),
            'whatsapp_channel'        => self::get('whatsapp_channel', ''),
            'whatsapp_support_enabled'=> self::get('whatsapp_support_enabled', '0') === '1',
            'whatsapp_support_link'   => self::get('whatsapp_support_link', ''),
            'brand_powered_by'        => self::get('brand_powered_by', 'SANJAYXLEGACY'),
            'trial_ai_credits'        => (int) self::get('trial_ai_credits', '0'),
        ];
    }
}
