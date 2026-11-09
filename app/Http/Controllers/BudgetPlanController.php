<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Services\BudgetPlan\BudgetPlanDataCollector;
use FireflyIII\Services\BudgetPlan\GeminiService;
use FireflyIII\Services\BudgetPlan\PromptBuilder;
use FireflyIII\Support\Facades\Steam;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BudgetPlanController extends Controller
{
    private const array CREDIT_CARDS = [
        ['key' => 'ei',      'label' => 'EI RTA CC',  'account_id' => 49],
        ['key' => 'mashreq', 'label' => 'Mashreq CC', 'account_id' => 50],
        ['key' => 'fab',     'label' => 'FAB CC',     'account_id' => 51],
    ];

    private const array FIXED_OBLIGATIONS = [
        ['key' => 'dad_transfer', 'label' => 'Transfer to Dad (Anees Ahmad)', 'amount' => '5000.00'],
        ['key' => 'donations',    'label' => 'Donations (Droplets of Mercy + Impact Guru)', 'amount' => '384.00'],
        ['key' => 'gymnation',    'label' => 'GymNation (2 people)', 'amount' => '400.00'],
    ];

    private const int EMIRATES_NBD_ACCOUNT_ID = 1;

    /** Sub-folders of storage/budget-plans, in display order. Files are named YYYY-MM_TITLE.md. */
    private const array CATEGORIES = [
        'monthly' => 'Monthly Budget Plans',
        'reviews' => 'Reviews & Reflections',
        'goals'   => 'Savings Goals',
        'drafts'  => 'Drafts & Tests',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            app('view')->share('title', 'Budget Plans');
            app('view')->share('mainTitleIcon', 'fa-book');

            return $next($request);
        });
    }

    public function index(): Factory|\Illuminate\Contracts\View\View
    {
        $groups = [];
        foreach ($this->planFiles() as $plan) {
            $groups[$plan['category']] ??= ['title' => self::CATEGORIES[$plan['category']] ?? 'Other', 'plans' => []];
            $groups[$plan['category']]['plans'][] = $plan;
        }
        $plans = array_merge(...array_column($groups, 'plans') ?: [[]]);

        return view('budget-plans.index', compact('groups', 'plans'));
    }

    public function create(): Factory|\Illuminate\Contracts\View\View
    {
        $subTitle      = 'Create New Budget Plan';
        $defaultSalary = 25724.42;
        $defaultStart  = Carbon::now()->subMonths(3)->format('Y-m-d');
        $defaultEnd    = Carbon::now()->format('Y-m-d');
        $geminiReady   = '' !== (string) config('gemini.api_key');

        $historicalPlans = $this->listBudgetPlans();

        $nextMonth          = Carbon::now()->addMonth();
        $defaultBudgetMonth = (int) $nextMonth->format('n');
        $defaultBudgetYear  = (int) $nextMonth->format('Y');

        $budgetMonths = [];
        for ($m = 1; $m <= 12; $m++) {
            $budgetMonths[] = [
                'value' => $m,
                'label' => Carbon::create(2000, $m, 1)->format('F'),
            ];
        }

        $accountBalances = $this->getAccountBalances();

        $defaultBankBalance = $accountBalances[self::EMIRATES_NBD_ACCOUNT_ID] ?? '0';
        $defaultCashOnHand  = '0';

        $creditCards = [];
        foreach (self::CREDIT_CARDS as $cc) {
            $creditCards[] = array_merge($cc, [
                'balance' => $accountBalances[$cc['account_id']] ?? '0',
            ]);
        }

        $fixedObligations = array_map(fn (array $ob) => array_merge($ob, ['status' => 'active']), self::FIXED_OBLIGATIONS);

        $defaultBudgetPeriodStart = Carbon::now()->format('Y-m-d');
        $defaultBudgetPeriodEnd   = Carbon::now()->addDays(31)->format('Y-m-d');

        return view('budget-plans.create', compact(
            'subTitle',
            'defaultSalary',
            'defaultStart',
            'defaultEnd',
            'geminiReady',
            'historicalPlans',
            'budgetMonths',
            'defaultBudgetMonth',
            'defaultBudgetYear',
            'defaultBankBalance',
            'defaultCashOnHand',
            'creditCards',
            'fixedObligations',
            'defaultBudgetPeriodStart',
            'defaultBudgetPeriodEnd',
        ));
    }

    public function generate(Request $request): RedirectResponse
    {
        set_time_limit(0);

        $request->validate([
            'salary'                            => 'required|numeric|min:0',
            'bank_balance'                      => 'required|numeric|min:0',
            'cash_on_hand'                      => 'nullable|numeric|min:0',
            'cc_balances'                       => 'nullable|array',
            'cc_balances.*'                     => 'nullable|numeric|min:0',
            'start_date'                        => 'required|date',
            'end_date'                          => 'required|date|after_or_equal:start_date',
            'budget_month'                      => 'required|integer|between:1,12',
            'budget_year'                       => 'required|integer|min:2020|max:2100',
            'budget_period_start'               => 'required|date',
            'budget_period_end'                 => 'required|date|after_or_equal:budget_period_start',
            'goals'                             => 'nullable|string|max:5000',
            'extra_expenses'                    => 'nullable|array',
            'extra_expenses.*.name'             => 'nullable|string|max:255',
            'extra_expenses.*.amount'           => 'nullable|numeric|min:0',
            'already_paid'                      => 'nullable|array',
            'already_paid.*.name'               => 'nullable|string|max:255',
            'already_paid.*.amount'             => 'nullable|numeric|min:0',
            'already_paid.*.account'            => 'nullable|string|max:255',
            'fixed_obligations'                 => 'nullable|array',
            'fixed_obligations.*'               => 'nullable|string|in:active,paused,cancelled',
            'fixed_obligation_amounts'          => 'nullable|array',
            'fixed_obligation_labels'           => 'nullable|array',
            'subscription_changes'              => 'nullable|array',
            'subscription_changes.*.name'       => 'nullable|string|max:255',
            'subscription_changes.*.action'     => 'nullable|string|in:cancelled,paused,new,price_change',
            'subscription_changes.*.amount'     => 'nullable|numeric|min:0',
            'reference_plans'                   => 'nullable|array',
            'reference_plans.*'                 => 'string|max:255',
        ]);

        $salary      = (float) $request->input('salary');
        $bankBalance = (float) $request->input('bank_balance');
        $cashOnHand  = (float) $request->input('cash_on_hand', 0);
        $startDate   = Carbon::parse($request->input('start_date'));
        $endDate     = Carbon::parse($request->input('end_date'));
        $budgetMonth = (int) $request->input('budget_month');
        $budgetYear  = (int) $request->input('budget_year');
        $goals       = (string) $request->input('goals', '');

        $budgetPeriodStart = $request->input('budget_period_start');
        $budgetPeriodEnd   = $request->input('budget_period_end');

        $ccBalances = [];
        foreach (self::CREDIT_CARDS as $cc) {
            $ccBalances[$cc['label']] = (float) ($request->input('cc_balances.' . $cc['key'], 0));
        }

        $extraExpenses = collect($request->input('extra_expenses', []))
            ->filter(fn ($e) => !empty($e['name']) && !empty($e['amount']))
            ->values()
            ->toArray();

        $alreadyPaid = collect($request->input('already_paid', []))
            ->filter(fn ($e) => !empty($e['name']) && !empty($e['amount']))
            ->values()
            ->toArray();

        $fixedObligations = [];
        $obligationStatuses = $request->input('fixed_obligations', []);
        $obligationAmounts  = $request->input('fixed_obligation_amounts', []);
        $obligationLabels   = $request->input('fixed_obligation_labels', []);
        foreach ($obligationStatuses as $key => $status) {
            $fixedObligations[] = [
                'key'    => $key,
                'label'  => $obligationLabels[$key] ?? $key,
                'amount' => (float) ($obligationAmounts[$key] ?? 0),
                'status' => $status,
            ];
        }

        $subscriptionChanges = collect($request->input('subscription_changes', []))
            ->filter(fn ($e) => !empty($e['name']))
            ->values()
            ->toArray();

        $referencePlans = $this->loadSelectedPlans($request->input('reference_plans', []));

        $user = auth()->user();

        try {
            $collector     = new BudgetPlanDataCollector();
            $financialData = $collector->collect($user, $startDate, $endDate);

            $budgetForDate = Carbon::create($budgetYear, $budgetMonth, 1);

            $promptBuilder = new PromptBuilder();
            $prompts       = $promptBuilder->build(
                salary: $salary,
                bankBalance: $bankBalance,
                cashOnHand: $cashOnHand,
                ccBalances: $ccBalances,
                budgetPeriodStart: $budgetPeriodStart,
                budgetPeriodEnd: $budgetPeriodEnd,
                startDate: $startDate->format('Y-m-d'),
                endDate: $endDate->format('Y-m-d'),
                extraExpenses: $extraExpenses,
                alreadyPaid: $alreadyPaid,
                fixedObligations: $fixedObligations,
                subscriptionChanges: $subscriptionChanges,
                goals: $goals,
                financialData: $financialData,
                referencePlans: $referencePlans,
                budgetFor: $budgetForDate->format('F Y'),
            );

            $gemini   = new GeminiService();
            $markdown = $gemini->generate($prompts['system'], $prompts['user']);

            $filename = $this->generateFilename($budgetMonth, $budgetYear);
            $dir      = storage_path('budget-plans/monthly');

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($dir . '/' . $filename, $markdown);

            session()->flash('success', 'Budget plan generated successfully!');

            return redirect()->route('budget-plans.show', ['path' => 'monthly/' . $filename]);
        } catch (\RuntimeException $e) {
            session()->flash('error', 'Failed to generate budget plan: ' . $e->getMessage());

            return redirect()->route('budget-plans.create')
                ->withInput();
        }
    }

    public function show(string $path): Factory|\Illuminate\Contracts\View\View
    {
        $full = $this->resolvePlanPath($path);
        if (null === $full) {
            throw new NotFoundHttpException();
        }

        $content = file_get_contents($full);
        if (false === $content) {
            throw new NotFoundHttpException();
        }

        $filename = basename($full);
        $subTitle = $this->describe($filename)['name'];

        return view('budget-plans.show', compact('content', 'subTitle', 'filename'));
    }

    private function getAccountBalances(): array
    {
        /** @var AccountRepositoryInterface $repo */
        $repo = app(AccountRepositoryInterface::class);

        $allIds = array_merge(
            [self::EMIRATES_NBD_ACCOUNT_ID],
            array_column(self::CREDIT_CARDS, 'account_id')
        );

        $accounts   = new Collection();
        foreach ($allIds as $id) {
            $account = $repo->find($id);
            if (null !== $account) {
                $accounts->push($account);
            }
        }

        if ($accounts->isEmpty()) {
            return [];
        }

        $now      = Carbon::now();
        $balances = Steam::accountsBalancesOptimized($accounts, $now);

        $result = [];
        foreach ($accounts as $account) {
            $result[$account->id] = $balances[$account->id]['balance'] ?? '0';
        }

        return $result;
    }

    /** @return array<string, string> relative path => label, newest first (for the "reference plans" picker) */
    private function listBudgetPlans(): array
    {
        $files = $this->planFiles();
        usort($files, static fn (array $a, array $b): int => strcmp($b['filename'], $a['filename']));
        $plans = [];
        foreach ($files as $plan) {
            $plans[$plan['path']] = trim($plan['period'] . ' ' . $plan['name']);
        }

        return $plans;
    }

    private function loadSelectedPlans(array $paths): array
    {
        $plans = [];
        foreach ($paths as $path) {
            $full = $this->resolvePlanPath((string) $path);
            if (null === $full) {
                continue;
            }
            $content = file_get_contents($full);
            if (false !== $content) {
                $plans[] = ['name' => $this->describe(basename($full))['name'], 'content' => $content];
            }
        }

        return $plans;
    }

    private function generateFilename(int $month, int $year): string
    {
        $date     = Carbon::create($year, $month, 1);
        $base     = sprintf('%s_%s_BUDGET', $date->format('Y-m'), strtoupper($date->format('F')));
        $filename = $base . '.md';
        $dir      = storage_path('budget-plans/monthly');
        $counter  = 1;

        while (file_exists($dir . '/' . $filename)) {
            ++$counter;
            $filename = sprintf('%s_%d.md', $base, $counter);
        }

        return $filename;
    }

    /**
     * Every plan file: the category folders first (in CATEGORIES order), then any loose files in the root.
     * Within a category, files sort by name, which is chronological thanks to the YYYY-MM prefix.
     *
     * @return array<int, array{path: string, category: string, filename: string, name: string, period: string, size: int, modified: string}>
     */
    private function planFiles(): array
    {
        $root  = storage_path('budget-plans');
        $plans = [];
        foreach ([...array_keys(self::CATEGORIES), ''] as $category) {
            $files = glob(rtrim($root . '/' . $category, '/') . '/*.md') ?: [];
            sort($files);
            foreach ($files as $file) {
                $filename = basename($file);
                $modified = filemtime($file);
                $plans[]  = [
                    'path'     => ('' === $category ? '' : $category . '/') . $filename,
                    'category' => '' === $category ? 'other' : $category,
                    'filename' => $filename,
                    'size'     => (int) filesize($file),
                    'modified' => false !== $modified ? date('F j, Y', $modified) : 'Unknown',
                ] + $this->describe($filename);
            }
        }

        return $plans;
    }

    /** "2026-03_MARCH_BUDGET.md" → name "March Budget", period "Mar 2026". */
    private function describe(string $filename): array
    {
        $stem = str_replace('.md', '', $filename);
        if (1 === preg_match('/^(\d{4})-(\d{2})_(.+)$/', $stem, $m)) {
            return [
                'name'   => ucwords(strtolower(str_replace('_', ' ', $m[3]))),
                'period' => Carbon::create((int) $m[1], (int) $m[2], 1)->format('M Y'),
            ];
        }

        return ['name' => str_replace('_', ' ', $stem), 'period' => ''];
    }

    /** Map "monthly/2026-03_MARCH_BUDGET.md" (or a loose root file) to a real file inside storage/budget-plans. */
    private function resolvePlanPath(string $path): ?string
    {
        if (1 !== preg_match('#^(?:([a-z]+)/)?([A-Za-z0-9_\-]+\.md)$#', $path, $m)) {
            return null;
        }
        $category = $m[1];
        if ('' !== $category && !array_key_exists($category, self::CATEGORIES)) {
            return null;
        }
        $full = storage_path('budget-plans/' . ('' === $category ? '' : $category . '/') . $m[2]);

        return is_file($full) ? $full : null;
    }
}
