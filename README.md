# Creator Outreach CRM

Sistema PHP + MySQL para descobrir criadores no YouTube por nicho, coletar apenas e-mails publicados na descricao dos videos, salvar leads por categoria e disparar campanhas por fila SMTP com link de descadastro.

## O que esta pronto

- Busca por palavra-chave/nicho usando a YouTube Data API v3 oficial.
- Filtros de videos/Shorts, views minimas e maximas, inscritos maximos do canal, pais, idioma, ordenacao e data minima.
- Extracao de e-mails presentes em descricoes publicas dos videos.
- Banco com categorias, canais, videos, leads e origem do achado.
- Controle de leads com status, notas, fontes por video, exclusao individual, limpeza geral, bloqueio manual e exportacao CSV.
- Dashboard operacional com leads ativos, qualificados, ignorados, fila, envios e falhas.
- Processamento automatico das buscas pelo painel e por cron, sem precisar clicar em `Rodar` a cada pagina.
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

## Atualizacoes automaticas pelo GitHub

A fonte oficial de atualizacoes e o repositorio GitHub:

```text
https://github.com/Lhsa050/scrappingyoutube
```

O painel consulta o arquivo `VERSION` no GitHub, compara com o `VERSION` instalado na hospedagem e aplica a atualizacao automaticamente.

Fluxo:

1. Atualize os arquivos no GitHub.
2. Atualize o arquivo `VERSION` no GitHub para uma versao maior.
3. No painel, abra `Atualizacoes`.
4. Configure:
   - Repositorio GitHub: `Lhsa050/scrappingyoutube`
   - Branch: `main`
   - Token GitHub: somente se o repositorio for privado
5. Clique em `Buscar atualizacoes`.
6. Se houver nova versao, clique em `Atualizar agora`.

O atualizador automatico vai:

- baixar o ZIP oficial da branch no GitHub;
- criar backup dos arquivos substituidos em `storage/backups`;
- preservar `.env`, `storage` e arquivos internos do Git;
- copiar os arquivos novos para a instalacao;
- rodar a migracao do banco;
- validar se o arquivo `VERSION` foi atualizado.

Se o repositorio for privado, crie um token GitHub com permissao de leitura de conteudo e salve no painel em `Atualizacoes`.

## Fluxo de uso

1. Entre no painel.
2. Crie uma busca em `Buscas`, por exemplo:
   - Nicho: `Dicas de financas`
   - Palavras-chave: uma por linha, separadas com Enter
     - `financas pessoais`
     - `investimentos para iniciantes`
     - `renda extra`
   - Tipo de conteudo: `Videos e Shorts`, `Somente videos` ou `Somente Shorts`
   - Views minimas: `10000`
   - Views maximas: `100000`
   - Inscritos maximos: `30000`
3. Depois de criar, a busca roda automaticamente pela tela. O cron tambem continua processando em lote se voce fechar o navegador.
4. Revise os leads em `Leads`, abra `Detalhes`, qualifique contatos bons e ignore ou bloqueie contatos que nao devem receber campanha.
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
