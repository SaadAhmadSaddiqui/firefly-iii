<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use Carbon\Carbon;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * November 2026: budget limits (Nov 1–9 actual + Nov 10–30 plan), subscription
 * clean-up after the Aug–Nov import, and the car loan as an expected bill.
 * See storage/budget-plans/monthly/2026-11_NOVEMBER_BUDGET.md.
 */
class SetupNovemberBudget extends Command
{
    protected $description = 'Apply November 2026 budget limits and subscription changes.';

    protected $signature = 'firefly:setup-november-budget
                            {--user=1 : Firefly III user ID}
                            {--dry-run : Preview without saving}';

    /** @var array<int, array{name: string, amount: string, create?: bool, inactive?: bool}> */
    private const array NOVEMBER_BUDGETS = [
        // Fixed / committed
        ['name' => 'Transfer to Dad', 'amount' => '5000'],
        // Rent Fund piggy is linked to this budget, so each monthly share shows as "spent" here.
        ['name' => 'Rent', 'amount' => '4833'],
        ['name' => 'Business Tools', 'amount' => '2130'],
        ['name' => 'Utilities (e& + SEWA)', 'amount' => '1300'],
        ['name' => 'Personal Subscriptions', 'amount' => '890'],
        ['name' => 'Fitness (GymNation, 2 people)', 'amount' => '394'],
        ['name' => 'Donations', 'amount' => '280'],
        ['name' => 'Referral Commission', 'amount' => '300'],
        // Variable (Nov 1–9 actual + Nov 10–30 allocation)
        ['name' => 'Groceries', 'amount' => '2422'],
        ['name' => 'Food Delivery', 'amount' => '1088'],
        ['name' => 'Transportation', 'amount' => '1039'],
        ['name' => 'Shopping', 'amount' => '801'],
        ['name' => "Bashaair's Shopping", 'amount' => '776'],
        ['name' => 'Miscellaneous / Cash', 'amount' => '469'],
        ['name' => 'Dining Out', 'amount' => '367'],
        ['name' => 'Activities / Outings', 'amount' => '231'],
        ['name' => "Bashaair's Grooming", 'amount' => '150'],
        ['name' => 'Grooming', 'amount' => '100'],
        ['name' => 'Medical', 'amount' => '100'],
        ['name' => 'Family Usage of Credit Cards', 'amount' => '100'],
        ['name' => 'Bank Fees', 'amount' => '242'],
        ['name' => 'Government Services', 'amount' => '220'],
        // One-offs / not this month
        ['name' => 'Family Transfer', 'amount' => '0'],
        ['name' => 'Travel', 'amount' => '0'],
        ['name' => 'OSAP Repayment', 'amount' => '0'], // Mar/Apr 2027
        ['name' => 'Car Loan', 'amount' => '0', 'create' => true], // starts Dec 1
        // Finished one-offs: keep their history, hide them from the active list.
        ['name' => 'Car Purchase (One-Time)', 'amount' => '0', 'inactive' => true],
        ['name' => 'Canadian Immigration Fees', 'amount' => '0', 'inactive' => true],
        ['name' => 'Dawat (One-Time)', 'amount' => '0', 'inactive' => true],
        ['name' => 'Debt Repayment', 'amount' => '0', 'inactive' => true],
    ];

    /**
     * New subscriptions. `also_desc` links past journals that were renamed during the import review.
     *
     * @var array<int, array{name: string, match: string, amount_min: string, amount_max: string, date: string, repeat_freq: string, also_desc?: string}>
     */
    private const array NEW_SUBSCRIPTIONS = [
        ['name' => 'Claude (Anthropic)', 'match' => 'ANTHROPIC', 'amount_min' => '755.00', 'amount_max' => '770.00', 'date' => '2026-10-04', 'repeat_freq' => 'monthly'],
        ['name' => 'Vercel', 'match' => 'VERCEL', 'amount_min' => '74.00', 'amount_max' => '80.00', 'date' => '2026-10-06', 'repeat_freq' => 'monthly'],
        ['name' => 'Audible', 'match' => 'Audible', 'amount_min' => '56.00', 'amount_max' => '58.00', 'date' => '2026-08-07', 'repeat_freq' => 'monthly'],
        ['name' => 'noon One', 'match' => 'noon one', 'amount_min' => '29.00', 'amount_max' => '31.00', 'date' => '2026-01-24', 'repeat_freq' => 'monthly'],
        ['name' => 'ChatGPT Pro (Saad)', 'match' => 'OPENAI *CHATGPT', 'amount_min' => '370.00', 'amount_max' => '405.00', 'date' => '2026-09-23', 'repeat_freq' => 'monthly'],
        ['name' => 'Apple Developer Program', 'match' => 'APPLE.COM/BILL', 'amount_min' => '380.00', 'amount_max' => '395.00', 'date' => '2026-10-23', 'repeat_freq' => 'yearly', 'also_desc' => 'Apple Developer Program (annual)'],
        ['name' => 'Canva', 'match' => 'Canva', 'amount_min' => '450.00', 'amount_max' => '460.00', 'date' => '2026-10-26', 'repeat_freq' => 'yearly'],
        // Debit description unknown until the first installment; verify after Dec 1.
        ['name' => 'Car Loan', 'match' => 'LOAN', 'amount_min' => '1270.00', 'amount_max' => '1290.00', 'date' => '2026-12-01', 'repeat_freq' => 'monthly'],
    ];

