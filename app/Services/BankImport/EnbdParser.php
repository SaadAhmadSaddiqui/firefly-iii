<?php

declare(strict_types=1);

namespace FireflyIII\Services\BankImport;

use Carbon\Carbon;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;

class EnbdParser
{
    private array $expenseAccountCache = [];
    private array $revenueAccountCache = [];
    private array $skipped = [];
    private ?int $debitAccountId = null;

    public function __construct()
    {
        $this->loadAccountCaches();
    }

    /**
     * Parse Emirates NBD / Emirates Islamic JSON export and return mapped transactions.
     *
     * @return array{transactions: array[], skipped: array[]}
     */
    public function parse(string $rawContent, int $sourceAccountId): array
    {
        $this->skipped = [];

        $data = json_decode($rawContent, true);
        if (!is_array($data) || !array_key_exists('transactions', $data)) {
            return ['transactions' => [], 'skipped' => [['reason' => 'Invalid JSON: expected a top-level "transactions" array.', 'description' => '']]];
        }

        $transactions = $data['transactions'];
        usort($transactions, fn ($a, $b) => ($a['date'] ?? 0) <=> ($b['date'] ?? 0));

        $mapped = [];
        foreach ($transactions as $txn) {
            $result = $this->mapTransaction($txn, $sourceAccountId);
            if (null !== $result) {
                $mapped[] = $result;
            }
        }

        return ['transactions' => $mapped, 'skipped' => $this->skipped];
    }

