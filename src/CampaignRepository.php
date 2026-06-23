<?php

declare(strict_types=1);

final class CampaignRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(array $data): int
    {
        $now = Database::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO campaigns
             (name, category_id, product_name, commission, subject, body_text, status, created_at, updated_at)
             VALUES
             (:name, :category_id, :product_name, :commission, :subject, :body_text, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'category_id' => $data['category_id'],
            'product_name' => $data['product_name'],
            'commission' => $data['commission'],
            'subject' => $data['subject'],
            'body_text' => $data['body_text'],
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Database::lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT ca.*, c.name AS category_name,
                    (SELECT COUNT(*) FROM email_queue q WHERE q.campaign_id = ca.id) AS queued_total,
                    (SELECT COUNT(*) FROM email_queue q WHERE q.campaign_id = ca.id AND q.status = \'sent\') AS sent_total,
                    (SELECT COUNT(*) FROM email_queue q WHERE q.campaign_id = ca.id AND q.status = \'failed\') AS failed_total
             FROM campaigns ca
             JOIN categories c ON c.id = ca.category_id
             ORDER BY ca.id DESC'
        )->fetchAll();
    }

    /**
     * @return array<string, int>
     */
    public function queueStats(): array
    {
        $stats = ['queued' => 0, 'sent' => 0, 'failed' => 0];
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS total FROM email_queue GROUP BY status')->fetchAll();
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (array_key_exists($status, $stats)) {
                $stats[$status] = (int) $row['total'];
            }
        }

        return $stats;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ca.*, c.name AS category_name FROM campaigns ca JOIN categories c ON c.id = ca.category_id WHERE ca.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $campaign = $stmt->fetch();
        return $campaign ?: null;
    }

    public function queueCampaign(int $campaignId): int
    {
        $campaign = $this->find($campaignId);
        if (!$campaign) {
            throw new RuntimeException('Campanha nao encontrada.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT l.*, c.name AS category_name, ch.title AS channel_title,
                    (SELECT v.title
                     FROM lead_sources ls
                     JOIN videos v ON v.id = ls.video_id
                     WHERE ls.lead_id = l.id
                     ORDER BY ls.id DESC
                     LIMIT 1) AS video_title
             FROM leads l
             JOIN categories c ON c.id = l.category_id
             JOIN channels ch ON ch.id = l.channel_id
             LEFT JOIN suppression_list s ON s.email = l.email
             WHERE l.category_id = :category_id
               AND l.unsubscribed_at IS NULL
               AND l.status IN (\'discovered\', \'qualified\')
               AND s.id IS NULL
             ORDER BY l.id ASC'
        );
        $stmt->execute(['category_id' => $campaign['category_id']]);

        $insertSql = Config::dbConnection() === 'mysql'
            ? 'INSERT IGNORE INTO email_queue
               (campaign_id, lead_id, recipient, subject, body_text, status, scheduled_at, created_at, updated_at)
               VALUES (:campaign_id, :lead_id, :recipient, :subject, :body_text, :status, :scheduled_at, :created_at, :updated_at)'
            : 'INSERT OR IGNORE INTO email_queue
               (campaign_id, lead_id, recipient, subject, body_text, status, scheduled_at, created_at, updated_at)
               VALUES (:campaign_id, :lead_id, :recipient, :subject, :body_text, :status, :scheduled_at, :created_at, :updated_at)';
        $insert = $this->pdo->prepare($insertSql);

        $count = 0;
        while ($lead = $stmt->fetch()) {
            $vars = $this->variables($campaign, $lead);
            $now = Database::now();
            $insert->execute([
                'campaign_id' => $campaign['id'],
                'lead_id' => $lead['id'],
                'recipient' => $lead['email'],
                'subject' => $this->render((string) $campaign['subject'], $vars, false),
                'body_text' => $this->render((string) $campaign['body_text'], $vars, true),
                'status' => 'queued',
                'scheduled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count += $insert->rowCount() > 0 ? 1 : 0;
        }

        $update = $this->pdo->prepare('UPDATE campaigns SET status = :status, updated_at = :updated_at WHERE id = :id');
        $update->execute(['status' => 'queued', 'updated_at' => Database::now(), 'id' => $campaignId]);

        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dueQueue(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.*, l.unsubscribe_token
             FROM email_queue q
             JOIN leads l ON l.id = q.lead_id
             LEFT JOIN suppression_list s ON s.email = q.recipient
             WHERE q.status = :status
               AND q.scheduled_at <= :now
               AND l.unsubscribed_at IS NULL
               AND s.id IS NULL
             ORDER BY q.id ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':status', 'queued');
        $stmt->bindValue(':now', Database::now());
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markSent(array $queueItem): void
    {
        $now = Database::now();
        $stmt = $this->pdo->prepare(
            'UPDATE email_queue SET status = :status, attempts = attempts + 1, sent_at = :sent_at, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute(['status' => 'sent', 'sent_at' => $now, 'updated_at' => $now, 'id' => $queueItem['id']]);

        $stmt = $this->pdo->prepare(
            'UPDATE leads SET last_contacted_at = :last_contacted_at, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute(['last_contacted_at' => $now, 'updated_at' => $now, 'id' => $queueItem['lead_id']]);

        $this->event((int) $queueItem['campaign_id'], (int) $queueItem['lead_id'], 'sent', null);
    }

    public function markFailed(array $queueItem, string $error): void
    {
        $attempts = (int) $queueItem['attempts'] + 1;
        $status = $attempts >= 3 ? 'failed' : 'queued';
        $nextRun = (new DateTimeImmutable('now'))->modify('+20 minutes')->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'UPDATE email_queue
             SET status = :status, attempts = :attempts, last_error = :last_error, scheduled_at = :scheduled_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'attempts' => $attempts,
            'last_error' => function_exists('mb_substr') ? mb_substr($error, 0, 1000, 'UTF-8') : substr($error, 0, 1000),
            'scheduled_at' => $nextRun,
            'updated_at' => Database::now(),
            'id' => $queueItem['id'],
        ]);

        $this->event((int) $queueItem['campaign_id'], (int) $queueItem['lead_id'], 'failed', $error);
    }

    public function event(int $campaignId, int $leadId, string $eventType, ?string $meta): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_events (campaign_id, lead_id, event_type, meta, created_at)
             VALUES (:campaign_id, :lead_id, :event_type, :meta, :created_at)'
        );
        $stmt->execute([
            'campaign_id' => $campaignId,
            'lead_id' => $leadId,
            'event_type' => $eventType,
            'meta' => $meta,
            'created_at' => Database::now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function variables(array $campaign, array $lead): array
    {
        return [
            'email' => (string) $lead['email'],
            'creator_name' => (string) $lead['channel_title'],
            'channel_title' => (string) $lead['channel_title'],
            'video_title' => (string) ($lead['video_title'] ?? ''),
            'category' => (string) $lead['category_name'],
            'niche' => (string) $lead['category_name'],
            'product_name' => (string) $campaign['product_name'],
            'commission' => (string) $campaign['commission'],
            'unsubscribe_url' => app_unsubscribe_url((string) $lead['unsubscribe_token']),
        ];
    }

    /**
     * @param array<string, string> $vars
     */
    private function render(string $template, array $vars, bool $appendUnsubscribe): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }

        if ($appendUnsubscribe && !str_contains($template, $vars['unsubscribe_url'])) {
            $template .= "\n\nSe preferir nao receber novos contatos, use este link: " . $vars['unsubscribe_url'];
        }

        return $template;
    }
}
