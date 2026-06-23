<?php

declare(strict_types=1);

final class ScrapeService
{
    public function __construct(
        private readonly LeadRepository $leads,
        private readonly YouTubeClient $youtube,
        private readonly EmailExtractor $extractor
    ) {
    }

    /**
     * Processa uma pagina de resultados por execucao.
     *
     * @return array{job_id:int, status:string, videos_checked:int, emails_found:int}
     */
    public function process(?int $jobId = null, ?string $batchId = null): array
    {
        $job = $this->leads->nextRunnableJob($jobId, $batchId);
        if (!$job) {
            return ['job_id' => 0, 'status' => 'idle', 'videos_checked' => 0, 'emails_found' => 0];
        }

        $this->leads->updateJob((int) $job['id'], [
            'status' => 'running',
            'started_at' => $job['started_at'] ?: Database::now(),
            'error_message' => null,
            'quota_retry_at' => null,
        ]);

        try {
            $publishedAfter = null;
            if (!empty($job['published_after'])) {
                $publishedAfter = (new DateTimeImmutable((string) $job['published_after']))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format(DateTimeInterface::RFC3339);
            }

            $search = $this->youtube->searchVideos([
                'q' => $this->searchQuery($job),
                'order' => (string) $job['order_by'],
                'pageToken' => $job['next_page_token'] ?? null,
                'regionCode' => $job['region_code'] ?? null,
                'relevanceLanguage' => $job['relevance_language'] ?? null,
                'publishedAfter' => $publishedAfter,
                'maxResults' => 50,
            ]);

            $ids = [];
            foreach ($search['items'] ?? [] as $item) {
                if (($item['id']['kind'] ?? '') === 'youtube#video' && !empty($item['id']['videoId'])) {
                    $ids[] = (string) $item['id']['videoId'];
                }
            }

            $videos = $this->youtube->videos($ids);
            $channelIds = [];
            foreach ($videos as $video) {
                $channelId = (string) ($video['snippet']['channelId'] ?? '');
                if ($channelId !== '') {
                    $channelIds[] = $channelId;
                }
            }
            $channelsById = [];
            foreach ($this->youtube->channels($channelIds) as $channel) {
                $id = (string) ($channel['id'] ?? '');
                if ($id !== '') {
                    $channelsById[$id] = $channel;
                }
            }

            $checked = 0;
            $emailsFound = 0;

            foreach ($videos as $video) {
                $checked++;
                $durationSeconds = $this->durationSeconds((string) ($video['contentDetails']['duration'] ?? ''));
                $videoType = $durationSeconds > 0 && $durationSeconds <= 60 ? 'short' : 'video';
                $wantedType = (string) ($job['video_type'] ?? 'both');
                if (in_array($wantedType, ['video', 'short'], true) && $videoType !== $wantedType) {
                    continue;
                }

                $views = (int) ($video['statistics']['viewCount'] ?? 0);
                $maxViews = $job['max_views'] === null ? null : (int) $job['max_views'];
                if ($views < (int) $job['min_views']) {
                    continue;
                }
                if ($maxViews !== null && $maxViews > 0 && $views > $maxViews) {
                    continue;
                }

                $snippet = $video['snippet'] ?? [];
                $youtubeChannelId = (string) ($snippet['channelId'] ?? '');
                $channel = $channelsById[$youtubeChannelId] ?? [];
                $maxSubscribers = $job['max_subscribers'] === null ? null : (int) $job['max_subscribers'];
                if ($maxSubscribers !== null && $maxSubscribers > 0 && !$this->channelIsInsideSubscriberLimit($channel, $maxSubscribers)) {
                    continue;
                }

                if (!$this->matchesPrecisionFilters($video, $channel, $job)) {
                    continue;
                }

                $description = (string) ($snippet['description'] ?? '');
                $emails = $this->extractor->extract($description);
                if ($emails === []) {
                    continue;
                }

                $video['_duration_seconds'] = $durationSeconds > 0 ? $durationSeconds : null;
                $video['_video_type'] = $videoType;
                $channelId = $this->leads->upsertChannel($snippet, $channel);
                $videoId = $this->leads->upsertVideo($video, $channelId);
                $sourceUrl = 'https://www.youtube.com/watch?v=' . rawurlencode((string) $video['id']);

                foreach ($emails as $emailData) {
                    $leadId = $this->leads->upsertLead($emailData['email'], (int) $job['category_id'], $channelId);
                    $this->leads->attachLeadSource($leadId, $videoId, $sourceUrl, $emailData['context']);
                    $emailsFound++;
                }
            }

            $pagesProcessed = (int) $job['pages_processed'] + 1;
            $nextToken = (string) ($search['nextPageToken'] ?? '');
            $finished = $nextToken === '' || $pagesProcessed >= (int) $job['max_pages'];

            $this->leads->updateJob((int) $job['id'], [
                'status' => $finished ? 'completed' : 'running',
                'next_page_token' => $finished ? null : $nextToken,
                'pages_processed' => $pagesProcessed,
                'videos_checked' => (int) $job['videos_checked'] + $checked,
                'emails_found' => (int) $job['emails_found'] + $emailsFound,
                'finished_at' => $finished ? Database::now() : null,
            ]);

            return [
                'job_id' => (int) $job['id'],
                'status' => $finished ? 'completed' : 'running',
                'videos_checked' => $checked,
                'emails_found' => $emailsFound,
            ];
        } catch (Throwable $exception) {
            if ($this->isQuotaPause($exception)) {
                $this->leads->updateJob((int) $job['id'], [
                    'status' => 'quota_wait',
                    'error_message' => $exception->getMessage(),
                    'quota_retry_at' => $this->quotaRetryAt(),
                    'finished_at' => null,
                ]);

                return [
                    'job_id' => (int) $job['id'],
                    'status' => 'quota_wait',
                    'videos_checked' => 0,
                    'emails_found' => 0,
                ];
            }

            $this->leads->updateJob((int) $job['id'], [
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => Database::now(),
            ]);
            throw $exception;
        }
    }

