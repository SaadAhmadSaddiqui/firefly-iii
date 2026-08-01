<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use Carbon\Carbon;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\User;
use Illuminate\Console\Command;

/**
 * Apply July 2026 budget limits from storage/budget-plans/JULY_BUDGET_2026.md
 */
class SetupJulyBudget extends Command
{
    protected $description = 'Set Firefly III budget limits and auto-budget amounts for July 2026.';

    protected $signature = 'firefly:setup-july-budget
                            {--user=1 : Firefly III user ID}
                            {--dry-run : Preview without saving}';

    /**
     * @var array<int, array{name: string, amount: string, auto?: bool, create?: bool, inactive?: bool}>
     */
    private const array JULY_BUDGETS = [
        ['name' => 'Transfer to Dad', 'amount' => '5000'],
        ['name' => 'Dad Reimbursement', 'amount' => '0', 'auto' => false, 'inactive' => true],
        ['name' => 'OSAP Repayment', 'amount' => '3000', 'auto' => false],
        ['name' => 'Debt Repayment', 'amount' => '0'],
        ['name' => 'Rent', 'amount' => '0'],
        ['name' => "Bashaair's Bag Savings", 'amount' => '0', 'inactive' => true],
        ['name' => "Bashaair's Bag Purchase", 'amount' => '0', 'inactive' => true],
        ['name' => "Bashaair's Shopping", 'amount' => '0'], // tracking only — no cap
        ['name' => "Bashaair's Grooming", 'amount' => '300', 'create' => true],
        ['name' => 'Groceries', 'amount' => '1800'],
        ['name' => 'Dining Out', 'amount' => '800'],
        ['name' => 'Food Delivery', 'amount' => '500'],
        ['name' => 'Utilities (e& + SEWA)', 'amount' => '1300'],
        ['name' => 'Business Tools', 'amount' => '1250'],
        ['name' => 'Personal Subscriptions', 'amount' => '550'],
        ['name' => 'Transportation', 'amount' => '650'],
        ['name' => 'Fitness (GymNation, 2 people)', 'amount' => '400'],
        ['name' => 'Shopping', 'amount' => '400'],
        ['name' => 'Grooming', 'amount' => '250'], // Saad
        ['name' => 'Family Transfer', 'amount' => '300', 'create' => true],
        ['name' => 'Miscellaneous / Cash', 'amount' => '300', 'create' => true],
        ['name' => 'Donations', 'amount' => '280'],
        ['name' => 'Additional Donation', 'amount' => '0', 'auto' => false, 'inactive' => true],
        ['name' => 'Medical', 'amount' => '150'],
        ['name' => 'Bank Fees', 'amount' => '50', 'create' => true],
        ['name' => 'Activities / Outings', 'amount' => '0'],
        ['name' => 'Education (Preply)', 'amount' => '0', 'inactive' => true],
        ['name' => 'Family Usage of Credit Cards', 'amount' => '0'],
        ['name' => 'Government Services', 'amount' => '0'],
        ['name' => 'Dawat (One-Time)', 'amount' => '0'],
        ['name' => 'Investing (Capital.com)', 'amount' => '0', 'create' => true, 'inactive' => true],
        ['name' => 'Canadian Immigration Fees', 'amount' => '4000', 'create' => true], // one-off; keep through refund+retry
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $user   = User::find((int) $this->option('user'));
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        if (!$user) {
            $this->error('User not found.');

            return 1;
        }

        $currency = TransactionCurrency::where('code', 'AED')->first();
        if (!$currency) {
            $this->error('AED currency not found.');

            return 1;
        }

        $periodStart = Carbon::parse('2026-07-01');
        $periodEnd   = Carbon::parse('2026-07-31');

        $this->info('═══════════════════════════════════════════════════');
        $this->info('  JULY 2026 BUDGET LIMITS');
        $this->info('═══════════════════════════════════════════════════');
        $this->newLine();

        foreach (self::JULY_BUDGETS as $def) {
            $this->applyBudget($user, $currency, $periodStart, $periodEnd, $def, $prefix, $dryRun);
        }

        $this->newLine();
        $this->info('--- SUBSCRIPTIONS ---');
        $this->deactivateBill($user, 'Preply', $prefix, $dryRun);

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete.' : 'July 2026 budgets applied.');
        $this->line('Manual (not automated): settle CCs, block LinkedIn on card, cancel Audible/Talabat Pro if unused.');
        $this->line('When freelance lands: raise OSAP Repayment limit to AED 5,331 and pay the top-up.');

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
            $this->line(sprintf('  %sCreate budget "%s" → AED %s (July)', $prefix, $def['name'], $def['amount']));
            if ($dryRun) {
                return;
            }
            $budget = Budget::create([
                'user_id'       => $user->id,
                'user_group_id' => $user->user_group_id,
                'name'          => $def['name'],
                'active'        => empty($def['inactive']),
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
        } elseif (!$budget->active) {
            $this->line(sprintf('  %sReactivate "%s"', $prefix, $def['name']));
            if (!$dryRun) {
                $budget->active = true;
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
            $limit->amount   = $def['amount'];
            $limit->end_date = $periodEnd;
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

    private function deactivateBill(User $user, string $name, string $prefix, bool $dryRun): void
    {
        $bill = Bill::where('user_id', $user->id)
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->first();

        if (!$bill) {
            $this->warn(sprintf('  %sBill "%s" not found — skipped.', $prefix, $name));

            return;
        }

        if (!$bill->active) {
            $this->line(sprintf('  %sBill "%s" already inactive.', $prefix, $name));

            return;
        }

        $this->line(sprintf('  %sDeactivate bill "%s"', $prefix, $name));
        if (!$dryRun) {
            $bill->active = false;
            $bill->save();
        }
    }
}
