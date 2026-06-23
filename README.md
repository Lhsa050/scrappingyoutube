# Creator Outreach CRM

Sistema PHP + MySQL para descobrir criadores no YouTube por nicho, coletar apenas e-mails publicados na descricao dos videos, salvar leads por categoria e disparar campanhas por fila SMTP com link de descadastro.

## O que esta pronto

- Busca por palavra-chave/nicho usando a YouTube Data API v3 oficial.
- Filtros de views minimas e maximas, pais, idioma, ordenacao e data minima.
- Extracao de e-mails presentes em descricoes publicas dos videos.
- Banco com categorias, canais, videos, leads e origem do achado.
- Exportacao CSV.
- Campanhas com variaveis como `{{creator_name}}`, `{{niche}}`, `{{product_name}}`, `{{commission}}` e `{{unsubscribe_url}}`.
- Fila de envio SMTP com limite por execucao.
- Configuracao SMTP pelo painel, depois da instalacao.
- Link de descadastro e lista de bloqueio.
- Compatibilidade com MySQL em producao e SQLite para testes.

## Requisitos

- PHP 8.1+ com PDO, PDO MySQL, OpenSSL e cURL habilitados.
- MySQL na Hostinger.
- Chave da YouTube Data API v3.
- Conta SMTP, preferencialmente no dominio que sera usado para contato.

Referencias oficiais uteis:

- YouTube Data API `search.list`: https://developers.google.com/youtube/v3/docs/search/list
- YouTube Data API `videos.list`: https://developers.google.com/youtube/v3/docs/videos/list
- Calculadora de quota da YouTube Data API: https://developers.google.com/youtube/v3/determine_quota_cost
- MySQL na Hostinger: https://www.hostinger.com/support/mysql-databases/
- Cron Jobs na Hostinger: https://www.hostinger.com/support/hpanel/cron-jobs/
- SMTP Hostinger: https://www.hostinger.com/support/4305847-set-up-hostinger-email-on-your-applications-and-devices/

## Instalacao na Hostinger

1. Envie os arquivos para `public_html`.
2. Crie um banco MySQL no hPanel e guarde nome, usuario e senha.
3. Abra `https://seudominio.com/install.php`.
4. Preencha:
   - URL do sistema
   - e-mail e senha do admin
   - dados do MySQL
   - YouTube API Key
5. Clique em `Instalar agora`.

O instalador vai:

- testar a conexao MySQL;
- criar todas as tabelas;
- criar o arquivo `.env`;
- gerar o hash seguro da senha admin;
- criar `storage/installed.lock` para bloquear reinstalacao acidental;
- mostrar os comandos de cron prontos para copiar no hPanel.

Depois de instalar, entre no painel e abra `Configuracoes` para salvar SMTP, remetente, Reply-To e limite de emails por execucao do cron.

Se o sistema ja estiver instalado, o instalador mostra uma tela de bloqueio. Para reinstalar, remova manualmente `storage/installed.lock` e, se quiser refazer a configuracao, remova tambem `.env`.

### Instalacao manual opcional

Se preferir fazer manualmente, copie `.env.example` para `.env`, edite os dados e gere a senha do painel:

```bash
php -r "echo password_hash('sua-senha-forte', PASSWORD_DEFAULT);"
```

Depois rode:

```bash
php workers/migrate.php
```

## Cron Jobs

Crie dois cron jobs no hPanel, em Advanced > Cron Jobs.

Busca no YouTube, por exemplo a cada 10 minutos:

```bash
/usr/bin/php /home/SEU_USUARIO/domains/SEU_DOMINIO/public_html/workers/run_scrape.php
```

Envio de e-mails, por exemplo a cada 5 ou 10 minutos:

```bash
/usr/bin/php /home/SEU_USUARIO/domains/SEU_DOMINIO/public_html/workers/send_queue.php
```

Controle o volume no painel em `Configuracoes`. Comece baixo, como `10` ou `20`, e aumente somente depois de validar entregabilidade.

## Atualizacoes

Em hospedagem compartilhada, a forma mais estavel de atualizar e subir um pacote completo pelo Gerenciador de Arquivos da Hostinger. O painel apenas verifica a versao remota e mostra o pacote para baixar.

Fluxo:

1. Gere um pacote local com:

```powershell
.\tools\build-update.ps1 -Version "1.1.0" -BaseUrl "https://seudominio.com/updates" -Notes "Correcoes e melhorias"
```

2. Suba para a hospedagem, em uma pasta publica como `public_html/updates`:
   - `build/creator-outreach-1.1.0.zip`
   - `build/manifest.json`
3. No painel, abra `Atualizacoes`.
4. Informe a URL do manifesto, por exemplo:

```text
https://seudominio.com/updates/manifest.json
```

5. Clique em `Buscar atualizacoes`.
6. Se houver nova versao, baixe o ZIP indicado.
7. No hPanel, abra o Gerenciador de Arquivos, entre em `public_html`, envie o ZIP e extraia por cima da instalacao.
8. Nao apague `.env` nem `storage`.
9. Depois abra `/repair.php`, digite a senha admin e clique em reparar para conferir arquivos, permissoes e banco.

O pacote:

- preserva `.env` e `storage`;
- inclui `repair.php` para recuperar o painel se algum arquivo ficar com permissao ruim;
- evita o PHP tentando sobrescrever arquivos dele mesmo enquanto esta rodando.

## Fluxo de uso

1. Entre no painel.
2. Crie uma busca em `Buscas`, por exemplo:
   - Nicho: `Dicas de financas`
   - Palavras-chave: `financas pessoais investimentos renda extra`
   - Views minimas: `10000`
   - Views maximas: `100000`
3. Rode manualmente pelo botao `Rodar` ou aguarde o cron.
4. Revise os leads em `Leads`.
5. Configure SMTP em `Configuracoes`.
6. Crie uma campanha em `Campanhas`.
7. Clique em `Enfileirar`.
8. O cron `send_queue.php` enviara aos poucos.

## Boas praticas de entrega

- Use um e-mail do seu dominio, com SPF, DKIM e DMARC configurados.
- Comece com baixo volume.
- Mantenha mensagem curta, clara e personalizada.
- Nao use assunto enganoso.
- Mantenha o link de descadastro.
- Bloqueie manualmente qualquer contato que pedir remocao.

## Estrutura

```text
public/              Painel web
src/                 Codigo da aplicacao
database/            Schemas MySQL e SQLite
workers/             Scripts de cron
storage/             Arquivos locais e logs
```

## Observacoes

Este sistema nao tenta acessar e-mails ocultos, pagina "sobre" protegida, CAPTCHA, perfis privados ou qualquer dado nao publicado na descricao do video. Ele usa a API oficial do YouTube e registra a URL do video onde o e-mail foi encontrado.
