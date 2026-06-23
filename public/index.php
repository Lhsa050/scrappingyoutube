<?php

declare(strict_types=1);

if (!is_file(dirname(__DIR__) . '/.env')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/../src/bootstrap.php';

session_start();
verify_csrf();

$pdo = Database::pdo();
$leadRepo = new LeadRepository($pdo);
$campaignRepo = new CampaignRepository($pdo);
$settingsRepo = new SettingsRepository($pdo);
$page = (string) ($_GET['page'] ?? 'dashboard');

if ($page === 'unsubscribe') {
    $ok = false;
    $token = (string) ($_GET['token'] ?? '');
    if ($token !== '') {
        $ok = $leadRepo->unsubscribeByToken($token);
    }
    public_header('Descadastro');
    echo '<main class="public-message">';
    echo '<h1>' . ($ok ? 'Descadastro confirmado' : 'Link invalido') . '</h1>';
    echo '<p>' . ($ok ? 'Esse contato foi removido da fila de envios.' : 'Nao encontramos esse token de descadastro.') . '</p>';
    echo '</main>';
    public_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_string('action') === 'login') {
    if (Config::adminPasswordIsValid(post_string('password'))) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['logged_in_at'] = time();
        redirect('?page=dashboard');
    }
    flash('Senha invalida ou ADMIN_PASSWORD_HASH nao configurado.', 'error');
    redirect('?page=login');
}

if ($page === 'logout') {
    session_destroy();
    redirect('?page=login');
}

if (empty($_SESSION['logged_in'])) {
    login_page();
    exit;
}

if ($page === 'leads' && ($_GET['export'] ?? '') === 'csv') {
    $leadRepo->exportLeadsCsv([
        'category_id' => (int) ($_GET['category_id'] ?? 0),
        'q' => (string) ($_GET['q'] ?? ''),
        'active' => (string) ($_GET['active'] ?? ''),
    ]);
}

try {
    handle_post_actions($page, $leadRepo, $campaignRepo, $settingsRepo);
} catch (Throwable $exception) {
    flash($exception->getMessage(), 'error');
    redirect('?page=' . rawurlencode($page));
}

$categories = $leadRepo->categories();
app_header($page);

if ($page === 'jobs') {
    jobs_page($leadRepo, $categories);
} elseif ($page === 'leads') {
    leads_page($leadRepo, $categories);
} elseif ($page === 'campaigns') {
    campaigns_page($campaignRepo, $categories);
} elseif ($page === 'settings') {
    settings_page($settingsRepo);
} elseif ($page === 'updates') {
    updates_page($settingsRepo);
} else {
    dashboard_page($leadRepo, $campaignRepo);
}

app_footer();

