<?php

declare(strict_types=1);

final class YouTubeClient
{
    private const BASE_URL = 'https://www.googleapis.com/youtube/v3/';
    private const WATCH_URL = 'https://www.youtube.com/watch?v=';
    private const SEARCH_URL = 'https://www.youtube.com/results';

    /** @var array<int, string> */
    private array $apiKeys;
    private bool $publicScrapeActive = false;

    /** @var array<string, array<string, mixed>> */
    private array $publicVideoCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $publicChannelCache = [];

    /**
     * @param string|array<int, string> $apiKeys
     */
    public function __construct(string|array $apiKeys, private readonly string $provider = 'auto')
    {
        $this->apiKeys = $this->normalizeApiKeys($apiKeys);
        if ($this->apiKeys === [] && $this->provider === 'api') {
            throw new RuntimeException('Defina pelo menos uma chave gratuita da YouTube Data API.');
        }

        if ($this->provider === 'scrape' || $this->apiKeys === []) {
            $this->publicScrapeActive = true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function searchVideos(array $params): array
    {
        $params = array_filter([
            'part' => 'snippet',
            'type' => 'video',
            'q' => $params['q'] ?? '',
            'maxResults' => min(50, max(1, (int) ($params['maxResults'] ?? 50))),
            'order' => $params['order'] ?? 'relevance',
            'pageToken' => $params['pageToken'] ?? null,
            'regionCode' => $params['regionCode'] ?? null,
            'relevanceLanguage' => $params['relevanceLanguage'] ?? null,
            'publishedAfter' => $params['publishedAfter'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($this->publicScrapeActive) {
            return $this->publicSearchVideos($params);
        }

        try {
            return $this->request('search', $params);
        } catch (RuntimeException $exception) {
            if ($this->canFallbackToPublicScrape($exception)) {
                $this->publicScrapeActive = true;
                return $this->publicSearchVideos($params);
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, string> $ids
     * @return array<int, array<string, mixed>>
     */
    public function videos(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        if ($this->publicScrapeActive) {
            return $this->publicVideos($ids);
        }

        try {
            $response = $this->request('videos', [
                'part' => 'snippet,statistics,contentDetails',
                'id' => implode(',', array_slice($ids, 0, 50)),
            ]);

            return $response['items'] ?? [];
        } catch (RuntimeException $exception) {
            if ($this->canFallbackToPublicScrape($exception)) {
                $this->publicScrapeActive = true;
                return $this->publicVideos($ids);
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, string> $ids
     * @return array<int, array<string, mixed>>
     */
    public function channels(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        if ($this->publicScrapeActive) {
            return $this->publicChannels($ids);
        }

        try {
            $response = $this->request('channels', [
                'part' => 'statistics,snippet',
                'id' => implode(',', array_slice($ids, 0, 50)),
            ]);

            return $response['items'] ?? [];
        } catch (RuntimeException $exception) {
            if ($this->canFallbackToPublicScrape($exception)) {
                $this->publicScrapeActive = true;
                return $this->publicChannels($ids);
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $endpoint, array $params): array
    {
        $keyErrors = [];

        foreach ($this->apiKeys as $index => $apiKey) {
            $requestParams = $params;
            $requestParams['key'] = $apiKey;
            $url = self::BASE_URL . $endpoint . '?' . http_build_query($requestParams);
            $body = $this->httpGet($url);
            $json = json_decode($body, true);

            if (!is_array($json)) {
                throw new RuntimeException('Resposta invalida da API do YouTube.');
            }

            if (!isset($json['error'])) {
                return $json;
            }

            $message = (string) ($json['error']['message'] ?? 'Erro desconhecido da API do YouTube.');
            $reason = $this->errorReason($json);
            if (!$this->shouldTryNextKey($reason, $message)) {
                throw new RuntimeException($message);
            }

            $keyErrors[] = 'chave ' . ($index + 1) . ': ' . $message;
        }

        if ($this->onlyQuotaErrors($keyErrors)) {
            throw new RuntimeException('Todas as chaves gratuitas da YouTube Data API atingiram quota ou limite temporario.');
        }

        throw new RuntimeException('Nenhuma chave gratuita da YouTube Data API funcionou. ' . implode(' | ', $keyErrors));
    }

    /**
     * @param string|array<int, string> $apiKeys
     * @return array<int, string>
     */
    private function normalizeApiKeys(string|array $apiKeys): array
    {
        $raw = is_array($apiKeys) ? implode("\n", $apiKeys) : $apiKeys;
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $keys = [];
        foreach ($parts as $part) {
            $key = trim($part);
            if ($key !== '') {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function errorReason(array $json): string
    {
        $errors = $json['error']['errors'] ?? [];
        if (is_array($errors) && isset($errors[0]['reason'])) {
            return strtolower((string) $errors[0]['reason']);
        }

        return '';
    }

    private function shouldTryNextKey(string $reason, string $message): bool
    {
        $message = strtolower($message);
        foreach ([
            'quota',
            'dailylimit',
            'ratelimit',
            'keyinvalid',
            'accessnotconfigured',
            'forbidden',
        ] as $needle) {
            if (str_contains($reason, $needle) || str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function canFallbackToPublicScrape(RuntimeException $exception): bool
    {
        if ($this->provider === 'api') {
            return false;
        }

        $message = strtolower($exception->getMessage());
        foreach ([
            'quota',
            'limit',
            'chaves gratuitas',
            'keyinvalid',
            'forbidden',
            'accessnotconfigured',
            'falha http',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return $this->apiKeys === [];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicSearchVideos(array $params): array
    {
        $pageIndex = $this->publicPageIndex((string) ($params['pageToken'] ?? ''));
        $query = trim((string) ($params['q'] ?? '') . ' ' . $this->publicQuerySuffix($pageIndex));
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? $query);
        $maxResults = min(50, max(1, (int) ($params['maxResults'] ?? 50)));

        $url = self::SEARCH_URL . '?' . http_build_query([
            'search_query' => $query,
            'hl' => $params['relevanceLanguage'] ?? 'pt',
            'gl' => $params['regionCode'] ?? 'BR',
        ]);

        $html = $this->httpGetPublic($url);
        preg_match_all('/"videoId":"([A-Za-z0-9_-]{11})"/', $html, $matches);
        $ids = array_values(array_unique($matches[1] ?? []));
        if ($ids === []) {
            throw new RuntimeException('Scraping publico nao encontrou videos no YouTube para esta busca.');
        }

        $items = [];
        foreach (array_slice($ids, 0, $maxResults) as $id) {
            $items[] = ['id' => ['kind' => 'youtube#video', 'videoId' => $id]];
        }

        return [
            'items' => $items,
            'nextPageToken' => 'public:' . ($pageIndex + 1),
            '_provider' => 'public_scrape',
        ];
    }

    /**
     * @param array<int, string> $ids
     * @return array<int, array<string, mixed>>
     */
    private function publicVideos(array $ids): array
    {
        $videos = [];
        foreach (array_slice($ids, 0, 50) as $id) {
            if (isset($this->publicVideoCache[$id])) {
                $videos[] = $this->publicVideoCache[$id];
                continue;
            }

            $html = $this->httpGetPublic(self::WATCH_URL . rawurlencode($id));
            $description = $this->jsonStringValue($html, 'shortDescription');
            $title = $this->jsonStringValue($html, 'title') ?: $this->htmlTitle($html);
            $author = $this->jsonStringValue($html, 'author') ?: 'Canal sem titulo';
            $channelId = $this->jsonStringValue($html, 'channelId') ?: 'public-' . substr(sha1($author), 0, 16);
            $viewCount = $this->firstNumber($html, '/"viewCount":"?(\d+)"?/');
            $lengthSeconds = $this->firstNumber($html, '/"lengthSeconds":"?(\d+)"?/');
            $publishedAt = $this->jsonStringValue($html, 'publishDate') ?: $this->jsonStringValue($html, 'datePublished');
            $subscriberCount = $this->subscriberCountFromHtml($html);

            $this->publicChannelCache[$channelId] = [
                'id' => $channelId,
                'snippet' => ['title' => $author],
                'statistics' => [
                    'subscriberCount' => $subscriberCount ?? 0,
                    'hiddenSubscriberCount' => false,
                    'subscriberCountEstimated' => $subscriberCount === null,
                ],
            ];

            $video = [
                'id' => $id,
                'snippet' => [
                    'title' => $title !== '' ? $title : 'Video sem titulo',
                    'description' => $description,
                    'channelId' => $channelId,
                    'channelTitle' => $author,
                    'publishedAt' => $publishedAt !== '' ? $publishedAt . 'T00:00:00Z' : null,
                    'thumbnails' => [
                        'default' => ['url' => 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/default.jpg'],
                    ],
                ],
                'statistics' => ['viewCount' => $viewCount],
                'contentDetails' => ['duration' => $this->secondsToIsoDuration($lengthSeconds)],
                '_provider' => 'public_scrape',
            ];
            $this->publicVideoCache[$id] = $video;
            $videos[] = $video;
        }

        return $videos;
    }

    /**
     * @param array<int, string> $ids
     * @return array<int, array<string, mixed>>
     */
    private function publicChannels(array $ids): array
    {
        $channels = [];
        foreach ($ids as $id) {
            if (isset($this->publicChannelCache[$id])) {
                $channels[] = $this->publicChannelCache[$id];
            }
        }

        return $channels;
    }

    private function publicPageIndex(string $token): int
    {
        if (preg_match('/^public:(\d+)$/', $token, $match)) {
            return max(0, (int) $match[1]);
        }

        return $token !== '' ? 1 : 0;
    }

    private function publicQuerySuffix(int $index): string
    {
        $suffixes = [
            '',
            'contato',
            'email',
            'parceria',
            'divulgacao',
            'afiliados',
            'instagram',
            'consultoria',
            'dicas',
            'canal pequeno',
            'iniciantes',
            'brasil',
        ];

        $suffix = $suffixes[$index % count($suffixes)];
        $cycle = intdiv($index, count($suffixes));
        if ($cycle > 0) {
            $suffix = trim($suffix . ' ' . chr(97 + ($cycle % 26)));
        }

        return $suffix;
    }

    /**
     * @param array<int, string> $errors
     */
    private function onlyQuotaErrors(array $errors): bool
    {
        if ($errors === []) {
            return false;
        }

        foreach ($errors as $error) {
            $error = strtolower($error);
            if (!str_contains($error, 'quota') && !str_contains($error, 'limit')) {
                return false;
            }
        }

        return true;
    }

    private function jsonStringValue(string $html, string $key): string
    {
        if (!preg_match('/"' . preg_quote($key, '/') . '":"((?:\\\\.|[^"\\\\])*)"/', $html, $match)) {
            return '';
        }

        $decoded = json_decode('"' . $match[1] . '"', true);
        return is_string($decoded) ? $decoded : stripslashes($match[1]);
    }

    private function htmlTitle(string $html): string
    {
        if (!preg_match('/<title>(.*?)<\/title>/is', $html, $match)) {
            return '';
        }

        return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    private function firstNumber(string $html, string $pattern): int
    {
        if (!preg_match($pattern, $html, $match)) {
            return 0;
        }

        return (int) preg_replace('/\D+/', '', $match[1]);
    }

    private function subscriberCountFromHtml(string $html): ?int
    {
        foreach ([
            '/"subscriberCountText":\{"simpleText":"((?:\\\\.|[^"\\\\])*)"/',
            '/"subscriberCountText":\{"runs":\[\{"text":"((?:\\\\.|[^"\\\\])*)"/',
        ] as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                $text = json_decode('"' . $match[1] . '"', true);
                $count = $this->compactNumber((string) ($text ?: $match[1]));
                if ($count !== null) {
                    return $count;
                }
            }
        }

        return null;
    }

    private function compactNumber(string $text): ?int
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        if (!preg_match('/([\d.,]+)/', $text, $match)) {
            return null;
        }

        $number = $match[1];
        $hasMultiplier = str_contains($text, 'mil')
            || str_contains($text, 'k')
            || str_contains($text, 'mi')
            || str_contains($text, 'm ')
            || str_contains($text, 'million');

        if (!$hasMultiplier && preg_match('/^\d{1,3}([.,]\d{3})+$/', $number)) {
            $number = str_replace([',', '.'], '', $number);
        }
        if (str_contains($number, ',') && str_contains($number, '.')) {
            $number = strrpos($number, ',') > strrpos($number, '.')
                ? str_replace(',', '.', str_replace('.', '', $number))
                : str_replace(',', '', $number);
        } elseif (str_contains($number, ',')) {
            $number = str_replace(',', '.', $number);
        } elseif (substr_count($number, '.') > 1) {
            $number = str_replace('.', '', $number);
        }
        $value = (float) $number;
        $multiplier = 1;
        if (str_contains($text, 'mil') || str_contains($text, 'k')) {
            $multiplier = 1000;
        } elseif (str_contains($text, 'mi') || str_contains($text, 'm ') || str_contains($text, 'million')) {
            $multiplier = 1000000;
        }

        return (int) round($value * $multiplier);
    }

    private function secondsToIsoDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $seconds %= 60;

        return 'PT' . ($hours > 0 ? $hours . 'H' : '') . ($minutes > 0 ? $minutes . 'M' : '') . $seconds . 'S';
    }

    private function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 35,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'CreatorOutreachCRM/1.0',
            ]);

            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($body === false) {
                throw new RuntimeException($error !== '' ? $error : 'Falha HTTP ao consultar YouTube.');
            }

            if ($status >= 400 && (string) $body === '') {
                throw new RuntimeException('Falha HTTP ao consultar YouTube. HTTP ' . $status . '.');
            }

            return (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 35,
                'ignore_errors' => true,
                'header' => "User-Agent: CreatorOutreachCRM/1.0\r\n",
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Falha HTTP ao consultar YouTube.');
        }

        return $body;
    }

    private function httpGetPublic(string $url): string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 35,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                CURLOPT_HTTPHEADER => [
                    'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]);

            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($body === false || $status >= 400) {
                throw new RuntimeException($error !== '' ? $error : 'Scraping publico falhou ao consultar YouTube. HTTP ' . $status . '.');
            }

            return (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 35,
                'ignore_errors' => true,
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36\r\nAccept-Language: pt-BR,pt;q=0.9,en;q=0.8\r\n",
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Scraping publico falhou ao consultar YouTube.');
        }

        return $body;
    }
}