    /**
     * Existing subscriptions to update: new name, rule description match, amount range.
     *
     * @var array<int, array{name: string, new_name?: string, match?: string, amount_min?: string, amount_max?: string, also_desc?: string}>
     */
    private const array UPDATE_SUBSCRIPTIONS = [
        // The old $20 Plus bill becomes the wife's plan; Saad moved to Pro (new bill above).
        ['name' => 'OpenAI ChatGPT Plus', 'new_name' => 'ChatGPT Plus (Wife)'],
        // Wife upgraded iCloud+; range kept clear of iCloud (Personal) at 41.25.
        ['name' => 'iCloud (Wife)', 'amount_min' => '39.50', 'amount_max' => '40.50', 'also_desc' => 'iCloud+ upgrade (Wife)'],
        // Card descriptor changed from "HIGHLEVEL INC" to "HIGHLEVEL LLC".
        ['name' => 'HighLevel Add-on', 'match' => 'HIGHLEVEL'],
    ];

    /** No charges since Aug 1, 2026. */
    private const array DEACTIVATE_SUBSCRIPTIONS = ['Google Workspace (NSTME)', 'Google One Storage (Unkown)'];

    private bool $dryRun = false;

    private string $prefix = '';

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->prefix = $this->dryRun ? '[DRY RUN] ' : '';
        $user         = User::find((int) $this->option('user'));
        $currency     = TransactionCurrency::where('code', 'AED')->first();
        if (null === $user || null === $currency) {
            $this->error('User or AED currency not found.');

            return 1;
        }

        DB::beginTransaction();

        try {
            $this->info('--- NOVEMBER 2026 BUDGET LIMITS ---');
            foreach (self::NOVEMBER_BUDGETS as $def) {
                $this->applyBudget($user, $currency, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), $def);
            }

            $this->newLine();
            $this->info('--- SUBSCRIPTIONS ---');
            $ruleGroup = RuleGroup::where('user_id', $user->id)->where('title', 'Subscription Rules')->whereNull('deleted_at')->firstOrFail();
            foreach (self::NEW_SUBSCRIPTIONS as $def) {
                $this->createSubscription($user, $currency, $ruleGroup, $def);
            }
            foreach (self::UPDATE_SUBSCRIPTIONS as $def) {
                $this->updateSubscription($user, $def);
            }
            foreach (self::DEACTIVATE_SUBSCRIPTIONS as $name) {
                $this->deactivateSubscription($user, $name);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return 1;
        }

        if ($this->dryRun) {
            DB::rollBack();
            $this->info('Dry run complete — rolled back.');

            return 0;
        }
        DB::commit();
        $this->info('November 2026 changes applied.');

