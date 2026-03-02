<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use FireflyIII\Console\Commands\ShowsFriendlyMessages;
use FireflyIII\Support\System\OAuthKeys;
use Illuminate\Console\Command;

class RestoreDatabase extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'Restore a Firefly III backup from a .tar.gz archive created by firefly:backup.';

    protected $signature = 'firefly:restore
                            {file : Path to the backup .tar.gz archive}
                            {--force : Skip confirmation prompt}
                            {--psql= : Custom path to the psql binary}';

    public function handle(): int
    {
        $driver = config('database.default');
        if ('pgsql' !== $driver) {
            $this->friendlyError(sprintf('This command only supports PostgreSQL. Current DB_CONNECTION is "%s".', $driver));

            return 1;
        }

        $psql = $this->option('psql') ?: $this->findBinary('psql');
        if (null === $psql) {
            $this->friendlyError('psql binary not found. Install PostgreSQL client tools or use --psql to specify the path.');

            return 1;
        }

        $archivePath = (string) $this->argument('file');
        if (!file_exists($archivePath) || !is_readable($archivePath)) {
            $this->friendlyError(sprintf('File not found or not readable: %s', $archivePath));

            return 1;
        }

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'firefly_restore_' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            $this->friendlyError(sprintf('Could not create temporary directory: %s', $tempDir));

            return 1;
        }

        $this->friendlyInfo('Extracting backup archive...');

        try {
            $phar = new \PharData($archivePath);
            $phar->extractTo($tempDir);
        } catch (\Exception $e) {
            $this->friendlyError(sprintf('Failed to extract archive: %s', $e->getMessage()));
            $this->cleanupDirectory($tempDir);

            return 1;
        }

        $contentDir = $this->locateContentDirectory($tempDir);
        if (null === $contentDir) {
            $this->friendlyError('Invalid backup archive: manifest.json not found.');
            $this->cleanupDirectory($tempDir);

            return 1;
        }

        $manifestPath = $contentDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $raw          = file_get_contents($manifestPath);
        if (false === $raw) {
            $this->friendlyError('Could not read manifest.json.');
            $this->cleanupDirectory($tempDir);

            return 1;
        }

        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            $this->friendlyError('Invalid backup archive: manifest.json is malformed.');
            $this->cleanupDirectory($tempDir);

            return 1;
        }

        $sqlFile = $contentDir . DIRECTORY_SEPARATOR . 'database.sql';
        if (!file_exists($sqlFile)) {
            $this->friendlyError('Invalid backup archive: database.sql not found.');
            $this->cleanupDirectory($tempDir);

            return 1;
        }

        $this->friendlyInfo(sprintf('Backup from:          %s', $manifest['exported_at'] ?? 'unknown'));
        $this->friendlyInfo(sprintf('Firefly III version:  %s', $manifest['version'] ?? 'unknown'));
        $this->friendlyInfo(sprintf('Source database:      %s', $manifest['database'] ?? 'unknown'));

        if (!$this->option('force')) {
            $this->friendlyWarning('This will REPLACE ALL DATA in the current database.');
            $this->friendlyWarning('This action cannot be undone.');
            if (!$this->confirm('Do you want to continue?')) {
                $this->friendlyInfo('Restore cancelled.');
                $this->cleanupDirectory($tempDir);

                return 0;
            }
        }

        $config   = config('database.connections.pgsql');
        $host     = $config['host'];
        $port     = (string) $config['port'];
        $database = $config['database'];
        $username = $config['username'];
        $password = (string) ($config['password'] ?? '');

        $command = sprintf(
            '%s -q -h %s -p %s -U %s -d %s -f %s',
            escapeshellarg($psql),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($sqlFile)
        );

        $this->friendlyInfo('Restoring database (this may take a while)...');

        $env = $this->buildEnv(['PGPASSWORD' => $password]);
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);

        if (!is_resource($process)) {
            $this->friendlyError('Failed to execute psql.');
            $this->cleanupDirectory($tempDir);

            return 1;
        }

        fclose($pipes[0]);
        $stderr   = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if (0 !== $exitCode) {
            $this->friendlyError(sprintf('psql failed (exit code %d): %s', $exitCode, $stderr));
            $this->cleanupDirectory($tempDir);

            return 1;
        }

        $this->friendlyPositive('Database restored successfully.');

        $attachmentsDir  = $contentDir . DIRECTORY_SEPARATOR . 'attachments';
        $uploadDir       = storage_path('upload');
        $attachmentCount = 0;

        if (is_dir($attachmentsDir)) {
            if (is_dir($uploadDir)) {
                $existingFiles = glob($uploadDir . DIRECTORY_SEPARATOR . '*');
                if (false !== $existingFiles) {
                    foreach ($existingFiles as $file) {
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }
                }
            } elseif (!mkdir($uploadDir, 0755, true)) {
                $this->friendlyWarning(sprintf('Could not create upload directory: %s', $uploadDir));
            }

            $files = glob($attachmentsDir . DIRECTORY_SEPARATOR . '*');
            if (false !== $files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        copy($file, $uploadDir . DIRECTORY_SEPARATOR . basename($file));
                        ++$attachmentCount;
                    }
                }
            }
        }

        if ($attachmentCount > 0) {
            $this->friendlyPositive(sprintf('Restored %d attachment file(s).', $attachmentCount));
        } else {
            $this->friendlyInfo('No attachment files to restore.');
        }

        try {
            OAuthKeys::verifyKeysRoutine();
            $this->friendlyPositive('OAuth keys verified.');
        } catch (\Exception $e) {
            $this->friendlyWarning(sprintf('Could not verify OAuth keys: %s', $e->getMessage()));
            $this->friendlyWarning('You may need to run "php artisan passport:keys" manually.');
        }

        $this->cleanupDirectory($tempDir);

        $this->friendlyPositive('Restore complete!');

        return 0;
    }

    /**
     * PharData::extractTo may place files at the root of $dir or inside a
     * single subdirectory depending on how the archive was built. This
     * method returns the directory that actually contains manifest.json.
     */
    private function locateContentDirectory(string $dir): ?string
    {
        if (file_exists($dir . DIRECTORY_SEPARATOR . 'manifest.json')) {
            return $dir;
        }

        $subdirs = glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        if (false !== $subdirs) {
            foreach ($subdirs as $subdir) {
                if (file_exists($subdir . DIRECTORY_SEPARATOR . 'manifest.json')) {
                    return $subdir;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function buildEnv(array $extra): array
    {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }

        return array_merge($env, $extra);
    }

    private function findBinary(string $name): ?string
    {
        if (str_contains(PHP_OS, 'WIN')) {
            return $this->findBinaryWindows($name);
        }

        return $this->findBinaryUnix($name);
    }

    private function findBinaryUnix(string $name): ?string
    {
        foreach (['which', 'command -v'] as $lookup) {
            $output   = [];
            $exitCode = 1;
            exec(sprintf('%s %s 2>/dev/null', $lookup, escapeshellarg($name)), $output, $exitCode);
            if (0 === $exitCode && !empty($output[0])) {
                return trim($output[0]);
            }
        }

        foreach (['/usr/bin', '/usr/local/bin', '/usr/lib/postgresql/17/bin', '/usr/lib/postgresql/16/bin'] as $dir) {
            $candidate = $dir . '/' . $name;
            if (file_exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function findBinaryWindows(string $name): ?string
    {
        $output   = [];
        $exitCode = 1;
        exec(sprintf('where %s 2>&1', escapeshellarg($name)), $output, $exitCode);
        if (0 === $exitCode && !empty($output[0])) {
            return trim($output[0]);
        }

        $exe          = $name . '.exe';
        $programFiles = [
            getenv('ProgramFiles') ?: 'C:\\Program Files',
            getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)',
        ];
        foreach ($programFiles as $root) {
            $pgDir = $root . DIRECTORY_SEPARATOR . 'PostgreSQL';
            if (!is_dir($pgDir)) {
                continue;
            }
            $versions = glob($pgDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            if (false === $versions) {
                continue;
            }
            rsort($versions);
            foreach ($versions as $ver) {
                $candidate = $ver . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $exe;
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
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
}
