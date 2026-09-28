<?php
declare(strict_types=1);

namespace ExamLegacy;

/**
 * Store — product/PDF catalog. Public output NEVER includes the protected
 * pdf_path or any server internals. Per-user "owned" flag is computed from
 * verified pdf_access.
 */
final class Store
{
    /** Public-safe URL for a cover image (served via /api/cover from PUBLIC_MEDIA_DIR). */
    public static function coverUrl(?string $thumbnailPath): ?string
    {
        if ($thumbnailPath === null || $thumbnailPath === '') {
            return null;
        }
        return '/api/cover?f=' . rawurlencode($thumbnailPath);
    }

    /** Shape a product row for public consumption. */
    public static function publicShape(array $p, ?int $userId = null): array
    {
        $price = (int) $p['price_paise'];
        $mrp = (int) $p['mrp_paise'];
        $discount = ($mrp > $price && $mrp > 0) ? (int) round((($mrp - $price) / $mrp) * 100) : 0;
        $owned = false;
        if ($userId !== null) {
            $owned = Db::scalar(
                "SELECT COUNT(*) FROM pdf_access WHERE user_id = ? AND product_id = ? AND status = 'active'
                   AND (expires_at IS NULL OR expires_at > NOW())",
                [$userId, (int) $p['id']]
            ) > 0;
        }
        return [
            'id'            => (int) $p['id'],
            'slug'          => (string) $p['slug'],
            'title'         => (string) $p['title'],
            'subtitle'      => (string) ($p['subtitle'] ?? ''),
            'description'   => (string) ($p['description'] ?? ''),
            'category'      => (string) $p['category'],
            'language'      => (string) ($p['language'] ?? 'English'),
            'price_paise'   => $price,
            'mrp_paise'     => $mrp,
            'currency'      => (string) $p['currency'],
            'discount_pct'  => $discount,
            'thumbnail_url' => self::coverUrl($p['thumbnail_path'] ?? null),
            'page_count'    => (int) $p['page_count'],
            'file_size_bytes' => (int) $p['file_size_bytes'],
            'is_vip'        => (bool) $p['is_vip'],
            'owned'         => $owned,
        ];
    }

    /** List published products with optional search/category filter. */
    public static function listPublished(?string $search = null, ?string $category = null, int $limit = 60, int $offset = 0, ?int $userId = null): array
    {
        $where = ['is_published = 1'];
        $params = [];
        if ($search !== null && $search !== '') {
            $where[] = '(title LIKE ? OR subtitle LIKE ? OR category LIKE ? OR description LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($category !== null && $category !== '' && strtolower($category) !== 'all') {
            $where[] = 'category = ?';
            $params[] = $category;
        }
        $sql = 'SELECT * FROM products WHERE ' . implode(' AND ', $where) .
               ' ORDER BY id DESC LIMIT ' . max(1, min(120, $limit)) . ' OFFSET ' . max(0, $offset);
        $rows = Db::all($sql, $params);
        return array_map(fn($p) => self::publicShape($p, $userId), $rows);
    }

    public static function categories(): array
    {
        return array_map(
            fn($r) => (string) $r['category'],
            Db::all('SELECT DISTINCT category FROM products WHERE is_published = 1 ORDER BY category')
        );
    }

    public static function findPublishedBySlug(string $slug, ?int $userId = null): ?array
    {
        $p = Db::one('SELECT * FROM products WHERE slug = ? AND is_published = 1', [$slug]);
        return $p === null ? null : self::publicShape($p, $userId);
    }

    public static function findPublishedById(int $id, ?int $userId = null): ?array
    {
        $p = Db::one('SELECT * FROM products WHERE id = ? AND is_published = 1', [$id]);
        return $p === null ? null : self::publicShape($p, $userId);
    }

    // ---- Admin helpers -----------------------------------------------------

    public static function create(array $d): int
    {
        Db::run(
            'INSERT INTO products
                (slug, title, subtitle, description, category, language, price_paise, mrp_paise, currency,
                 thumbnail_path, pdf_path, page_count, file_size_bytes, download_allowed, is_published, is_vip, access_duration_days)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $d['slug'], $d['title'], $d['subtitle'] ?? '', $d['description'] ?? '',
                $d['category'] ?? 'General', $d['language'] ?? 'English',
                (int) $d['price_paise'], (int) ($d['mrp_paise'] ?? $d['price_paise']), $d['currency'] ?? 'INR',
                $d['thumbnail_path'] ?? null, $d['pdf_path'],
                (int) ($d['page_count'] ?? 0), (int) ($d['file_size_bytes'] ?? 0),
                !empty($d['download_allowed']) ? 1 : 0, !empty($d['is_published']) ? 1 : 0,
                !empty($d['is_vip']) ? 1 : 0, (int) ($d['access_duration_days'] ?? 0),
            ]
        );
        return Db::insertId();
    }

    public static function update(int $id, array $d): void
    {
        $fields = [];
        $params = [];
        $map = [
            'slug', 'title', 'subtitle', 'description', 'category', 'language',
            'thumbnail_path', 'pdf_path', 'currency',
        ];
        foreach ($map as $f) {
            if (array_key_exists($f, $d)) {
                $fields[] = "$f = ?";
                $params[] = $d[$f];
            }
        }
        foreach (['price_paise', 'mrp_paise', 'page_count', 'file_size_bytes', 'access_duration_days'] as $f) {
            if (array_key_exists($f, $d)) {
                $fields[] = "$f = ?";
                $params[] = (int) $d[$f];
            }
        }
        foreach (['download_allowed', 'is_published', 'is_vip'] as $f) {
            if (array_key_exists($f, $d)) {
                $fields[] = "$f = ?";
                $params[] = !empty($d[$f]) ? 1 : 0;
            }
        }
        if (empty($fields)) {
            return;
        }
        $params[] = $id;
        Db::run('UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    public static function delete(int $id): void
    {
        // Unpublish rather than hard delete to preserve order history integrity.
        Db::run('UPDATE products SET is_published = 0 WHERE id = ?', [$id]);
    }
}
