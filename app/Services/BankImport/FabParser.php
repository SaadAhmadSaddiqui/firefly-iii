<?php

declare(strict_types=1);

namespace FireflyIII\Services\BankImport;

use Carbon\Carbon;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;

class FabParser
{
    private array $expenseAccountCache = [];
    private array $revenueAccountCache = [];
    private array $skipped = [];

    public function __construct()
    {
        $this->loadAccountCaches();
    }

    /**
     * Parse FAB credit card CSV export and return mapped transactions.
     *
     * @return array{transactions: array[], skipped: array[]}
     */
    public function parse(string $rawContent, int $sourceAccountId): array
    {
        $this->skipped = [];

        $lines = preg_split('/\r?\n/', $rawContent);
        if (false === $lines) {
            return ['transactions' => [], 'skipped' => [['reason' => 'Could not parse CSV content.', 'description' => '']]];
        }

        $lines = array_filter($lines, fn ($l) => '' !== trim($l));
        $lines = array_values($lines);

        if (count($lines) < 2) {
            return ['transactions' => [], 'skipped' => [['reason' => 'CSV is empty or has no data rows.', 'description' => '']]];
        }

        array_shift($lines);

        // Reverse so oldest-first (CSV is newest-first)
        $lines = array_reverse($lines);

        $mapped = [];
        foreach ($lines as $lineIdx => $rawLine) {
            $cols = str_getcsv($rawLine, ',', '"', '');
            if (count($cols) < 5) {
                continue;
            }

            $result = $this->mapRow($cols, $sourceAccountId, $lineIdx + 2);
            if (null !== $result) {
                $mapped[] = $result;
            }
        }

        return ['transactions' => $mapped, 'skipped' => $this->skipped];
    }

    private function mapRow(array $cols, int $sourceAccountId, int $csvLine): ?array
    {
        $postingDate = trim($cols[0]);
        $description = trim($cols[1]);
        $rawDesc     = trim($cols[2]);
        $debitStr    = trim($cols[3]);
        $creditStr   = trim($cols[4]);

        $debit  = (float) str_replace(',', '', $debitStr);
        $credit = (float) str_replace(',', '', $creditStr);

        if (stripos($rawDesc, 'Card Payment') !== false && $credit > 0) {
            $this->skipped[] = ['reason' => 'CC payment already imported as transfer', 'description' => sprintf('%s (%.2f AED)', $postingDate, $credit)];

            return null;
        }

        $isDebit = $debit > 0;
        $amount  = $isDebit ? $debit : $credit;

        if ($amount <= 0) {
            return null;
        }

        $carbonDate = Carbon::createFromFormat('d/m/Y', $postingDate, 'Asia/Dubai')->startOfDay();

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
        $cleaned = preg_replace('/\s{2,}.*$/', '', $raw) ?? $raw;

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
}
