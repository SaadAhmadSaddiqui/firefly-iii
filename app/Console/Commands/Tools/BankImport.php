<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use FireflyIII\Services\BankImport\BankImportService;
use FireflyIII\User;
use Illuminate\Console\Command;

/**
 * Import bank exports from the command line through exactly the same pipeline as the
 * Bank Import pages. Always try --dry-run first: it reports what would be created, skipped
 * and refreshed without storing anything.
 *
 *   php artisan firefly:bank-import enbd nbd-aug-oct.json nbd-nov.json --dry-run
 *   php artisan firefly:bank-import ei ei-part-1.json ei-part-2.json
 *   php artisan firefly:bank-import mashreq mashreq.json
 *   php artisan firefly:bank-import fab fab.csv
 *
 * Import Emirates NBD first: card bill payments become NBD → card transfers from that export,
 * and the card-side copies are skipped by the card parsers.
 */
class BankImport extends Command
{
    protected $description = 'Import Emirates NBD / Emirates Islamic / Mashreq / FAB exports (same pipeline as the Bank Import pages).';

    protected $signature = 'firefly:bank-import
                            {bank : enbd, ei, mashreq or fab}
                            {files* : One or more export files}
                            {--account= : Firefly asset account ID (defaults: enbd=1, ei=49, mashreq=50, fab=51)}
                            {--format=json : Mashreq only: json or csv}
                            {--user=1 : Firefly III user ID}
                            {--dry-run : Show what would happen without storing anything}';

    public function handle(BankImportService $service): int
    {
        $bank = strtolower((string) $this->argument('bank'));
        if (!array_key_exists($bank, BankImportService::DEFAULT_ACCOUNTS)) {
            $this->error(sprintf('Unknown bank "%s". Use one of: %s.', $bank, implode(', ', array_keys(BankImportService::DEFAULT_ACCOUNTS))));

            return self::FAILURE;
        }
        $user = User::find((int) $this->option('user'));
        if (null === $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }
        auth()->setUser($user);

        $dryRun  = (bool) $this->option('dry-run');
        $account = null !== $this->option('account') ? (int) $this->option('account') : null;
        $totals  = ['created' => 0, 'updated' => 0, 'duplicates' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ((array) $this->argument('files') as $file) {
            if (!is_readable($file)) {
                $this->error(sprintf('Cannot read %s', $file));

                return self::FAILURE;
            }
            $parsed = $service->parse($bank, (string) file_get_contents($file), $account, (string) $this->option('format'));
            $result = $service->import($user, $parsed['transactions'], $dryRun);

            $types = array_count_values(array_map(static fn (array $t): string => (string) $t['type'], $parsed['transactions']));
            $this->info(sprintf('%s%s: %d parsed %s | %d skipped | %d created, %d updated, %d duplicate, %d failed',
                $dryRun ? '[DRY RUN] ' : '', basename($file), count($parsed['transactions']), json_encode($types),
                count($parsed['skipped']), $result['created'], $result['updated'], $result['duplicates'], $result['failed']));

            foreach (array_count_values(array_map(static fn (array $s): string => (string) ($s['reason'] ?? '?'), $parsed['skipped'])) as $reason => $count) {
                $this->line(sprintf('    skipped %2d × %s', $count, $reason));
            }
            foreach ($result['details'] as $detail) {
                if (in_array($detail['status'], ['updated', 'failed'], true)) {
                    $this->line(sprintf('    %-9s %s %10s %s %s', $detail['status'], $detail['date'], $detail['amount'], $detail['description'], $detail['message']));
                }
            }
            foreach ($parsed['transactions'] as $txn) {
                if (in_array('possible-duplicate', $txn['tags'] ?? [], true) || in_array('unidentified-card', $txn['tags'] ?? [], true)) {
                    $this->warn(sprintf('    check     %s %10s %s (%s)', $txn['date']->format('Y-m-d'), $txn['amount'], $txn['description'], implode(', ', array_intersect($txn['tags'], ['possible-duplicate', 'unidentified-card']))));
                }
            }

            foreach (['created', 'updated', 'duplicates', 'failed'] as $key) {
                $totals[$key] += $result[$key];
            }
            $totals['skipped'] += count($parsed['skipped']);
        }

        $this->newLine();
        $this->info(sprintf('%sTotal: %d created, %d updated, %d duplicate, %d skipped, %d failed.', $dryRun ? '[DRY RUN] ' : '', $totals['created'], $totals['updated'], $totals['duplicates'], $totals['skipped'], $totals['failed']));

        return 0 === $totals['failed'] ? self::SUCCESS : self::FAILURE;
    }
}
