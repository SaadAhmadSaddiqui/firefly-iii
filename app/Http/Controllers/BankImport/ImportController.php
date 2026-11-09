<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\BankImport;

use Carbon\Carbon;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Services\BankImport\BankImportService;
use FireflyIII\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bank import pages (Emirates NBD / Emirates Islamic, Mashreq, FAB): upload or paste an export,
 * preview what will be created, then import. All parsing and importing lives in BankImportService.
 */
class ImportController extends Controller
{
    private const array PAGES = [
        'enbd'    => ['title' => 'Emirates NBD / Islamic', 'format' => 'json', 'accept' => '.json'],
        'mashreq' => ['title' => 'Mashreq', 'format' => 'json', 'accept' => '.json,.csv'],
        'fab'     => ['title' => 'FAB', 'format' => 'csv', 'accept' => '.csv'],
    ];

    public function __construct(private readonly BankImportService $service)
    {
        parent::__construct();

        $this->middleware(static function ($request, $next) {
            app('view')->share('mainTitleIcon', 'bi-bank');
            app('view')->share('title', 'Bank Import');

            return $next($request);
        });
    }

    public function enbd(): View
    {
        return $this->page('enbd');
    }

    public function mashreq(): View
    {
        return $this->page('mashreq');
    }

    public function fab(): View
    {
        return $this->page('fab');
    }

    public function previewEnbd(Request $request): JsonResponse
    {
        return $this->preview($request, 'enbd');
    }

    public function previewMashreq(Request $request): JsonResponse
    {
        return $this->preview($request, 'mashreq');
    }

    public function previewFab(Request $request): JsonResponse
    {
        return $this->preview($request, 'fab');
    }

    public function importEnbd(Request $request): JsonResponse
    {
        return $this->import($request, 'enbd');
    }

    public function importMashreq(Request $request): JsonResponse
    {
        return $this->import($request, 'mashreq');
    }

    public function importFab(Request $request): JsonResponse
    {
        return $this->import($request, 'fab');
    }

    private function page(string $bank): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('bank-import.'.$bank, [
            'subTitle'       => self::PAGES[$bank]['title'],
            'bank'           => $bank,
            'accept'         => self::PAGES[$bank]['accept'],
            'defaultAccount' => BankImportService::DEFAULT_ACCOUNTS[$bank],
            'budgets'        => $user->budgets()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'categories'     => $user->categories()->orderBy('name')->get(['id', 'name']),
            'bills'          => $user->bills()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function preview(Request $request, string $bank): JsonResponse
    {
        $content = $this->extractContent($request);
        if (null === $content) {
            return response()->json(['error' => 'No content provided. Upload a file or paste the export.'], 422);
        }
        $result = $this->service->parse($bank, $content, $this->accountId($request, $bank), (string) $request->input('format', self::PAGES[$bank]['format']));

        return response()->json($this->formatPreview($result));
    }

    private function import(Request $request, string $bank): JsonResponse
    {
        $content = $this->extractContent($request);
        if (null === $content) {
            return response()->json(['error' => 'No content provided.'], 422);
        }
        $result = $this->service->parse($bank, $content, $this->accountId($request, $bank), (string) $request->input('format', self::PAGES[$bank]['format']));

        /** @var User $user */
        $user = auth()->user();

        return response()->json($this->service->import($user, $result['transactions'], (bool) $request->input('dry_run', false), $this->overrides($request)));
    }

    private function accountId(Request $request, string $bank): int
    {
        return (int) $request->input('source_account_id', BankImportService::DEFAULT_ACCOUNTS[$bank]);
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

        return is_string($pasted) && '' !== trim($pasted) ? $pasted : null;
    }

    private function overrides(Request $request): array
    {
        $raw = $request->input('overrides', '[]');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array{transactions: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>} $parsed
     */
    private function formatPreview(array $parsed): array
    {
        $rows = [];
        foreach ($parsed['transactions'] as $txn) {
            $row = [
                'date'             => $txn['date'] instanceof Carbon ? $txn['date']->format('Y-m-d') : (string) $txn['date'],
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
            foreach (['original_id', 'original_raw'] as $key) {
                if (isset($txn[$key])) {
                    $row[$key] = $txn[$key];
                }
            }
            $rows[] = $row;
        }

        $skippedRows = [];
        foreach ($parsed['skipped'] as $skip) {
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
            foreach (['original_id', 'original_raw'] as $key) {
                if (isset($skip[$key])) {
                    $row[$key] = $skip[$key];
                }
            }
            $skippedRows[] = $row;
        }

        return [
            'transactions'  => array_merge($rows, $skippedRows),
            'skipped'       => $parsed['skipped'],
            'total'         => count($rows),
            'skipped_count' => count($skippedRows),
        ];
    }
}