    /**
     * Processa varias paginas em lote, respeitando limite de passos e tempo.
     *
     * @return array{status:string, steps:int, videos_checked:int, emails_found:int, keep_running:bool, last_job_id:int, progress:array<string, int|string|bool>}
     */
    public function processBatch(?int $jobId = null, int $maxSteps = 6, int $maxSeconds = 35, ?string $batchId = null): array
    {
        $maxSteps = max(1, $maxSteps);
        $maxSeconds = max(5, $maxSeconds);
        $startedAt = time();
        $steps = 0;
        $videosChecked = 0;
        $emailsFound = 0;
        $lastJobId = 0;
        $specificJob = $jobId !== null;

        while ($steps < $maxSteps && (time() - $startedAt) < $maxSeconds) {
            $result = $this->process($jobId, $batchId);
            if ($result['status'] === 'idle') {
                break;
            }

            $steps++;
            $videosChecked += (int) $result['videos_checked'];
            $emailsFound += (int) $result['emails_found'];
            $lastJobId = (int) $result['job_id'];

            if ($specificJob && $result['status'] !== 'running') {
                break;
            }
        }

        return [
            'status' => $steps === 0 ? 'idle' : 'processed',
            'steps' => $steps,
            'videos_checked' => $videosChecked,
            'emails_found' => $emailsFound,
            'keep_running' => $this->leads->runnableJobsCount($batchId) > 0,
            'last_job_id' => $lastJobId,
            'progress' => $this->leads->jobProgressSummary($batchId),
        ];
    }

    private function searchQuery(array $job): string
    {
        $parts = [
            (string) ($job['keywords'] ?? ''),
            (string) ($job['niche'] ?? ''),
        ];

        return trim(implode(' ', array_unique(array_filter(array_map('trim', $parts)))));
    }

    private function isQuotaPause(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'quota')
            || str_contains($message, 'limite temporario')
            || str_contains($message, 'limite gratuito')
            || str_contains($message, 'chaves gratuitas');
    }

    private function quotaRetryAt(): string
    {
        return (new DateTimeImmutable('+6 hours'))->format('Y-m-d H:i:s');
    }

    private function matchesPrecisionFilters(array $video, array $channel, array $job): bool
    {
        $snippet = $video['snippet'] ?? [];
        $text = $this->matchText(implode(' ', [
            (string) ($snippet['title'] ?? ''),
            (string) ($snippet['description'] ?? ''),
            (string) ($snippet['channelTitle'] ?? ''),
            (string) ($channel['snippet']['title'] ?? ''),
        ]));

        foreach ($this->terms((string) ($job['exclude_terms'] ?? '')) as $term) {
            if ($term !== '' && str_contains($text, $term)) {
                return false;
            }
        }

        $includeTerms = $this->terms((string) ($job['include_terms'] ?? ''));
        if ($includeTerms === []) {
            return true;
        }

        $matches = 0;
        foreach ($includeTerms as $term) {
            if ($term !== '' && str_contains($text, $term)) {
                $matches++;
            }
        }

        return (string) ($job['match_mode'] ?? 'any') === 'all'
            ? $matches === count($includeTerms)
            : $matches > 0;
    }

    /**
     * @return array<int, string>
     */
    private function terms(string $value): array
    {
        $lines = preg_split('/\R+/', $value) ?: [];
        $terms = [];
        foreach ($lines as $line) {
            $term = $this->matchText($line);
            if ($term !== '') {
                $terms[$term] = $term;
            }
        }

        return array_values($terms);
    }

    private function matchText(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($ascii) && $ascii !== '') {
                $value = strtolower($ascii);
            }
        }

        return $value;
    }

    private function channelIsInsideSubscriberLimit(array $channel, int $maxSubscribers): bool
    {
        $statistics = $channel['statistics'] ?? [];
        if (!is_array($statistics) || !empty($statistics['hiddenSubscriberCount'])) {
            return false;
        }

        if (!array_key_exists('subscriberCount', $statistics)) {
            return false;
        }

        return (int) $statistics['subscriberCount'] <= $maxSubscribers;
    }

    private function durationSeconds(string $duration): int
    {
        if ($duration === '') {
            return 0;
        }

        try {
            $interval = new \DateInterval($duration);
        } catch (\Throwable) {
            return 0;
        }

        return ($interval->d * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;
    }
}
