<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Services\BudgetPlan\BudgetPlanDataCollector;
use FireflyIII\Services\BudgetPlan\GeminiService;
use FireflyIII\Services\BudgetPlan\PromptBuilder;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BudgetPlanController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            app('view')->share('title', 'Budget Plans');
            app('view')->share('mainTitleIcon', 'fa-book');

            return $next($request);
        });
    }

    /**
     * @return Factory|View
     */
    public function index(): Factory|\Illuminate\Contracts\View\View
    {
        $directory = storage_path('budget-plans');
        $plans     = [];

        if (is_dir($directory)) {
            $files = glob($directory . '/*.md');
            if (false !== $files) {
                foreach ($files as $file) {
                    $filename = basename($file);
                    $name     = str_replace(['_', '.md'], [' ', ''], $filename);
                    $modified = filemtime($file);

                    $plans[]  = [
                        'filename' => $filename,
                        'name'     => $name,
                        'size'     => filesize($file),
                        'modified' => false !== $modified ? date('F j, Y', $modified) : 'Unknown',
                    ];
                }
            }
        }

        usort($plans, static fn (array $a, array $b): int => strcmp($b['filename'], $a['filename']));

        return view('budget-plans.index', compact('plans'));
    }

    /**
     * @return Factory|View
     */
    public function create(): Factory|\Illuminate\Contracts\View\View
    {
        $subTitle       = 'Create New Budget Plan';
        $defaultSalary  = 25724.42;
        $defaultStart   = Carbon::now()->subMonths(3)->format('Y-m-d');
        $defaultEnd     = Carbon::now()->format('Y-m-d');
        $geminiReady    = '' !== (string) config('gemini.api_key');
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
        ));
    }

    public function generate(Request $request): RedirectResponse
    {
        set_time_limit(0);

        $request->validate([
            'salary'                  => 'required|numeric|min:0',
            'start_date'              => 'required|date',
            'end_date'                => 'required|date|after_or_equal:start_date',
            'budget_month'            => 'required|integer|between:1,12',
            'budget_year'             => 'required|integer|min:2020|max:2100',
            'goals'                   => 'nullable|string|max:5000',
            'extra_expenses'          => 'nullable|array',
            'extra_expenses.*.name'   => 'nullable|string|max:255',
            'extra_expenses.*.amount' => 'nullable|numeric|min:0',
            'reference_plans'         => 'nullable|array',
            'reference_plans.*'       => 'string|max:255',
        ]);

        $salary      = (float) $request->input('salary');
        $startDate   = Carbon::parse($request->input('start_date'));
        $endDate     = Carbon::parse($request->input('end_date'));
        $budgetMonth = (int) $request->input('budget_month');
        $budgetYear  = (int) $request->input('budget_year');
        $goals       = (string) $request->input('goals', '');

        $extraExpenses = collect($request->input('extra_expenses', []))
            ->filter(fn ($e) => !empty($e['name']) && !empty($e['amount']))
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
                $salary,
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
                $extraExpenses,
                $goals,
                $financialData,
                $referencePlans,
                $budgetForDate->format('F Y'),
            );

            $gemini   = new GeminiService();
            $markdown = $gemini->generate($prompts['system'], $prompts['user']);

            $filename = $this->generateFilename($budgetMonth, $budgetYear);
            $dir      = storage_path('budget-plans');

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($dir . '/' . $filename, $markdown);

            session()->flash('success', 'Budget plan generated successfully!');

            return redirect()->route('budget-plans.show', ['filename' => $filename]);
        } catch (\RuntimeException $e) {
            session()->flash('error', 'Failed to generate budget plan: ' . $e->getMessage());

            return redirect()->route('budget-plans.create')
                ->withInput();
        }
    }

    /**
     * @return Factory|View
     */
    public function show(string $filename): Factory|\Illuminate\Contracts\View\View
    {
        $filename  = basename($filename);

        if (!str_ends_with($filename, '.md')) {
            throw new NotFoundHttpException();
        }

        $path = storage_path('budget-plans/' . $filename);

        if (!file_exists($path)) {
            throw new NotFoundHttpException();
        }

        $content   = file_get_contents($path);
        if (false === $content) {
            throw new NotFoundHttpException();
        }

        $name      = str_replace(['_', '.md'], [' ', ''], $filename);
        $subTitle  = $name;

        return view('budget-plans.show', compact('content', 'subTitle', 'filename'));
    }

    private function listBudgetPlans(): array
    {
        $directory = storage_path('budget-plans');
        $plans     = [];

        if (is_dir($directory)) {
            $files = glob($directory . '/*.md');
            if (false !== $files) {
                foreach ($files as $file) {
                    $filename = basename($file);
                    $label    = str_replace(['_', '.md'], [' ', ''], $filename);
                    $plans[$filename] = $label;
                }
            }
        }

        arsort($plans);

        return $plans;
    }

    private function loadSelectedPlans(array $filenames): array
    {
        $plans = [];
        $dir   = storage_path('budget-plans');

        foreach ($filenames as $filename) {
            $filename = basename($filename);
            $path     = $dir . '/' . $filename;

            if (!file_exists($path) || !str_ends_with($filename, '.md')) {
                continue;
            }

            $content = file_get_contents($path);
            if (false !== $content) {
                $label    = str_replace(['_', '.md'], [' ', ''], $filename);
                $plans[]  = [
                    'name'    => $label,
                    'content' => $content,
                ];
            }
        }

        return $plans;
    }

    private function generateFilename(int $month, int $year): string
    {
        $monthName = strtoupper(Carbon::create($year, $month, 1)->format('F'));
        $base      = sprintf('%s_BUDGET_%d', $monthName, $year);
        $filename  = $base . '.md';
        $dir       = storage_path('budget-plans');
        $counter   = 1;

        while (file_exists($dir . '/' . $filename)) {
            $counter++;
            $filename = sprintf('%s_%d.md', $base, $counter);
        }

        return $filename;
    }
}
