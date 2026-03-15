<?php

declare(strict_types=1);

namespace FireflyIII\Services\BankImport;

use Carbon\Carbon;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;

class MashreqParser
{
    private array $expenseAccountCache = [];
    private array $revenueAccountCache = [];
    private array $skipped = [];

    public function __construct()
    {
        $this->loadAccountCaches();
    }

    /**
     * Parse Mashreq credit card export (CSV or JSON) and return mapped transactions.
     *
     * @param  string  $format  'csv' or 'json'
     * @return array{transactions: array[], skipped: array[]}
     */
    public function parse(string $rawContent, int $sourceAccountId, string $format = 'csv'): array
    {
        if ($format === 'json') {
            return $this->parseJson($rawContent, $sourceAccountId);
        }

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

        $mapped = [];
        foreach ($lines as $lineNum => $rawLine) {
            $cols = str_getcsv($rawLine, ',', '"', '');
            if (count($cols) < 5) {
                continue;
            }

            $result = $this->mapRow($cols, $sourceAccountId, $lineNum + 2, $rawLine);
            if (null !== $result) {
                $mapped[] = $result;
            }
        }

        return ['transactions' => $mapped, 'skipped' => $this->skipped];
    }

    /**
     * Parse Mashreq JSON export: { "transactions": [ { transDate, transDescription, amount, ... }, ... ] }
     *
     * @return array{transactions: array[], skipped: array[]}
     */
    private function parseJson(string $rawContent, int $sourceAccountId): array
    {
        $this->skipped = [];

        $data = json_decode($rawContent, true);
        if (! is_array($data) || ! isset($data['transactions']) || ! is_array($data['transactions'])) {
            return ['transactions' => [], 'skipped' => [['reason' => 'Invalid JSON or missing "transactions" array.', 'description' => '']]];
        }

        $mapped = [];
        foreach ($data['transactions'] as $idx => $item) {
            if (! is_array($item)) {
                continue;
            }
            $result = $this->mapJsonTransaction($item, $sourceAccountId);
            if (null !== $result) {
                $mapped[] = $result;
            }
        }

        return ['transactions' => $mapped, 'skipped' => $this->skipped];
    }

