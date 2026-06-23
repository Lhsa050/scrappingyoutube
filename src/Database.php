<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;
    private static bool $migrated = false;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $connection = Config::dbConnection();
        $username = $connection === 'mysql' ? Config::get('DB_USERNAME', '') : null;
        $password = $connection === 'mysql' ? Config::get('DB_PASSWORD', '') : null;

        self::$pdo = new PDO(Config::dbDsn(), $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if ($connection === 'sqlite') {
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        }

        return self::$pdo;
    }

    public static function migrate(bool $force = false): void
    {
        if (self::$migrated && !$force) {
            return;
        }

        $schema = Config::dbConnection() === 'mysql'
            ? Config::root() . '/database/schema_mysql.sql'
            : Config::root() . '/database/schema_sqlite.sql';

        if (!is_file($schema)) {
            throw new RuntimeException('Schema file not found: ' . $schema);
        }

        $sql = (string) file_get_contents($schema);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            self::pdo()->exec($statement);
        }

        self::ensureColumn('channels', 'subscriber_count', Config::dbConnection() === 'mysql' ? 'BIGINT UNSIGNED NULL' : 'INTEGER NULL');
        self::ensureColumn('channels', 'subscribers_hidden', Config::dbConnection() === 'mysql' ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('videos', 'duration_seconds', Config::dbConnection() === 'mysql' ? 'INT UNSIGNED NULL' : 'INTEGER NULL');
        self::ensureColumn('videos', 'video_type', Config::dbConnection() === 'mysql' ? 'VARCHAR(20) NOT NULL DEFAULT \'video\'' : 'TEXT NOT NULL DEFAULT \'video\'');
        self::ensureColumn('scrape_jobs', 'batch_id', Config::dbConnection() === 'mysql' ? 'VARCHAR(40) NULL' : 'TEXT NULL');
        self::ensureColumn('scrape_jobs', 'include_terms', Config::dbConnection() === 'mysql' ? 'TEXT NULL' : 'TEXT NULL');
        self::ensureColumn('scrape_jobs', 'exclude_terms', Config::dbConnection() === 'mysql' ? 'TEXT NULL' : 'TEXT NULL');
        self::ensureColumn('scrape_jobs', 'match_mode', Config::dbConnection() === 'mysql' ? 'VARCHAR(20) NOT NULL DEFAULT \'any\'' : 'TEXT NOT NULL DEFAULT \'any\'');
        self::ensureColumn('scrape_jobs', 'max_subscribers', Config::dbConnection() === 'mysql' ? 'BIGINT UNSIGNED NULL' : 'INTEGER NULL');
        self::ensureColumn('scrape_jobs', 'video_type', Config::dbConnection() === 'mysql' ? 'VARCHAR(20) NOT NULL DEFAULT \'both\'' : 'TEXT NOT NULL DEFAULT \'both\'');
        self::ensureColumn('scrape_jobs', 'next_page_token', Config::dbConnection() === 'mysql' ? 'VARCHAR(100) NULL' : 'TEXT NULL');
        self::ensureColumn('scrape_jobs', 'pages_processed', Config::dbConnection() === 'mysql' ? 'INT UNSIGNED NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('scrape_jobs', 'videos_checked', Config::dbConnection() === 'mysql' ? 'INT UNSIGNED NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('scrape_jobs', 'emails_found', Config::dbConnection() === 'mysql' ? 'INT UNSIGNED NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('scrape_jobs', 'error_message', Config::dbConnection() === 'mysql' ? 'TEXT NULL' : 'TEXT NULL');
        self::ensureColumn('scrape_jobs', 'started_at', Config::dbConnection() === 'mysql' ? 'DATETIME NULL' : 'TEXT NULL');
        self::ensureColumn('scrape_jobs', 'finished_at', Config::dbConnection() === 'mysql' ? 'DATETIME NULL' : 'TEXT NULL');
        self::ensureColumn('scrape_jobs', 'quota_retry_at', Config::dbConnection() === 'mysql' ? 'DATETIME NULL' : 'TEXT NULL');

        self::$migrated = true;
    }

    public static function now(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    private static function ensureColumn(string $table, string $column, string $definition): void
    {
        if (self::columnExists($table, $column)) {
            return;
        }

        self::pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private static function columnExists(string $table, string $column): bool
    {
        if (Config::dbConnection() === 'mysql') {
            $quotedColumn = self::pdo()->quote($column);
            $stmt = self::pdo()->query("SHOW COLUMNS FROM {$table} LIKE {$quotedColumn}");
            return $stmt->fetchColumn() !== false;
        }

        $stmt = self::pdo()->query("PRAGMA table_info({$table})");
        foreach ($stmt->fetchAll() as $row) {
            if (($row['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }
}
