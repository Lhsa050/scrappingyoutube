<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$repo = new LeadRepository(Database::pdo());
$service = new ScrapeService(
    $repo,
    new YouTubeClient((string) Config::get('YOUTUBE_API_KEY', '')),
    new EmailExtractor()
);

$jobId = isset($argv[1]) ? (int) $argv[1] : null;

try {
    $result = $service->process($jobId);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
