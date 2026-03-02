<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use FireflyIII\Console\Commands\ShowsFriendlyMessages;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'Create a full backup of the Firefly III database and attachments as a portable .tar.gz archive.';

    protected $signature = 'firefly:backup
                            {--output= : Directory to save the archive (defaults to storage/backups/)}
                            {--pg-dump= : Custom path to the pg_dump binary}';

    public function handle(): int
    {
        $driver = config('database.default');
        if ('pgsql' !== $driver) {
            $this->friendlyError(sprintf('This command only supports PostgreSQL. Current DB_CONNECTION is "%s".', $driver));

            return 1;
        }

        $pgDump = $this->option('pg-dump') ?: $this->findBinary('pg_dump');
        if (null === $pgDump) {
            $this->friendlyError('pg_dump binary not found. Install PostgreSQL client tools or use --pg-dump to specify the path.');

            return 1;
        }

        $config   = config('database.connections.pgsql');
        $host     = $config['host'];
        $port     = (string) $config['port'];
        $database = $config['database'];
        $username = $config['username'];
        $password = (string) ($config['password'] ?? '');

        $timestamp  = now()->format('Y-m-d_His');
        $backupName = sprintf('firefly_backup_%s', $timestamp);
        $tempDir    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $backupName;

        if (!mkdir($tempDir, 0755, true)) {
            $this->friendlyError(sprintf('Could not create temporary directory: %s', $tempDir));

            return 1;
        }

        $this->friendlyInfo('Starting Firefly III backup...');

        $manifest = [
            'version'     => config('firefly.version'),
            'exported_at' => now()->toIso8601String(),
            'database'    => $database,
            'php_version' => PHP_VERSION,
            'db_driver'   => 'pgsql',
        ];
        file_put_contents(
            $tempDir . DIRECTORY_SEPARATOR . 'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        $this->friendlyPositive('Manifest created.');

        $sqlFile = $tempDir . DIRECTORY_SEPARATOR . 'database.sql';
        $command = sprintf(
            '%s --clean --if-exists --no-owner --no-privileges -h %s -p %s -U %s -d %s --file=%s',
            escapeshellarg($pgDump),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($sqlFile)
        );

        $this->friendlyInfo('Running pg_dump...');

        putenv('PGPASSWORD=' . $password);
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        putenv('PGPASSWORD');

        if (!is_resource($process)) {
            $this->friendlyError('Failed to execute pg_dump.');
            $this->cleanupDirectory($tempDir);

            return 1;
        }

        fclose($pipes[0]);
        $stderr   = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if (0 !== $exitCode) {
            $this->friendlyError(sprintf('pg_dump failed (exit code %d): %s', $exitCode, $stderr));
            $this->cleanupDirectory($tempDir);

            return 1;
        }

        $this->friendlyPositive(sprintf('Database dump complete (%s).', $this->formatBytes((int) filesize($sqlFile))));

        $uploadDir      = storage_path('upload');
        $attachmentsDir = $tempDir . DIRECTORY_SEPARATOR . 'attachments';
        $attachmentCount = 0;

        if (is_dir($uploadDir)) {
            mkdir($attachmentsDir, 0755, true);
            $files = glob($uploadDir . DIRECTORY_SEPARATOR . '*');
            if (false !== $files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        copy($file, $attachmentsDir . DIRECTORY_SEPARATOR . basename($file));
                        ++$attachmentCount;
                    }
                }
            }
        }

        if ($attachmentCount > 0) {
            $this->friendlyPositive(sprintf('Copied %d attachment file(s).', $attachmentCount));
        } else {
            $this->friendlyInfo('No attachment files found.');
        }

        $outputDir = $this->option('output') ?: storage_path('backups');
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            $this->friendlyError(sprintf('Could not create output directory: %s', $outputDir));
            $this->cleanupDirectory($tempDir);

            return 1;
        }

        $tarPath = $outputDir . DIRECTORY_SEPARATOR . $backupName . '.tar';

        try {
            $tar = new \PharData($tarPath);
            $tar->buildFromDirectory($tempDir);
            $tar->compress(\Phar::GZ);
            unlink($tarPath);
        } catch (\Exception $e) {
            $this->friendlyError(sprintf('Failed to create archive: %s', $e->getMessage()));
            $this->cleanupDirectory($tempDir);

            return 1;
        }

        $this->cleanupDirectory($tempDir);

        $archivePath = $tarPath . '.gz';
        $this->friendlyPositive(sprintf(
            'Backup complete: %s (%s)',
            $archivePath,
            $this->formatBytes((int) filesize($archivePath))
        ));

        return 0;
    }

    private function findBinary(string $name): ?string
    {
        $command = str_contains(PHP_OS, 'WIN') ? 'where' : 'which';
        exec(sprintf('%s %s 2>&1', $command, escapeshellarg($name)), $output, $exitCode);

        if (0 === $exitCode && !empty($output[0])) {
            return trim($output[0]);
        }

        return null;
    }

    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    private function formatBytes(int $bytes): string
    {
        if (0 === $bytes) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = 0;
        $value = (float) $bytes;
        while ($value >= 1024.0 && $i < count($units) - 1) {
            $value /= 1024.0;
            ++$i;
        }

        return sprintf('%.1f %s', $value, $units[$i]);
    }
}
