<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use Carbon\Carbon;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportFabTransactions extends Command
{
    protected $description = 'Import transactions from FAB credit card CSV export into Firefly III.';

    protected $signature = 'firefly:import-fab
                            {file : Path to fab-transactions.csv}
                            {--source-account-id=51 : Firefly III asset account ID for FAB Cashback Card}
                            {--dry-run : Preview what would be created without making changes}';

    private array $expenseAccountCache = [];
    private array $revenueAccountCache = [];

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!file_exists($file) || !is_readable($file)) {
            $this->error(sprintf('File not found or not readable: %s', $file));

            return 1;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $lines || count($lines) < 2) {
            $this->error('CSV is empty or unreadable.');

            return 1;
        }

        array_shift($lines);

        $sourceAccountId = (int) $this->option('source-account-id');
        $sourceAccount   = Account::find($sourceAccountId);
        if (!$sourceAccount) {
            $this->error(sprintf('Source asset account #%d not found.', $sourceAccountId));

            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->loadAccountCaches();

        /** @var User $user */
        $user = auth()->user() ?? User::first();

        // Reverse the lines so we import oldest-first (CSV is newest-first)
        $lines = array_reverse($lines);

        $stats = ['withdrawal' => 0, 'deposit' => 0, 'transfer' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($lines as $lineIdx => $rawLine) {
            $cols = str_getcsv($rawLine, ',', '"', '');
            if (count($cols) < 5) {
                continue;
            }

            $mapped = $this->mapRow($cols, $sourceAccountId, $lineIdx + 2);
            if (null === $mapped) {
                ++$stats['skipped'];

                continue;
            }

            $typeLabel = $mapped['type'];
            $dir       = match ($typeLabel) {
                'deposit'  => 'IN ',
                'transfer' => 'XFR',
                default    => 'OUT',
            };
            $displayDate = $mapped['date'] instanceof Carbon ? $mapped['date']->format('Y-m-d') : (string) $mapped['date'];
            $line = sprintf(
                '  [%s] %s | AED %.2f | %s → %s | %s',
                $dir,
                $displayDate,
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
                    Log::error(sprintf('ImportFab failed: %s — %s', $mapped['description'], $msg));
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

    /**
     * Map a CSV row [Posting Date, Value date, Description, Debit Amount, Credit Amount]
     */
    private function mapRow(array $cols, int $sourceAccountId, int $csvLine): ?array
    {
        $postingDate = trim($cols[0]);
        $description = trim($cols[1]);
        $rawDesc     = trim($cols[2]);
        $debitStr    = trim($cols[3]);
        $creditStr   = trim($cols[4]);

        $debit  = (float) str_replace(',', '', $debitStr);
        $credit = (float) str_replace(',', '', $creditStr);

        // Skip card payment rows (already imported as transfers from NBD)
        if (stripos($rawDesc, 'Card Payment') !== false && $credit > 0) {
            $this->line(sprintf('  <comment>SKIP</comment>  %s CC payment already imported as transfer (%.2f AED)', $postingDate, $credit));

            return null;
        }

        $isDebit = $debit > 0;
        $amount  = $isDebit ? $debit : $credit;

        if ($amount <= 0) {
            return null;
        }

        // Parse date: " dd/mm/yyyy"
        $carbonDate = Carbon::createFromFormat('d/m/Y', $postingDate, 'Asia/Dubai')->startOfDay();

        // Extract reference number and merchant from description
        // Format: "2949491030 - MERCHANT NAME  CITY  CODE"
        [$refNumber, $merchantRaw] = $this->parseDescription($rawDesc);
        $merchantName = $this->cleanMerchantName($merchantRaw);

        $externalId = md5(sprintf('%s|%s|%.2f|%d', $postingDate, $rawDesc, $isDebit ? -$amount : $amount, $csvLine));

        $notes = sprintf("FAB CSV line %d\nRef: %s\nDescription: %s", $csvLine, $refNumber, $rawDesc);

        $tags = ['fab-cc'];
        if ($this->isFee($rawDesc)) {
            $tags[] = 'bank-fee';
        }

        if ($isDebit) {
            $expenseName = $this->isFee($rawDesc)
                ? 'FAB Card Fees'
                : $this->matchExpenseAccount($merchantName);

            return [
                'type'                  => 'withdrawal',
                'date'                  => $carbonDate,
                'amount'                => (string) $amount,
                'currency_code'         => 'AED',
                'description'           => $merchantName,
                'source_id'             => $sourceAccountId,
                'source_name'           => null,
                'destination_id'        => null,
                'destination_name'      => $expenseName,
                'tags'                  => $tags,
                'notes'                 => $notes,
                'external_id'           => $externalId,
                'internal_reference'    => $refNumber,
            ];
        }

        // Credit (non-card-payment): refund or other credit
        $revenueName = $this->matchRevenueAccount($merchantName);

        return [
            'type'                  => 'deposit',
            'date'                  => $carbonDate,
            'amount'                => (string) $amount,
            'currency_code'         => 'AED',
            'description'           => $merchantName,
            'source_id'             => null,
            'source_name'           => $revenueName,
            'destination_id'        => $sourceAccountId,
            'destination_name'      => null,
            'tags'                  => $tags,
            'notes'                 => $notes,
            'external_id'           => $externalId,
            'internal_reference'    => $refNumber,
        ];
    }

    private function parseDescription(string $desc): array
    {
        if (preg_match('/^(\d+)\s*-\s*(.+)$/s', $desc, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return ['', $desc];
    }

    private function cleanMerchantName(string $raw): string
    {
        // Strip everything from the first run of 2+ spaces onward
        // e.g. "FIND SALT              Dubai         ARE" → "FIND SALT"
        // e.g. "CARREFOUR HYPERMARKET  SHARJAH       UAE" → "CARREFOUR HYPERMARKET"
        $cleaned = preg_replace('/\s{2,}.*$/', '', $raw) ?? $raw;

        // Handle edge case: "CAESARS REST AND CONFE SHARJAH       SHJ"
        // After the above: "CAESARS REST AND CONFE SHARJAH" — strip known trailing cities
        $cities = [
            'SHARJAH', 'DUBAI', 'ABU DHABI', 'ABUDHABI', 'AJMAN',
            'REDMOND', 'ALMATY', 'HELSINKI', 'CORK',
        ];
        foreach ($cities as $city) {
            $cleaned = preg_replace('/\s+' . preg_quote($city, '/') . '\s*$/i', '', $cleaned) ?? $cleaned;
        }

        $cleaned = trim($cleaned);

        if ('' === $cleaned) {
            return trim($raw);
        }

        return $this->cleanName($cleaned);
    }

    private function isFee(string $desc): bool
    {
        $upper = strtoupper($desc);

        return str_contains($upper, 'MEMBERSHIP FEE')
            || str_contains($upper, 'VAT ON MEMBERSHIP')
            || str_contains($upper, 'VAT ON SERVICE')
            || str_contains($upper, 'SERVICE CHARGES');
    }

    // ─── Shared helpers ───

    private function cleanName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        $words  = explode(' ', $name);
        $result = [];
        foreach ($words as $word) {
            if (mb_strtoupper($word) === $word && mb_strlen($word) > 3) {
                $result[] = mb_convert_case($word, MB_CASE_TITLE);
            } else {
                $result[] = $word;
            }
        }

        return implode(' ', $result);
    }

    private function matchExpenseAccount(string $name): string
    {
        $lower = mb_strtolower(trim($name));

        foreach ($this->expenseAccountCache as $accountName) {
            if (mb_strtolower($accountName) === $lower) {
                return $accountName;
            }
            if (str_contains(mb_strtolower($accountName), $lower)) {
                return $accountName;
            }
        }

        return $this->cleanName($name);
    }

    private function matchRevenueAccount(string $name): string
    {
        $lower = mb_strtolower(trim($name));

        foreach ($this->revenueAccountCache as $accountName) {
            if (mb_strtolower($accountName) === $lower) {
                return $accountName;
            }
            if (str_contains(mb_strtolower($accountName), $lower)) {
                return $accountName;
            }
            if (str_contains($lower, mb_strtolower($accountName))) {
                return $accountName;
            }
        }

        return $this->cleanName($name);
    }

    private function loadAccountCaches(): void
    {
        $expenseType = AccountType::where('type', 'Expense account')->first();
        if ($expenseType) {
            $this->expenseAccountCache = Account::where('account_type_id', $expenseType->id)
                ->whereNull('deleted_at')
                ->pluck('name')
                ->toArray();
        }

        $revenueType = AccountType::where('type', 'Revenue account')->first();
        if ($revenueType) {
            $this->revenueAccountCache = Account::where('account_type_id', $revenueType->id)
                ->whereNull('deleted_at')
                ->pluck('name')
                ->toArray();
        }
    }

    private function truncate(string $str, int $len): string
    {
        return mb_strlen($str) > $len ? mb_substr($str, 0, $len - 1) . '…' : $str;
    }
}
