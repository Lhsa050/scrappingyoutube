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
    public function process(?int $jobId = null): array
    {
        $job = $this->leads->nextRunnableJob($jobId);
        if (!$job) {
            return ['job_id' => 0, 'status' => 'idle', 'videos_checked' => 0, 'emails_found' => 0];
        }

        $this->leads->updateJob((int) $job['id'], [
            'status' => 'running',
            'started_at' => $job['started_at'] ?: Database::now(),
            'error_message' => null,
        ]);

        try {
            $publishedAfter = null;
            if (!empty($job['published_after'])) {
                $publishedAfter = (new DateTimeImmutable((string) $job['published_after']))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format(DateTimeInterface::RFC3339);
            }

            $search = $this->youtube->searchVideos([
                'q' => (string) $job['keywords'],
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

                $description = (string) ($snippet['description'] ?? '');
                $emails = $this->extractor->extract($description);
                if ($emails === []) {
                    continue;
                }

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
     * @return array{status:string, steps:int, videos_checked:int, emails_found:int, keep_running:bool, last_job_id:int}
     */
    public function processBatch(?int $jobId = null, int $maxSteps = 6, int $maxSeconds = 35): array
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
            $result = $this->process($jobId);
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
            'keep_running' => $this->leads->runnableJobsCount() > 0,
            'last_job_id' => $lastJobId,
        ];
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
}