    /**
     * Map a single JSON transaction object to the internal format.
     */
    private function mapJsonTransaction(array $item, int $sourceAccountId): ?array
    {
        $transDate       = $item['transDate'] ?? $item['postDate'] ?? null;
        $transDescription = $item['transDescription'] ?? '';
        $amount          = isset($item['amount']) ? (float) $item['amount'] : 0.0;
        $currency        = strtoupper(trim((string) ($item['currency'] ?? 'AED')));
        $sourceAmount    = isset($item['sourceAmount']) ? (float) $item['sourceAmount'] : abs($amount);
        $transRefNo      = $item['transRefNo'] ?? '';

        if ($transDate === null || $transDate === '') {
            return null;
        }

        if (0.0 === $amount) {
            return null;
        }

        if (stripos($transDescription, 'INWARD IPP CC') !== false) {
            $this->skipped[] = [
                'reason'        => 'CC payment already imported as transfer',
                'description'   => $transDescription,
                'date'          => $transDate,
                'amount'        => (string) abs($amount),
                'currency_code' => $currency,
                'type'          => $amount > 0 ? 'deposit' : 'withdrawal',
                'original_raw'  => json_encode($item),
            ];

            return null;
        }

        try {
            $carbonDate = Carbon::createFromFormat('Y-m-d', trim($transDate), 'Asia/Dubai');
        } catch (\Exception $e) {
            try {
                $carbonDate = Carbon::createFromFormat('d-M-Y', trim($transDate), 'Asia/Dubai');
            } catch (\Exception $e2) {
                return null;
            }
        }
        $carbonDate  = $carbonDate->startOfDay();
        $absAmount   = abs($amount);
        $isCredit    = $amount > 0;
        $description = trim($transDescription);
        $externalId  = $transRefNo !== '' ? 'mashreq-json-' . $transRefNo : md5(json_encode($item));

        $foreignAmount   = null;
        $foreignCurrency = null;
        if ($currency !== 'AED') {
            $foreignAmount   = (string) $sourceAmount;
            $foreignCurrency = $currency;
        } elseif (abs($sourceAmount - $absAmount) > 0.01) {
            $foreignAmount   = (string) $sourceAmount;
            $foreignCurrency = 'AED';
        }

        $merchantName = $this->extractMerchant($description);
        $notes        = sprintf("Mashreq JSON\nDescription: %s", $description);
        if (null !== $foreignAmount && $foreignCurrency !== 'AED') {
            $notes .= sprintf("\nOriginal: %s %.2f", $foreignCurrency, $sourceAmount);
        } elseif ($foreignCurrency === 'AED' && null !== $foreignAmount) {
            $notes .= sprintf("\nMerchant charge: AED %.2f (billed: AED %.2f)", $sourceAmount, $absAmount);
        }

        $tags = $this->deriveTags($description, $currency);

        if ($isCredit) {
            $revenueName   = $this->resolveRevenueName($description);
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
                'original_raw'          => json_encode($item),
                'original_id'           => $transRefNo !== '' ? $transRefNo : $externalId,
            ];
        } else {
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
                'original_raw'          => json_encode($item),
                'original_id'           => $transRefNo !== '' ? $transRefNo : $externalId,
            ];
        }

        if (null !== $foreignAmount && $foreignCurrency !== 'AED') {
            $result['foreign_amount']        = $foreignAmount;
            $result['foreign_currency_code'] = $foreignCurrency;
        }

        return $result;
    }

    private function mapRow(array $cols, int $sourceAccountId, int $csvLine, string $rawLine = ''): ?array
    {
        [$dateStr, $description, $origCurrency, $origAmountStr, $localAmountStr] = $cols;

        $description = trim($description);
        $localAmount = (float) $localAmountStr;
        $origAmount  = (float) $origAmountStr;
        $origCurrency = strtoupper(trim($origCurrency));

        if (0.0 === $localAmount) {
            return null;
        }

        if (stripos($description, 'INWARD IPP CC') !== false) {
            $this->skipped[] = [
                'reason'        => 'CC payment already imported as transfer',
                'description'   => $description,
                'date'          => trim($dateStr),
                'amount'        => (string) abs($localAmount),
                'currency_code' => 'AED',
                'type'          => $localAmount > 0 ? 'deposit' : 'withdrawal',
                'original_raw'  => $rawLine,
            ];

            return null;
        }

        try {
            $carbonDate = Carbon::createFromFormat('d-M-Y', trim($dateStr), 'Asia/Dubai');
        } catch (\Exception $e) {
            $carbonDate = Carbon::createFromFormat('Y-m-d', trim($dateStr), 'Asia/Dubai');
        }
        $carbonDate = $carbonDate->startOfDay();
        $absAmount  = abs($localAmount);
        $isCredit   = $localAmount > 0;

        $externalId = md5(sprintf('%s|%s|%.2f|%d', $dateStr, $description, $localAmount, $csvLine));

        $foreignAmount   = null;
        $foreignCurrency = null;
        if ('AED' !== $origCurrency) {
            $foreignAmount   = (string) $origAmount;
            $foreignCurrency = $origCurrency;
        } elseif (abs($origAmount - $absAmount) > 0.01) {
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
                'original_raw'          => $rawLine,
            ];
        } else {
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
                'original_raw'          => $rawLine,
            ];
        }

        if (null !== $foreignAmount && $foreignCurrency !== 'AED') {
            $result['foreign_amount']        = $foreignAmount;
            $result['foreign_currency_code'] = $foreignCurrency;
        }

        return $result;
    }

    private function extractMerchant(string $description): string
    {
        $desc = trim($description);

        if (stripos($desc, 'LOYALTY POINTS REDEMPTION') !== false) {
            return 'Mashreq Loyalty Points Redemption';
        }

        $cities = [
            'DUBAI', 'SHARJAH', 'ABUDHABI', 'ABU DHABI', 'AJMAN',
            'ALMATY', 'HELSINKI', 'CORK', 'PAYSEND.COM',
        ];
        foreach ($cities as $city) {
            $pattern = '/\s+' . preg_quote($city, '/') . '\s*$/i';
            $desc = preg_replace($pattern, '', $desc) ?? $desc;
        }

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
