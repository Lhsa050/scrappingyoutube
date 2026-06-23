<?php

declare(strict_types=1);

session_start();

$root = __DIR__;
$envPath = $root . '/.env';
$lockPath = $root . '/storage/installed.lock';
$schemaPath = $root . '/database/schema_mysql.sql';
$errors = [];
$success = false;

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

if (is_file($lockPath)) {
    render_installer([
        'installed' => true,
        'success' => false,
        'errors' => [],
        'values' => defaults(),
        'cron' => cron_commands($root),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Sessao expirada. Recarregue o instalador e tente novamente.';
    }

    $values = values_from_post();
    $errors = array_merge($errors, validate_install_values($values, $envPath));

    if ($errors === []) {
        try {
            ensure_install_folders($root);
            migrate_mysql($values, $schemaPath);
            write_env_file($envPath, $values);
            file_put_contents($lockPath, 'installed_at=' . date(DATE_ATOM) . PHP_EOL, LOCK_EX);
            @chmod($envPath, 0600);
            $success = true;
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
} else {
    $values = defaults();
}

render_installer([
    'installed' => false,
    'success' => $success,
    'errors' => $errors,
    'values' => $values,
    'cron' => cron_commands($root),
]);

function defaults(): array
{
    $appUrl = detect_app_url();

    return [
        'app_name' => 'Creator Outreach CRM',
        'app_url' => $appUrl,
        'app_timezone' => 'America/Sao_Paulo',
        'admin_email' => 'admin@' . (parse_url($appUrl, PHP_URL_HOST) ?: 'seudominio.com'),
        'admin_password' => '',
        'admin_password_confirm' => '',
        'db_host' => 'localhost',
        'db_port' => '3306',
        'db_database' => '',
        'db_username' => '',
        'db_password' => '',
        'youtube_api_key' => '',
        'overwrite_env' => '',
    ];
}

function values_from_post(): array
{
    $values = defaults();
    foreach ($values as $key => $_) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    return $values;
}

function validate_install_values(array $values, string $envPath): array
{
    $errors = [];
    $required = [
        'app_name' => 'Nome do sistema',
        'app_url' => 'URL do sistema',
        'admin_email' => 'E-mail admin',
        'admin_password' => 'Senha admin',
        'db_host' => 'Host MySQL',
        'db_database' => 'Banco MySQL',
        'db_username' => 'Usuario MySQL',
        'youtube_api_key' => 'YouTube API Key',
    ];

    foreach ($required as $key => $label) {
        if (($values[$key] ?? '') === '') {
            $errors[] = "Preencha: {$label}.";
        }
    }

    if (!filter_var($values['app_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Informe uma URL valida em URL do sistema.';
    }

    if (!filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Informe um e-mail admin valido.';
    }

    if (strlen($values['admin_password']) < 8) {
        $errors[] = 'A senha admin precisa ter pelo menos 8 caracteres.';
    }

    if ($values['admin_password'] !== $values['admin_password_confirm']) {
        $errors[] = 'A confirmacao da senha admin nao confere.';
    }

    if ((int) $values['db_port'] <= 0) {
        $errors[] = 'Informe uma porta MySQL valida.';
    }

    if (is_file($envPath) && $values['overwrite_env'] !== '1') {
        $errors[] = 'Ja existe um arquivo .env. Marque a opcao de sobrescrever para continuar.';
    }

    return $errors;
}

function ensure_install_folders(string $root): void
{
    foreach ([$root . '/storage', $root . '/storage/logs'] as $folder) {
        if (!is_dir($folder) && !mkdir($folder, 0755, true)) {
            throw new RuntimeException('Nao foi possivel criar a pasta: ' . $folder);
        }
    }

    if (!is_writable($root)) {
        throw new RuntimeException('A pasta raiz nao esta gravavel. Ajuste permissoes para o instalador criar o .env.');
    }

    if (!is_writable($root . '/storage')) {
        throw new RuntimeException('A pasta storage nao esta gravavel.');
    }
}

function migrate_mysql(array $values, string $schemaPath): void
{
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('A extensao pdo_mysql nao esta habilitada no PHP.');
    }

    if (!is_file($schemaPath)) {
        throw new RuntimeException('Schema MySQL nao encontrado em database/schema_mysql.sql.');
    }

    $dsn = 'mysql:host=' . $values['db_host']
        . ';port=' . (int) $values['db_port']
        . ';dbname=' . $values['db_database']
        . ';charset=utf8mb4';

    $pdo = new PDO($dsn, $values['db_username'], $values['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $sql = (string) file_get_contents($schemaPath);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
}

function write_env_file(string $envPath, array $values): void
{
    $content = [
        'APP_NAME' => $values['app_name'],
        'APP_URL' => rtrim($values['app_url'], '/'),
        'APP_TIMEZONE' => $values['app_timezone'],
        'ADMIN_EMAIL' => $values['admin_email'],
        'ADMIN_PASSWORD_HASH' => password_hash($values['admin_password'], PASSWORD_DEFAULT),
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $values['db_host'],
        'DB_PORT' => (string) (int) $values['db_port'],
        'DB_DATABASE' => $values['db_database'],
        'DB_USERNAME' => $values['db_username'],
        'DB_PASSWORD' => $values['db_password'],
        'YOUTUBE_API_KEY' => $values['youtube_api_key'],
    ];

    $lines = [];
    foreach ($content as $key => $value) {
        $lines[] = $key . '=' . env_quote((string) $value);
    }

    if (file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Nao foi possivel gravar o arquivo .env.');
    }
}

function env_quote(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function detect_app_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'seudominio.com';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
    $dir = rtrim(str_replace('/public', '', dirname($script)), '/');

    return $scheme . '://' . $host . ($dir === '' || $dir === '.' ? '' : $dir);
}

function csrf_token(): string
{
    if (empty($_SESSION['installer_csrf'])) {
        $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['installer_csrf'];
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cron_commands(string $root): array
{
    $php = '/usr/bin/php';
    $scrape = realpath($root . '/workers/run_scrape.php') ?: $root . '/workers/run_scrape.php';
    $send = realpath($root . '/workers/send_queue.php') ?: $root . '/workers/send_queue.php';

    return [
        'scrape' => $php . ' ' . $scrape,
        'send' => $php . ' ' . $send,
    ];
}

function render_installer(array $state): void
{
    $values = $state['values'];
    $errors = $state['errors'];
    $cron = $state['cron'];

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Instalador - Creator Outreach CRM</title>';
    echo '<style>';
    echo ':root{--bg:#f6f7f4;--surface:#fff;--text:#20231f;--muted:#677068;--line:#dfe4dc;--accent:#246b4b;--danger:#a53a3a;--warn:#8a5a18}';
    echo '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;letter-spacing:0}.wrap{width:min(1080px,100%);margin:0 auto;padding:30px 18px 48px}.hero{display:flex;justify-content:space-between;gap:20px;align-items:end;margin-bottom:20px}h1{margin:0;font-size:30px;line-height:1.1}p{color:var(--muted);margin:6px 0 0}.panel{background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:20px;margin-bottom:16px;box-shadow:0 12px 30px rgba(28,38,30,.08)}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.wide{grid-column:1/-1}label{display:grid;gap:7px;color:var(--muted);font-size:13px;font-weight:700}input,select{min-height:42px;border:1px solid var(--line);border-radius:7px;background:#fbfcfa;color:var(--text);font:inherit;font-size:14px;padding:10px 11px}button,.button{min-height:44px;border:0;border-radius:8px;background:var(--accent);color:#fff;display:inline-flex;align-items:center;justify-content:center;padding:0 18px;font:inherit;font-weight:800;text-decoration:none;cursor:pointer}.actions{display:flex;gap:12px;justify-content:flex-end;align-items:center}.alert{border-radius:8px;padding:12px 14px;margin-bottom:14px}.alert.error{background:#f6e7e7;color:var(--danger)}.alert.success{background:#e7f4e7;color:#1f5c35}.alert.warn{background:#f6edda;color:var(--warn)}code{display:block;white-space:pre-wrap;background:#19241d;color:#eef4ec;border-radius:8px;padding:12px;margin-top:8px;overflow:auto}.check{display:flex;gap:10px;align-items:center;color:var(--text);font-weight:650}.check input{width:auto;min-height:auto}.steps{display:grid;gap:10px;margin:0;padding:0;list-style:none}.steps li{border-bottom:1px solid var(--line);padding:10px 0}.steps li:last-child{border-bottom:0}@media(max-width:760px){.grid,.hero{grid-template-columns:1fr;display:grid}.actions{justify-content:stretch;flex-direction:column}.actions>*{width:100%}}';
    echo '</style></head><body><main class="wrap">';
    echo '<section class="hero"><div><h1>Instalador</h1><p>Configure banco, senha e YouTube API em poucos minutos.</p></div><a class="button" href="index.php">Abrir painel</a></section>';

    if ($state['installed']) {
        echo '<section class="panel"><div class="alert success">O sistema ja esta instalado.</div>';
        echo '<p>Para reinstalar, remova manualmente <strong>storage/installed.lock</strong> e, se quiser refazer a configuracao, remova tambem <strong>.env</strong>.</p></section>';
        render_cron_panel($cron);
        echo '</main></body></html>';
        return;
    }

    if ($state['success']) {
        echo '<section class="panel"><div class="alert success">Instalacao concluida. O .env foi criado e as tabelas foram preparadas no MySQL.</div>';
        echo '<ul class="steps"><li>Acesse o painel em <a href="index.php">index.php</a>.</li><li>Entre em Configuracoes e salve o SMTP.</li><li>Cadastre os cron jobs abaixo no hPanel.</li><li>Depois de confirmar que tudo abriu, voce pode apagar o arquivo install.php para uma camada extra de seguranca.</li></ul></section>';
        render_cron_panel($cron);
        echo '</main></body></html>';
        return;
    }

    foreach ($errors as $error) {
        echo '<div class="alert error">' . h($error) . '</div>';
    }

    if (is_file(__DIR__ . '/.env')) {
        echo '<div class="alert warn">Existe um arquivo .env neste servidor. O instalador so vai sobrescrever se voce marcar a confirmacao no formulario.</div>';
    }

    echo '<form method="post" class="panel">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
    echo '<div class="grid">';
    field('Nome do sistema', 'app_name', $values);
    field('URL do sistema', 'app_url', $values);
    field('Timezone', 'app_timezone', $values);
    field('E-mail admin', 'admin_email', $values, 'email');
    field('Senha admin', 'admin_password', $values, 'password');
    field('Confirmar senha admin', 'admin_password_confirm', $values, 'password');
    echo '</div>';

    echo '<h2>Banco MySQL</h2><div class="grid">';
    field('Host MySQL', 'db_host', $values);
    field('Porta MySQL', 'db_port', $values, 'number');
    field('Nome do banco', 'db_database', $values);
    field('Usuario do banco', 'db_username', $values);
    field('Senha do banco', 'db_password', $values, 'password');
    echo '</div>';

    echo '<h2>YouTube</h2><div class="grid">';
    field('YouTube API Key', 'youtube_api_key', $values, 'password', 'wide');
    echo '</div>';

    if (is_file(__DIR__ . '/.env')) {
        echo '<label class="check wide"><input type="checkbox" name="overwrite_env" value="1"> Sobrescrever o arquivo .env existente</label>';
    }

    echo '<div class="actions"><button type="submit">Instalar agora</button></div>';
    echo '</form>';
    echo '</main></body></html>';
}

function field(string $label, string $name, array $values, string $type = 'text', string $class = ''): void
{
    $value = str_contains($name, 'password') || $name === 'youtube_api_key' ? '' : (string) ($values[$name] ?? '');
    echo '<label class="' . h($class) . '">' . h($label) . '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '"></label>';
}

function render_cron_panel(array $cron): void
{
    echo '<section class="panel"><h2>Cron jobs para Hostinger</h2>';
    echo '<p>Cadastre estes comandos no hPanel em Advanced &gt; Cron Jobs.</p>';
    echo '<p>Busca no YouTube, a cada 10 minutos:</p><code>' . h($cron['scrape']) . '</code>';
    echo '<p>Envio de e-mails, a cada 5 ou 10 minutos:</p><code>' . h($cron['send']) . '</code>';
    echo '</section>';
}