function handle_post_actions(
    string $page,
    LeadRepository $leadRepo,
    CampaignRepository $campaignRepo,
    SettingsRepository $settingsRepo
): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = post_string('action');

    if ($page === 'jobs' && $action === 'create_job') {
        if (post_string('niche') === '' || post_string('keywords') === '') {
            throw new RuntimeException('Informe nicho e palavras-chave para criar a busca.');
        }

        $categoryId = $leadRepo->categoryId(post_string('category', 'Geral'));
        $publishedAfter = post_string('published_after');
        $leadRepo->createScrapeJob([
            'niche' => post_string('niche'),
            'keywords' => post_string('keywords'),
            'category_id' => $categoryId,
            'min_views' => max(0, post_int('min_views', 0)),
            'max_views' => post_string('max_views') === '' ? null : max(0, post_int('max_views')),
            'max_pages' => min(20, max(1, post_int('max_pages', 1))),
            'region_code' => strtoupper(post_string('region_code', 'BR')),
            'relevance_language' => strtolower(post_string('relevance_language', 'pt')),
            'order_by' => post_string('order_by', 'relevance'),
            'published_after' => $publishedAfter === '' ? null : $publishedAfter . ' 00:00:00',
        ]);
        flash('Busca criada. O cron vai processar uma pagina por execucao.');
        redirect('?page=jobs');
    }

    if ($page === 'jobs' && $action === 'run_job') {
        $service = new ScrapeService(
            $leadRepo,
            new YouTubeClient((string) Config::get('YOUTUBE_API_KEY', '')),
            new EmailExtractor()
        );
        $result = $service->process(post_int('job_id'));
        flash('Busca processada: ' . $result['videos_checked'] . ' videos, ' . $result['emails_found'] . ' e-mails.');
        redirect('?page=jobs');
    }

    if ($page === 'leads' && $action === 'suppress') {
        $leadRepo->suppress(post_string('email'), 'Bloqueio manual');
        flash('E-mail adicionado a lista de bloqueio.');
        redirect('?page=leads');
    }

    if ($page === 'campaigns' && $action === 'create_campaign') {
        $campaignRepo->create([
            'name' => post_string('name'),
            'category_id' => post_int('category_id'),
            'product_name' => post_string('product_name'),
            'commission' => post_string('commission'),
            'subject' => post_string('subject'),
            'body_text' => post_string('body_text'),
        ]);
        flash('Campanha criada como rascunho.');
        redirect('?page=campaigns');
    }

    if ($page === 'campaigns' && $action === 'queue_campaign') {
        $mailErrors = $settingsRepo->validateMailSettings($settingsRepo->all());
        if ($mailErrors !== []) {
            throw new RuntimeException('Configure o SMTP antes de enfileirar uma campanha.');
        }

        $queued = $campaignRepo->queueCampaign(post_int('campaign_id'));
        flash($queued . ' contatos foram adicionados a fila.');
        redirect('?page=campaigns');
    }

    if ($page === 'settings' && in_array($action, ['save_mail_settings', 'test_mail_settings'], true)) {
        $current = $settingsRepo->all();
        $password = post_string('smtp_password') !== '' ? post_string('smtp_password') : (string) ($current['smtp_password'] ?? '');
        $values = [
            'smtp_host' => post_string('smtp_host'),
            'smtp_port' => (string) post_int('smtp_port', 465),
            'smtp_encryption' => post_string('smtp_encryption', 'ssl'),
            'smtp_username' => post_string('smtp_username'),
            'smtp_password' => $password,
            'mail_from_email' => post_string('mail_from_email'),
            'mail_from_name' => post_string('mail_from_name'),
            'mail_reply_to' => post_string('mail_reply_to'),
            'emails_per_run' => (string) max(1, post_int('emails_per_run', 20)),
        ];

        $errors = $settingsRepo->validateMailSettings($values);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $settingsRepo->save($values);

        if ($action === 'test_mail_settings') {
            $recipient = post_string('test_recipient', $values['mail_from_email']);
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail valido para o teste.');
            }

            (new Mailer())->send(
                $recipient,
                'Teste SMTP - Creator Outreach CRM',
                "Se voce recebeu este e-mail, seu SMTP esta configurado corretamente.\n\nEnviado pelo painel do Creator Outreach CRM.",
                Config::appUrl()
            );
            flash('Configuracoes salvas e e-mail de teste enviado.');
            redirect('?page=settings');
        }

        flash('Configuracoes de SMTP salvas.');
        redirect('?page=settings');
    }

    if ($page === 'updates' && in_array($action, ['save_update_settings', 'check_updates'], true)) {
        $manifestUrl = post_string('update_manifest_url');
        if ($manifestUrl !== '' && !filter_var($manifestUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Informe uma URL valida para o manifesto de atualizacao.');
        }
        $settingsRepo->save(['update_manifest_url' => $manifestUrl]);

        $updater = new UpdaterService($settingsRepo);

        if ($action === 'save_update_settings') {
            flash('Configuracoes de atualizacao salvas.');
            redirect('?page=updates');
        }

        if ($action === 'check_updates') {
            $_SESSION['update_check'] = $updater->check();
            flash($_SESSION['update_check']['available'] ? 'Atualizacao encontrada.' : 'Voce ja esta na versao mais recente.');
            redirect('?page=updates');
        }
    }
}

function app_header(string $active): void
{
    $flash = flash();
    $nav = [
        'dashboard' => 'Visao geral',
        'jobs' => 'Buscas',
        'leads' => 'Leads',
        'campaigns' => 'Campanhas',
        'settings' => 'Configuracoes',
        'updates' => 'Atualizacoes',
    ];

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h((string) Config::get('APP_NAME', 'Creator Outreach CRM')) . '</title>';
    echo '<link rel="stylesheet" href="assets/app.css"></head><body>';
    echo '<aside class="sidebar"><div class="brand"><span class="mark">CO</span><strong>' . h((string) Config::get('APP_NAME', 'Creator Outreach CRM')) . '</strong></div><nav>';
    foreach ($nav as $key => $label) {
        $class = $active === $key ? 'active' : '';
        echo '<a class="' . $class . '" href="?page=' . h($key) . '">' . h($label) . '</a>';
    }
    echo '</nav><a class="logout" href="?page=logout">Sair</a></aside>';
    echo '<main class="shell">';
    if ($flash) {
        echo '<div class="flash ' . h($flash['type']) . '">' . h($flash['message']) . '</div>';
    }
}

