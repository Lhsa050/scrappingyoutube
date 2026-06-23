<?php

declare(strict_types=1);

final class YouTubeClient
{
    private const BASE_URL = 'https://www.googleapis.com/youtube/v3/';

    public function __construct(private readonly string $apiKey)
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Defina YOUTUBE_API_KEY no arquivo .env.');
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
            'key' => $this->apiKey,
        ], static fn ($value) => $value !== null && $value !== '');

        return $this->request('search', $params);
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

        $response = $this->request('videos', [
            'part' => 'snippet,statistics',
            'id' => implode(',', array_slice($ids, 0, 50)),
            'key' => $this->apiKey,
        ]);

        return $response['items'] ?? [];
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

        $response = $this->request('channels', [
            'part' => 'statistics',
            'id' => implode(',', array_slice($ids, 0, 50)),
            'key' => $this->apiKey,
        ]);

        return $response['items'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $endpoint, array $params): array
    {
        $url = self::BASE_URL . $endpoint . '?' . http_build_query($params);
        $body = $this->httpGet($url);
        $json = json_decode($body, true);

        if (!is_array($json)) {
            throw new RuntimeException('Resposta invalida da API do YouTube.');
        }

        if (isset($json['error'])) {
            $message = $json['error']['message'] ?? 'Erro desconhecido da API do YouTube.';
            throw new RuntimeException((string) $message);
        }

        return $json;
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

            if ($body === false || $status >= 400) {
                throw new RuntimeException($error !== '' ? $error : 'Falha HTTP ao consultar YouTube.');
            }

            return (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 35,
                'header' => "User-Agent: CreatorOutreachCRM/1.0\r\n",
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Falha HTTP ao consultar YouTube.');
        }

        return $body;
    }
}
