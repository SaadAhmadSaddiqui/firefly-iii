<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use FireflyIII\Console\Commands\ShowsFriendlyMessages;
use FireflyIII\Services\Internal\GoogleDriveService;
use FireflyIII\User;
use Illuminate\Console\Command;

class BackupRemote extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'Create a full backup and upload it to Google Drive.';

    protected $signature = 'firefly:backup-remote
                            {--user= : User ID whose Google Drive tokens to use (defaults to first admin)}
                            {--pg-dump= : Custom path to the pg_dump binary}';

    public function handle(): int
    {
        $user = $this->resolveUser();
        if (null === $user) {
            $this->friendlyError('No valid user found. Use --user=ID to specify a user.');

            return 1;
        }

        $service = new GoogleDriveService();
        $service->setUser($user);

        if (!$service->isConfigured()) {
            $this->friendlyError('Google Drive is not configured. Set GOOGLE_DRIVE_CLIENT_ID and GOOGLE_DRIVE_CLIENT_SECRET in .env.');

            return 1;
        }

        if (!$service->isConnected()) {
            $this->friendlyError('Google Drive is not connected. Log into the web UI and connect via the Backup page first.');

            return 1;
        }

        $this->friendlyInfo('Creating local backup first...');
        $exitCode = $this->call('firefly:backup', [
            '--pg-dump' => $this->option('pg-dump'),
        ]);

        if (0 !== $exitCode) {
            $this->friendlyError('Local backup failed. Cannot upload to Google Drive.');

            return 1;
        }

        $archivePath = $this->findLatestBackup();
        if (null === $archivePath) {
            $this->friendlyError('No backup archive found in storage/backups/.');

            return 1;
        }

        $this->friendlyInfo(sprintf('Uploading %s to Google Drive...', basename($archivePath)));

        try {
            $fileId = $service->uploadBackup($archivePath);
            $this->friendlyPositive(sprintf('Backup uploaded to Google Drive (ID: %s).', $fileId));
        } catch (\Exception $e) {
            $this->friendlyError(sprintf('Upload failed: %s', $e->getMessage()));

            return 1;
        }

        return 0;
    }

    private function resolveUser(): ?User
    {
        $userId = $this->option('user');

        if (null !== $userId) {
            return User::find((int) $userId);
        }

        return User::first();
    }

    private function findLatestBackup(): ?string
    {
        $dir   = storage_path('backups');
        $files = glob($dir . '/firefly_backup_*.tar.gz');

        if (false === $files || 0 === count($files)) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }
}
