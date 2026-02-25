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

class ImportMashreqTransactions extends Command
{
    protected $description = 'Import transactions from Mashreq credit card CSV export into Firefly III.';

    protected $signature = 'firefly:import-mashreq
                            {file : Path to mashreq-transactions.csv}
                            {--source-account-id=50 : Firefly III asset account ID for Mashreq Cashback Card}
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

        array_shift($lines); // remove header

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

        $stats = ['withdrawal' => 0, 'deposit' => 0, 'transfer' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($lines as $lineNum => $rawLine) {
            $cols = str_getcsv($rawLine, ',', '"', '');
            if (count($cols) < 5) {
                continue;
            }

            $mapped = $this->mapRow($cols, $sourceAccountId, $lineNum + 2);
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

    /**
     * Map a CSV row [Date, Description, Currency, OriginalAmount, LocalAmount]
     * into a Firefly III transaction array.
     */
    private function mapRow(array $cols, int $sourceAccountId, int $csvLine): ?array
    {
        [$dateStr, $description, $origCurrency, $origAmountStr, $localAmountStr] = $cols;

        $description = trim($description);
        $localAmount = (float) $localAmountStr;
        $origAmount  = (float) $origAmountStr;
        $origCurrency = strtoupper(trim($origCurrency));

        if (0.0 === $localAmount) {
            return null;
        }

        // Skip INWARD IPP CC (credit card payments from bank — already imported as transfers)
        if (stripos($description, 'INWARD IPP CC') !== false) {
            $this->line(sprintf('  <comment>SKIP</comment>  %s CC payment already imported as transfer (%.2f AED)', $dateStr, $localAmount));

            return null;
        }

        // Support both "2025-09-22" and "22-Sep-2025" date formats
        try {
            $carbonDate = Carbon::createFromFormat('d-M-Y', trim($dateStr), 'Asia/Dubai');
        } catch (\Exception $e) {
            $carbonDate = Carbon::createFromFormat('Y-m-d', trim($dateStr), 'Asia/Dubai');
        }
        $carbonDate = $carbonDate->startOfDay();
        $absAmount  = abs($localAmount);
        $isCredit   = $localAmount > 0;

        $externalId = md5(sprintf('%s|%s|%.2f|%d', $dateStr, $description, $localAmount, $csvLine));

        // Detect foreign currency
        $foreignAmount   = null;
        $foreignCurrency = null;
        if ('AED' !== $origCurrency) {
            $foreignAmount   = (string) $origAmount;
            $foreignCurrency = $origCurrency;
        } elseif (abs($origAmount - $absAmount) > 0.01) {
            // AED but amounts differ (bank markup) — note the original in description
            $foreignAmount   = (string) $origAmount;
            $foreignCurrency = 'AED';
        }

        $merchantName = $this->extractMerchant($description);
        $notes        = sprintf("Mashreq CSV line %d\nDescription: %s", $csvLine, $description);
        if (null !== $foreignAmount && $foreignCurrency !== 'AED') {
            $notes .= sprintf("\nOriginal: %s %.2f", $foreignCurrency, $origAmount);
        } elseif ($foreignCurrency === 'AED' && null !== $foreignAmount) {
            $notes .= sprintf("\nMerchant charge: AED %.2f (billed: AED %.2f)", $origAmount, $absAmount);
        }

        $tags = $this->deriveTags($description, $origCurrency);

        if ($isCredit) {
            // Credits: loyalty points, refunds, etc.
            $revenueName = $this->resolveRevenueName($description);

            $result = [
                'type'                  => 'deposit',
                'date'                  => $carbonDate,
                'amount'                => (string) $absAmount,
                'currency_code'         => 'AED',
                'description'           => $merchantName,
                'source_id'             => null,
                'source_name'           => $revenueName,
                'destination_id'        => $sourceAccountId,
                'destination_name'      => null,
                'tags'                  => $tags,
                'notes'                 => $notes,
                'external_id'           => $externalId,
            ];
        } else {
            // Debits: purchases
            $expenseName = $this->matchExpenseAccount($merchantName);

            $result = [
                'type'                  => 'withdrawal',
                'date'                  => $carbonDate,
                'amount'                => (string) $absAmount,
                'currency_code'         => 'AED',
                'description'           => $merchantName,
                'source_id'             => $sourceAccountId,
                'source_name'           => null,
                'destination_id'        => null,
                'destination_name'      => $expenseName,
                'tags'                  => $tags,
                'notes'                 => $notes,
                'external_id'           => $externalId,
            ];
        }

        if (null !== $foreignAmount && $foreignCurrency !== 'AED') {
            $result['foreign_amount']        = $foreignAmount;
            $result['foreign_currency_code'] = $foreignCurrency;
        }

        return $result;
    }

    /**
     * Extract a clean merchant name from the CSV description.
     * Format is typically: "MERCHANT NAME CITY" (e.g. "TALABAT POSTPAID DUBAI")
     */
    private function extractMerchant(string $description): string
    {
        $desc = trim($description);

        if (stripos($desc, 'LOYALTY POINTS REDEMPTION') !== false) {
            return 'Mashreq Loyalty Points Redemption';
        }

        // Remove trailing city names (common UAE cities + international)
        $cities = [
            'DUBAI', 'SHARJAH', 'ABUDHABI', 'ABU DHABI', 'AJMAN',
            'ALMATY', 'HELSINKI', 'CORK', 'PAYSEND.COM',
        ];
        foreach ($cities as $city) {
            $pattern = '/\s+' . preg_quote($city, '/') . '\s*$/i';
            $desc = preg_replace($pattern, '', $desc) ?? $desc;
        }

        // Remove "ITUNES.COM" suffix from Apple entries
        $desc = preg_replace('/\s+ITUNES\.COM\s*$/i', '', $desc) ?? $desc;

        return $this->cleanName($desc);
    }

    private function resolveRevenueName(string $description): string
    {
        if (stripos($description, 'LOYALTY POINTS REDEMPTION') !== false) {
            return 'Mashreq Rewards';
        }

        $merchant = $this->extractMerchant($description);

        return $this->matchRevenueAccount($merchant);
    }

    private function deriveTags(string $description, string $origCurrency): array
    {
        $tags = ['mashreq-cc'];

        if ('AED' !== $origCurrency) {
            $tags[] = 'foreign-currency';
        }

        $descUpper = strtoupper($description);
        if (str_contains($descUpper, 'APPLE.COM/BILL')) {
            $tags[] = 'subscription';
        } elseif (str_contains($descUpper, 'DONATION')) {
            $tags[] = 'donation';
        } elseif (str_contains($descUpper, 'LOYALTY POINTS')) {
            $tags[] = 'loyalty-reward';
        }

        return $tags;
    }

    // ─── Shared helpers (same patterns as ImportTransactions) ───

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
