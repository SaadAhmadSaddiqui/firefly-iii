<?php

declare(strict_types=1);

namespace FireflyIII\Services\BankImport;

use Carbon\Carbon;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Repositories\RuleGroup\RuleGroupRepositoryInterface;
use FireflyIII\Services\Internal\Update\GroupUpdateService;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\TransactionRules\Engine\RuleEngineInterface;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * One import pipeline for the web pages and `firefly:bank-import`: parse a bank export, then
 * create (or refresh) the transactions, so both paths always behave identically.
 */
class BankImportService
{
    /** Default Firefly asset account per bank. */
    public const array DEFAULT_ACCOUNTS = [
        'enbd'    => 1,   // Emirates NBD current account
        'ei'      => 49,  // Emirates Islamic RTA credit card (same JSON format as ENBD)
        'mashreq' => 50,  // Mashreq Cashback card
        'fab'     => 51,  // FAB Cashback card
    ];

    /**
     * @return array{transactions: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
     */
    public function parse(string $bank, string $content, ?int $accountId = null, string $format = 'json'): array
    {
        $accountId ??= self::DEFAULT_ACCOUNTS[$bank] ?? throw new InvalidArgumentException(sprintf('Unknown bank "%s".', $bank));

        return match ($bank) {
            'enbd', 'ei' => new EnbdParser()->parse($content, $accountId),
            'mashreq'    => new MashreqParser()->parse($content, $accountId, $format),
            'fab'        => new FabParser()->parse($content, $accountId),
            default      => throw new InvalidArgumentException(sprintf('Unknown bank "%s".', $bank)),
        };
    }

