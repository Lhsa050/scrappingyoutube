<?php

declare(strict_types=1);

final class Mailer
{
    private $socket = null;

    public function send(string $to, string $subject, string $body, string $unsubscribeUrl): void
    {
        $settings = (new SettingsRepository(Database::pdo()))->all();
        $host = (string) ($settings['smtp_host'] ?? '');
        if ($host === '') {
            throw new RuntimeException('Configure o SMTP no painel antes de enviar campanhas.');
        }

        $port = (int) ($settings['smtp_port'] ?? 465);
        $encryption = strtolower((string) ($settings['smtp_encryption'] ?? 'ssl'));
        $fromEmail = (string) ($settings['mail_from_email'] ?? '');
        $fromName = (string) ($settings['mail_from_name'] ?? 'Creator Outreach');
        $replyTo = (string) (($settings['mail_reply_to'] ?? '') !== '' ? $settings['mail_reply_to'] : $fromEmail);

        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Endereco de e-mail invalido.');
        }

        $this->connect($host, $port, $encryption);
        try {
            $this->command('EHLO ' . $this->serverName(), 250);

            if ($encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Nao foi possivel ativar TLS no SMTP.');
                }
                $this->command('EHLO ' . $this->serverName(), 250);
            }

            $username = (string) ($settings['smtp_username'] ?? '');
            $password = (string) ($settings['smtp_password'] ?? '');
            if ($username !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($username), 334);
                $this->command(base64_encode($password), 235);
            }

            $this->command('MAIL FROM:<' . $fromEmail . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', 354);

            $message = $this->headers($fromName, $fromEmail, $replyTo, $to, $subject, $unsubscribeUrl)
                . "\r\n\r\n"
                . $this->normalizeBody($body);
            fwrite($this->socket, $this->dotStuff($message) . "\r\n.\r\n");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function connect(string $host, int $port, string $encryption): void
    {
        $target = $encryption === 'ssl' ? 'ssl://' . $host : $host;
        $this->socket = stream_socket_client($target . ':' . $port, $errno, $error, 30);
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Falha ao conectar no SMTP: ' . $error);
        }

        stream_set_timeout($this->socket, 30);
        $this->expect(220);
    }

    private function command(string $command, int|array $expected): string
    {
        fwrite($this->socket, $command . "\r\n");
        return $this->expect($expected);
    }

    private function expect(int|array $expected): string
    {
        $expected = is_array($expected) ? $expected : [$expected];
        $response = '';

        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('Resposta SMTP inesperada: ' . trim($response));
        }

        return $response;
    }

    private function headers(string $fromName, string $fromEmail, string $replyTo, string $to, string $subject, string $unsubscribeUrl): string
    {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';

        return implode("\r\n", [
            'From: ' . $encodedFrom,
            'Reply-To: ' . $replyTo,
            'To: ' . $to,
            'Subject: ' . $encodedSubject,
            'Date: ' . date(DATE_RFC2822),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'List-Unsubscribe: <' . $unsubscribeUrl . '>',
            'X-Mailer: Creator Outreach CRM',
        ]);
    }

    private function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        return str_replace("\n", "\r\n", $body);
    }

    private function dotStuff(string $message): string
    {
        return preg_replace('/^\./m', '..', $message) ?? $message;
    }

    private function serverName(): string
    {
        $host = parse_url(Config::appUrl(), PHP_URL_HOST);
        return $host ?: 'localhost';
    }
}
