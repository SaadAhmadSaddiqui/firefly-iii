<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Backup;

use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Http\Middleware\IsDemoUser;
use FireflyIII\Services\Internal\GoogleDriveService;
use FireflyIII\Support\System\OAuthKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class GoogleDriveController extends Controller
{
    private GoogleDriveService $driveService;

    public function __construct()
    {
        parent::__construct();

        $this->middleware(IsDemoUser::class);
        $this->middleware(function ($request, $next) {
            $this->driveService = new GoogleDriveService();
            $this->driveService->setUser(auth()->user());

            return $next($request);
        });
    }

    public function connect(): RedirectResponse
    {
        if (!$this->driveService->isConfigured()) {
            session()->flash('error', (string) trans('firefly.gdrive_not_configured'));

            return redirect(route('backup.index'));
        }

        $url = $this->driveService->getAuthorizationUrl();

        return redirect()->away($url);
    }

    public function callback(): RedirectResponse
    {
        $code  = request()->get('code');
        $error = request()->get('error');

        if (null !== $error || null === $code) {
            Log::error('Google Drive OAuth callback error: ' . ($error ?? 'no code returned'));
            session()->flash('error', (string) trans('firefly.gdrive_auth_failed'));

            return redirect(route('backup.index'));
        }

        try {
            $this->driveService->handleCallback((string) $code);
            session()->flash('success', (string) trans('firefly.gdrive_connected'));
        } catch (\Exception $e) {
            Log::error('Google Drive OAuth callback exception: ' . $e->getMessage());
            session()->flash('error', (string) trans('firefly.gdrive_auth_failed'));
        }

        return redirect(route('backup.index'));
    }

    public function disconnect(): RedirectResponse
    {
        $this->driveService->disconnect();
        session()->flash('success', (string) trans('firefly.gdrive_disconnected'));

        return redirect(route('backup.index'));
    }

    public function backup(): RedirectResponse
    {
        if (!$this->driveService->isConnected()) {
            session()->flash('error', (string) trans('firefly.gdrive_not_connected'));

            return redirect(route('backup.index'));
        }

        $driver = config('database.default');
        if ('pgsql' !== $driver) {
            session()->flash('error', (string) trans('firefly.backup_only_postgresql'));

            return redirect(route('backup.index'));
        }

        $pgDump = $this->findBinary('pg_dump');
        if (null === $pgDump) {
            session()->flash('error', (string) trans('firefly.backup_pg_dump_not_found'));

            return redirect(route('backup.index'));
        }

        $archivePath = $this->createBackupArchive($pgDump);
        if (null === $archivePath) {
            return redirect(route('backup.index'));
        }

        try {
            $this->driveService->uploadBackup($archivePath);
            session()->flash('success', (string) trans('firefly.gdrive_backup_success'));
        } catch (\Exception $e) {
            Log::error('Google Drive backup upload failed: ' . $e->getMessage());
            session()->flash('error', (string) trans('firefly.gdrive_backup_failed'));
        }

        return redirect(route('backup.index'));
    }

    public function files(): JsonResponse
    {
        if (!$this->driveService->isConnected()) {
            return response()->json(['files' => [], 'error' => 'Not connected']);
        }

        try {
            $backups = $this->driveService->listBackups();

            return response()->json(['files' => $backups]);
        } catch (\Exception $e) {
            Log::error('Google Drive list files failed: ' . $e->getMessage());

            return response()->json(['files' => [], 'error' => $e->getMessage()]);
        }
    }

    public function restore(): RedirectResponse
    {
        if (!$this->driveService->isConnected()) {
            session()->flash('error', (string) trans('firefly.gdrive_not_connected'));

            return redirect(route('backup.index'));
        }

        $fileId = request()->get('file_id');
        if (empty($fileId)) {
            session()->flash('error', (string) trans('firefly.gdrive_no_file_selected'));

            return redirect(route('backup.index'));
        }

        $psqlBin = $this->findBinary('psql');
        if (null === $psqlBin) {
            session()->flash('error', (string) trans('firefly.backup_psql_not_found'));

            return redirect(route('backup.index'));
        }

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'firefly_gdrive_restore_' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            session()->flash('error', (string) trans('firefly.backup_temp_dir_failed'));

            return redirect(route('backup.index'));
        }

        try {
            $archivePath = $tempDir . DIRECTORY_SEPARATOR . 'backup.tar.gz';
            $this->driveService->downloadBackup((string) $fileId, $archivePath);

            $this->restoreFromArchive($archivePath, $psqlBin, $tempDir);

            $this->cleanupDirectory($tempDir);

            auth()->logout();
            session()->invalidate();
            session()->regenerateToken();
            session()->flash('success', (string) trans('firefly.backup_restore_success'));

            return redirect(route('login'));
        } catch (\Exception $e) {
            Log::error('Google Drive restore failed: ' . $e->getMessage());
            $this->cleanupDirectory($tempDir);
            session()->flash('error', (string) trans('firefly.gdrive_restore_failed'));

            return redirect(route('backup.index'));
        }
    }

    public function deleteRemote(): RedirectResponse
    {
        if (!$this->driveService->isConnected()) {
            session()->flash('error', (string) trans('firefly.gdrive_not_connected'));

            return redirect(route('backup.index'));
        }

        $fileId = request()->get('file_id');
        if (empty($fileId)) {
            session()->flash('error', (string) trans('firefly.gdrive_no_file_selected'));

            return redirect(route('backup.index'));
        }

        try {
            $this->driveService->deleteBackup((string) $fileId);
            session()->flash('success', (string) trans('firefly.gdrive_delete_success'));
        } catch (\Exception $e) {
            Log::error('Google Drive delete failed: ' . $e->getMessage());
            session()->flash('error', (string) trans('firefly.gdrive_delete_failed'));
        }

        return redirect(route('backup.index'));
    }

    public function pushPlans(): RedirectResponse
    {
        if (!$this->driveService->isConnected()) {
            session()->flash('error', (string) trans('firefly.gdrive_not_connected'));

            return redirect(route('backup.index'));
        }

        try {
            $result = $this->driveService->pushBudgetPlans();
            session()->flash('success', (string) trans('firefly.gdrive_push_plans_success', ['count' => $result['uploaded']]));
        } catch (\Exception $e) {
            Log::error('Google Drive push budget plans failed: ' . $e->getMessage());
            session()->flash('error', (string) trans('firefly.gdrive_push_plans_failed'));
        }

        return redirect(route('backup.index'));
    }

    public function pullPlans(): RedirectResponse
    {
        if (!$this->driveService->isConnected()) {
            session()->flash('error', (string) trans('firefly.gdrive_not_connected'));

            return redirect(route('backup.index'));
        }

        try {
            $result = $this->driveService->pullBudgetPlans();
            session()->flash('success', (string) trans('firefly.gdrive_pull_plans_success', ['count' => $result['downloaded']]));
        } catch (\Exception $e) {
            Log::error('Google Drive pull budget plans failed: ' . $e->getMessage());
            session()->flash('error', (string) trans('firefly.gdrive_pull_plans_failed'));
        }

        return redirect(route('backup.index'));
    }

    private function createBackupArchive(string $pgDump): ?string
    {
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
            session()->flash('error', (string) trans('firefly.backup_temp_dir_failed'));

            return null;
        }

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

        $env     = $this->buildEnv(['PGPASSWORD' => $password]);
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);

        if (!is_resource($process)) {
            $this->cleanupDirectory($tempDir);
            session()->flash('error', (string) trans('firefly.backup_pg_dump_failed'));

            return null;
        }

        fclose($pipes[0]);
        $stderr   = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if (0 !== $exitCode) {
            Log::error(sprintf('pg_dump failed (exit code %d): %s', $exitCode, $stderr));
            $this->cleanupDirectory($tempDir);
            session()->flash('error', (string) trans('firefly.backup_pg_dump_failed'));

            return null;
        }

        $uploadDir      = storage_path('upload');
        $attachmentsDir = $tempDir . DIRECTORY_SEPARATOR . 'attachments';
        if (is_dir($uploadDir)) {
            mkdir($attachmentsDir, 0755, true);
            $files = glob($uploadDir . DIRECTORY_SEPARATOR . '*');
            if (false !== $files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        copy($file, $attachmentsDir . DIRECTORY_SEPARATOR . basename($file));
                    }
                }
            }
        }

        $outputDir = storage_path('backups');
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $tarPath = $outputDir . DIRECTORY_SEPARATOR . $backupName . '.tar';

        try {
            $tar = new \PharData($tarPath);
            $tar->buildFromDirectory($tempDir);
            $tar->compress(\Phar::GZ);
            unlink($tarPath);
        } catch (\Exception $e) {
            Log::error(sprintf('Failed to create backup archive: %s', $e->getMessage()));
            $this->cleanupDirectory($tempDir);
            session()->flash('error', (string) trans('firefly.backup_archive_failed'));

            return null;
        }

        $this->cleanupDirectory($tempDir);

        return $tarPath . '.gz';
    }

    private function restoreFromArchive(string $archivePath, string $psqlBin, string $workDir): void
    {
        $extractDir = $workDir . DIRECTORY_SEPARATOR . 'contents';
        mkdir($extractDir, 0755, true);

        $phar = new \PharData($archivePath);
        $phar->extractTo($extractDir);

        $contentDir = $this->locateContentDirectory($extractDir);
        if (null === $contentDir) {
            throw new \RuntimeException('Invalid backup archive: manifest.json not found.');
        }

        $sqlFile = $contentDir . DIRECTORY_SEPARATOR . 'database.sql';
        if (!file_exists($sqlFile)) {
            throw new \RuntimeException('Invalid backup archive: database.sql not found.');
        }

        $config   = config('database.connections.pgsql');
        $host     = $config['host'];
        $port     = (string) $config['port'];
        $database = $config['database'];
        $username = $config['username'];
        $password = (string) ($config['password'] ?? '');

        $command = sprintf(
            '%s -q -h %s -p %s -U %s -d %s -f %s',
            escapeshellarg($psqlBin),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($sqlFile)
        );

        $env     = $this->buildEnv(['PGPASSWORD' => $password]);
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to execute psql.');
        }

        fclose($pipes[0]);
        $stderr   = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if (0 !== $exitCode) {
            throw new \RuntimeException(sprintf('psql restore failed (exit code %d): %s', $exitCode, $stderr));
        }

        $attachmentsDir = $contentDir . DIRECTORY_SEPARATOR . 'attachments';
        $uploadDir      = storage_path('upload');

        if (is_dir($attachmentsDir)) {
            if (is_dir($uploadDir)) {
                $existingFiles = glob($uploadDir . DIRECTORY_SEPARATOR . '*');
                if (false !== $existingFiles) {
                    foreach ($existingFiles as $existing) {
                        if (is_file($existing)) {
                            unlink($existing);
                        }
                    }
                }
            } elseif (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $restoredFiles = glob($attachmentsDir . DIRECTORY_SEPARATOR . '*');
            if (false !== $restoredFiles) {
                foreach ($restoredFiles as $restoredFile) {
                    if (is_file($restoredFile)) {
                        copy($restoredFile, $uploadDir . DIRECTORY_SEPARATOR . basename($restoredFile));
                    }
                }
            }
        }

        try {
            OAuthKeys::verifyKeysRoutine();
        } catch (\Exception $e) {
            Log::warning(sprintf('Could not verify OAuth keys after restore: %s', $e->getMessage()));
        }
    }

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
