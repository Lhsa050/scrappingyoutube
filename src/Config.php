<?php

declare(strict_types=1);

final class Config
{
    private static array $values = [];

    public static function load(string $root): void
    {
        self::$values = [];
        $envPath = $root . DIRECTORY_SEPARATOR . '.env';

        if (is_file($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines ?: [] as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $trimmed, 2);
                $key = trim($key);
                $value = trim($value);
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false) {
            return (string) $env;
        }

        return self::$values[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function appUrl(): string
    {
        return rtrim((string) self::get('APP_URL', 'http://localhost'), '/');
    }

    public static function timezone(): string
    {
        return (string) self::get('APP_TIMEZONE', 'America/Sao_Paulo');
    }

    public static function dbConnection(): string
    {
        return strtolower((string) self::get('DB_CONNECTION', 'sqlite'));
    }

    public static function dbDsn(): string
    {
        if (self::dbConnection() === 'mysql') {
            $host = self::get('DB_HOST', 'localhost');
            $port = self::get('DB_PORT', '3306');
            $database = self::get('DB_DATABASE', '');
            return "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        }

        $path = self::root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app.sqlite';
        return 'sqlite:' . $path;
    }

    public static function adminPasswordIsValid(string $password): bool
    {
        $hash = self::get('ADMIN_PASSWORD_HASH', '');
        if ($hash !== null && $hash !== '') {
            return password_verify($password, $hash);
        }

        $plain = self::get('ADMIN_PASSWORD', '');
        return $plain !== '' && hash_equals($plain, $password);
    }
}