    /**
     * Create the parsed transactions for $user. In dry-run mode everything happens inside a
     * transaction that is rolled back, so the counts are real but nothing is stored.
     *
     * @param array<int, array<string, mixed>> $transactions
     * @param array<int, array<string, mixed>> $overrides index => budget_id / category_id / bill_id
     *
     * @return array{created: int, updated: int, duplicates: int, failed: int, errors: array<int, string>, dry_run: bool, details: array<int, array<string, mixed>>}
     */
    public function import(User $user, array $transactions, bool $dryRun = false, array $overrides = []): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'duplicates' => 0, 'failed' => 0, 'errors' => [], 'dry_run' => $dryRun, 'details' => []];

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            /** @var TransactionGroupFactory $factory */
            $factory = app(TransactionGroupFactory::class);
            $factory->setUser($user);

            foreach ($transactions as $idx => $mapped) {
                foreach (['budget_id', 'category_id', 'bill_id'] as $field) {
                    if (!empty($overrides[$idx][$field])) {
                        $mapped[$field] = (int) $overrides[$idx][$field];
                    }
                }

                $date   = $mapped['date'] instanceof Carbon ? $mapped['date']->format('Y-m-d') : (string) $mapped['date'];
                $detail = [
                    'date'        => $date,
                    'description' => $mapped['description'],
                    'amount'      => $mapped['amount'],
                    'type'        => $mapped['type'],
                    'currency'    => $mapped['currency_code'] ?? 'AED',
                    'status'      => 'created',
                    'message'     => '',
                ];

                try {
                    // Auth→settle: the same external_id may come back with the final billed amount.
                    // Refresh the existing journal instead of keeping the auth amount or adding a copy.
                    $existing = $this->findJournalByExternalId($mapped['external_id'] ?? null);
                    if (null !== $existing) {
                        $result = $this->refreshExistingJournalAmount($existing, $mapped);
                        if ('updated' === $result['status']) {
                            ++$stats['updated'];
                            $detail['status'] = 'updated';
                        } else {
                            ++$stats['duplicates'];
                            $detail['status'] = 'duplicate';
                        }
                        $detail['message']  = $result['message'];
                        $stats['details'][] = $detail;

                        continue;
                    }

                    $group = $factory->create([
                        'user'                    => $user,
                        'user_group'              => $user->userGroup,
                        'group_title'             => null,
                        'error_if_duplicate_hash' => true,
                        'apply_rules'             => true,
                        'fire_webhooks'           => false,
                        'transactions'            => [$mapped],
                    ]);
                    ++$stats['created'];

                    $this->applyRules($group, $user);

                    /** @var null|TransactionJournal $journal */
                    $journal = $group->transactionJournals()->first();
                    if (null !== $journal) {
                        $journal->refresh();
                        $detail['bill_name']     = $journal->bill?->name;
                        $detail['budget_name']   = $journal->budgets()->first()?->name;
                        $detail['category_name'] = $journal->categories()->first()?->name;
                    }
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    if (str_contains(strtolower($message), 'duplicate')) {
                        ++$stats['duplicates'];
                        $detail['status']  = 'duplicate';
                        $detail['message'] = 'Duplicate transaction already exists';
                    } else {
                        ++$stats['failed'];
                        $detail['status']  = 'failed';
                        $detail['message'] = $message;
                        $stats['errors'][] = sprintf('%s: %s', mb_substr((string) $mapped['description'], 0, 40), $message);
                        Log::error(sprintf('BankImport failed: %s — %s', $mapped['description'], $message));
                    }
                }

                $stats['details'][] = $detail;
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        return $stats;
    }

    private function applyRules(TransactionGroup $group, User $user): void
    {
        $journalIds = $group->transactionJournals()->pluck('id')->all();
        if ([] === $journalIds) {
            return;
        }

        /** @var RuleGroupRepositoryInterface $ruleGroups */
        $ruleGroups = app(RuleGroupRepositoryInterface::class);
        $ruleGroups->setUser($user);

        /** @var RuleEngineInterface $engine */
        $engine     = app(RuleEngineInterface::class);
        $engine->setUser($user);
        $engine->addOperator(['type' => 'journal_id', 'value' => implode(',', $journalIds)]);
        $engine->setRuleGroups($ruleGroups->getRuleGroupsWithRules('store-journal'));
        $engine->fire();
    }

    private function findJournalByExternalId(?string $externalId): ?TransactionJournal
    {
        if (null === $externalId || '' === $externalId) {
            return null;
        }

        $meta = TransactionJournalMeta::where('name', 'external_id')
            ->where('hash', hash('sha256', json_encode($externalId, JSON_THROW_ON_ERROR)))
            ->whereNull('deleted_at')
            ->first();

        return null === $meta ? null : TransactionJournal::where('id', $meta->transaction_journal_id)->whereNull('deleted_at')->first();
    }

    /**
     * When a bank export revisits an already-imported external_id (e.g. AUTHORIZED → SETTLED
     * with a final FX amount), overwrite Firefly's amount with the bank's current amount.
     *
     * @return array{status: string, message: string}
     */
    private function refreshExistingJournalAmount(TransactionJournal $journal, array $mapped): array
    {
        $duplicate = ['status' => 'duplicate', 'message' => 'Duplicate transaction already exists'];
        $newAmount = Steam::positive((string) ($mapped['amount'] ?? '0'));
        $anyTxn    = $journal->transactions()->whereNull('deleted_at')->first();
        $group     = $journal->transactionGroup;
        if (null === $anyTxn || null === $group) {
            return $duplicate;
        }

        $oldAmount = Steam::positive((string) $anyTxn->amount);
        if (0 === bccomp($oldAmount, $newAmount, 12)) {
            return $duplicate;
        }

        $update = ['transaction_journal_id' => $journal->id, 'amount' => $newAmount];
        if (isset($mapped['foreign_amount'], $mapped['foreign_currency_code'])) {
            $update['foreign_amount']        = (string) $mapped['foreign_amount'];
            $update['foreign_currency_code'] = (string) $mapped['foreign_currency_code'];
        }

        /** @var GroupUpdateService $updateService */
        $updateService = app(GroupUpdateService::class);
        $updateService->update($group, ['transactions' => [$update]]);

        Log::info(sprintf('BankImport updated amount for external_id %s: %s → %s (%s)', (string) ($mapped['external_id'] ?? '?'), $oldAmount, $newAmount, (string) ($mapped['description'] ?? '')));

        return ['status' => 'updated', 'message' => sprintf('Updated amount %s → %s (settled/re-import)', $oldAmount, $newAmount)];
    }
}
