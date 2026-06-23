<?php

declare(strict_types=1);

final class YouTubeClient
{
    private const BASE_URL = 'https://www.googleapis.com/youtube/v3/';

    /** @var array<int, string> */
    private array $apiKeys;

    /**
     * @param string|array<int, string> $apiKeys
     */
    public function __construct(string|array $apiKeys)
    {
        $this->apiKeys = $this->normalizeApiKeys($apiKeys);
        if ($this->apiKeys === []) {
            throw new RuntimeException('Defina pelo menos uma chave gratuita da YouTube Data API.');
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
            'part' => 'snippet,statistics,contentDetails',
            'id' => implode(',', array_slice($ids, 0, 50)),
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
            'part' => 'statistics,snippet',
            'id' => implode(',', array_slice($ids, 0, 50)),
        ]);

        return $response['items'] ?? [];
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
}
