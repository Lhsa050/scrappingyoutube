<?php

declare(strict_types=1);

final class EmailExtractor
{
    /**
     * @return array<int, array{email:string, context:string}>
     */
    public function extract(string $text): array
    {
        $prepared = $this->prepare($text);
        $matches = [];
        preg_match_all('/(?<![A-Z0-9._%+\-])([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})(?![A-Z0-9._%+\-])/i', $prepared, $matches, PREG_OFFSET_CAPTURE);

        $found = [];
        foreach ($matches[1] ?? [] as [$rawEmail, $offset]) {
            $email = normalize_email(trim($rawEmail, " \t\n\r\0\x0B.,;:!?()[]{}<>"));
            if (!$this->isAllowed($email)) {
                continue;
            }

            $found[$email] = [
                'email' => $email,
                'context' => $this->context($prepared, (int) $offset),
            ];
        }

        return array_values($found);
    }

    private function prepare(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}"], '', $text);

        $patterns = [
            '/([a-z0-9._%+\-]+)\s*(?:\(|\[|\{)?\s*(?:at|arroba)\s*(?:\)|\]|\})?\s*([a-z0-9.\-]+)\s*(?:\(|\[|\{)?\s*(?:dot|ponto)\s*(?:\)|\]|\})?\s*([a-z]{2,})/iu',
            '/([a-z0-9._%+\-]+)\s*(?:\(|\[|\{)?\s*@\s*(?:\)|\]|\})?\s*([a-z0-9.\-]+)\s*(?:\(|\[|\{)?\s*(?:dot|ponto)\s*(?:\)|\]|\})?\s*([a-z]{2,})/iu',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '$1@$2.$3', $text) ?? $text;
        }

        return $text;
    }

    private function isAllowed(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        [$local, $domain] = explode('@', $email, 2);
        if ($local === '' || $domain === '' || str_contains($domain, '..')) {
            return false;
        }

        $blockedLocals = ['noreply', 'no-reply', 'donotreply', 'naoresponda'];
        if (in_array($local, $blockedLocals, true)) {
            return false;
        }

        return true;
    }

    private function context(string $text, int $offset): string
    {
        $start = max(0, $offset - 90);
        $chunk = function_exists('mb_substr')
            ? mb_substr($text, $start, 220, 'UTF-8')
            : substr($text, $start, 220);
        $chunk = preg_replace('/\s+/u', ' ', $chunk) ?? $chunk;
        return trim($chunk);
    }
}
