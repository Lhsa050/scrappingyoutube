<?php

declare(strict_types=1);

session_start();

$root = __DIR__;
$envPath = $root . '/.env';
$messages = [];
$errors = [];
$authenticated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim((string) ($_POST['password'] ?? ''));
    if ($password !== '' && repair_admin_password_is_valid($envPath, $password)) {
        $_SESSION['repair_authenticated'] = true;
        $authenticated = true;
    } else {
        $errors[] = 'Senha administrativa invalida.';
    }
}

if (!empty($_SESSION['repair_authenticated'])) {
    $authenticated = true;
}

if ($authenticated && ($_POST['action'] ?? '') === 'repair') {
    try {
        repair_write_front_controller($root);
        $messages[] = 'index.php e .htaccess principais conferidos.';
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }

    try {
        repair_permissions($root);
        $messages[] = 'Permissoes basicas ajustadas quando permitido pelo servidor.';
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }

    try {
        repair_migrate($root);
        $messages[] = 'Banco conferido e migrations executadas.';
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

repair_render($authenticated, $messages, $errors, repair_checks($root));

function repair_admin_password_is_valid(string $envPath, string $password): bool
{
    if (!is_file($envPath)) {
        return false;
    }

    $env = repair_read_env($envPath);
    $hash = $env['ADMIN_PASSWORD_HASH'] ?? '';
    if ($hash === '') {
        return false;
    }

    return password_verify($password, $hash);
}

/**
 * @return array<string, string>
 */
function repair_read_env(string $envPath): array
{
    $values = [];
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $values[trim($key)] = stripcslashes($value);
    }

    return $values;
}

function repair_write_front_controller(string $root): void
{
    $index = <<<'PHP'
<?php

declare(strict_types=1);

require __DIR__ . '/public/index.php';
PHP;

    $htaccess = <<<'HTACCESS'
Options -Indexes

DirectoryIndex index.php
RewriteEngine On

<FilesMatch "^\.env">
  Require all denied
</FilesMatch>

RedirectMatch 404 ^/(src|database|workers|storage)(/.*)?$

RewriteRule ^assets/(.*)$ public/assets/$1 [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^$ public/index.php [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ public/index.php [L]
HTACCESS;

    if (file_put_contents($root . '/index.php', $index . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Nao foi possivel restaurar index.php.');
    }

    if (file_put_contents($root . '/.htaccess', $htaccess . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Nao foi possivel restaurar .htaccess.');
    }
}

function repair_permissions(string $root): void
{
    $paths = [
        'index.php',
        '.htaccess',
        'public/index.php',
        'public/install.php',
        'install.php',
        'repair.php',
        'VERSION',
    ];

    foreach ($paths as $path) {
        $full = $root . '/' . $path;
        if (is_file($full)) {
            @chmod($full, 0644);
        }
    }

    foreach (['public', 'src', 'database', 'workers', 'storage', 'storage/logs'] as $path) {
        $full = $root . '/' . $path;
        if (is_dir($full)) {
            @chmod($full, 0755);
        }
    }
}

function repair_migrate(string $root): void
{
    $bootstrap = $root . '/src/bootstrap.php';
    if (!is_file($bootstrap)) {
        throw new RuntimeException('src/bootstrap.php nao encontrado.');
    }

    require_once $bootstrap;
    Database::migrate(true);
}

/**
 * @return array<string, bool>
 */
function repair_checks(string $root): array
{
    $required = [
        '.env',
        'index.php',
        '.htaccess',
        'public/index.php',
        'src/bootstrap.php',
        'src/Config.php',
        'src/Database.php',
        'database/schema_mysql.sql',
        'VERSION',
    ];

    $checks = [];
    foreach ($required as $path) {
        $checks[$path] = is_file($root . '/' . $path);
    }

    return $checks;
}

/**
 * @param array<int, string> $messages
 * @param array<int, string> $errors
 * @param array<string, bool> $checks
 */
function repair_render(bool $authenticated, array $messages, array $errors, array $checks): void
{
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Reparo - Creator Outreach CRM</title>';
    echo '<style>body{margin:0;background:#f6f7f4;color:#20231f;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{width:min(920px,100%);margin:0 auto;padding:28px 18px}.panel{background:#fff;border:1px solid #dfe4dc;border-radius:8px;padding:20px;margin-bottom:16px;box-shadow:0 12px 30px rgba(28,38,30,.08)}h1{margin:0 0 8px}.muted{color:#697068}.ok{color:#246b4b}.bad{color:#a53a3a}.alert{border-radius:8px;padding:12px 14px;margin-bottom:12px}.success{background:#e7f4e7;color:#1f5c35}.error{background:#f6e7e7;color:#a53a3a}input{width:100%;min-height:42px;border:1px solid #dfe4dc;border-radius:7px;padding:10px;font:inherit}button{min-height:42px;border:0;border-radius:8px;background:#246b4b;color:#fff;font-weight:800;padding:0 16px;cursor:pointer}.grid{display:grid;gap:10px}.checks{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px}.check{padding:10px;border:1px solid #dfe4dc;border-radius:7px}</style>';
    echo '</head><body><main class="wrap">';
    echo '<section class="panel"><h1>Reparo do sistema</h1><p class="muted">Use esta tela quando o painel ficar em 403 ou apos subir um pacote manualmente.</p></section>';

    foreach ($messages as $message) {
        echo '<div class="alert success">' . repair_h($message) . '</div>';
    }
    foreach ($errors as $error) {
        echo '<div class="alert error">' . repair_h($error) . '</div>';
    }

    if (!$authenticated) {
        echo '<section class="panel"><form method="post" class="grid"><label>Senha admin<input type="password" name="password" required autofocus></label><button type="submit">Entrar no reparo</button></form></section>';
    } else {
        echo '<section class="panel"><form method="post"><input type="hidden" name="action" value="repair"><button type="submit">Reparar agora</button></form></section>';
    }

    echo '<section class="panel"><h2>Arquivos essenciais</h2><div class="checks">';
    foreach ($checks as $path => $exists) {
        echo '<div class="check ' . ($exists ? 'ok' : 'bad') . '">' . repair_h($exists ? 'OK ' : 'FALTA ') . repair_h($path) . '</div>';
    }
    echo '</div></section>';

    echo '<section class="panel"><p><a href="index.php">Tentar abrir o painel</a></p></section>';
    echo '</main></body></html>';
}

function repair_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