    private function mapTransaction(array $txn, int $sourceAccountId): ?array
    {
        $type       = $txn['type'] ?? '';
        $direction  = $txn['creditDebitIndicator'] ?? '';
        $currency   = $txn['currencyCode'] ?? 'AED';
        $dateMs     = $this->resolveTransactionDateMs($txn);
        $externalId = $txn['id'] ?? null;
        $txnType    = $txn['type'] ?? '';
        $isCreditCardExport = 'creditcard' === ($txn['product']['type'] ?? '');

        // Prefer accountAmount (what hits the card/ledger). Keep raw amount for FX foreign_amount.
        $rawAmount       = $this->parseNumeric($txn['amount'] ?? 0);
        $accountCurrency = $txn['accountAmount']['currencyCode'] ?? $currency;
        $amount          = array_key_exists('amount', $txn['accountAmount'] ?? [])
            ? $this->parseNumeric($txn['accountAmount']['amount'], $rawAmount)
            : $rawAmount;

        $status = strtoupper($txn['status'] ?? '');
        if (in_array($status, ['FAILED', 'CANCELLED', 'REVERSED', 'REFUNDED', 'DROPPED'], true)) {
            $skipDate = $dateMs ? date('Y-m-d', (int) ($dateMs / 1000)) : '?';
            $this->skipped[] = [
                'reason'        => "Status: {$status}",
                'description'   => $this->getEnTitle($txn) ?: 'Unknown',
                'date'          => $skipDate,
                'amount'        => (string) $amount,
                'currency_code' => $currency,
                'type'          => 'DR' === $direction ? 'withdrawal' : 'deposit',
                'original_id'   => $externalId,
            ];

            return null;
        }

        if ('PAYMENT' === $txnType && 'CR' === $direction && $this->hasEpochPrimaryDate($txn)) {
            if ($isCreditCardExport) {
                if (0.0 === $amount || null === $dateMs || $dateMs < 86400000) {
                    return null;
                }

                return $this->mapCreditCardBillPayment($txn, $sourceAccountId, $dateMs, $amount, $accountCurrency, $externalId);
            }

            $skipDate = $dateMs ? date('Y-m-d', (int) ($dateMs / 1000)) : '?';
            $this->skipped[] = [
                'reason'        => 'CC payment mirror entry (imported via debit CARD_PAYMENT)',
                'description'   => $this->getEnTitle($txn) ?: 'CC Payment',
                'date'          => $skipDate,
                'amount'        => (string) $amount,
                'currency_code' => $currency,
                'type'          => 'deposit',
                'original_id'   => $externalId,
            ];

            return null;
        }

        if (0.0 === $amount || null === $dateMs || $dateMs < 86400000) {
            return null;
        }

        $carbonDate  = Carbon::createFromTimestampMs($dateMs)->setTimezone('Asia/Dubai')->startOfDay();
        $enTitle     = $this->getEnTitle($txn);
        $enSubtitle  = $this->getEnSubtitle($txn);
        $terminal    = $txn['terminal']['name'] ?? null;
        $terminalCity    = $txn['terminal']['city'] ?? null;
        $terminalCountry = $txn['terminal']['country'] ?? null;
        $narrations  = $txn['purpose']['narrations'] ?? [];
        $refNumber   = $txn['referenceNumber'] ?? $txn['bookingReference'] ?? '';

        // ENBD sometimes nests amount/exchangeRate as {source, parsedValue} objects.
        // Casting those arrays to float yields 1 — which turned the AED 14,500 rent
        // cheque into "Cheque Clearing — AED 1.00".
        $foreignAmount   = null;
        $foreignCurrency = null;
        $exchangeRate    = $this->parseNumeric($txn['exchangeRate'] ?? 1.0, 1.0);

        if (1.0 !== $exchangeRate || $currency !== $accountCurrency) {
            $foreignAmount   = (string) $rawAmount;
            $foreignCurrency = $currency;
            $currency        = $accountCurrency;
        }

        if (null === $foreignAmount) {
            foreach ($narrations as $narr) {
                if (preg_match('/^([\d.]+),((?:EUR|USD|GBP|CAD|PKR|SGD|INR|SAR|BHD|QAR|OMR|KWD))$/i', trim($narr), $m)) {
                    $foreignAmount   = $m[1];
                    $foreignCurrency = strtoupper($m[2]);

                    break;
                }
            }
        }

        $tags   = $this->deriveTags($txn);
        $result = [
            'type'                  => 'withdrawal',
            'date'                  => $carbonDate,
            'amount'                => (string) $amount,
            'currency_code'         => $currency,
            'description'           => '',
            'source_id'             => $sourceAccountId,
            'source_name'           => null,
            'destination_id'        => null,
            'destination_name'      => '',
            'tags'                  => $tags,
            'notes'                 => $this->buildNotes($txn, $narrations, $refNumber),
            'external_id'          => $externalId,
            'internal_reference'   => $refNumber,
            'original_id'          => $externalId,
        ];

        if (null !== $foreignAmount && null !== $foreignCurrency && $foreignCurrency !== $currency) {
            $result['foreign_amount']        = $foreignAmount;
            $result['foreign_currency_code'] = $foreignCurrency;
        }

        if ('DR' === $direction) {
            return match ($type) {
                'ECOMMERCE', 'POS' => $this->mapMerchantPurchase($result, $txn, $enTitle, $terminal, $terminalCity, $terminalCountry),
                'CARD_PAYMENT'     => $this->mapCardPayment($result, $txn, $enSubtitle, $narrations),
                'INTRA_BANK', 'LOCAL', 'INTRA_GROUP', 'INTERNATIONAL' => $this->mapBankTransfer($result, $txn, $enTitle, $enSubtitle, $type),
                'P2P'              => $this->mapP2P($result, $txn, $enTitle, $enSubtitle),
                'CHQ_CLEARING'     => $this->mapCheque($result, $txn, $enSubtitle),
                'WITHDRAWAL'       => $this->mapAtmWithdrawal($result, $txn, $enSubtitle),
                'CHARGES', 'OTHER' => $this->mapOtherDebit($result, $txn, $enSubtitle),
                default            => $this->mapGenericDebit($result, $txn, $enTitle, $enSubtitle),
            };
        }

        if ('CR' === $direction) {
            $result['type'] = 'deposit';
            $result['source_id']      = null;
            $result['source_name']    = '';
            $result['destination_id'] = $sourceAccountId;
            $result['destination_name'] = null;

            return match ($type) {
                'SALARY'            => $this->mapSalary($result, $txn, $enSubtitle),
                'REVERSAL'          => $this->mapReversal($result, $txn, $enTitle, $enSubtitle, $terminal),
                'ECOMMERCE'         => $this->mapRefund($result, $txn, $enTitle, $terminal),
                'DEPOSIT'           => $this->mapCashDeposit($result, $txn, $enSubtitle),
                'OTHER'             => $this->mapOtherCredit($result, $txn, $enSubtitle, $narrations),
                default             => $this->mapGenericCredit($result, $txn, $enTitle, $enSubtitle),
            };
        }

        return null;
    }

