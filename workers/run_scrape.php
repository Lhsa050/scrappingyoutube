<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$repo = new LeadRepository(Database::pdo());
$settings = new SettingsRepository(Database::pdo());
$service = new ScrapeService(
    $repo,
    new YouTubeClient($settings->youtubeApiKeys(), (string) $settings->get('youtube_provider', 'auto')),
    new EmailExtractor()
);

$jobId = isset($argv[1]) ? (int) $argv[1] : null;
$maxSteps = isset($argv[2]) ? max(1, (int) $argv[2]) : 12;

try {
    $result = $service->processBatch($jobId, $maxSteps, 90);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
