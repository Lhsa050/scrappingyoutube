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
}
