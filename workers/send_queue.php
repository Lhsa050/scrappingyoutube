<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$campaigns = new CampaignRepository(Database::pdo());
$settings = new SettingsRepository(Database::pdo());
$mailer = new Mailer();
$limit = $settings->int('emails_per_run', 20);
$sent = 0;
$failed = 0;

foreach ($campaigns->dueQueue($limit) as $item) {
    try {
        $unsubscribeUrl = app_unsubscribe_url((string) $item['unsubscribe_token']);
        $mailer->send((string) $item['recipient'], (string) $item['subject'], (string) $item['body_text'], $unsubscribeUrl);
        $campaigns->markSent($item);
        $sent++;
    } catch (Throwable $exception) {
        $campaigns->markFailed($item, $exception->getMessage());
        $failed++;
    }
}

echo json_encode([
    'sent' => $sent,
    'failed' => $failed,
    'limit' => $limit,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