    // ─── DR mappers ───

    private function mapMerchantPurchase(array $result, array $txn, string $title, ?string $terminal, ?string $city, ?string $country): array
    {
        $merchantName           = $this->cleanMerchantName($terminal ?: $title);
        $result['description']  = $merchantName;
        $result['destination_name'] = $merchantName;

        if ($city && $country) {
            $result['notes'] = trim($result['notes'] . "\nLocation: {$city}, {$country}");
        }

        return $result;
    }

    private function mapCardPayment(array $result, array $txn, string $subtitle, array $narrations): array
    {
        $cardAccount = $this->detectCreditCardFromNarrations($narrations, $subtitle);

        if (null !== $cardAccount) {
            $result['type']             = 'transfer';
            $result['description']      = sprintf('Credit Card Payment — %s', $cardAccount);
            $result['destination_name'] = $cardAccount;
            $result['tags'][]           = 'credit-card-payment';
        } else {
            $result['description']      = 'Debit Card Settlement';
            $result['destination_name'] = 'Card Payment (Unidentified Merchant)';
        }

        return $result;
    }

    private function detectCreditCardFromNarrations(array $narrations, string $subtitle): ?string
    {
        $cardMap = [
            '9107' => 'Mashreq Cashback Card',
            '1910' => 'Mashreq Cashback Card',
            '7879' => 'FAB Cashback Card',
            '0009' => 'Emirates Islamic RTA Credit Card',
        ];

        $allNarr = implode(' ', $narrations);
        foreach ($cardMap as $lastDigits => $accountName) {
            if (preg_match('/\d+\*{4,6}' . preg_quote((string) $lastDigits, '/') . '/', $allNarr)) {
                return $accountName;
            }
        }

        if (str_contains(strtolower($subtitle), 'emirates islami')) {
            return 'Emirates Islamic RTA Credit Card';
        }

        return null;
    }

    private function mapBankTransfer(array $result, array $txn, string $title, string $subtitle, string $type): array
    {
        $recipientName = $this->cleanName('' !== $subtitle && 'Local transfer' !== $subtitle && 'Transfer to other Emirates NBD account' !== $subtitle && 'Account transfer' !== $subtitle ? $subtitle : $title);

        $result['description']      = sprintf('Transfer to %s', $recipientName);
        $result['destination_name'] = $this->matchExpenseAccount($recipientName);

        $label = match ($type) {
            'INTRA_BANK'    => 'internal-transfer',
            'LOCAL'         => 'local-transfer',
            'INTRA_GROUP'   => 'intra-group-transfer',
            'INTERNATIONAL' => 'international-transfer',
            default         => 'bank-transfer',
        };
        $result['tags'][] = $label;

        return $result;
    }

    private function mapP2P(array $result, array $txn, string $title, string $subtitle): array
    {
        $recipient = $subtitle ?: $title;
        if (preg_match('/^00971\d+$/', $recipient)) {
            $recipient = 'AANI — ' . $recipient;
        } else {
            $recipient = $this->cleanName($recipient);
        }

        $result['description']      = sprintf('AANI Payment to %s', $recipient);
        $result['destination_name'] = $this->matchExpenseAccount($recipient);
        $result['tags'][]           = 'aani-payment';

        return $result;
    }

