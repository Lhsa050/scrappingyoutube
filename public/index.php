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
        'status' => (string) ($_GET['status'] ?? ''),
    ]);
}

try {
    handle_post_actions($page, $leadRepo, $campaignRepo, $settingsRepo);
} catch (Throwable $exception) {
    flash($exception->getMessage(), 'error');
    redirect(current_path(['page' => $page]));
}

$categories = $leadRepo->categories();
app_header($page);

if ($page === 'jobs') {
    jobs_page($leadRepo, $categories);
} elseif ($page === 'leads') {
    leads_page($leadRepo, $categories);
} elseif ($page === 'lead') {
    lead_detail_page($leadRepo);
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
            'max_subscribers' => post_string('max_subscribers') === '' ? null : max(0, post_int('max_subscribers', 30000)),
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

    if ($page === 'leads' && $action === 'delete_lead') {
        $deleted = $leadRepo->deleteLead(post_int('lead_id'));
        flash($deleted ? 'Lead excluido.' : 'Lead nao encontrado.');
        redirect(local_return_path(post_string('return_to', '?page=leads'), '?page=leads'));
    }

    if ($page === 'leads' && $action === 'clear_leads') {
        $deleted = $leadRepo->deleteAllLeads();
        flash($deleted . ' lead(s) excluido(s).');
        redirect('?page=leads');
    }

    if ($page === 'lead' && $action === 'update_lead') {
        $leadId = post_int('lead_id');
        $leadRepo->updateLead($leadId, post_string('status', 'discovered'), post_string('notes'));
        flash('Lead atualizado.');
        redirect('?page=lead&id=' . $leadId);
    }

    if ($page === 'lead' && $action === 'suppress') {
        $leadId = post_int('lead_id');
        $email = post_string('email');
        $leadRepo->suppress($email, 'Bloqueio manual');
        $leadRepo->updateLead($leadId, 'ignored', post_string('notes'));
        flash('Lead bloqueado e marcado como ignorado.');
        redirect('?page=lead&id=' . $leadId);
    }

    if ($page === 'lead' && $action === 'delete_lead') {
        $deleted = $leadRepo->deleteLead(post_int('lead_id'));
        flash($deleted ? 'Lead excluido.' : 'Lead nao encontrado.');
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

    if ($page === 'updates' && $action === 'apply_update') {
        $updater = new UpdaterService($settingsRepo);
        $result = $updater->installLatest();
        $_SESSION['update_result'] = $result;
        unset($_SESSION['update_check']);
        flash($result['updated'] ? 'Atualizacao aplicada com sucesso.' : (string) $result['message']);
        redirect('?page=updates');
    }

    if ($page === 'updates' && in_array($action, ['save_update_settings', 'check_updates'], true)) {
        $current = $settingsRepo->all();
        $githubRepo = post_string('github_repo', 'Lhsa050/scrappingyoutube');
        $githubBranch = post_string('github_branch', 'main');
        $githubToken = post_string('github_token') !== '' ? post_string('github_token') : (string) ($current['github_token'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $githubRepo)) {
            throw new RuntimeException('Repositorio GitHub invalido. Use usuario/repositorio.');
        }

        $settingsRepo->save([
            'github_repo' => $githubRepo,
            'github_branch' => $githubBranch,
            'github_token' => $githubToken,
        ]);

        $updater = new UpdaterService($settingsRepo);

        if ($action === 'save_update_settings') {
            flash('Configuracoes de atualizacao salvas.');
            redirect('?page=updates');
        }

        if ($action === 'check_updates') {
            $_SESSION['update_check'] = $updater->check();
            unset($_SESSION['update_result']);
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
        $class = $active === $key || ($active === 'lead' && $key === 'leads') ? 'active' : '';
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
    $queueStats = $campaignRepo->queueStats();
    $jobs = $leadRepo->recentJobs(6);
    $campaigns = array_slice($campaignRepo->all(), 0, 6);
    $recentLeads = $leadRepo->leads(['active' => '1'], 6);

    echo '<section class="topbar"><div><p class="eyebrow">Operacao</p><h1>Visao geral</h1></div><p>' . h(date('d/m/Y H:i')) . '</p></section>';
    echo '<section class="metrics">';
    metric('Leads ativos', format_int((int) $stats['active_leads']), 'Total coletado: ' . format_int((int) $stats['leads']));
    metric('Qualificados', format_int((int) $stats['qualified_leads']), 'Novos 7 dias: ' . format_int((int) $stats['new_leads_7d']));
    metric('Videos salvos', format_int((int) $stats['videos']), 'Buscas abertas: ' . format_int((int) $stats['running_jobs']));
    metric('Fila pendente', format_int((int) $queueStats['queued']), 'Enviados: ' . format_int((int) $queueStats['sent']));
    metric('Falhas de envio', format_int((int) $queueStats['failed']), 'Bloqueados: ' . format_int((int) $stats['suppressed']));
    metric('Ignorados', format_int((int) $stats['ignored_leads']), 'Nao entram em campanha');
    echo '</section>';

    echo '<section class="grid two">';
    echo '<div class="panel"><div class="panel-head"><h2>Buscas recentes</h2><a href="?page=jobs">Ver buscas</a></div>';
    jobs_table($jobs, false);
    echo '</div>';
    echo '<div class="panel"><div class="panel-head"><h2>Campanhas</h2><a href="?page=campaigns">Ver campanhas</a></div>';
    campaigns_table($campaigns);
    echo '</div></section>';

    echo '<section class="panel"><div class="panel-head"><h2>Leads recentes</h2><a href="?page=leads">Ver leads</a></div>';
    leads_table($recentLeads, false);
    echo '</section>';
}

function metric(string $label, string $value, string $detail = ''): void
{
    echo '<div class="metric"><span>' . h($label) . '</span><strong>' . h($value) . '</strong>';
    if ($detail !== '') {
        echo '<small>' . h($detail) . '</small>';
    }
    echo '</div>';
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
    input('Inscritos maximos', 'max_subscribers', '30000', 'number');
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
        $maxSubscribers = empty($job['max_subscribers']) ? 'sem teto' : 'ate ' . format_int((int) $job['max_subscribers']) . ' inscritos';
        $maxViews = empty($job['max_views']) ? 'sem teto de views' : 'ate ' . format_int((int) $job['max_views']) . ' views';
        echo '<td><strong>' . h((string) $job['niche']) . '</strong><span>' . h((string) ($job['category_name'] ?? '')) . ' - ' . h($maxSubscribers) . ' - ' . h($maxViews) . '</span></td>';
        echo '<td>' . status_badge((string) $job['status']);
        if (!empty($job['error_message'])) {
            echo '<span class="error-line">' . h(truncate_text((string) $job['error_message'], 90)) . '</span>';
        }
        echo '</td>';
        echo '<td>' . h(format_int((int) $job['pages_processed'])) . '/' . h(format_int((int) $job['max_pages'])) . '</td>';
        echo '<td>' . h(format_int((int) $job['videos_checked'])) . '</td>';
        echo '<td>' . h(format_int((int) $job['emails_found'])) . '</td>';
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
        'status' => (string) ($_GET['status'] ?? ''),
    ];
    $leads = $repo->leads($filters);
    $stats = $repo->stats();

    echo '<section class="topbar"><div><p class="eyebrow">Controle de contatos</p><h1>Leads encontrados</h1></div><div class="top-actions">';
    echo '<a class="button" href="' . h(current_path(['export' => 'csv'])) . '">Exportar CSV</a>';
    echo '<form method="post" class="inline-form" onsubmit="return confirm(\'Excluir todos os leads?\')">' . csrf_field() . '<button type="submit" name="action" value="clear_leads" class="danger-button">Limpar leads</button></form>';
    echo '</div></section>';
    echo '<section class="metrics compact">';
    metric('Ativos', format_int((int) $stats['active_leads']));
    metric('Qualificados', format_int((int) $stats['qualified_leads']));
    metric('Ignorados', format_int((int) $stats['ignored_leads']));
    metric('Bloqueados', format_int((int) $stats['suppressed']));
    echo '</section>';
    echo '<section class="panel">';
    echo '<form method="get" class="filters"><input type="hidden" name="page" value="leads">';
    echo '<label>Categoria<select name="category_id"><option value="0">Todas</option>';
    foreach ($categories as $category) {
        $selected = (int) $filters['category_id'] === (int) $category['id'] ? 'selected' : '';
        echo '<option value="' . h((string) $category['id']) . '" ' . $selected . '>' . h((string) $category['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Busca<input name="q" value="' . h($filters['q']) . '" placeholder="email ou canal"></label>';
    echo '<label>Status<select name="status"><option value="">Todos</option>';
    foreach (lead_status_options() as $value => $label) {
        $selected = $filters['status'] === $value ? 'selected' : '';
        echo '<option value="' . h($value) . '" ' . $selected . '>' . h($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Ativos<select name="active"><option value="1" ' . ($filters['active'] === '1' ? 'selected' : '') . '>Somente ativos</option><option value="0" ' . ($filters['active'] === '0' ? 'selected' : '') . '>Todos</option></select></label>';
    echo '<button type="submit">Filtrar</button></form>';
    echo '</section>';

    echo '<section class="panel">';
    leads_table($leads, true);
    echo '</section>';
}

function leads_table(array $leads, bool $actions): void
{
    echo '<div class="table-wrap"><table class="leads-table"><thead><tr><th>E-mail</th><th>Controle</th><th>Categoria e canal</th><th>Origem</th><th>Contexto</th><th></th></tr></thead><tbody>';
    foreach ($leads as $lead) {
        $sourceUrl = (string) ($lead['latest_source_url'] ?? '');
        echo '<tr>';
        echo '<td><a class="lead-email" href="?page=lead&id=' . h((string) $lead['id']) . '">' . h((string) $lead['email']) . '</a><span>visto em ' . h(format_date((string) $lead['last_seen_at'])) . '</span></td>';
        echo '<td>' . status_badge((string) $lead['status']);
        if ((int) ($lead['suppressed'] ?? 0) > 0) {
            echo '<span class="status blocked">bloqueado</span>';
        }
        echo '</td>';
        echo '<td><strong>' . h((string) $lead['category_name']) . '</strong><span>' . h((string) $lead['channel_title']) . '</span><span>' . h(subscriber_label($lead)) . '</span></td>';
        echo '<td><strong>' . h(format_int((int) ($lead['source_count'] ?? 0))) . ' fonte(s)</strong>';
        echo '<span>' . h(truncate_text((string) ($lead['latest_video_title'] ?? ''), 80)) . '</span>';
        if ($sourceUrl !== '') {
            echo '<a href="' . h($sourceUrl) . '" target="_blank" rel="noopener">Abrir video</a>';
        }
        echo '</td>';
        echo '<td><span class="context-preview">' . h(truncate_text((string) ($lead['latest_context'] ?? ''), 150)) . '</span></td>';
        echo '<td class="actions-cell">';
        if ($actions) {
            echo '<a class="button secondary-link" href="?page=lead&id=' . h((string) $lead['id']) . '">Detalhes</a>';
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="suppress"><input type="hidden" name="email" value="' . h((string) $lead['email']) . '"><button class="secondary" type="submit">Bloquear</button></form>';
            echo '<form method="post" class="inline-form" onsubmit="return confirm(\'Excluir este lead?\')">' . csrf_field() . '<input type="hidden" name="action" value="delete_lead"><input type="hidden" name="lead_id" value="' . h((string) $lead['id']) . '"><input type="hidden" name="return_to" value="' . h(current_path()) . '"><button class="icon-delete" type="submit" title="Excluir lead">x</button></form>';
        }
        echo '</td>';
        echo '</tr>';
    }
    if ($leads === []) {
        echo '<tr><td colspan="6" class="empty">Nenhum lead encontrado com esses filtros.</td></tr>';
    }
    echo '</tbody></table></div>';
}

function lead_detail_page(LeadRepository $repo): void
{
    $id = (int) ($_GET['id'] ?? 0);
    $lead = $repo->findLead($id);

    if (!$lead) {
        echo '<section class="topbar"><div><p class="eyebrow">Lead</p><h1>Lead nao encontrado</h1></div><a class="button secondary-link" href="?page=leads">Voltar</a></section>';
        echo '<section class="panel"><p>Nao encontramos esse contato na base.</p></section>';
        return;
    }

    $sources = $repo->leadSources($id);

    echo '<section class="topbar"><div><p class="eyebrow">Lead</p><h1>' . h((string) $lead['email']) . '</h1></div><a class="button secondary-link" href="?page=leads">Voltar aos leads</a></section>';
    echo '<section class="metrics compact">';
    metric('Fontes encontradas', format_int((int) $lead['source_count']));
    metric('Entradas na fila', format_int((int) $lead['queue_total']));
    metric('E-mails enviados', format_int((int) $lead['sent_total']));
    metric('Falhas', format_int((int) $lead['failed_total']));
    echo '</section>';

    echo '<section class="grid two">';
    echo '<div class="panel profile-panel">';
    echo '<div class="panel-head"><h2>Contato</h2>' . status_badge((string) $lead['status']) . '</div>';
    echo '<dl class="detail-list">';
    echo '<dt>Categoria</dt><dd>' . h((string) $lead['category_name']) . '</dd>';
    echo '<dt>Canal</dt><dd>' . h((string) $lead['channel_title']) . '</dd>';
    echo '<dt>Inscritos</dt><dd>' . h(subscriber_label($lead)) . '</dd>';
    echo '<dt>Primeiro achado</dt><dd>' . h(format_date((string) $lead['first_seen_at'])) . '</dd>';
    echo '<dt>Ultimo achado</dt><dd>' . h(format_date((string) $lead['last_seen_at'])) . '</dd>';
    echo '<dt>Ultimo contato</dt><dd>' . h(format_date((string) ($lead['last_contacted_at'] ?? ''))) . '</dd>';
    echo '<dt>Bloqueio</dt><dd>' . ((int) $lead['suppressed'] > 0 ? '<span class="status blocked">bloqueado</span>' : '<span class="muted">nao bloqueado</span>') . '</dd>';
    echo '</dl>';
    echo '</div>';

    echo '<div class="panel">';
    echo '<div class="panel-head"><h2>Qualificacao</h2></div>';
    echo '<form method="post" class="stack-form">';
    echo csrf_field() . '<input type="hidden" name="action" value="update_lead"><input type="hidden" name="lead_id" value="' . h((string) $lead['id']) . '">';
    echo '<label>Status<select name="status">';
    foreach (lead_status_options() as $value => $label) {
        $selected = (string) $lead['status'] === $value ? 'selected' : '';
        echo '<option value="' . h($value) . '" ' . $selected . '>' . h($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Notas<textarea name="notes" rows="8" placeholder="Anote observacoes sobre esse contato, potencial de parceria ou restricoes.">' . h((string) ($lead['notes'] ?? '')) . '</textarea></label>';
    echo '<div class="form-actions split-actions"><button type="submit" class="secondary">Salvar lead</button></div>';
    echo '</form>';
    echo '<form method="post" class="danger-form">';
    echo csrf_field() . '<input type="hidden" name="action" value="suppress"><input type="hidden" name="lead_id" value="' . h((string) $lead['id']) . '"><input type="hidden" name="email" value="' . h((string) $lead['email']) . '"><input type="hidden" name="notes" value="' . h((string) ($lead['notes'] ?? '')) . '">';
    echo '<button type="submit" class="ghost-danger">Bloquear e ignorar</button>';
    echo '</form>';
    echo '<form method="post" class="danger-form" onsubmit="return confirm(\'Excluir este lead?\')">';
    echo csrf_field() . '<input type="hidden" name="action" value="delete_lead"><input type="hidden" name="lead_id" value="' . h((string) $lead['id']) . '">';
    echo '<button type="submit" class="ghost-danger">Excluir lead</button>';
    echo '</form>';
    echo '</div>';
    echo '</section>';

    echo '<section class="panel"><div class="panel-head"><h2>Fontes do e-mail</h2><span class="muted">' . h(format_int(count($sources))) . ' registro(s)</span></div>';
    echo '<div class="source-list">';
    foreach ($sources as $source) {
        $url = (string) ($source['youtube_url'] ?: $source['source_url']);
        echo '<article class="source-item">';
        echo '<div><a class="source-title" href="' . h($url) . '" target="_blank" rel="noopener">' . h((string) $source['video_title']) . '</a>';
        echo '<span>' . h((string) $source['channel_title']) . ' - ' . h(format_int((int) $source['view_count'])) . ' views - ' . h(format_date((string) ($source['published_at'] ?? ''))) . '</span></div>';
        echo '<pre class="context-block">' . h((string) ($source['found_context'] ?? '')) . '</pre>';
        echo '</article>';
    }
    if ($sources === []) {
        echo '<p class="empty">Nenhuma fonte registrada para esse lead.</p>';
    }
    echo '</div></section>';
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
    echo '<div class="table-wrap"><table><thead><tr><th>Campanha</th><th>Categoria</th><th>Status</th><th>Fila</th><th>Enviados</th><th>Falhas</th><th></th></tr></thead><tbody>';
    foreach ($campaigns as $campaign) {
        echo '<tr>';
        echo '<td><strong>' . h((string) $campaign['name']) . '</strong><span>' . h((string) $campaign['product_name']) . '</span></td>';
        echo '<td>' . h((string) $campaign['category_name']) . '</td>';
        echo '<td>' . status_badge((string) $campaign['status']) . '</td>';
        echo '<td>' . h(format_int((int) $campaign['queued_total'])) . '</td>';
        echo '<td>' . h(format_int((int) $campaign['sent_total'])) . '</td>';
        echo '<td>' . h(format_int((int) ($campaign['failed_total'] ?? 0))) . '</td>';
        echo '<td>';
        if ($actions) {
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="queue_campaign"><input type="hidden" name="campaign_id" value="' . h((string) $campaign['id']) . '"><button type="submit" class="secondary">Enfileirar</button></form>';
        }
        echo '</td></tr>';
    }
    if ($campaigns === []) {
        echo '<tr><td colspan="7" class="empty">Nenhuma campanha criada.</td></tr>';
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
    $result = $_SESSION['update_result'] ?? null;

    echo '<section class="topbar"><h1>Atualizacoes</h1><p>Busque e aplique novas versoes direto do GitHub.</p></section>';

    echo '<section class="metrics">';
    metric('Versao instalada', $updater->currentVersion());
    metric('Ultima verificada', is_array($check) ? (string) $check['latest_version'] : '-');
    metric('Status', is_array($check) && $check['available'] ? 'Atualizacao disponivel' : 'Sem atualizacao');
    metric('Origem', 'GitHub');
    echo '</section>';

    echo '<section class="panel">';
    echo '<form method="post" class="campaign-form">';
    echo csrf_field();
    settings_input('Repositorio GitHub', 'github_repo', (string) $settings['github_repo']);
    settings_input('Branch', 'github_branch', (string) $settings['github_branch']);
    settings_input('Token GitHub opcional', 'github_token', '', 'password');
    echo '<div class="form-actions"><button type="submit" name="action" value="save_update_settings" class="secondary">Salvar GitHub</button><button type="submit" name="action" value="check_updates">Buscar atualizacoes</button></div>';
    echo '</form>';
    echo '</section>';

    if (is_array($result)) {
        echo '<section class="panel">';
        echo '<div class="panel-head"><h2>Ultima atualizacao</h2>' . status_badge(!empty($result['updated']) ? 'completed' : 'pending') . '</div>';
        echo '<p>' . h((string) ($result['message'] ?? '')) . '</p>';
        if (!empty($result['version'])) {
            echo '<p>Versao: <strong>' . h((string) $result['version']) . '</strong></p>';
        }
        if (!empty($result['backup_path'])) {
            echo '<p>Backup criado em: <strong>' . h((string) $result['backup_path']) . '</strong></p>';
        }
        echo '</section>';
    }

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
            echo '<div class="update-steps">';
            echo '<p><strong>Atualizacao automatica:</strong> o sistema vai baixar o ZIP oficial do GitHub, criar backup local, preservar <strong>.env</strong> e <strong>storage</strong>, copiar os arquivos e rodar a migracao do banco.</p>';
            echo '<form method="post" class="form-actions split-actions">';
            echo csrf_field();
            echo '<button type="submit" name="action" value="apply_update">Atualizar agora</button>';
            echo '</form>';
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
    $placeholder = in_array($name, ['smtp_password', 'github_token'], true) ? 'Preencha somente se quiser alterar' : '';
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

/**
 * @return array<string, string>
 */
function lead_status_options(): array
{
    return [
        'discovered' => 'Descoberto',
        'qualified' => 'Qualificado',
        'ignored' => 'Ignorado',
    ];
}

function status_badge(string $status): string
{
    $status = strtolower(trim($status));
    $class = preg_replace('/[^a-z0-9_-]/', '', $status) ?: 'unknown';
    $labels = [
        'pending' => 'pendente',
        'running' => 'rodando',
        'completed' => 'concluido',
        'failed' => 'falhou',
        'draft' => 'rascunho',
        'queued' => 'na fila',
        'sent' => 'enviado',
        'discovered' => 'descoberto',
        'qualified' => 'qualificado',
        'ignored' => 'ignorado',
        'unsubscribed' => 'descadastrado',
        'blocked' => 'bloqueado',
    ];

    return '<span class="status ' . h($class) . '">' . h($labels[$status] ?? $status) . '</span>';
}

function format_int(int $value): string
{
    return number_format($value, 0, ',', '.');
}

function format_date(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d/m/Y H:i', $timestamp);
}

function subscriber_label(array $row): string
{
    if (!empty($row['subscribers_hidden'])) {
        return 'inscritos ocultos';
    }

    if (($row['subscriber_count'] ?? null) === null || (string) $row['subscriber_count'] === '') {
        return 'inscritos nao informados';
    }

    return format_int((int) $row['subscriber_count']) . ' inscritos';
}

function truncate_text(?string $value, int $limit = 120): string
{
    $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    if ($value === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value, 'UTF-8') > $limit
            ? mb_substr($value, 0, max(0, $limit - 3), 'UTF-8') . '...'
            : $value;
    }

    return strlen($value) > $limit ? substr($value, 0, max(0, $limit - 3)) . '...' : $value;
}

function local_return_path(string $path, string $fallback): string
{
    $path = trim($path);
    if ($path === '' || !str_starts_with($path, '?')) {
        return $fallback;
    }

    return str_contains($path, "\n") || str_contains($path, "\r") ? $fallback : $path;
}

function default_campaign_body(): string
{
    return "Oi, {{creator_name}}.\n\nVi seu conteudo sobre {{niche}} e achei que existe uma boa conexao com o publico que acompanha o seu canal.\n\nTenho uma proposta de parceria para divulgar {{product_name}}, com comissao de {{commission}} por venda aprovada. A ideia e simples: voce indica com seu link, acompanha os resultados e recebe por performance.\n\nSe fizer sentido, responda este e-mail que eu te envio os detalhes da oferta, materiais de divulgacao e as condicoes.\n\nObrigado,\nSua equipe\n\nSe preferir nao receber novos contatos, use este link: {{unsubscribe_url}}";
}