        return 0;
    }

    /** @param array{name: string, amount: string, create?: bool, inactive?: bool} $def */
    private function applyBudget(User $user, TransactionCurrency $currency, Carbon $start, Carbon $end, array $def): void
    {
        if (!empty($def['inactive'])) {
            $updated = Budget::where('user_id', $user->id)->where('name', $def['name'])->whereNull('deleted_at')->update(['active' => false]);
            $this->line(sprintf('  %s%-34s %s', $this->prefix, $def['name'], $updated > 0 ? 'deactivated' : 'not found — skipped'));

            return;
        }
        $budget = Budget::where('user_id', $user->id)->where('name', $def['name'])->whereNull('deleted_at')->first();
        if (null === $budget && !empty($def['create'])) {
            $budget = Budget::create([
                'user_id'       => $user->id,
                'user_group_id' => $user->user_group_id,
                'name'          => $def['name'],
                'active'        => true,
                'order'         => (int) Budget::where('user_id', $user->id)->max('order') + 1,
            ]);
            $this->line(sprintf('  %sCreated budget "%s"', $this->prefix, $def['name']));
        }
        if (null === $budget) {
            $this->warn(sprintf('  %sBudget "%s" not found — skipped.', $this->prefix, $def['name']));

            return;
        }
        if (!$budget->active) {
            $budget->active = true;
            $budget->save();
        }

        $auto = AutoBudget::where('budget_id', $budget->id)->first() ?? new AutoBudget();
        if (!$auto->exists) {
            $auto->budget()->associate($budget);
            $auto->transaction_currency_id = $currency->id;
            $auto->period                  = 'monthly';
        }
        // Every budget resets monthly (Food Delivery had drifted to "rollover").
        $auto->auto_budget_type = AutoBudgetType::AUTO_BUDGET_RESET->value;
        $auto->amount           = $def['amount'];
        $auto->save();

        $limit = BudgetLimit::where('budget_id', $budget->id)->where('transaction_currency_id', $currency->id)
            ->where('start_date', $start->format('Y-m-d'))->first() ?? new BudgetLimit();
        if (!$limit->exists) {
            $limit->budget()->associate($budget);
            $limit->start_date              = $start;
            $limit->transaction_currency_id = $currency->id;
            $limit->generated               = false;
            $limit->period                  = 'monthly';
        }
        $limit->end_date = $end;
        $limit->amount   = $def['amount'];
        $limit->save();

        $this->line(sprintf('  %s%-34s AED %8s', $this->prefix, $def['name'], $def['amount']));
    }

    /** @param array{name: string, match: string, amount_min: string, amount_max: string, date: string, repeat_freq: string, also_desc?: string} $def */
    private function createSubscription(User $user, TransactionCurrency $currency, RuleGroup $ruleGroup, array $def): void
    {
        $bill = Bill::where('user_id', $user->id)->where('name', $def['name'])->whereNull('deleted_at')->first();
        if (null === $bill) {
            $bill = Bill::create([
                'user_id'                 => $user->id,
                'user_group_id'           => $user->user_group_id,
                'name'                    => $def['name'],
                'match'                   => 'MIGRATED_TO_RULES',
                'amount_min'              => $def['amount_min'],
                'amount_max'              => $def['amount_max'],
                'date'                    => $def['date'],
                'date_tz'                 => 'Asia/Dubai',
                'repeat_freq'             => $def['repeat_freq'],
                'skip'                    => 0,
                'automatch'               => true,
                'active'                  => true,
                'transaction_currency_id' => $currency->id,
                'order'                   => (int) Bill::where('user_id', $user->id)->max('order') + 1,
            ]);
            $this->createRule($ruleGroup, $bill, $def);
        }
        $linked = $this->linkPastJournals($user, $bill, $def['match'], $def['amount_min'], $def['amount_max'], $def['also_desc'] ?? null);
        $this->line(sprintf('  %sSubscription %-26s %s %s–%s (%s), linked %d past charge(s)', $this->prefix, '"'.$def['name'].'"', $def['match'], $def['amount_min'], $def['amount_max'], $def['repeat_freq'], $linked));
    }

    /** @param array{name: string, new_name?: string, match?: string, amount_min?: string, amount_max?: string, also_desc?: string} $def */
    private function updateSubscription(User $user, array $def): void
    {
        $bill = Bill::where('user_id', $user->id)->where('name', $def['name'])->whereNull('deleted_at')->first();
        if (null === $bill) {
            $this->warn(sprintf('  %sSubscription "%s" not found — skipped.', $this->prefix, $def['name']));

            return;
        }
        $rule = Rule::whereNull('deleted_at')->whereHas('ruleActions', fn ($q) => $q->where('action_type', 'link_to_bill')->where('action_value', $bill->name))->first();

        if (isset($def['new_name'])) {
            $bill->name = $def['new_name'];
            if (null !== $rule) {
                $rule->title = sprintf('Rule for subscription: %s', $def['new_name']);
                $rule->save();
                RuleAction::where('rule_id', $rule->id)->where('action_type', 'link_to_bill')->update(['action_value' => $def['new_name']]);
            }
        }
        if (isset($def['amount_min'], $def['amount_max'])) {
            $bill->amount_min = $def['amount_min'];
            $bill->amount_max = $def['amount_max'];
            if (null !== $rule) {
                RuleTrigger::where('rule_id', $rule->id)->where('trigger_type', 'amount_more')->update(['trigger_value' => $def['amount_min']]);
                RuleTrigger::where('rule_id', $rule->id)->where('trigger_type', 'amount_less')->update(['trigger_value' => $def['amount_max']]);
            }
        }
        if (isset($def['match']) && null !== $rule) {
            RuleTrigger::where('rule_id', $rule->id)->where('trigger_type', 'description_contains')->update(['trigger_value' => $def['match']]);
        }
        $bill->save();

        $match  = (string) ($def['match'] ?? RuleTrigger::where('rule_id', $rule?->id)->where('trigger_type', 'description_contains')->value('trigger_value'));
        $linked = $this->linkPastJournals($user, $bill, $match, (string) $bill->amount_min, (string) $bill->amount_max, $def['also_desc'] ?? null);
        $this->line(sprintf('  %sUpdated %-30s → "%s" %s–%s, linked %d past charge(s)', $this->prefix, '"'.$def['name'].'"', $bill->name, $bill->amount_min, $bill->amount_max, $linked));
    }

    private function deactivateSubscription(User $user, string $name): void
    {
        $bill = Bill::where('user_id', $user->id)->where('name', $name)->whereNull('deleted_at')->first();
        if (null === $bill) {
            $this->warn(sprintf('  %sSubscription "%s" not found — skipped.', $this->prefix, $name));

            return;
        }
        $bill->active = false;
        $bill->save();
        Rule::whereNull('deleted_at')->whereHas('ruleActions', fn ($q) => $q->where('action_type', 'link_to_bill')->where('action_value', $name))->update(['active' => false]);
        $this->line(sprintf('  %sDeactivated "%s" and its rule', $this->prefix, $name));
    }

    /** Link withdrawals since Aug 1, 2026 that the bill's rule would have matched. */
    private function linkPastJournals(User $user, Bill $bill, string $match, string $min, string $max, ?string $alsoDesc): int
    {
        $ids = TransactionJournal::where('transaction_journals.user_id', $user->id)
            ->whereNull('transaction_journals.deleted_at')
            ->where('transaction_journals.date', '>=', '2026-08-01')
            ->whereNull('transaction_journals.bill_id')
            ->where(function ($q) use ($match, $alsoDesc): void {
                $q->where('transaction_journals.description', 'ilike', '%'.$match.'%');
                if (null !== $alsoDesc) {
                    $q->orWhere('transaction_journals.description', $alsoDesc);
                }
            })
            ->join('transactions as t', fn ($j) => $j->on('t.transaction_journal_id', '=', 'transaction_journals.id')->where('t.amount', '>', 0))
            ->where('t.amount', '>', $min)->where('t.amount', '<', $max)
            ->pluck('transaction_journals.id');

        return TransactionJournal::whereIn('id', $ids)->update(['bill_id' => $bill->id]);
    }

    /** @param array{name: string, match: string, amount_min: string, amount_max: string} $def */
    private function createRule(RuleGroup $ruleGroup, Bill $bill, array $def): void
    {
        $rule  = Rule::create([
            'user_id'         => $bill->user_id,
            'user_group_id'   => $bill->user_group_id,
            'rule_group_id'   => $ruleGroup->id,
            'title'           => sprintf('Rule for subscription: %s', $bill->name),
            'description'     => sprintf('Auto-link transactions to "%s" subscription.', $bill->name),
            'order'           => (int) Rule::where('rule_group_id', $ruleGroup->id)->max('order') + 1,
            'active'          => true,
            'strict'          => true,
            'stop_processing' => false,
        ]);
        $order = 0;
        foreach ([['user_action', 'store-journal'], ['description_contains', $def['match']], ['amount_more', $def['amount_min']], ['amount_less', $def['amount_max']]] as [$type, $value]) {
            RuleTrigger::create(['rule_id' => $rule->id, 'trigger_type' => $type, 'trigger_value' => $value, 'order' => $order++, 'active' => true, 'stop_processing' => false]);
        }
        RuleAction::create(['rule_id' => $rule->id, 'action_type' => 'link_to_bill', 'action_value' => $bill->name, 'order' => 1, 'active' => true, 'stop_processing' => false]);
    }
}
