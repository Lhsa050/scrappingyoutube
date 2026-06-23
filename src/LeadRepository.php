<?php

declare(strict_types=1);

final class LeadRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function categoryId(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'Geral';
        }

        $stmt = $this->pdo->prepare('SELECT id FROM categories WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $stmt = $this->pdo->prepare('INSERT INTO categories (name, created_at) VALUES (:name, :created_at)');
        $stmt->execute(['name' => $name, 'created_at' => Database::now()]);
        return Database::lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categories(): array
    {
        return $this->pdo->query(
            'SELECT c.*, COUNT(l.id) AS leads_count
             FROM categories c
             LEFT JOIN leads l ON l.category_id = c.id
             GROUP BY c.id, c.name, c.created_at
             ORDER BY c.name'
        )->fetchAll();
    }

    public function createScrapeJob(array $data): int
    {
        $now = Database::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO scrape_jobs
             (batch_id, niche, keywords, include_terms, exclude_terms, match_mode, category_id, min_views, max_views, max_subscribers, video_type, max_pages, region_code, relevance_language, order_by, published_after, status, created_at, updated_at)
             VALUES
             (:batch_id, :niche, :keywords, :include_terms, :exclude_terms, :match_mode, :category_id, :min_views, :max_views, :max_subscribers, :video_type, :max_pages, :region_code, :relevance_language, :order_by, :published_after, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            'batch_id' => $data['batch_id'] ?? null,
            'niche' => $data['niche'],
            'keywords' => $data['keywords'],
            'include_terms' => $data['include_terms'] ?? null,
            'exclude_terms' => $data['exclude_terms'] ?? null,
            'match_mode' => $data['match_mode'] ?? 'any',
            'category_id' => $data['category_id'],
            'min_views' => $data['min_views'],
            'max_views' => $data['max_views'],
            'max_subscribers' => $data['max_subscribers'],
            'video_type' => $data['video_type'] ?? 'both',
            'max_pages' => $data['max_pages'],
            'region_code' => $data['region_code'],
            'relevance_language' => $data['relevance_language'],
            'order_by' => $data['order_by'],
            'published_after' => $data['published_after'],
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Database::lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentJobs(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.*, c.name AS category_name
             FROM scrape_jobs j
             JOIN categories c ON c.id = j.category_id
             ORDER BY j.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function runnableJobsCount(?string $batchId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM scrape_jobs WHERE (status IN (\'pending\', \'running\') OR (status = \'quota_wait\' AND (quota_retry_at IS NULL OR quota_retry_at <= :now)))';
        $params = ['now' => Database::now()];
        if ($batchId !== null && $batchId !== '') {
            $sql .= ' AND batch_id = :batch_id';
            $params['batch_id'] = $batchId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function latestRunnableBatchId(): ?string
    {
        $stmt = $this->pdo->query(
            'SELECT batch_id FROM scrape_jobs
             WHERE status IN (\'pending\', \'running\', \'quota_wait\') AND batch_id IS NOT NULL AND batch_id <> \'\'
             ORDER BY id DESC
             LIMIT 1'
        );
        $batchId = $stmt->fetchColumn();
        return $batchId === false ? null : (string) $batchId;
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function jobProgressSummary(?string $batchId = null): array
    {
        $where = '';
        $params = [];
        if ($batchId !== null && $batchId !== '') {
            $where = 'WHERE batch_id = :batch_id';
            $params['batch_id'] = $batchId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS total_jobs,
                    COALESCE(SUM(max_pages), 0) AS total_pages,
                    COALESCE(SUM(CASE WHEN pages_processed > max_pages THEN max_pages ELSE pages_processed END), 0) AS processed_pages,
                    COALESCE(SUM(videos_checked), 0) AS videos_checked,
                    COALESCE(SUM(emails_found), 0) AS emails_found,
                    COALESCE(SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END), 0) AS pending_jobs,
                    COALESCE(SUM(CASE WHEN status = \'running\' THEN 1 ELSE 0 END), 0) AS running_jobs,
                    COALESCE(SUM(CASE WHEN status = \'quota_wait\' THEN 1 ELSE 0 END), 0) AS waiting_jobs,
                    COALESCE(SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END), 0) AS completed_jobs,
                    COALESCE(SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END), 0) AS failed_jobs
             FROM scrape_jobs ' . $where
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        $totalJobs = (int) ($row['total_jobs'] ?? 0);
        $totalPages = (int) ($row['total_pages'] ?? 0);
        $processedPages = (int) ($row['processed_pages'] ?? 0);
        $pendingJobs = (int) ($row['pending_jobs'] ?? 0);
        $runningJobs = (int) ($row['running_jobs'] ?? 0);
        $waitingJobs = (int) ($row['waiting_jobs'] ?? 0);
        $failedJobs = (int) ($row['failed_jobs'] ?? 0);
        $activeJobs = $pendingJobs + $runningJobs;
        $percent = $totalPages > 0 ? min(100, (int) round(($processedPages / $totalPages) * 100)) : ($totalJobs > 0 ? 100 : 0);
        $status = $totalJobs === 0 ? 'idle' : ($activeJobs > 0 ? 'running' : ($waitingJobs > 0 ? 'quota_wait' : ($failedJobs > 0 ? 'failed' : 'completed')));

        return [
            'status' => $status,
            'active' => $activeJobs > 0,
            'percent' => $percent,
            'total_jobs' => $totalJobs,
            'pending_jobs' => $pendingJobs,
            'running_jobs' => $runningJobs,
            'waiting_jobs' => $waitingJobs,
            'completed_jobs' => (int) ($row['completed_jobs'] ?? 0),
            'failed_jobs' => $failedJobs,
            'total_pages' => $totalPages,
            'processed_pages' => $processedPages,
            'videos_checked' => (int) ($row['videos_checked'] ?? 0),
            'emails_found' => (int) ($row['emails_found'] ?? 0),
        ];
    }

    public function nextRunnableJob(?int $id = null, ?string $batchId = null): ?array
    {
        if ($id !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM scrape_jobs WHERE id = :id AND status IN (\'pending\', \'running\', \'quota_wait\') LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $job = $stmt->fetch();
            return $job ?: null;
        }

        $sql = 'SELECT * FROM scrape_jobs WHERE (status IN (\'pending\', \'running\') OR (status = \'quota_wait\' AND (quota_retry_at IS NULL OR quota_retry_at <= :now)))';
        $params = ['now' => Database::now()];
        if ($batchId !== null && $batchId !== '') {
            $sql .= ' AND batch_id = :batch_id';
            $params['batch_id'] = $batchId;
        }
        $sql .= ' ORDER BY id ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $job = $stmt->fetch();
        return $job ?: null;
    }

    public function updateJob(int $id, array $data): void
    {
        $data['updated_at'] = Database::now();
        $sets = [];
        foreach ($data as $key => $_) {
            $sets[] = "{$key} = :{$key}";
        }

        $stmt = $this->pdo->prepare('UPDATE scrape_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $data['id'] = $id;
        $stmt->execute($data);
    }

    public function upsertChannel(array $snippet, array $channel = []): int
    {
        $now = Database::now();
        $youtubeChannelId = (string) ($snippet['channelId'] ?? '');
        $title = (string) ($snippet['channelTitle'] ?? 'Canal sem titulo');
        $thumbnail = $snippet['thumbnails']['default']['url'] ?? null;
        $statistics = $channel['statistics'] ?? [];
        $subscriberCount = array_key_exists('subscriberCount', $statistics) ? (int) $statistics['subscriberCount'] : null;
        $subscribersHidden = !empty($statistics['hiddenSubscriberCount']) ? 1 : 0;

        $stmt = $this->pdo->prepare('SELECT id FROM channels WHERE youtube_channel_id = :youtube_channel_id');
        $stmt->execute(['youtube_channel_id' => $youtubeChannelId]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $stmt = $this->pdo->prepare(
                'UPDATE channels
                 SET title = :title, thumbnail_url = :thumbnail_url, subscriber_count = :subscriber_count,
                     subscribers_hidden = :subscribers_hidden, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'title' => $title,
                'thumbnail_url' => $thumbnail,
                'subscriber_count' => $subscriberCount,
                'subscribers_hidden' => $subscribersHidden,
                'updated_at' => $now,
                'id' => $id,
            ]);
            return (int) $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO channels
             (youtube_channel_id, title, thumbnail_url, subscriber_count, subscribers_hidden, created_at, updated_at)
             VALUES
             (:youtube_channel_id, :title, :thumbnail_url, :subscriber_count, :subscribers_hidden, :created_at, :updated_at)'
        );
        $stmt->execute([
            'youtube_channel_id' => $youtubeChannelId,
            'title' => $title,
            'thumbnail_url' => $thumbnail,
            'subscriber_count' => $subscriberCount,
            'subscribers_hidden' => $subscribersHidden,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Database::lastInsertId();
    }

    public function upsertVideo(array $video, int $channelId): int
    {
        $now = Database::now();
        $snippet = $video['snippet'] ?? [];
        $statistics = $video['statistics'] ?? [];
        $videoId = (string) ($video['id'] ?? '');
        $publishedAt = isset($snippet['publishedAt'])
            ? (new DateTimeImmutable((string) $snippet['publishedAt']))->format('Y-m-d H:i:s')
            : null;

        $data = [
            'youtube_video_id' => $videoId,
            'channel_id' => $channelId,
            'title' => (string) ($snippet['title'] ?? 'Video sem titulo'),
            'description' => (string) ($snippet['description'] ?? ''),
            'view_count' => (int) ($statistics['viewCount'] ?? 0),
            'duration_seconds' => $video['_duration_seconds'] ?? null,
            'video_type' => (string) ($video['_video_type'] ?? 'video'),
            'published_at' => $publishedAt,
            'youtube_url' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId),
            'updated_at' => $now,
        ];

        $stmt = $this->pdo->prepare('SELECT id FROM videos WHERE youtube_video_id = :youtube_video_id');
        $stmt->execute(['youtube_video_id' => $videoId]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $stmt = $this->pdo->prepare(
                'UPDATE videos
                 SET channel_id = :channel_id, title = :title, description = :description, view_count = :view_count,
                     duration_seconds = :duration_seconds, video_type = :video_type,
                     published_at = :published_at, youtube_url = :youtube_url, updated_at = :updated_at
                 WHERE youtube_video_id = :youtube_video_id'
            );
            $stmt->execute($data);
            return (int) $id;
        }

        $data['created_at'] = $now;
        $stmt = $this->pdo->prepare(
            'INSERT INTO videos
             (youtube_video_id, channel_id, title, description, view_count, duration_seconds, video_type, published_at, youtube_url, created_at, updated_at)
             VALUES
             (:youtube_video_id, :channel_id, :title, :description, :view_count, :duration_seconds, :video_type, :published_at, :youtube_url, :created_at, :updated_at)'
        );
        $stmt->execute($data);

        return Database::lastInsertId();
    }

    public function upsertLead(string $email, int $categoryId, int $channelId): int
    {
        $email = normalize_email($email);
        $now = Database::now();

        $stmt = $this->pdo->prepare('SELECT id FROM leads WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $stmt = $this->pdo->prepare(
                'UPDATE leads
                 SET category_id = :category_id, channel_id = :channel_id, last_seen_at = :last_seen_at, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'category_id' => $categoryId,
                'channel_id' => $channelId,
                'last_seen_at' => $now,
                'updated_at' => $now,
                'id' => $id,
            ]);
            return (int) $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO leads
             (email, category_id, channel_id, status, unsubscribe_token, first_seen_at, last_seen_at, created_at, updated_at)
             VALUES
             (:email, :category_id, :channel_id, :status, :unsubscribe_token, :first_seen_at, :last_seen_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'email' => $email,
            'category_id' => $categoryId,
            'channel_id' => $channelId,
            'status' => 'discovered',
            'unsubscribe_token' => bin2hex(random_bytes(24)),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Database::lastInsertId();
    }

    public function attachLeadSource(int $leadId, int $videoId, string $sourceUrl, string $context): void
    {
        if (Config::dbConnection() === 'mysql') {
            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO lead_sources (lead_id, video_id, source_url, found_context, created_at)
                 VALUES (:lead_id, :video_id, :source_url, :found_context, :created_at)'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT OR IGNORE INTO lead_sources (lead_id, video_id, source_url, found_context, created_at)
                 VALUES (:lead_id, :video_id, :source_url, :found_context, :created_at)'
            );
        }

        $stmt->execute([
            'lead_id' => $leadId,
            'video_id' => $videoId,
            'source_url' => $sourceUrl,
            'found_context' => $context,
            'created_at' => Database::now(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        $lastSevenDays = (new DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');

        return [
            'leads' => (int) $this->pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn(),
            'active_leads' => (int) $this->pdo->query('SELECT COUNT(*) FROM leads WHERE unsubscribed_at IS NULL')->fetchColumn(),
            'qualified_leads' => (int) $this->pdo->query('SELECT COUNT(*) FROM leads WHERE status = \'qualified\' AND unsubscribed_at IS NULL')->fetchColumn(),
            'ignored_leads' => (int) $this->pdo->query('SELECT COUNT(*) FROM leads WHERE status = \'ignored\'')->fetchColumn(),
            'new_leads_7d' => $this->countSince('leads', 'first_seen_at', $lastSevenDays),
            'videos' => (int) $this->pdo->query('SELECT COUNT(*) FROM videos')->fetchColumn(),
            'queued' => (int) $this->pdo->query('SELECT COUNT(*) FROM email_queue WHERE status = \'queued\'')->fetchColumn(),
            'sent' => (int) $this->pdo->query('SELECT COUNT(*) FROM email_queue WHERE status = \'sent\'')->fetchColumn(),
            'failed' => (int) $this->pdo->query('SELECT COUNT(*) FROM email_queue WHERE status = \'failed\'')->fetchColumn(),
            'suppressed' => (int) $this->pdo->query('SELECT COUNT(*) FROM suppression_list')->fetchColumn(),
            'running_jobs' => (int) $this->pdo->query('SELECT COUNT(*) FROM scrape_jobs WHERE status IN (\'pending\', \'running\', \'quota_wait\')')->fetchColumn(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function leads(array $filters = [], int $limit = 80): array
    {
        [$where, $params] = $this->leadWhere($filters);
        $sql = 'SELECT l.*, c.name AS category_name, ch.title AS channel_title,
                       ch.subscriber_count, ch.subscribers_hidden,
                       (SELECT COUNT(*)
                        FROM lead_sources ls
                        WHERE ls.lead_id = l.id) AS source_count,
                       (SELECT v.title
                        FROM lead_sources ls
                        JOIN videos v ON v.id = ls.video_id
                        WHERE ls.lead_id = l.id
                        ORDER BY ls.id DESC
                        LIMIT 1) AS latest_video_title,
                       (SELECT ls.source_url
                        FROM lead_sources ls
                        WHERE ls.lead_id = l.id
                        ORDER BY ls.id DESC
                        LIMIT 1) AS latest_source_url,
                       (SELECT ls.found_context
                        FROM lead_sources ls
                        WHERE ls.lead_id = l.id
                        ORDER BY ls.id DESC
                        LIMIT 1) AS latest_context,
                       (SELECT COUNT(*)
                        FROM suppression_list s
                        WHERE s.email = l.email) AS suppressed
                FROM leads l
                JOIN categories c ON c.id = l.category_id
                JOIN channels ch ON ch.id = l.channel_id
                ' . $where . '
                ORDER BY l.id DESC
                LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findLead(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, c.name AS category_name, ch.title AS channel_title, ch.youtube_channel_id, ch.thumbnail_url,
                    ch.subscriber_count, ch.subscribers_hidden,
                    (SELECT COUNT(*) FROM lead_sources ls WHERE ls.lead_id = l.id) AS source_count,
                    (SELECT COUNT(*) FROM email_queue q WHERE q.lead_id = l.id) AS queue_total,
                    (SELECT COUNT(*) FROM email_queue q WHERE q.lead_id = l.id AND q.status = \'sent\') AS sent_total,
                    (SELECT COUNT(*) FROM email_queue q WHERE q.lead_id = l.id AND q.status = \'failed\') AS failed_total,
                    (SELECT COUNT(*) FROM suppression_list s WHERE s.email = l.email) AS suppressed
             FROM leads l
             JOIN categories c ON c.id = l.category_id
             JOIN channels ch ON ch.id = l.channel_id
             WHERE l.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $lead = $stmt->fetch();
        return $lead ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function leadSources(int $leadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ls.*, v.title AS video_title, v.view_count, v.published_at, v.youtube_url, ch.title AS channel_title
             FROM lead_sources ls
             JOIN videos v ON v.id = ls.video_id
             JOIN channels ch ON ch.id = v.channel_id
             WHERE ls.lead_id = :lead_id
             ORDER BY ls.id DESC'
        );
        $stmt->execute(['lead_id' => $leadId]);
        return $stmt->fetchAll();
    }

    public function updateLead(int $id, string $status, string $notes): void
    {
        $allowed = ['discovered', 'qualified', 'ignored'];
        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException('Status de lead invalido.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE leads SET status = :status, notes = :notes, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'notes' => trim($notes),
            'updated_at' => Database::now(),
            'id' => $id,
        ]);
    }

    public function deleteLead(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM leads WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function deleteAllLeads(): int
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn();
        if ($total === 0) {
            return 0;
        }

        $this->pdo->exec('DELETE FROM leads');
        return $total;
    }

    public function deleteSearchHistory(): int
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM scrape_jobs')->fetchColumn();
        if ($total === 0) {
            return 0;
        }

        $this->pdo->exec('DELETE FROM scrape_jobs');
        return $total;
    }

    /**
     * @return array<string, int>
     */
    public function deleteAllOperationalData(): array
    {
        $counts = [
            'leads' => (int) $this->pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn(),
            'videos' => (int) $this->pdo->query('SELECT COUNT(*) FROM videos')->fetchColumn(),
            'channels' => (int) $this->pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn(),
            'jobs' => (int) $this->pdo->query('SELECT COUNT(*) FROM scrape_jobs')->fetchColumn(),
            'campaigns' => (int) $this->pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn(),
            'queued_emails' => (int) $this->pdo->query('SELECT COUNT(*) FROM email_queue')->fetchColumn(),
            'suppressed' => (int) $this->pdo->query('SELECT COUNT(*) FROM suppression_list')->fetchColumn(),
        ];

        $this->pdo->beginTransaction();
        try {
            foreach ([
                'email_events',
                'email_queue',
                'campaigns',
                'lead_sources',
                'leads',
                'videos',
                'channels',
                'scrape_jobs',
                'suppression_list',
                'categories',
            ] as $table) {
                $this->pdo->exec('DELETE FROM ' . $table);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $counts;
    }

    public function exportLeadsCsv(array $filters = []): never
    {
        [$where, $params] = $this->leadWhere($filters);
        $stmt = $this->pdo->prepare(
            'SELECT l.email, c.name AS category, ch.title AS channel, ch.subscriber_count, ch.subscribers_hidden,
                    l.status, l.first_seen_at, l.last_seen_at, l.last_contacted_at, l.unsubscribed_at,
                    (SELECT COUNT(*) FROM lead_sources ls WHERE ls.lead_id = l.id) AS source_count,
                    (SELECT ls.source_url FROM lead_sources ls WHERE ls.lead_id = l.id ORDER BY ls.id DESC LIMIT 1) AS latest_source_url
             FROM leads l
             JOIN categories c ON c.id = l.category_id
             JOIN channels ch ON ch.id = l.channel_id
             ' . $where . '
             ORDER BY l.id DESC'
        );
        $stmt->execute($params);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="leads-youtube.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['email', 'categoria', 'canal', 'inscritos', 'inscritos_ocultos', 'status', 'primeiro_achado', 'ultimo_achado', 'ultimo_contato', 'descadastro', 'fontes', 'ultima_url']);
        while ($row = $stmt->fetch()) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    public function unsubscribeByToken(string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, email FROM leads WHERE unsubscribe_token = :token');
        $stmt->execute(['token' => $token]);
        $lead = $stmt->fetch();
        if (!$lead) {
            return false;
        }

        $now = Database::now();
        $stmt = $this->pdo->prepare(
            'UPDATE leads SET unsubscribed_at = :unsubscribed_at, status = :status, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'unsubscribed_at' => $now,
            'status' => 'unsubscribed',
            'updated_at' => $now,
            'id' => $lead['id'],
        ]);

        $this->suppress((string) $lead['email'], 'Descadastro pelo link');
        return true;
    }

    public function suppress(string $email, string $reason = 'Manual'): void
    {
        $email = normalize_email($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        if (Config::dbConnection() === 'mysql') {
            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO suppression_list (email, reason, created_at) VALUES (:email, :reason, :created_at)'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT OR IGNORE INTO suppression_list (email, reason, created_at) VALUES (:email, :reason, :created_at)'
            );
        }

        $stmt->execute([
            'email' => $email,
            'reason' => $reason,
            'created_at' => Database::now(),
        ]);
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function leadWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'l.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['q'])) {
            $where[] = '(l.email LIKE :q OR ch.title LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (!empty($filters['status'])) {
            $allowed = ['discovered', 'qualified', 'ignored', 'unsubscribed'];
            if (in_array((string) $filters['status'], $allowed, true)) {
                $where[] = 'l.status = :status';
                $params['status'] = (string) $filters['status'];
            }
        }

        if (($filters['active'] ?? '') === '1') {
            $where[] = 'l.unsubscribed_at IS NULL';
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    private function countSince(string $table, string $column, string $since): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} >= :since");
        $stmt->execute(['since' => $since]);
        return (int) $stmt->fetchColumn();
    }
}