    private function mapAtmWithdrawal(array $result, array $txn, string $subtitle): array
    {
        $result['description']      = 'ATM Cash Withdrawal';
        $result['destination_name'] = 'Cash / ATM';
        $result['tags'][]           = 'atm-withdrawal';

        if ('' !== $subtitle) {
            $result['notes'] = trim($result['notes'] . "\n" . $subtitle);
        }

        return $result;
    }

    private function mapCheque(array $result, array $txn, string $subtitle): array
    {
        $result['description']      = 'Cheque Clearing';
        $result['destination_name'] = 'Cheque Payment';
        $result['tags'][]           = 'cheque';

        if ('' !== $subtitle) {
            $result['notes'] = trim($result['notes'] . "\n" . $subtitle);
        }

        return $result;
    }

    private function mapOtherDebit(array $result, array $txn, string $subtitle): array
    {
        $result['description']      = 'Bank Fee / Charge';
        $result['destination_name'] = 'Emirates NBD Fees';
        $result['tags'][]           = 'bank-fee';

        return $result;
    }

    private function mapGenericDebit(array $result, array $txn, string $title, string $subtitle): array
    {
        $name = '' !== $title && '?' !== $title ? $title : $subtitle;
        $result['description']      = $this->cleanName($name ?: 'Unknown Debit');
        $result['destination_name'] = $result['description'];

        return $result;
    }

    // ─── CR mappers ───

    private function mapSalary(array $result, array $txn, string $subtitle): array
    {
        $employer     = $this->extractEmployer($subtitle);
        $revenueName  = $this->matchRevenueAccount($employer);

        $result['description'] = sprintf('Salary — %s', $employer);
        $result['source_name'] = $revenueName;
        $result['tags'][]      = 'salary';

        return $result;
    }

    private function mapReversal(array $result, array $txn, string $title, string $subtitle, ?string $terminal): array
    {
        $merchant               = $this->extractRefundMerchant($title, $subtitle, $terminal, $txn);
        $result['description']  = sprintf('Refund — %s', $merchant);
        $result['source_name']  = $merchant;
        $result['tags'][]       = 'refund';

        return $result;
    }

    private function mapRefund(array $result, array $txn, string $title, ?string $terminal): array
    {
        $merchant               = $this->cleanMerchantName($terminal ?: $title);
        $result['description']  = sprintf('Refund — %s', $merchant);
        $result['source_name']  = $merchant;
        $result['tags'][]       = 'refund';

        return $result;
    }

    private function mapCashDeposit(array $result, array $txn, string $subtitle): array
    {
        $result['description'] = 'Cash Deposit';
        $result['source_name'] = 'Cash / ATM';
        $result['tags'][]      = 'cash-deposit';

        if ('' !== $subtitle) {
            $result['notes'] = trim($result['notes'] . "\n" . $subtitle);
        }

        return $result;
    }

    private function mapOtherCredit(array $result, array $txn, string $subtitle, array $narrations): array
    {
        $combined = $subtitle . ' ' . implode(' ', $narrations);

        if (preg_match('/NST\s+MEDIA/i', $combined)) {
            $result['description'] = 'NST Media FZC Payout';
            $result['source_name'] = $this->matchRevenueAccount('NST MEDIA FZC');
            $result['tags'][]      = 'business-income';
        } else {
            $payer = $this->extractPayer($subtitle);
            $result['description'] = sprintf('Incoming — %s', $payer);
            $result['source_name'] = $this->matchRevenueAccount($payer);
        }

        return $result;
    }

    private function mapGenericCredit(array $result, array $txn, string $title, string $subtitle): array
    {
        $name = '' !== $title && '?' !== $title ? $title : ($subtitle ?: 'Unknown Income');
        $result['description'] = $this->cleanName($name);
        $result['source_name'] = $result['description'];

        return $result;
    }

