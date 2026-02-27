<?php

declare(strict_types=1);

namespace FireflyIII\Services\Internal;

use FireflyIII\Models\Preference;
use FireflyIII\User;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
    private const PREFERENCE_KEY = 'google_drive_tokens';
    private const FOLDER_MIME    = 'application/vnd.google-apps.folder';

    private ?User $user = null;

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function isConfigured(): bool
    {
        return '' !== (string) config('google-drive.client_id')
            && '' !== (string) config('google-drive.client_secret');
    }

    public function isConnected(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $tokens = $this->getStoredTokens();

        return null !== $tokens && isset($tokens['refresh_token']);
    }

    public function getAuthorizationUrl(): string
    {
        $client = $this->buildBaseClient();
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes([GoogleDrive::DRIVE_FILE]);

        return $client->createAuthUrl();
    }

    public function handleCallback(string $code): void
    {
        $client = $this->buildBaseClient();
        $token  = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            Log::error('Google Drive OAuth error: ' . ($token['error_description'] ?? $token['error']));

            throw new \RuntimeException('Google Drive authorization failed: ' . ($token['error_description'] ?? $token['error']));
        }

        $this->storeTokens($token);
    }

    public function disconnect(): void
    {
        if (null !== $this->user) {
            app('preferences')->setForUser($this->user, self::PREFERENCE_KEY, null);
        } else {
            app('preferences')->set(self::PREFERENCE_KEY, null);
        }
    }

    public function getClient(): GoogleClient
    {
        $client = $this->buildBaseClient();
        $tokens = $this->getStoredTokens();

        if (null === $tokens) {
            throw new \RuntimeException('Google Drive is not connected. Please authorize first.');
        }

        $client->setAccessToken($tokens);

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $client->getRefreshToken();
            if (null === $refreshToken) {
                throw new \RuntimeException('Google Drive refresh token is missing. Please re-authorize.');
            }

            $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            if (isset($newToken['error'])) {
                Log::error('Google Drive token refresh failed: ' . ($newToken['error_description'] ?? $newToken['error']));

                throw new \RuntimeException('Failed to refresh Google Drive token. Please re-authorize.');
            }

            if (!isset($newToken['refresh_token'])) {
                $newToken['refresh_token'] = $refreshToken;
            }

            $this->storeTokens($newToken);
        }

        return $client;
    }

    /**
     * @return string The Google Drive file ID.
     */
    public function uploadBackup(string $filePath): string
    {
        $service  = new GoogleDrive($this->getClient());
        $folderId = $this->resolveFolder($service, (string) config('google-drive.backup_folder'));

        $fileMetadata = new DriveFile([
            'name'    => basename($filePath),
            'parents' => [$folderId],
        ]);

        $content  = file_get_contents($filePath);
        if (false === $content) {
            throw new \RuntimeException('Could not read backup file: ' . $filePath);
        }

        $file = $service->files->create($fileMetadata, [
            'data'       => $content,
            'mimeType'   => 'application/gzip',
            'uploadType' => 'multipart',
            'fields'     => 'id, name',
        ]);

        Log::info(sprintf('Backup uploaded to Google Drive: %s (ID: %s)', $file->getName(), $file->getId()));

        return $file->getId();
    }

    /**
     * @return array<int, array{id: string, name: string, size: string, createdTime: string}>
     */
    public function listBackups(): array
    {
        $service  = new GoogleDrive($this->getClient());
        $folderId = $this->findFolder($service, (string) config('google-drive.backup_folder'));

        if (null === $folderId) {
            return [];
        }

        $query   = sprintf("'%s' in parents and trashed = false", $folderId);
        $results = $service->files->listFiles([
            'q'       => $query,
            'fields'  => 'files(id, name, size, createdTime)',
            'orderBy' => 'createdTime desc',
        ]);

        $backups = [];
        foreach ($results->getFiles() as $file) {
            $backups[] = [
                'id'          => $file->getId(),
                'name'        => $file->getName(),
                'size'        => $file->getSize(),
                'createdTime' => $file->getCreatedTime(),
            ];
        }

        return $backups;
    }

    public function downloadBackup(string $fileId, string $destPath): void
    {
        $service  = new GoogleDrive($this->getClient());
        $response = $service->files->get($fileId, ['alt' => 'media']);
        $content  = $response->getBody()->getContents();

        if (false === file_put_contents($destPath, $content)) {
            throw new \RuntimeException('Could not write downloaded backup to: ' . $destPath);
        }
    }

    public function deleteBackup(string $fileId): void
    {
        $service = new GoogleDrive($this->getClient());
        $service->files->delete($fileId);
    }

    /**
     * @return array{uploaded: int, files: list<string>}
     */
    public function pushBudgetPlans(): array
    {
        $service   = new GoogleDrive($this->getClient());
        $folderId  = $this->resolveFolder($service, (string) config('google-drive.budget_plans_folder'));
        $localDir  = storage_path('budget-plans');
        $uploaded  = 0;
        $fileNames = [];

        if (!is_dir($localDir)) {
            return ['uploaded' => 0, 'files' => []];
        }

        $localFiles = glob($localDir . '/*.md');
        if (false === $localFiles) {
            return ['uploaded' => 0, 'files' => []];
        }

        $existingRemote = $this->listFilesInFolder($service, $folderId);

        foreach ($localFiles as $localFile) {
            $name    = basename($localFile);
            $content = file_get_contents($localFile);
            if (false === $content) {
                continue;
            }

            if (isset($existingRemote[$name])) {
                $service->files->update($existingRemote[$name], new DriveFile(), [
                    'data'       => $content,
                    'mimeType'   => 'text/markdown',
                    'uploadType' => 'multipart',
                ]);
            } else {
                $service->files->create(new DriveFile([
                    'name'    => $name,
                    'parents' => [$folderId],
                ]), [
                    'data'       => $content,
                    'mimeType'   => 'text/markdown',
                    'uploadType' => 'multipart',
                ]);
            }

            $fileNames[] = $name;
            ++$uploaded;
        }

        return ['uploaded' => $uploaded, 'files' => $fileNames];
    }

    /**
     * @return array{downloaded: int, files: list<string>}
     */
    public function pullBudgetPlans(): array
    {
        $service    = new GoogleDrive($this->getClient());
        $folderId   = $this->findFolder($service, (string) config('google-drive.budget_plans_folder'));
        $localDir   = storage_path('budget-plans');
        $downloaded = 0;
        $fileNames  = [];

        if (null === $folderId) {
            return ['downloaded' => 0, 'files' => []];
        }

        if (!is_dir($localDir) && !mkdir($localDir, 0755, true)) {
            throw new \RuntimeException('Could not create budget plans directory: ' . $localDir);
        }

        $query   = sprintf("'%s' in parents and trashed = false", $folderId);
        $results = $service->files->listFiles([
            'q'      => $query,
            'fields' => 'files(id, name)',
        ]);

        foreach ($results->getFiles() as $file) {
            $name = $file->getName();
            if (!str_ends_with($name, '.md')) {
                continue;
            }

            $response = $service->files->get($file->getId(), ['alt' => 'media']);
            $content  = $response->getBody()->getContents();

            $destPath = $localDir . DIRECTORY_SEPARATOR . basename($name);
            file_put_contents($destPath, $content);

            $fileNames[] = $name;
            ++$downloaded;
        }

        return ['downloaded' => $downloaded, 'files' => $fileNames];
    }

    private function buildBaseClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId((string) config('google-drive.client_id'));
        $client->setClientSecret((string) config('google-drive.client_secret'));
        $client->setRedirectUri((string) config('google-drive.redirect_uri'));
        $client->setScopes([GoogleDrive::DRIVE_FILE]);
        $client->setAccessType('offline');

        return $client;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getStoredTokens(): ?array
    {
        if (null !== $this->user) {
            $pref = app('preferences')->getEncryptedForUser($this->user, self::PREFERENCE_KEY);
        } else {
            $pref = app('preferences')->getEncrypted(self::PREFERENCE_KEY);
        }

        if (!$pref instanceof Preference || null === $pref->data || '' === $pref->data) {
            return null;
        }

        $data = $pref->data;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $tokens
     */
    private function storeTokens(array $tokens): void
    {
        $json = json_encode($tokens, JSON_THROW_ON_ERROR);

        if (null !== $this->user) {
            app('preferences')->setEncrypted(self::PREFERENCE_KEY, $json);
        } else {
            app('preferences')->setEncrypted(self::PREFERENCE_KEY, $json);
        }
    }

    /**
     * Resolve a nested folder path (e.g. "FireflyIII/Backups"), creating each level if needed.
     */
    private function resolveFolder(GoogleDrive $service, string $path): string
    {
        $parts    = array_filter(explode('/', $path));
        $parentId = 'root';

        foreach ($parts as $folderName) {
            $query   = sprintf(
                "name = '%s' and mimeType = '%s' and '%s' in parents and trashed = false",
                addcslashes($folderName, "'"),
                self::FOLDER_MIME,
                $parentId
            );
            $results = $service->files->listFiles([
                'q'      => $query,
                'fields' => 'files(id)',
            ]);

            $existing = $results->getFiles();
            if (count($existing) > 0) {
                $parentId = $existing[0]->getId();
            } else {
                $folder   = $service->files->create(new DriveFile([
                    'name'     => $folderName,
                    'mimeType' => self::FOLDER_MIME,
                    'parents'  => [$parentId],
                ]), [
                    'fields' => 'id',
                ]);
                $parentId = $folder->getId();
            }
        }

        return $parentId;
    }

    /**
     * Find a nested folder path without creating it. Returns null if any level is missing.
     */
    private function findFolder(GoogleDrive $service, string $path): ?string
    {
        $parts    = array_filter(explode('/', $path));
        $parentId = 'root';

        foreach ($parts as $folderName) {
            $query   = sprintf(
                "name = '%s' and mimeType = '%s' and '%s' in parents and trashed = false",
                addcslashes($folderName, "'"),
                self::FOLDER_MIME,
                $parentId
            );
            $results = $service->files->listFiles([
                'q'      => $query,
                'fields' => 'files(id)',
            ]);

            $existing = $results->getFiles();
            if (0 === count($existing)) {
                return null;
            }

            $parentId = $existing[0]->getId();
        }

        return $parentId;
    }

    /**
     * @return array<string, string> Map of filename => file ID for files in a folder.
     */
    private function listFilesInFolder(GoogleDrive $service, string $folderId): array
    {
        $query   = sprintf("'%s' in parents and trashed = false", $folderId);
        $results = $service->files->listFiles([
            'q'      => $query,
            'fields' => 'files(id, name)',
        ]);

        $map = [];
        foreach ($results->getFiles() as $file) {
            $map[$file->getName()] = $file->getId();
        }

        return $map;
    }
}
