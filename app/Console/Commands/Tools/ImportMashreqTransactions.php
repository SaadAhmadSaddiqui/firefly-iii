<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use Carbon\Carbon;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Models\Account;
use FireflyIII\Services\BankImport\MashreqParser;
use FireflyIII\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportMashreqTransactions extends Command
{
    protected $description = 'Import transactions from Mashreq credit card CSV export into Firefly III.';

    protected $signature = 'firefly:import-mashreq
                            {file : Path to mashreq-transactions.csv}
                            {--source-account-id=50 : Firefly III asset account ID for Mashreq Cashback Card}
                            {--dry-run : Preview what would be created without making changes}';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!file_exists($file) || !is_readable($file)) {
            $this->error(sprintf('File not found or not readable: %s', $file));

            return 1;
        }

        $raw = file_get_contents($file);
        if (false === $raw) {
            $this->error('Could not read file.');

            return 1;
        }

        $sourceAccountId = (int) $this->option('source-account-id');
        $sourceAccount   = Account::find($sourceAccountId);
        if (!$sourceAccount) {
            $this->error(sprintf('Source asset account #%d not found.', $sourceAccountId));

            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');

        $parser = new MashreqParser();
        $result = $parser->parse($raw, $sourceAccountId);

        foreach ($result['skipped'] as $skip) {
            $this->line(sprintf('  <comment>SKIP</comment>  %s — %s', $skip['description'], $skip['reason']));
        }

        /** @var User $user */
        $user = auth()->user() ?? User::first();

        $stats = ['withdrawal' => 0, 'deposit' => 0, 'transfer' => 0, 'skipped' => count($result['skipped']), 'failed' => 0];

        foreach ($result['transactions'] as $mapped) {
            $typeLabel = $mapped['type'];
            $dir       = match ($typeLabel) {
                'deposit'  => 'IN ',
                'transfer' => 'XFR',
                default    => 'OUT',
            };
            $displayDate = $mapped['date'] instanceof Carbon ? $mapped['date']->format('Y-m-d') : (string) $mapped['date'];
            $line = sprintf(
                '  [%s] %s | %s %.2f | %s → %s | %s',
                $dir,
                $displayDate,
                $mapped['currency_code'],
                (float) $mapped['amount'],
                $this->truncate($mapped['source_name'] ?? "(#{$sourceAccountId})", 30),
                $this->truncate($mapped['destination_name'] ?? "(#{$sourceAccountId})", 35),
                $this->truncate($mapped['description'], 50),
            );

            if ($dryRun) {
                $this->line($line);
                ++$stats[$typeLabel];

                continue;
            }

            try {
                $groupData = [
                    'user'                    => $user,
                    'user_group'              => $user->userGroup,
                    'group_title'             => null,
                    'error_if_duplicate_hash' => true,
                    'apply_rules'             => false,
                    'fire_webhooks'           => false,
                    'transactions'            => [$mapped],
                ];

                /** @var TransactionGroupFactory $factory */
                $factory = app(TransactionGroupFactory::class);
                $factory->setUser($user);
                $factory->create($groupData);

                $this->info($line);
                ++$stats[$typeLabel];
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                if (str_contains(strtolower($msg), 'duplicate')) {
                    $this->line(sprintf('  <comment>DUP</comment>   %s', $this->truncate($mapped['description'], 60)));
                    ++$stats['skipped'];
                } else {
                    $this->error(sprintf('  FAIL  %s: %s', $this->truncate($mapped['description'], 40), $msg));
                    Log::error(sprintf('ImportMashreq failed: %s — %s', $mapped['description'], $msg));
                    ++$stats['failed'];
                }
            }
        }

        $this->newLine();
        $total = $stats['withdrawal'] + $stats['deposit'] + $stats['transfer'];
        if ($dryRun) {
            $this->comment(sprintf(
                'Dry run complete. %d transactions (%d withdrawals, %d deposits, %d transfers). %d skipped.',
                $total, $stats['withdrawal'], $stats['deposit'], $stats['transfer'], $stats['skipped'],
            ));
        } else {
            $this->info(sprintf(
                'Done. Created %d transactions (%d withdrawals, %d deposits, %d transfers). Skipped %d, Failed %d.',
                $total, $stats['withdrawal'], $stats['deposit'], $stats['transfer'], $stats['skipped'], $stats['failed'],
            ));
        }

        return 0;
    }

    private function truncate(string $str, int $len): string
    {
        return mb_strlen($str) > $len ? mb_substr($str, 0, $len - 1) . '…' : $str;
    }
}
