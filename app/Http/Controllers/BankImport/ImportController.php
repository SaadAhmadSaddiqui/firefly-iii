<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\BankImport;

use Carbon\Carbon;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Services\BankImport\EnbdParser;
use FireflyIII\Services\BankImport\FabParser;
use FireflyIII\Services\BankImport\MashreqParser;
use FireflyIII\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return view('bank-import.enbd', compact('subTitle'));
    }

    public function mashreq(): Factory|\Illuminate\Contracts\View\View
    {
        $subTitle = 'Mashreq';

        return view('bank-import.mashreq', compact('subTitle'));
    }

    public function fab(): Factory|\Illuminate\Contracts\View\View
    {
        $subTitle = 'FAB';

        return view('bank-import.fab', compact('subTitle'));
    }

    public function previewEnbd(Request $request): JsonResponse
    {
        $content = $this->extractContent($request, 'json');
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
        $content = $this->extractContent($request, 'csv');
        if (null === $content) {
            return response()->json(['error' => 'No content provided. Upload a file or paste CSV.'], 422);
        }

        $sourceAccountId = (int) $request->input('source_account_id', 50);
        $parser = new MashreqParser();
        $result = $parser->parse($content, $sourceAccountId);

        return response()->json($this->formatPreviewResponse($result));
    }

    public function previewFab(Request $request): JsonResponse
    {
        $content = $this->extractContent($request, 'csv');
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
        $content = $this->extractContent($request, 'json');
        if (null === $content) {
            return response()->json(['error' => 'No content provided.'], 422);
        }

        $sourceAccountId = (int) $request->input('source_account_id', 1);
        $parser = new EnbdParser();
        $result = $parser->parse($content, $sourceAccountId);

        return $this->executeImport($result['transactions']);
    }

    public function importMashreq(Request $request): JsonResponse
    {
        $content = $this->extractContent($request, 'csv');
        if (null === $content) {
            return response()->json(['error' => 'No content provided.'], 422);
        }

        $sourceAccountId = (int) $request->input('source_account_id', 50);
        $parser = new MashreqParser();
        $result = $parser->parse($content, $sourceAccountId);

        return $this->executeImport($result['transactions']);
    }

    public function importFab(Request $request): JsonResponse
    {
        $content = $this->extractContent($request, 'csv');
        if (null === $content) {
            return response()->json(['error' => 'No content provided.'], 422);
        }

        $sourceAccountId = (int) $request->input('source_account_id', 51);
        $parser = new FabParser();
        $result = $parser->parse($content, $sourceAccountId);

        return $this->executeImport($result['transactions']);
    }

    private function extractContent(Request $request, string $expectedType): ?string
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
            $rows[] = [
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
            ];
        }

        return [
            'transactions' => $rows,
            'skipped'      => $parseResult['skipped'],
            'total'        => count($rows),
            'skipped_count' => count($parseResult['skipped']),
        ];
    }

    private function executeImport(array $transactions): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $stats = ['created' => 0, 'duplicates' => 0, 'failed' => 0, 'errors' => []];

        /** @var TransactionGroupFactory $factory */
        $factory = app(TransactionGroupFactory::class);
        $factory->setUser($user);

        foreach ($transactions as $mapped) {
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

                $factory->create($groupData);
                ++$stats['created'];
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                if (str_contains(strtolower($msg), 'duplicate')) {
                    ++$stats['duplicates'];
                } else {
                    ++$stats['failed'];
                    $stats['errors'][] = sprintf('%s: %s', mb_substr($mapped['description'], 0, 40), $msg);
                    Log::error(sprintf('BankImport failed: %s — %s', $mapped['description'], $msg));
                }
            }
        }

        return response()->json($stats);
    }
}
