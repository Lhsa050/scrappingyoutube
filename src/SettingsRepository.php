<?php

declare(strict_types=1);

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $settings = $this->defaults();
        $rows = $this->pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();

        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        return $settings;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $settings = $this->all();
        return $settings[$key] ?? $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    /**
     * @param array<string, string> $values
     */
    public function save(array $values): void
    {
        $now = Database::now();
        $sql = Config::dbConnection() === 'mysql'
            ? 'INSERT INTO app_settings (setting_key, setting_value, updated_at)
               VALUES (:setting_key, :setting_value, :updated_at)
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
            : 'INSERT INTO app_settings (setting_key, setting_value, updated_at)
               VALUES (:setting_key, :setting_value, :updated_at)
               ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at';

        $stmt = $this->pdo->prepare($sql);
        foreach ($values as $key => $value) {
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function defaults(): array
    {
        return [
            'smtp_host' => (string) Config::get('SMTP_HOST', 'smtp.hostinger.com'),
            'smtp_port' => (string) Config::int('SMTP_PORT', 465),
            'smtp_encryption' => strtolower((string) Config::get('SMTP_ENCRYPTION', 'ssl')),
            'smtp_username' => (string) Config::get('SMTP_USERNAME', ''),
            'smtp_password' => (string) Config::get('SMTP_PASSWORD', ''),
            'mail_from_email' => (string) Config::get('MAIL_FROM_EMAIL', ''),
            'mail_from_name' => (string) Config::get('MAIL_FROM_NAME', 'Sua Marca'),
            'mail_reply_to' => (string) Config::get('MAIL_REPLY_TO', ''),
            'emails_per_run' => (string) Config::int('EMAILS_PER_RUN', 20),
            'update_manifest_url' => (string) Config::get('UPDATE_MANIFEST_URL', ''),
            'github_repo' => (string) Config::get('GITHUB_REPO', 'Lhsa050/scrappingyoutube'),
            'github_branch' => (string) Config::get('GITHUB_BRANCH', 'main'),
            'github_token' => (string) Config::get('GITHUB_TOKEN', ''),
        ];
    }

    /**
     * @param array<string, string> $values
     * @return array<int, string>
     */
    public function validateMailSettings(array $values): array
    {
        $errors = [];
        $required = [
            'smtp_host' => 'Host SMTP',
            'smtp_port' => 'Porta SMTP',
            'smtp_username' => 'Usuario SMTP',
            'smtp_password' => 'Senha SMTP',
            'mail_from_email' => 'E-mail remetente',
            'mail_from_name' => 'Nome remetente',
        ];

        foreach ($required as $key => $label) {
            if (trim((string) ($values[$key] ?? '')) === '') {
                $errors[] = "Preencha: {$label}.";
            }
        }

        if ((int) ($values['smtp_port'] ?? 0) <= 0) {
            $errors[] = 'Informe uma porta SMTP valida.';
        }

        if (!in_array($values['smtp_encryption'] ?? '', ['ssl', 'tls', 'none'], true)) {
            $errors[] = 'Escolha uma criptografia SMTP valida.';
        }

        if (!filter_var($values['mail_from_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail remetente valido.';
        }

        if (($values['mail_reply_to'] ?? '') !== '' && !filter_var($values['mail_reply_to'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um Reply-To valido ou deixe em branco.';
        }

        if ((int) ($values['emails_per_run'] ?? 0) < 1 || (int) ($values['emails_per_run'] ?? 0) > 500) {
            $errors[] = 'Emails por execucao deve ficar entre 1 e 500.';
        }

        return $errors;
    }
}