function app_footer(): void
{
    echo '</main></body></html>';
}

function public_header(string $title): void
{
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title><link rel="stylesheet" href="assets/app.css"></head><body class="public">';
}

function public_footer(): void
{
    echo '</body></html>';
}

function login_page(): void
{
    $flash = flash();
    public_header('Login');
    echo '<main class="login">';
    echo '<form method="post" action="?page=login" class="login-panel">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="login">';
    echo '<h1>Entrar</h1>';
    if ($flash) {
        echo '<div class="flash ' . h($flash['type']) . '">' . h($flash['message']) . '</div>';
    }
    echo '<label>Senha administrativa<input type="password" name="password" required autofocus></label>';
    echo '<button type="submit">Acessar painel</button>';
    echo '</form></main>';
    public_footer();
}

function dashboard_page(LeadRepository $leadRepo, CampaignRepository $campaignRepo): void
{
    $stats = $leadRepo->stats();
    $jobs = $leadRepo->recentJobs(6);
    $campaigns = array_slice($campaignRepo->all(), 0, 6);

    echo '<section class="topbar"><h1>Visao geral</h1><p>' . h(date('d/m/Y H:i')) . '</p></section>';
    echo '<section class="metrics">';
    metric('Leads totais', (string) $stats['leads']);
    metric('Leads ativos', (string) $stats['active_leads']);
    metric('Videos salvos', (string) $stats['videos']);
    metric('Na fila', (string) $stats['queued']);
    echo '</section>';

    echo '<section class="grid two">';
    echo '<div class="panel"><div class="panel-head"><h2>Buscas recentes</h2><a href="?page=jobs">Ver buscas</a></div>';
    jobs_table($jobs, false);
    echo '</div>';
    echo '<div class="panel"><div class="panel-head"><h2>Campanhas</h2><a href="?page=campaigns">Ver campanhas</a></div>';
    campaigns_table($campaigns);
    echo '</div></section>';
}

function metric(string $label, string $value): void
{
    echo '<div class="metric"><span>' . h($label) . '</span><strong>' . h($value) . '</strong></div>';
}

function jobs_page(LeadRepository $repo, array $categories): void
{
    echo '<section class="topbar"><h1>Buscas no YouTube</h1><p>API oficial, filtros por views e origem registrada.</p></section>';
    echo '<section class="panel">';
    echo '<form method="post" class="form-grid">';
    echo csrf_field() . '<input type="hidden" name="action" value="create_job">';
    input('Nicho', 'niche', 'Dicas de financas');
    input('Palavras-chave', 'keywords', 'financas pessoais investimentos renda extra');
    input('Categoria', 'category', 'Financas');
    input('Views minimas', 'min_views', '10000', 'number');
    input('Views maximas', 'max_views', '100000', 'number');
    input('Paginas', 'max_pages', '3', 'number');
    select_field('Ordenacao', 'order_by', ['relevance' => 'Relevancia', 'viewCount' => 'Mais vistos', 'date' => 'Recentes'], 'relevance');
    input('Pais', 'region_code', 'BR');
    input('Idioma', 'relevance_language', 'pt');
    input('Publicado apos', 'published_after', '', 'date');
    echo '<div class="form-actions"><button type="submit">Criar busca</button></div>';
    echo '</form></section>';

    echo '<section class="panel"><div class="panel-head"><h2>Historico</h2></div>';
    jobs_table($repo->recentJobs(30), true);
    echo '</section>';
}

function jobs_table(array $jobs, bool $actions): void
{
    echo '<div class="table-wrap"><table><thead><tr><th>ID</th><th>Nicho</th><th>Status</th><th>Paginas</th><th>Videos</th><th>E-mails</th><th></th></tr></thead><tbody>';
    foreach ($jobs as $job) {
        echo '<tr>';
        echo '<td>#' . h((string) $job['id']) . '</td>';
        echo '<td><strong>' . h((string) $job['niche']) . '</strong><span>' . h((string) ($job['category_name'] ?? '')) . '</span></td>';
        echo '<td><span class="status ' . h((string) $job['status']) . '">' . h((string) $job['status']) . '</span></td>';
        echo '<td>' . h((string) $job['pages_processed']) . '/' . h((string) $job['max_pages']) . '</td>';
        echo '<td>' . h((string) $job['videos_checked']) . '</td>';
        echo '<td>' . h((string) $job['emails_found']) . '</td>';
        echo '<td>';
        if ($actions && in_array($job['status'], ['pending', 'running'], true)) {
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="run_job"><input type="hidden" name="job_id" value="' . h((string) $job['id']) . '"><button type="submit" class="secondary">Rodar</button></form>';
        }
        echo '</td></tr>';
    }
    if ($jobs === []) {
        echo '<tr><td colspan="7" class="empty">Nenhuma busca criada.</td></tr>';
    }
    echo '</tbody></table></div>';
}

