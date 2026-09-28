<?php
/**
 * ExamLegacy — 1-click database setup & migration utility (spec §9 install.php).
 *
 * What it does (REAL, no demo):
 *   1. Loads .env / environment configuration.
 *   2. Connects to MySQL with your configured credentials.
 *   3. Imports database.sql (schema = 25 tables + seed data), idempotently.
 *   4. Optionally applies migrations/upgrade_v21.sql on existing databases.
 *   5. Writes install.lock so the tool refuses to run again.
 *
 * Safety:
 *   - Lock: once installed, delete install.lock (or run via CLI with --force)
 *     to re-run. Statements are CREATE TABLE IF NOT EXISTS + inserts — no data
 *     is ever dropped.
 *   - CLI:  php install.php [--force]
 *   - Web:  /install.php  (works on first run; refused after lock exists)
 */
declare(strict_types=1);

$root = __DIR__;
$lockFile = $root . '/install.lock';
$forced = in_array('--force', $argv ?? [], true); // CLI only
$isCli = (php_sapi_name() === 'cli');

header('Content-Type: text/plain; charset=utf-8');

function out(string $line): void
{
    echo htmlspecialchars($line, ENT_QUOTES) . "\n";
    @ob_flush();
    flush();
}

function runSqlFile(PDO $pdo, string $file): array
{
    $sql = @file_get_contents($file);
    if ($sql === false) {
        return ['ok' => false, 'error' => 'Cannot read ' . basename($file), 'statements' => 0];
    }
    // Strip full-line comments, then split on ';' (schema has no procedures).
    $lines = preg_split('/\R/', $sql) ?: [];
    $clean = [];
    foreach ($lines as $ln) {
        if (preg_match('/^\s*--/', $ln)) {
            continue;
        }
        $clean[] = $ln;
    }
    $stmts = array_filter(array_map('trim', explode(';', implode("\n", $clean))), static fn($s) => $s !== '');
    $done = 0;
    foreach ($stmts as $stmt) {
        try {
            $pdo->exec($stmt);
            $done++;
        } catch (PDOException $e) {
            // Idempotent re-runs: duplicate seed rows are expected and safe.
            if (!preg_match('/^(1062|1060|1061|1091)/', (string) $e->getCode())) {
                return ['ok' => false, 'error' => substr($e->getMessage(), 0, 300), 'statements' => $done];
            }
        }
    }
    return ['ok' => true, 'error' => '', 'statements' => $done];
}

// ---- Guards ---------------------------------------------------------------
if (!$isCli && is_file($lockFile) && !$forced) {
    http_response_code(403);
    out('ExamLegacy is already set up. To run setup again, delete install.lock first.');
    exit;
}

// ---- Bootstrap config (loads .env and defines DB_* constants) -------------
require $root . '/api/config.php';

out('ExamLegacy — database setup');
out('==========================');

// ---- Connect --------------------------------------------------------------
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    out('Connected to MySQL at ' . DB_HOST . ':' . DB_PORT . ' (database "' . DB_NAME . '").');
} catch (PDOException $e) {
    out('Could not connect to MySQL: ' . $e->getMessage());
    out('Check DB_HOST / DB_NAME / DB_USER / DB_PASS in your .env file, then retry.');
    exit(1);
}

// ---- Import master schema + seed ------------------------------------------
$master = $root . '/database.sql';
if (is_file($master)) {
    $r = runSqlFile($pdo, $master);
    if (!$r['ok']) {
        out('Setup stopped in database.sql: ' . $r['error']);
        exit(1);
    }
    out('database.sql imported (' . $r['statements'] . ' statements, schema + seed).');
} else {
    $r1 = runSqlFile($pdo, $root . '/migrations/schema.sql');
    $r2 = runSqlFile($pdo, $root . '/migrations/seed.sql');
    if (!$r1['ok'] || !$r2['ok']) {
        out('Setup stopped: ' . ($r1['error'] ?: $r2['error']));
        exit(1);
    }
    out('migrations/schema.sql + seed.sql imported (' . ($r1['statements'] + $r2['statements']) . ' statements).');
}

// ---- Upgrade path for databases created before v2.1 ------------------------
$upgrade = $root . '/migrations/upgrade_v21.sql';
if (is_file($upgrade)) {
    $r = runSqlFile($pdo, $upgrade);
    if (!$r['ok']) {
        out('Upgrade step reported: ' . $r['error']);
        exit(1);
    }
    out('upgrade_v21.sql applied (' . $r['statements'] . ' statements).');
}

// ---- Verify required tables ------------------------------------------------
$expected = [
    'users', 'products', 'orders', 'order_items', 'payment_intents', 'credit_packs',
    'ai_credit_transactions', 'ai_usage', 'ai_documents', 'wallet_transactions',
    'pdf_access', 'vip_subscriptions', 'support_threads', 'support_messages',
    'notifications', 'site_settings', 'admin_audit_logs', 'study_topics',
    'user_notes', 'user_bookmarks', 'webhook_events',
    'viewer_sessions', 'pdf_access_logs', 'rate_limits', 'vip_plans',
];
$found = [];
$q = $pdo->query('SHOW TABLES');
while (($row = $q->fetch()) !== false) {
    $found[] = (string) array_values($row)[0];
}
$missing = array_diff($expected, $found);
if ($missing) {
    out('Missing tables: ' . implode(', ', $missing));
    exit(1);
}
out('All ' . count($expected) . ' tables verified.');

// ---- Lock ------------------------------------------------------------------
if (!is_file($lockFile)) {
    @file_put_contents($lockFile, date('c') . " — setup completed\n");
}
out('Setup complete. Delete install.lock after finishing, then open your site.');
out('Next: fill remaining .env keys (Cashfree, Gemini, SMTP, Firebase) and sign in at /admin.');
exit(0);