    private function mapCreditCardBillPayment(
        array $txn,
        int $creditCardAccountId,
        int $dateMs,
        float $amount,
        string $currency,
        ?string $externalId,
    ): array {
        $carbonDate = Carbon::createFromTimestampMs($dateMs)->setTimezone('Asia/Dubai')->startOfDay();
        $cardName   = Account::find($creditCardAccountId)?->name ?? 'Credit Card';
        $narrations = $txn['purpose']['narrations'] ?? [];
        $refNumber  = $txn['referenceNumber'] ?? $txn['bookingReference'] ?? '';

        return [
            'type'                 => 'transfer',
            'date'                 => $carbonDate,
            'amount'               => (string) $amount,
            'currency_code'        => $currency,
            'description'          => sprintf('Credit Card Payment — %s', $cardName),
            'source_id'            => $this->resolveDebitAccountId(),
            'source_name'          => null,
            'destination_id'       => $creditCardAccountId,
            'destination_name'     => null,
            'tags'                 => ['credit-card-payment'],
            'notes'                => $this->buildNotes($txn, $narrations, $refNumber),
            'external_id'          => $externalId,
            'internal_reference'   => $refNumber,
            'original_id'          => $externalId,
        ];
    }

    private function resolveTransactionDateMs(array $txn): ?int
    {
        foreach (['date', 'transactionDate', 'valueDate', 'initiationTime', 'executionTime'] as $field) {
            $value = $txn[$field] ?? null;
            if (null !== $value && (int) $value >= 86400000) {
                return (int) $value;
            }
        }

        return null;
    }

    private function hasEpochPrimaryDate(array $txn): bool
    {
        $primaryDate = $txn['date'] ?? $txn['transactionDate'] ?? null;

        return null === $primaryDate || (int) $primaryDate < 86400000;
    }

    private function resolveDebitAccountId(): int
    {
        if (null !== $this->debitAccountId) {
            return $this->debitAccountId;
        }

        $assetType = AccountType::where('type', 'Asset account')->first();
        if ($assetType) {
            $account = Account::where('account_type_id', $assetType->id)
                ->where('name', 'Emirates NBD')
                ->whereNull('deleted_at')
                ->first();
            if ($account) {
                $this->debitAccountId = (int) $account->id;

                return $this->debitAccountId;
            }
        }

        $this->debitAccountId = 1;

        return $this->debitAccountId;
    }

    // ─── Helpers ───

