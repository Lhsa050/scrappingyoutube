<?php

declare(strict_types=1);

final class UpdaterService
{
    private string $root;
    private string $storage;

    public function __construct(private readonly SettingsRepository $settings)
    {
        $this->root = Config::root();
        $this->storage = $this->root . '/storage/updates';
        if (!is_dir($this->storage)) {
            mkdir($this->storage, 0755, true);
        }
    }

    public function currentVersion(): string
    {
        $versionFile = $this->root . '/VERSION';
        if (!is_file($versionFile)) {
            return '0.0.0';
        }

        $version = (string) file_get_contents($versionFile);
        $version = preg_replace('/^\xEF\xBB\xBF/', '', $version) ?? $version;

        return trim($version) ?: '0.0.0';
    }

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        return $this->checkGitHub();
    }

    /**
     * @return array<string, mixed>
     */
    public function checkGitHub(): array
    {
        $repo = trim((string) $this->settings->get('github_repo', 'Lhsa050/scrappingyoutube'));
        $branch = trim((string) $this->settings->get('github_branch', 'main'));
        $token = trim((string) $this->settings->get('github_token', ''));

        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
            throw new RuntimeException('Repositorio GitHub invalido. Use o formato usuario/repositorio.');
        }

        $repoInfo = $this->githubJson('https://api.github.com/repos/' . $repo, $token);
        if ($branch === '') {
            $branch = (string) ($repoInfo['default_branch'] ?? 'main');
        }

        $versionInfo = $this->githubJson(
            'https://api.github.com/repos/' . $repo . '/contents/VERSION?ref=' . rawurlencode($branch),
            $token
        );

        $encoded = (string) ($versionInfo['content'] ?? '');
        $latestVersion = (string) base64_decode(str_replace(["\n", "\r"], '', $encoded), true);
        $latestVersion = preg_replace('/^\xEF\xBB\xBF/', '', $latestVersion) ?? $latestVersion;
        $latestVersion = trim($latestVersion);
        if ($latestVersion === '') {
            throw new RuntimeException('Nao foi possivel ler o arquivo VERSION no repositorio GitHub.');
        }

        $currentVersion = $this->currentVersion();
        $available = version_compare($latestVersion, $currentVersion, '>');
        $packageUrl = 'https://api.github.com/repos/' . $repo . '/zipball/' . rawurlencode($branch);

        return [
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'available' => $available,
            'manifest' => [
                'version' => $latestVersion,
                'released_at' => (string) ($repoInfo['pushed_at'] ?? ''),
                'package_url' => $packageUrl,
                'notes' => [
                    'Codigo fonte mais recente no GitHub: ' . $repo . ' @ ' . $branch,
                ],
                'source' => 'github',
                'repo' => $repo,
                'branch' => $branch,
                'requires_sha256' => false,
            ],
            'manifest_url' => 'https://github.com/' . $repo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkManifest(): array
    {
        $manifestUrl = trim((string) $this->settings->get('update_manifest_url', ''));
        if ($manifestUrl === '') {
            throw new RuntimeException('Configure a URL do manifesto de atualizacao em Configuracoes.');
        }

        $manifest = $this->fetchManifest($manifestUrl);
        $latestVersion = (string) ($manifest['version'] ?? '');
        if ($latestVersion === '') {
            throw new RuntimeException('Manifesto invalido: campo version ausente.');
        }

        $currentVersion = $this->currentVersion();
        $available = version_compare($latestVersion, $currentVersion, '>');

        return [
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'available' => $available,
            'manifest' => $manifest,
            'manifest_url' => $manifestUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function githubJson(string $url, string $token): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $body = $this->httpGet($url, $headers);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('GitHub respondeu um JSON invalido.');
        }

        if (isset($json['message']) && !isset($json['content']) && !isset($json['default_branch'])) {
            $message = (string) $json['message'];
            if (str_contains(strtolower($message), 'not found')) {
                throw new RuntimeException('Repositorio GitHub nao encontrado ou privado. Confirme o nome do repo ou configure um token.');
            }
            throw new RuntimeException('Erro do GitHub: ' . $message);
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    public function installLatest(): array
    {
        $check = $this->check();
        if (!$check['available']) {
            return [
                'updated' => false,
                'message' => 'Nenhuma atualizacao disponivel.',
                'version' => $check['current_version'],
            ];
        }

        $manifest = $check['manifest'];
        $packageUrl = (string) ($manifest['package_url'] ?? '');
        $expectedHash = strtolower((string) ($manifest['sha256'] ?? ''));
        $source = (string) ($manifest['source'] ?? '');
        if ($packageUrl === '') {
            throw new RuntimeException('Manifesto invalido: campo package_url ausente.');
        }
        if ($source !== 'github' && ($expectedHash === '' || !preg_match('/^[a-f0-9]{64}$/', $expectedHash))) {
            throw new RuntimeException('Manifesto invalido: sha256 ausente ou invalido.');
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('A extensao PHP ZipArchive precisa estar habilitada para aplicar atualizacoes.');
        }

        $version = preg_replace('/[^0-9A-Za-z._-]/', '-', (string) $manifest['version']);
        $zipPath = $this->storage . '/package-' . $version . '.zip';
        $extractPath = $this->storage . '/extract-' . $version . '-' . date('YmdHis');
        $backupPath = $this->root . '/storage/backups/update-' . date('YmdHis');
        $headers = [];
        if ($source === 'github') {
            $headers = $this->githubHeaders(trim((string) $this->settings->get('github_token', '')));
        }

        $this->download($packageUrl, $zipPath, $headers);
        $actualHash = hash_file('sha256', $zipPath);
        if ($expectedHash !== '' && !hash_equals($expectedHash, strtolower((string) $actualHash))) {
            throw new RuntimeException('SHA-256 do pacote nao confere. Atualizacao interrompida.');
        }

        $this->extract($zipPath, $extractPath);
        $this->applyPackage($extractPath, $backupPath, (array) ($manifest['delete'] ?? []));
        Database::migrate(true);
        $installedVersion = $this->currentVersion();
        if (version_compare($installedVersion, (string) $manifest['version'], '<')) {
            throw new RuntimeException('Os arquivos foram copiados, mas a versao instalada ainda aparece como ' . $installedVersion . '. Verifique permissoes do arquivo VERSION.');
        }
        $this->removeDirectory($extractPath);

        return [
            'updated' => true,
            'message' => 'Atualizacao aplicada com sucesso.',
            'version' => (string) $manifest['version'],
            'backup_path' => $backupPath,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function githubHeaders(string $token): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchManifest(string $url): array
    {
        $body = $this->httpGet($url);
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $manifest = json_decode($body, true);
        if (!is_array($manifest)) {
            $preview = trim(strip_tags(substr($body, 0, 300)));
            if (str_starts_with(ltrim($body), '<')) {
                throw new RuntimeException(
                    'A URL do manifesto respondeu HTML em vez de JSON. Verifique se o arquivo manifest.json existe na pasta publica updates. Resposta recebida: ' . $preview
                );
            }

            throw new RuntimeException('Manifesto de atualizacao nao e um JSON valido.');
        }

        return $manifest;
    }

    private function download(string $url, string $targetPath, array $headers = []): void
    {
        $body = $this->httpGet($url, $headers);
        if (strlen($body) < 100) {
            throw new RuntimeException('O pacote baixado esta vazio ou incompleto.');
        }
        if (substr($body, 0, 2) !== 'PK') {
            $preview = trim(strip_tags(substr($body, 0, 300)));
            throw new RuntimeException('GitHub nao respondeu um ZIP valido. Resposta recebida: ' . $preview);
        }
        if (file_put_contents($targetPath, $body, LOCK_EX) === false) {
            throw new RuntimeException('Nao foi possivel salvar o pacote de atualizacao.');
        }
    }

    private function httpGet(string $url, array $headers = []): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL de atualizacao invalida.');
        }

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => 'CreatorOutreachUpdater/' . $this->currentVersion(),
                CURLOPT_UNRESTRICTED_AUTH => true,
            ]);
            if ($headers !== []) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            }
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($body === false || $status >= 400) {
                $message = $error !== '' ? $error : 'Falha HTTP ao baixar atualizacao. HTTP ' . $status;
                throw new RuntimeException($message);
            }

            return (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'header' => "User-Agent: CreatorOutreachUpdater/" . $this->currentVersion() . "\r\n"
                    . implode("\r\n", $headers)
                    . ($headers === [] ? '' : "\r\n"),
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Falha HTTP ao baixar atualizacao.');
        }

        return (string) $body;
    }

    private function extract(string $zipPath, string $targetPath): void
    {
        if (!is_dir($targetPath)) {
            mkdir($targetPath, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Nao foi possivel abrir o ZIP de atualizacao.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $this->assertSafeRelativePath($name);
        }

        if (!$zip->extractTo($targetPath)) {
            $zip->close();
            throw new RuntimeException('Nao foi possivel extrair o pacote de atualizacao.');
        }
        $zip->close();
    }

    /**
     * @param array<int, string> $deleteList
     */
    private function applyPackage(string $extractPath, string $backupPath, array $deleteList): void
    {
        $sourceRoot = $this->packageRoot($extractPath);
        $files = $this->listFiles($sourceRoot);
        $this->assertWritablePlan($sourceRoot, $files);

        foreach ($deleteList as $relativePath) {
            $relativePath = $this->normalizeRelativePath((string) $relativePath);
            if ($relativePath === '' || $this->isProtectedPath($relativePath)) {
                continue;
            }
            $target = $this->root . '/' . $relativePath;
            $this->backupIfExists($target, $backupPath, $relativePath);
            if (is_file($target)) {
                unlink($target);
            }
        }

        foreach ($files as $file) {
            $relativePath = $this->normalizeRelativePath(substr($file, strlen($sourceRoot) + 1));
            if ($relativePath === '' || $this->isProtectedPath($relativePath)) {
                continue;
            }

            $target = $this->root . '/' . $relativePath;
            $this->backupIfExists($target, $backupPath, $relativePath);
            $targetDir = dirname($target);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            if (is_file($target) && !is_writable($target)) {
                @chmod($target, 0644);
            }
            if (!is_writable($targetDir)) {
                @chmod($targetDir, 0755);
            }

            $tempTarget = $target . '.updating';
            if (!copy($file, $tempTarget)) {
                $error = error_get_last();
                throw new RuntimeException('Falha ao preparar arquivo atualizado: ' . $relativePath . ' (' . ($error['message'] ?? 'sem detalhe') . ')');
            }
            if (!@rename($tempTarget, $target)) {
                if (!@copy($tempTarget, $target)) {
                    @unlink($tempTarget);
                    $error = error_get_last();
                    throw new RuntimeException('Falha ao copiar arquivo atualizado: ' . $relativePath . ' (' . ($error['message'] ?? 'sem detalhe') . ')');
                }
                @unlink($tempTarget);
            }
        }
    }

    /**
     * @param array<int, string> $files
     */
    private function assertWritablePlan(string $sourceRoot, array $files): void
    {
        $blocked = [];
        foreach ($files as $file) {
            $relativePath = $this->normalizeRelativePath(substr($file, strlen($sourceRoot) + 1));
            if ($relativePath === '' || $this->isProtectedPath($relativePath)) {
                continue;
            }

            $target = $this->root . '/' . $relativePath;
            $targetDir = dirname($target);
            $existingDir = $targetDir;
            while (!is_dir($existingDir) && dirname($existingDir) !== $existingDir) {
                $existingDir = dirname($existingDir);
            }

            if (is_file($target) && !is_writable($target)) {
                @chmod($target, 0644);
            }
            if (is_dir($existingDir) && !is_writable($existingDir)) {
                @chmod($existingDir, 0755);
            }

            if (is_file($target) && !is_writable($target)) {
                $blocked[] = $relativePath;
                continue;
            }
            if (!is_file($target) && is_dir($existingDir) && !is_writable($existingDir)) {
                $blocked[] = $relativePath;
            }
        }

        if ($blocked !== []) {
            throw new RuntimeException('Sem permissao para atualizar estes arquivos: ' . implode(', ', array_slice($blocked, 0, 8)));
        }
    }

    private function packageRoot(string $extractPath): string
    {
        $entries = array_values(array_filter(scandir($extractPath) ?: [], static fn ($item) => !in_array($item, ['.', '..'], true)));
        if (count($entries) === 1 && is_dir($extractPath . '/' . $entries[0])) {
            return $extractPath . '/' . $entries[0];
        }

        return $extractPath;
    }

    /**
     * @return array<int, string>
     */
    private function listFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = str_replace('\\', '/', $item->getPathname());
            }
        }

        return $files;
    }

    private function backupIfExists(string $target, string $backupRoot, string $relativePath): void
    {
        if (!is_file($target)) {
            return;
        }

        $backupTarget = $backupRoot . '/' . $relativePath;
        $backupDir = dirname($backupTarget);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        copy($target, $backupTarget);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        $this->assertSafeRelativePath($path);

        return $path;
    }

    private function assertSafeRelativePath(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        if (
            str_starts_with($normalized, '/') ||
            preg_match('/^[A-Za-z]:\//', $normalized) ||
            str_contains($normalized, '../') ||
            str_contains($normalized, '..\\') ||
            $normalized === '..'
        ) {
            throw new RuntimeException('Pacote contem caminho inseguro: ' . $path);
        }
    }

    private function isProtectedPath(string $relativePath): bool
    {
        return $relativePath === '.env'
            || str_starts_with($relativePath, 'storage/')
            || str_starts_with($relativePath, '.git/')
            || str_contains($relativePath, '/.git/');
    }
}
