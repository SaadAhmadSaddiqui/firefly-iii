<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\BankImport;

use Carbon\Carbon;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Repositories\RuleGroup\RuleGroupRepositoryInterface;
use FireflyIII\Services\BankImport\EnbdParser;
use FireflyIII\Services\BankImport\FabParser;
use FireflyIII\Services\BankImport\MashreqParser;
use FireflyIII\Services\Internal\Update\GroupUpdateService;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\TransactionRules\Engine\RuleEngineInterface;
use FireflyIII\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            app('view')->share('mainTitleIcon', 'fa-bank');
            app('view')->share('title', 'Bank Import');

            return $next($request);
        });
    }

    public function enbd(): Factory|\Illuminate\Contracts\View\View
    {
        $subTitle = 'Emirates NBD / Islamic';
        $refData  = $this->getReferenceData();

        return view('bank-import.enbd', compact('subTitle') + $refData);
    }

    public function mashreq(): Factory|\Illuminate\Contracts\View\View
    {
        $subTitle = 'Mashreq';
        $refData  = $this->getReferenceData();

        return view('bank-import.mashreq', compact('subTitle') + $refData);
    }

    public function fab(): Factory|\Illuminate\Contracts\View\View
    {
        $subTitle = 'FAB';
        $refData  = $this->getReferenceData();

        return view('bank-import.fab', compact('subTitle') + $refData);
    }

    public function previewEnbd(Request $request): JsonResponse
    {
        $content = $this->extractContent($request);
        if (null === $content) {
            return response()->json(['error' => 'No content provided. Upload a file or paste JSON.'], 422);
        }

        $sourceAccountId = (int) $request->input('source_account_id', 1);
        $parser = new EnbdParser();
        $result = $parser->parse($content, $sourceAccountId);

        return response()->json($this->formatPreviewResponse($result));
    }

    public function previewMashreq(Request $request): JsonResponse
    {
        $format  = $request->input('format', 'csv');
        $content = $this->extractContent($request);
        if (null === $content) {
            return response()->json(['error' => 'No content provided. Upload a file or paste ' . ($format === 'json' ? 'JSON' : 'CSV') . '.'], 422);
        }

        $sourceAccountId = (int) $request->input('source_account_id', 50);
        $parser = new MashreqParser();
        $result = $parser->parse($content, $sourceAccountId, $format);

        return response()->json($this->formatPreviewResponse($result));
    }

    public function previewFab(Request $request): JsonResponse
    {
        $content = $this->extractContent($request);
        if (null === $content) {
            return response()->json(['error' => 'No content provided. Upload a file or paste CSV.'], 422);
        }

        $sourceAccountId = (int) $request->input('source_account_id', 51);
        $parser = new FabParser();
        $result = $parser->parse($content, $sourceAccountId);

        return response()->json($this->formatPreviewResponse($result));
    }

    public function importEnbd(Request $request): JsonResponse
    {
        $content = $this->extractContent($request);
        if (null === $content) {
            return response()->json(['error' => 'No content provided.'], 422);
        }

        $dryRun = (bool) $request->input('dry_run', false);
        $overrides = $this->parseOverrides($request);
        $sourceAccountId = (int) $request->input('source_account_id', 1);
        $parser = new EnbdParser();
        $result = $parser->parse($content, $sourceAccountId);

        return $this->executeImport($result['transactions'], $dryRun, $overrides);
    }

    public function importMashreq(Request $request): JsonResponse
    {
        $format  = $request->input('format', 'csv');
        $content = $this->extractContent($request);
        if (null === $content) {
            return response()->json(['error' => 'No content provided.'], 422);
        }

        $dryRun = (bool) $request->input('dry_run', false);
        $overrides = $this->parseOverrides($request);
        $sourceAccountId = (int) $request->input('source_account_id', 50);
        $parser = new MashreqParser();
        $result = $parser->parse($content, $sourceAccountId, $format);

        return $this->executeImport($result['transactions'], $dryRun, $overrides);
    }

    public function importFab(Request $request): JsonResponse
    {
        $content = $this->extractContent($request);
        if (null === $content) {
            return response()->json(['error' => 'No content provided.'], 422);
        }

        $dryRun = (bool) $request->input('dry_run', false);
        $overrides = $this->parseOverrides($request);
        $sourceAccountId = (int) $request->input('source_account_id', 51);
        $parser = new FabParser();
        $result = $parser->parse($content, $sourceAccountId);

        return $this->executeImport($result['transactions'], $dryRun, $overrides);
    }

    private function extractContent(Request $request): ?string
    {
        if ($request->hasFile('import_file')) {
            $file = $request->file('import_file');
            if (null !== $file && $file->isValid()) {
                return $file->get();
            }
        }

        $pasted = $request->input('pasted_content');
        if (is_string($pasted) && '' !== trim($pasted)) {
            return $pasted;
        }

        return null;
    }

    private function formatPreviewResponse(array $parseResult): array
    {
        $rows = [];
        foreach ($parseResult['transactions'] as $txn) {
            $date = $txn['date'] instanceof Carbon ? $txn['date']->format('Y-m-d') : (string) $txn['date'];
            $row = [
                'date'             => $date,
                'type'             => $txn['type'],
                'amount'           => $txn['amount'],
                'currency'         => $txn['currency_code'] ?? 'AED',
                'description'      => $txn['description'],
                'source'           => $txn['source_name'] ?? '(asset account)',
                'destination'      => $txn['destination_name'] ?? '(asset account)',
                'tags'             => $txn['tags'] ?? [],
                'foreign_amount'   => $txn['foreign_amount'] ?? null,
                'foreign_currency' => $txn['foreign_currency_code'] ?? null,
                'skipped'          => false,
            ];
            if (isset($txn['original_id'])) {
                $row['original_id'] = $txn['original_id'];
            }
            if (isset($txn['original_raw'])) {
                $row['original_raw'] = $txn['original_raw'];
            }
            $rows[] = $row;
        }

        $skippedRows = [];
        foreach ($parseResult['skipped'] as $skip) {
            $row = [
                'date'             => $skip['date'] ?? '?',
                'type'             => $skip['type'] ?? 'unknown',
                'amount'           => $skip['amount'] ?? '0',
                'currency'         => $skip['currency_code'] ?? 'AED',
                'description'      => $skip['description'] ?? '',
                'source'           => '',
                'destination'      => '',
                'tags'             => [],
                'foreign_amount'   => null,
                'foreign_currency' => null,
                'skipped'          => true,
                'skip_reason'      => $skip['reason'] ?? 'Skipped',
            ];
            if (isset($skip['original_id'])) {
                $row['original_id'] = $skip['original_id'];
            }
            if (isset($skip['original_raw'])) {
                $row['original_raw'] = $skip['original_raw'];
            }
            $skippedRows[] = $row;
        }

        return [
            'transactions'  => array_merge($rows, $skippedRows),
            'skipped'       => $parseResult['skipped'],
            'total'         => count($rows),
            'skipped_count' => count($skippedRows),
        ];
    }

    private function parseOverrides(Request $request): array
    {
        $raw = $request->input('overrides', '[]');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    private function getReferenceData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $budgets    = $user->budgets()->where('active', true)->orderBy('name')->get(['id', 'name']);
        $categories = $user->categories()->orderBy('name')->get(['id', 'name']);
        $bills      = $user->bills()->where('active', true)->orderBy('name')->get(['id', 'name']);

        return [
            'budgetsJson'    => $budgets->toJson(),
            'categoriesJson' => $categories->toJson(),
            'billsJson'      => $bills->toJson(),
        ];
    }

    private function applyRulesSync(TransactionGroup $group, User $user): void
    {
        $journals = $group->transactionJournals()->get();
        if ($journals->isEmpty()) {
            return;
        }

        $journalIds = $journals->pluck('id')->toArray();

        /** @var RuleGroupRepositoryInterface $ruleGroupRepository */
        $ruleGroupRepository = app(RuleGroupRepositoryInterface::class);
        $ruleGroupRepository->setUser($user);

        $groups = $ruleGroupRepository->getRuleGroupsWithRules('store-journal');

        /** @var RuleEngineInterface $ruleEngine */
        $ruleEngine = app(RuleEngineInterface::class);
        $ruleEngine->setUser($user);
        $ruleEngine->addOperator(['type' => 'journal_id', 'value' => implode(',', $journalIds)]);
        $ruleEngine->setRuleGroups($groups);
        $ruleEngine->fire();
    }

    private function executeImport(array $transactions, bool $dryRun = false, array $overrides = []): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $stats = ['created' => 0, 'updated' => 0, 'duplicates' => 0, 'failed' => 0, 'errors' => [], 'dry_run' => $dryRun, 'details' => []];

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            /** @var TransactionGroupFactory $factory */
            $factory = app(TransactionGroupFactory::class);
            $factory->setUser($user);

            foreach ($transactions as $idx => $mapped) {
                if (isset($overrides[$idx])) {
                    $ov = $overrides[$idx];
                    if (!empty($ov['budget_id'])) {
                        $mapped['budget_id'] = (int) $ov['budget_id'];
                    }
                    if (!empty($ov['category_id'])) {
                        $mapped['category_id'] = (int) $ov['category_id'];
                    }
                    if (!empty($ov['bill_id'])) {
                        $mapped['bill_id'] = (int) $ov['bill_id'];
                    }
                }

                $date = $mapped['date'] instanceof Carbon ? $mapped['date']->format('Y-m-d') : (string) $mapped['date'];
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
                    // Auth→settle: same external_id may return with a new billed amount.
                    // Update the existing journal instead of keeping the first (auth) amount
                    // or creating a second copy under a different import hash.
                    $existingJournal = $this->findJournalByExternalId($mapped['external_id'] ?? null);
                    if (null !== $existingJournal) {
                        $updateResult = $this->refreshExistingJournalAmount($existingJournal, $mapped);
                        if ('updated' === $updateResult['status']) {
                            ++$stats['updated'];
                            $detail['status']  = 'updated';
                            $detail['message'] = $updateResult['message'];
                        } else {
                            ++$stats['duplicates'];
                            $detail['status']  = 'duplicate';
                            $detail['message'] = 'Duplicate transaction already exists';
                        }
                        $stats['details'][] = $detail;

                        continue;
                    }

                    $groupData = [
                        'user'                    => $user,
                        'user_group'              => $user->userGroup,
                        'group_title'             => null,
                        'error_if_duplicate_hash' => true,
                        'apply_rules'             => true,
                        'fire_webhooks'           => false,
                        'transactions'            => [$mapped],
                    ];

                    $group = $factory->create($groupData);
                    ++$stats['created'];

                    $this->applyRulesSync($group, $user);

                    /** @var null|TransactionJournal $journal */
                    $journal = $group->transactionJournals()->first();
                    if (null !== $journal) {
                        $journal->refresh();
                        $bill = $journal->bill;
                        if (null !== $bill) {
                            $detail['bill_name'] = $bill->name;
                        }
                        $budget = $journal->budgets()->first();
                        if (null !== $budget) {
                            $detail['budget_name'] = $budget->name;
                        }
                        $category = $journal->categories()->first();
                        if (null !== $category) {
                            $detail['category_name'] = $category->name;
                        }
                    }
                } catch (\Exception $e) {
                    $msg = $e->getMessage();
                    if (str_contains(strtolower($msg), 'duplicate')) {
                        ++$stats['duplicates'];
                        $detail['status']  = 'duplicate';
                        $detail['message'] = 'Duplicate transaction already exists';
                    } else {
                        ++$stats['failed'];
                        $detail['status']  = 'failed';
                        $detail['message'] = $msg;
                        $stats['errors'][] = sprintf('%s: %s', mb_substr($mapped['description'], 0, 40), $msg);
                        Log::error(sprintf('BankImport failed: %s — %s', $mapped['description'], $msg));
                    }
                }

                $stats['details'][] = $detail;
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        return response()->json($stats);
    }

    private function findJournalByExternalId(null|string $externalId): ?TransactionJournal
    {
        if (null === $externalId || '' === $externalId) {
            return null;
        }

        $meta = TransactionJournalMeta::where('name', 'external_id')
            ->where('hash', hash('sha256', json_encode($externalId, JSON_THROW_ON_ERROR)))
            ->whereNull('deleted_at')
            ->first()
        ;

        if (null === $meta) {
            return null;
        }

        /** @var null|TransactionJournal $journal */
        $journal = TransactionJournal::where('id', $meta->transaction_journal_id)
            ->whereNull('deleted_at')
            ->first()
        ;

        return $journal;
    }

    /**
     * When a bank export revisits an already-imported external_id (e.g. AUTHORIZED → SETTLED
     * with a final FX amount), overwrite Firefly's amount with the bank's current amount.
     *
     * @return array{status: string, message: string}
     */
    private function refreshExistingJournalAmount(TransactionJournal $journal, array $mapped): array
    {
        $newAmount = Steam::positive((string) ($mapped['amount'] ?? '0'));
        $anyTxn    = $journal->transactions()->whereNull('deleted_at')->first();
        if (null === $anyTxn) {
            return ['status' => 'duplicate', 'message' => 'Duplicate transaction already exists'];
        }

        $oldAmount = Steam::positive((string) $anyTxn->amount);
        if (0 === bccomp($oldAmount, $newAmount, 12)) {
            return ['status' => 'duplicate', 'message' => 'Duplicate transaction already exists'];
        }

        $group = $journal->transactionGroup;
        if (null === $group) {
            return ['status' => 'duplicate', 'message' => 'Duplicate transaction already exists'];
        }

        $update = [
            'transaction_journal_id' => $journal->id,
            'amount'                 => $newAmount,
        ];
        if (isset($mapped['foreign_amount'], $mapped['foreign_currency_code'])) {
            $update['foreign_amount']        = (string) $mapped['foreign_amount'];
            $update['foreign_currency_code'] = (string) $mapped['foreign_currency_code'];
        }

        /** @var GroupUpdateService $updateService */
        $updateService = app(GroupUpdateService::class);
        $updateService->update($group, ['transactions' => [$update]]);

        Log::info(sprintf(
            'BankImport updated amount for external_id %s: %s → %s (%s)',
            (string) ($mapped['external_id'] ?? '?'),
            $oldAmount,
            $newAmount,
            (string) ($mapped['description'] ?? '')
        ));

        return [
            'status'  => 'updated',
            'message' => sprintf('Updated amount %s → %s (settled/re-import)', $oldAmount, $newAmount),
        ];
    }
}