    private function parseNumeric(mixed $value, float $default = 0.0): float
    {
        if (is_array($value)) {
            if (isset($value['parsedValue']) && is_numeric($value['parsedValue'])) {
                return (float) $value['parsedValue'];
            }
            if (isset($value['source']) && is_numeric($value['source'])) {
                return (float) $value['source'];
            }
            if (isset($value['amount'])) {
                return $this->parseNumeric($value['amount'], $default);
            }

            return $default;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    private function getEnTitle(array $txn): string
    {
        foreach ($txn['purpose']['extendedNarrations'] ?? [] as $n) {
            if ('en' === ($n['languange'] ?? '')) {
                return trim($n['title'] ?? '');
            }
        }

        return '';
    }

    private function getEnSubtitle(array $txn): string
    {
        foreach ($txn['purpose']['extendedNarrations'] ?? [] as $n) {
            if ('en' === ($n['languange'] ?? '')) {
                return trim($n['subTitle'] ?? '');
            }
        }

        return '';
    }

    private function extractRefundMerchant(string $title, string $subtitle, ?string $terminal, array $txn): string
    {
        if (null !== $terminal && '' !== trim($terminal)) {
            return $this->cleanMerchantName($terminal);
        }

        foreach ([$subtitle, trim($txn['mlEnriched']['cleanDescription'] ?? '')] as $candidate) {
            $merchant = $this->merchantFromReversalSubtitle($candidate);
            if (null !== $merchant) {
                return $merchant;
            }
        }

        $genericTitles = ['retail return', 'reversal', 'refund', 'return'];
        if (!in_array(mb_strtolower(trim($title)), $genericTitles, true)) {
            return $this->cleanMerchantName($title);
        }

        return $this->cleanMerchantName('' !== $subtitle ? $subtitle : $title);
    }

    private function merchantFromReversalSubtitle(string $subtitle): ?string
    {
        $subtitle = trim($subtitle);
        if ('' === $subtitle) {
            return null;
        }

        if (preg_match('/^(retail return|reversal|e-commerce|ecommerce)$/i', $subtitle)) {
            return null;
        }

        if (preg_match('/((?:www\.)?[\w.-]+\.com)/i', $subtitle, $match)) {
            return $this->cleanMerchantName($match[1]);
        }

        $stripped = preg_replace('/(Dubai|Abu Dhabi|Sharjah|DUBAI|DXB|ARE|UAE|784).*$/i', '', $subtitle) ?? $subtitle;
        $stripped = trim($stripped);
        if ('' !== $stripped && !preg_match('/^(retail return|reversal)$/i', $stripped)) {
            return $this->cleanMerchantName($stripped);
        }

        return null;
    }

    private function cleanMerchantName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s*\([\d+]+,\s*[A-Z]{2}\)$/', '', $name) ?? $name;
        $name = preg_replace('/\s*\([^)]+,\s*[A-Z]{2}\)$/', '', $name) ?? $name;
        $name = rtrim($name, '* ');
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        if ('' === $name) {
            return 'Unknown Merchant';
        }

        return $name;
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

    private function extractEmployer(string $subtitle): string
    {
        if (preg_match('/(?:AED\s+[\d.,]+\s+)(.+?)(?:\s*\/REF\/)/i', $subtitle, $m)) {
            return $this->cleanName(trim($m[1]));
        }
        if (preg_match('/AED\s+[\d.,]+\s+(.+?)(?:\s+PA\s*YMENT|\s*$)/i', $subtitle, $m)) {
            return $this->cleanName(trim($m[1]));
        }

        return 'Employer';
    }

    private function extractPayer(string $subtitle): string
    {
        if (preg_match('/([A-Z][A-Z\s]+(?:FZC|LLC|PJSC|FZE))\s/i', $subtitle, $m)) {
            return $this->cleanName(trim($m[1]));
        }

        return $this->cleanName($subtitle ?: 'Unknown Payer');
    }

    private function deriveTags(array $txn): array
    {
        $tags = [];
        $type = $txn['type'] ?? '';

        $typeTag = match ($type) {
            'ECOMMERCE'     => 'e-commerce',
            'POS'           => 'pos-purchase',
            'CARD_PAYMENT'  => 'card-payment',
            'SALARY'        => 'salary',
            'REVERSAL'      => 'reversal',
            'CHQ_CLEARING'  => 'cheque',
            'P2P'           => 'p2p',
            'DEPOSIT'       => 'cash-deposit',
            'CHARGES'       => 'bank-fee',
            'WITHDRAWAL'    => 'atm-withdrawal',
            default         => null,
        };
        if (null !== $typeTag) {
            $tags[] = $typeTag;
        }

        return $tags;
    }

    private function buildNotes(array $txn, array $narrations, string $refNumber): string
    {
        $lines = [];
        $lines[] = sprintf('Emirates NBD Ref: %s', $refNumber);

        if (isset($txn['bookingReference'])) {
            $lines[] = sprintf('Booking: %s', $txn['bookingReference']);
        }

        $nonEmpty = array_filter($narrations, fn ($n) => '' !== trim($n));
        if (!empty($nonEmpty)) {
            $lines[] = 'Narration: ' . implode(' / ', $nonEmpty);
        }

        return implode("\n", $lines);
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