function leads_page(LeadRepository $repo, array $categories): void
{
    $filters = [
        'category_id' => (int) ($_GET['category_id'] ?? 0),
        'q' => (string) ($_GET['q'] ?? ''),
        'active' => (string) ($_GET['active'] ?? '1'),
    ];
    $leads = $repo->leads($filters);

    echo '<section class="topbar"><h1>Leads encontrados</h1><a class="button" href="' . h(current_path(['export' => 'csv'])) . '">Exportar CSV</a></section>';
    echo '<section class="panel">';
    echo '<form method="get" class="filters"><input type="hidden" name="page" value="leads">';
    echo '<label>Categoria<select name="category_id"><option value="0">Todas</option>';
    foreach ($categories as $category) {
        $selected = (int) $filters['category_id'] === (int) $category['id'] ? 'selected' : '';
        echo '<option value="' . h((string) $category['id']) . '" ' . $selected . '>' . h((string) $category['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Busca<input name="q" value="' . h($filters['q']) . '" placeholder="email ou canal"></label>';
    echo '<label>Ativos<select name="active"><option value="1" ' . ($filters['active'] === '1' ? 'selected' : '') . '>Sim</option><option value="0" ' . ($filters['active'] === '0' ? 'selected' : '') . '>Todos</option></select></label>';
    echo '<button type="submit">Filtrar</button></form>';
    echo '</section>';

    echo '<section class="panel"><div class="table-wrap"><table><thead><tr><th>E-mail</th><th>Categoria</th><th>Canal</th><th>Origem</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($leads as $lead) {
        echo '<tr>';
        echo '<td><strong>' . h((string) $lead['email']) . '</strong><span>visto em ' . h((string) $lead['last_seen_at']) . '</span></td>';
        echo '<td>' . h((string) $lead['category_name']) . '</td>';
        echo '<td>' . h((string) $lead['channel_title']) . '</td>';
        echo '<td><a href="' . h((string) $lead['latest_source_url']) . '" target="_blank" rel="noopener">Abrir video</a></td>';
        echo '<td><span class="status ' . h((string) $lead['status']) . '">' . h((string) $lead['status']) . '</span></td>';
        echo '<td><form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="suppress"><input type="hidden" name="email" value="' . h((string) $lead['email']) . '"><button class="secondary" type="submit">Bloquear</button></form></td>';
        echo '</tr>';
    }
    if ($leads === []) {
        echo '<tr><td colspan="6" class="empty">Nenhum lead encontrado com esses filtros.</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function campaigns_page(CampaignRepository $campaignRepo, array $categories): void
{
    echo '<section class="topbar"><h1>Campanhas</h1><p>Envio por fila SMTP com descadastro.</p></section>';
    if ($categories === []) {
        echo '<section class="panel"><p>Crie uma busca primeiro para gerar a primeira categoria.</p></section>';
        echo '<section class="panel"><div class="panel-head"><h2>Campanhas criadas</h2></div>';
        campaigns_table($campaignRepo->all(), true);
        echo '</section>';
        return;
    }

    echo '<section class="panel">';
    echo '<form method="post" class="campaign-form">';
    echo csrf_field() . '<input type="hidden" name="action" value="create_campaign">';
    input('Nome da campanha', 'name', 'Parceria afiliados - financas');
    echo '<label>Categoria<select name="category_id" required>';
    foreach ($categories as $category) {
        echo '<option value="' . h((string) $category['id']) . '">' . h((string) $category['name']) . ' (' . h((string) $category['leads_count']) . ')</option>';
    }
    echo '</select></label>';
    input('Produto', 'product_name', 'Meu Produto');
    input('Comissao', 'commission', '30% por venda');
    input('Assunto', 'subject', '{{creator_name}}, parceria para {{product_name}}');
    echo '<label class="wide">Corpo<textarea name="body_text" rows="12" required>' . h(default_campaign_body()) . '</textarea></label>';
    echo '<div class="form-actions"><button type="submit">Criar campanha</button></div>';
    echo '</form></section>';

    echo '<section class="panel"><div class="panel-head"><h2>Campanhas criadas</h2></div>';
    campaigns_table($campaignRepo->all(), true);
    echo '</section>';
}

function campaigns_table(array $campaigns, bool $actions = false): void
{
    echo '<div class="table-wrap"><table><thead><tr><th>Campanha</th><th>Categoria</th><th>Status</th><th>Fila</th><th>Enviados</th><th></th></tr></thead><tbody>';
    foreach ($campaigns as $campaign) {
        echo '<tr>';
        echo '<td><strong>' . h((string) $campaign['name']) . '</strong><span>' . h((string) $campaign['product_name']) . '</span></td>';
        echo '<td>' . h((string) $campaign['category_name']) . '</td>';
        echo '<td><span class="status ' . h((string) $campaign['status']) . '">' . h((string) $campaign['status']) . '</span></td>';
        echo '<td>' . h((string) $campaign['queued_total']) . '</td>';
        echo '<td>' . h((string) $campaign['sent_total']) . '</td>';
        echo '<td>';
        if ($actions) {
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="queue_campaign"><input type="hidden" name="campaign_id" value="' . h((string) $campaign['id']) . '"><button type="submit" class="secondary">Enfileirar</button></form>';
        }
        echo '</td></tr>';
    }
    if ($campaigns === []) {
        echo '<tr><td colspan="6" class="empty">Nenhuma campanha criada.</td></tr>';
    }
    echo '</tbody></table></div>';
}

function settings_page(SettingsRepository $settingsRepo): void
{
    $settings = $settingsRepo->all();
    $configured = trim((string) $settings['smtp_username']) !== ''
        && trim((string) $settings['smtp_password']) !== ''
        && trim((string) $settings['mail_from_email']) !== '';

    echo '<section class="topbar"><h1>Configuracoes</h1><p>SMTP, remetente e volume de envio.</p></section>';
    echo '<section class="panel">';
    echo '<div class="panel-head"><h2>SMTP</h2><span class="status ' . ($configured ? 'completed' : 'running') . '">' . ($configured ? 'configurado' : 'pendente') . '</span></div>';
    echo '<form method="post" class="campaign-form">';
    echo csrf_field();
    settings_input('Host SMTP', 'smtp_host', (string) $settings['smtp_host']);
    settings_input('Porta SMTP', 'smtp_port', (string) $settings['smtp_port'], 'number');
    echo '<label>Criptografia SMTP<select name="smtp_encryption">';
    foreach (['ssl' => 'SSL', 'tls' => 'TLS/STARTTLS', 'none' => 'Nenhuma'] as $value => $label) {
        echo '<option value="' . h($value) . '"' . ($settings['smtp_encryption'] === $value ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    echo '</select></label>';
    settings_input('Usuario SMTP', 'smtp_username', (string) $settings['smtp_username']);
    settings_input('Senha SMTP', 'smtp_password', '', 'password');
    settings_input('E-mail remetente', 'mail_from_email', (string) $settings['mail_from_email'], 'email');
    settings_input('Nome remetente', 'mail_from_name', (string) $settings['mail_from_name']);
    settings_input('Reply-To', 'mail_reply_to', (string) $settings['mail_reply_to'], 'email');
    settings_input('Emails por execucao do cron', 'emails_per_run', (string) $settings['emails_per_run'], 'number');
    settings_input('Enviar teste para', 'test_recipient', (string) ($settings['mail_from_email'] ?: Config::get('ADMIN_EMAIL', '')), 'email');
    echo '<div class="form-actions"><button type="submit" name="action" value="test_mail_settings" class="secondary">Salvar e testar</button><button type="submit" name="action" value="save_mail_settings">Salvar configuracoes</button></div>';
    echo '</form>';
    echo '</section>';
}

function updates_page(SettingsRepository $settingsRepo): void
{
    $updater = new UpdaterService($settingsRepo);
    $settings = $settingsRepo->all();
    $check = $_SESSION['update_check'] ?? null;

    echo '<section class="topbar"><h1>Atualizacoes</h1><p>Verifique versoes e baixe o pacote para subir pela Hostinger.</p></section>';

    echo '<section class="metrics">';
    metric('Versao instalada', $updater->currentVersion());
    metric('Ultima verificada', is_array($check) ? (string) $check['latest_version'] : '-');
    metric('Status', is_array($check) && $check['available'] ? 'Atualizacao disponivel' : 'Sem atualizacao');
    metric('Aplicacao', 'manual');
    echo '</section>';

    echo '<section class="panel">';
    echo '<form method="post" class="campaign-form">';
    echo csrf_field();
    settings_input('URL do manifesto', 'update_manifest_url', (string) $settings['update_manifest_url'], 'url');
    echo '<div class="form-actions"><button type="submit" name="action" value="save_update_settings" class="secondary">Salvar URL</button><button type="submit" name="action" value="check_updates">Buscar atualizacoes</button></div>';
    echo '</form>';
    echo '</section>';

    if (is_array($check)) {
        $manifest = $check['manifest'];
        echo '<section class="panel">';
        echo '<div class="panel-head"><h2>Resultado da busca</h2><span class="status ' . ($check['available'] ? 'running' : 'completed') . '">' . ($check['available'] ? 'disponivel' : 'atualizado') . '</span></div>';
        echo '<p>Versao atual: <strong>' . h((string) $check['current_version']) . '</strong></p>';
        echo '<p>Versao remota: <strong>' . h((string) $check['latest_version']) . '</strong></p>';
        if (!empty($manifest['released_at'])) {
            echo '<p>Publicada em: ' . h((string) $manifest['released_at']) . '</p>';
        }
        if (!empty($manifest['notes']) && is_array($manifest['notes'])) {
            echo '<ul class="notes">';
            foreach ($manifest['notes'] as $note) {
                echo '<li>' . h((string) $note) . '</li>';
            }
            echo '</ul>';
        }
        if ($check['available']) {
            $packageUrl = (string) ($manifest['package_url'] ?? '');
            echo '<div class="update-steps">';
            echo '<p><strong>Novo metodo seguro:</strong> baixe o pacote, suba no Gerenciador de Arquivos da Hostinger e extraia por cima da instalacao.</p>';
            if ($packageUrl !== '') {
                echo '<p><a class="button" href="' . h($packageUrl) . '" target="_blank" rel="noopener">Baixar pacote ZIP</a></p>';
            }
            echo '<ol>';
            echo '<li>Baixe o ZIP acima.</li>';
            echo '<li>No hPanel, abra o Gerenciador de Arquivos.</li>';
            echo '<li>Entre em <strong>public_html</strong>.</li>';
            echo '<li>Envie o ZIP e clique em <strong>Extrair</strong>.</li>';
            echo '<li>Se perguntar, escolha substituir arquivos existentes.</li>';
            echo '<li>Nao apague <strong>.env</strong> nem <strong>storage</strong>.</li>';
            echo '<li>Depois abra <strong>/repair.php</strong> uma vez para checar arquivos e banco.</li>';
            echo '</ol>';
            echo '</div>';
        }
        echo '</section>';
    }
}

function input(string $label, string $name, string $value = '', string $type = 'text'): void
{
    echo '<label>' . h($label) . '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '"></label>';
}

function settings_input(string $label, string $name, string $value = '', string $type = 'text'): void
{
    $placeholder = $name === 'smtp_password' ? 'Preencha somente se quiser alterar' : '';
    echo '<label>' . h($label) . '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '" placeholder="' . h($placeholder) . '"></label>';
}

function select_field(string $label, string $name, array $options, string $selected): void
{
    echo '<label>' . h($label) . '<select name="' . h($name) . '">';
    foreach ($options as $value => $text) {
        echo '<option value="' . h((string) $value) . '" ' . ($selected === (string) $value ? 'selected' : '') . '>' . h((string) $text) . '</option>';
    }
    echo '</select></label>';
}

function default_campaign_body(): string
{
    return "Oi, {{creator_name}}.\n\nVi seu conteudo sobre {{niche}} e achei que existe uma boa conexao com o publico que acompanha o seu canal.\n\nTenho uma proposta de parceria para divulgar {{product_name}}, com comissao de {{commission}} por venda aprovada. A ideia e simples: voce indica com seu link, acompanha os resultados e recebe por performance.\n\nSe fizer sentido, responda este e-mail que eu te envio os detalhes da oferta, materiais de divulgacao e as condicoes.\n\nObrigado,\nSua equipe\n\nSe preferir nao receber novos contatos, use este link: {{unsubscribe_url}}";
}
