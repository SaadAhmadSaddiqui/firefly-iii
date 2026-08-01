<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use Carbon\Carbon;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\User;
use Illuminate\Console\Command;

/**
 * Apply June 2026 budget limits from storage/budget-plans/JUNE_BUDGET_2026.md
 */
class SetupJuneBudget extends Command
{
    protected $description = 'Set Firefly III budget limits and auto-budget amounts for June 2026.';

    protected $signature = 'firefly:setup-june-budget
                            {--user=1 : Firefly III user ID}
                            {--dry-run : Preview without saving}';

    /** @var array<int, array{name: string, amount: string, auto?: bool, create?: bool, inactive?: bool}> */
    private const array JUNE_BUDGETS = [
        ['name' => 'Transfer to Dad', 'amount' => '5000'],
        ['name' => 'Dad Reimbursement', 'amount' => '600', 'create' => true, 'auto' => false],
        ['name' => 'Rent', 'amount' => '0'],
        ['name' => 'Debt Repayment', 'amount' => '0'],
        ['name' => 'OSAP Repayment', 'amount' => '0', 'create' => true, 'auto' => false],
        ['name' => 'Groceries', 'amount' => '1600'],
        ['name' => 'Business Tools', 'amount' => '1220'],
        ['name' => 'Education (Preply)', 'amount' => '1181'],
        ['name' => 'Utilities (e& + SEWA)', 'amount' => '1150'],
        ['name' => 'Personal Subscriptions', 'amount' => '520'],
        ['name' => 'Transportation', 'amount' => '400'],
        ['name' => 'Fitness (GymNation, 2 people)', 'amount' => '197'],
        ['name' => 'Donations', 'amount' => '271'],
        ['name' => 'Additional Donation', 'amount' => '500', 'create' => true, 'auto' => false],
        ['name' => 'Food Delivery', 'amount' => '100'],
        ['name' => 'Dining Out', 'amount' => '100'],
        ['name' => 'Grooming', 'amount' => '90'],
        ['name' => 'Medical', 'amount' => '50'],
        ['name' => 'Family Usage of Credit Cards', 'amount' => '75'],
        ['name' => 'Shopping', 'amount' => '0'],
        ['name' => 'Activities / Outings', 'amount' => '0'],
        ['name' => "Bashaair's Bag Savings", 'amount' => '0'],
        ['name' => 'Government Services', 'amount' => '0'],
        ['name' => 'Dawat (One-Time)', 'amount' => '0'],
        ['name' => 'Qurbani / Eid', 'amount' => '0', 'inactive' => true],
        ["name" => "Bashaair's Bag Purchase", 'amount' => '0', 'inactive' => true],
    ];

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $user     = User::find((int) $this->option('user'));
        $prefix   = $dryRun ? '[DRY RUN] ' : '';

        if (!$user) {
            $this->error('User not found.');

            return 1;
        }

        $currency = TransactionCurrency::where('code', 'AED')->first();
        if (!$currency) {
            $this->error('AED currency not found.');

            return 1;
        }

        $periodStart = Carbon::parse('2026-06-01');
        $periodEnd   = Carbon::parse('2026-06-30');

        $this->info('═══════════════════════════════════════════════════');
        $this->info('  JUNE 2026 BUDGET LIMITS');
        $this->info('═══════════════════════════════════════════════════');
        $this->newLine();

        foreach (self::JUNE_BUDGETS as $def) {
            $this->applyBudget($user, $currency, $periodStart, $periodEnd, $def, $prefix, $dryRun);
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete.' : 'June 2026 budgets applied.');
        $this->line('Note: July OSAP — set OSAP Repayment limit to AED 5,331 when July starts.');
        $this->line('Note: Restore Rent auto-budget to 4,833 and Bashaair\'s Bag to 562.50 in July.');

        return 0;
    }

    /**
     * @param array{name: string, amount: string, auto?: bool, create?: bool, inactive?: bool} $def
     */
    private function applyBudget(
        User $user,
        TransactionCurrency $currency,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $def,
        string $prefix,
        bool $dryRun,
    ): void {
        $useAuto = $def['auto'] ?? true;
        $budget  = Budget::where('user_id', $user->id)
            ->where('name', $def['name'])
            ->whereNull('deleted_at')
            ->first();

        if (!$budget && !empty($def['create'])) {
            $this->line(sprintf('  %sCreate budget "%s" → AED %s (June)', $prefix, $def['name'], $def['amount']));
            if ($dryRun) {
                return;
            }
            $budget = Budget::create([
                'user_id'       => $user->id,
                'user_group_id' => $user->user_group_id,
                'name'          => $def['name'],
                'active'        => true,
                'order'         => (int) Budget::where('user_id', $user->id)->max('order') + 1,
            ]);
        }

        if (!$budget) {
            $this->warn(sprintf('  %sBudget "%s" not found — skipped.', $prefix, $def['name']));

            return;
        }

        if (!empty($def['inactive'])) {
            $this->line(sprintf('  %sDeactivate "%s"', $prefix, $def['name']));
            if (!$dryRun) {
                $budget->active = false;
                $budget->save();
            }
        }

        $this->line(sprintf('  %s%-42s AED %8s', $prefix, $def['name'], $def['amount']));

        if ($dryRun) {
            return;
        }

        if ($useAuto) {
            $autoBudget = AutoBudget::where('budget_id', $budget->id)->first();
            if ($autoBudget) {
                $autoBudget->amount = $def['amount'];
                $autoBudget->save();
            } else {
                $autoBudget                          = new AutoBudget();
                $autoBudget->budget()->associate($budget);
                $autoBudget->transaction_currency_id = $currency->id;
                $autoBudget->auto_budget_type        = AutoBudgetType::AUTO_BUDGET_RESET->value;
                $autoBudget->amount                  = $def['amount'];
                $autoBudget->period                  = 'monthly';
                $autoBudget->save();
            }
        }

        $limit = BudgetLimit::where('budget_id', $budget->id)
            ->where('transaction_currency_id', $currency->id)
            ->where('start_date', $periodStart->format('Y-m-d'))
            ->first();

        if ($limit) {
            $limit->amount    = $def['amount'];
            $limit->end_date  = $periodEnd;
            $limit->save();
        } else {
            $this->createBudgetLimit($budget, $currency, $periodStart, $periodEnd, $def['amount']);
        }
    }

    private function createBudgetLimit(Budget $budget, TransactionCurrency $currency, Carbon $start, Carbon $end, string $amount): BudgetLimit
    {
        $limit                          = new BudgetLimit();
        $limit->budget()->associate($budget);
        $limit->start_date              = $start;
        $limit->end_date                = $end;
        $limit->amount                  = $amount;
        $limit->transaction_currency_id = $currency->id;
        $limit->generated               = false;
        $limit->period                  = 'monthly';
        $limit->save();

        return $limit;
    }
}
