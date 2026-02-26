<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use Carbon\Carbon;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\Recurrence;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\User;
use Illuminate\Console\Command;

class SetupMarchBudget extends Command
{
    protected $description = 'Create budgets, piggy bank, and recurring transactions for the March 2026 budget plan.';

    protected $signature = 'firefly:setup-march-budget
                            {--user=1 : Firefly III user ID}
                            {--dry-run : Preview what would be created without making changes}';

    private const array BUDGETS = [
        ['name' => 'Groceries',               'amount' => '1500'],
        ['name' => 'Food Delivery',            'amount' => '250'],
        ['name' => 'Dining Out',               'amount' => '200'],
        ['name' => 'Transportation',           'amount' => '500'],
        ['name' => 'Medical',                  'amount' => '100'],
        ['name' => 'Grooming',                 'amount' => '90'],
        ['name' => 'Personal Subscriptions',   'amount' => '770'],
        ['name' => 'Business Tools',           'amount' => '1400'],
    ];

    private const array PIGGY_BANKS = [
        ['name' => 'Rent Fund', 'target' => '14500', 'account_id' => 1],
    ];

    private const array RECURRING = [
        [
            'title'       => 'Quarterly Rent Payment',
            'type'        => 'withdrawal',
            'amount'      => '14500',
            'source'      => 1,       // Emirates NBD
            'destination'  => 'Cheque Payment',
            'frequency'   => 'monthly',
            'skip'        => 2,       // every 3 months (skip 2)
            'first_date'  => '2026-04-16',
        ],
        [
            'title'       => 'Monthly Transfer to Dad',
            'type'        => 'withdrawal',
            'amount'      => '5000',
            'source'      => 1,
            'destination'  => 'Anees Ahmad (Emirates NBD Bank Pjsc)',
            'frequency'   => 'monthly',
            'skip'        => 0,
            'first_date'  => '2026-03-25',
        ],
        [
            'title'       => 'Impact Guru Donation',
            'type'        => 'withdrawal',
            'amount'      => '104.15',
            'source'      => 50,      // Mashreq CC
            'destination'  => 'Donation TO Impact Guru',
            'frequency'   => 'monthly',
            'skip'        => 0,
            'first_date'  => '2026-03-19',
        ],
        [
            'title'       => 'Droplets of Mercy Donation',
            'type'        => 'withdrawal',
            'amount'      => '279.51',
            'source'      => 1,
            'destination'  => 'DROPLETS OF MERCY',
            'frequency'   => 'monthly',
            'skip'        => 0,
            'first_date'  => '2026-03-11',
        ],
        [
            'title'       => 'GymNation Membership (2 people)',
            'type'        => 'withdrawal',
            'amount'      => '400',
            'source'      => 1,
            'destination'  => 'GYMNATION',
            'frequency'   => 'monthly',
            'skip'        => 0,
            'first_date'  => '2026-03-01',
        ],
    ];

    // --- Subscriptions (Bills) — matched via rules ---
    private const array SUBSCRIPTIONS = [
        // Personal Subscriptions — Apple (3 distinct recurring charges)
        [
            'name'        => 'iCloud (Personal)',
            'match'       => 'APPLE.COM/BILL',
            'amount_min'  => '40.00',
            'amount_max'  => '42.00',
            'date'        => '2025-09-09',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'iCloud (Wife)',
            'match'       => 'APPLE.COM/BILL',
            'amount_min'  => '11.00',
            'amount_max'  => '13.00',
            'date'        => '2025-09-24',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'YouTube Premium',
            'match'       => 'APPLE.COM/BILL',
            'amount_min'  => '64.00',
            'amount_max'  => '67.00',
            'date'        => '2025-09-30',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'OpenAI ChatGPT Plus',
            'match'       => 'OPENAI CHATGPT',
            'amount_min'  => '79.00',
            'amount_max'  => '83.00',
            'date'        => '2025-09-25',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'Netflix',
            'match'       => 'Netflix',
            'amount_min'  => '73.00',
            'amount_max'  => '74.00',
            'date'        => '2025-10-20',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'Spotify Premium',
            'match'       => 'Spotify',
            'amount_min'  => '41.00',
            'amount_max'  => '42.00',
            'date'        => '2025-09-25',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'Google One Storage (Personal)',
            'match'       => 'GOOGLE ONE',
            'amount_min'  => '7.00',
            'amount_max'  => '8.00',
            'date'        => '2025-09-04',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'Google One Storage (Unkown)',
            'match'       => 'GOOGLE ONE',
            'amount_min'  => '79.00',
            'amount_max'  => '80.00',
            'date'        => '2025-09-04',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'PlayStation Network',
            'match'       => 'PlayStation',
            'amount_min'  => '22.00',
            'amount_max'  => '46.00',
            'date'        => '2025-09-10',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'Talabat Pro',
            'match'       => 'talabat pro',
            'amount_min'  => '29.00',
            'amount_max'  => '30.00',
            'date'        => '2025-09-07',
            'repeat_freq' => 'monthly',
        ],

        // Business Tools
        [
            'name'        => 'HighLevel Agency',
            'match'       => 'HIGHLEVEL AGENCY',
            'amount_min'  => '1128.00',
            'amount_max'  => '1132.00',
            'date'        => '2025-10-19',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'HighLevel Add-on',
            'match'       => 'HIGHLEVEL INC',
            'amount_min'  => '38.00',
            'amount_max'  => '39.00',
            'date'        => '2025-10-10',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'Google Workspace (NSTME)',
            'match'       => 'WORKSPACE NSTME',
            'amount_min'  => '191.00',
            'amount_max'  => '192.00',
            'date'        => '2025-12-01',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'Google Workspace (Morningg)',
            'match'       => 'Workspace morningg',
            'amount_min'  => '26.00',
            'amount_max'  => '27.00',
            'date'        => '2025-12-01',
            'repeat_freq' => 'monthly',
        ],
        [
            'name'        => 'AWS Cloud Hosting',
            'match'       => 'AWS EMEA',
            'amount_min'  => '2.00',
            'amount_max'  => '3.00',
            'date'        => '2025-10-02',
            'repeat_freq' => 'monthly',
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userId = (int) $this->option('user');
        $user   = User::find($userId);

        if (!$user) {
            $this->error(sprintf('User #%d not found.', $userId));
            return 1;
        }

        $currency = TransactionCurrency::where('code', 'AED')->first();
        if (!$currency) {
            $this->error('AED currency not found. Make sure it exists in Firefly III.');
            return 1;
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════');
        $this->info('  MARCH 2026 BUDGET SETUP');
        $this->info('═══════════════════════════════════════════════════');
        $this->newLine();

        // --- 1. Budgets with auto-budget (monthly reset) ---
        $this->info('--- BUDGETS (with monthly auto-budget limits) ---');
        $this->newLine();

        $periodStart = Carbon::parse('2026-03-01');
        $periodEnd   = Carbon::parse('2026-03-31');

        foreach (self::BUDGETS as $def) {
            $existing = Budget::where('user_id', $user->id)
                ->where('name', $def['name'])
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $this->warn(sprintf('  %sBudget "%s" already exists (ID #%d). Skipping creation.', $prefix, $def['name'], $existing->id));

                $existingLimit = BudgetLimit::where('budget_id', $existing->id)
                    ->where('start_date', $periodStart->format('Y-m-d'))
                    ->where('transaction_currency_id', $currency->id)
                    ->first();

                if ($existingLimit) {
                    $this->line(sprintf('    Budget limit for March already exists (AED %s).', $existingLimit->amount));
                } else {
                    $this->line(sprintf('    %sWould create March budget limit: AED %s', $prefix, $def['amount']));
                    if (!$dryRun) {
                        $this->createBudgetLimit($existing, $currency, $periodStart, $periodEnd, $def['amount']);
                        $this->info(sprintf('    Created March budget limit: AED %s', $def['amount']));
                    }
                }
                continue;
            }

            $this->line(sprintf('  %sCreate budget: %-20s  AED %s/month (auto-reset)', $prefix, $def['name'], $def['amount']));

            if (!$dryRun) {
                $budget = Budget::create([
                    'user_id'       => $user->id,
                    'user_group_id' => $user->user_group_id,
                    'name'          => $def['name'],
                    'active'        => true,
                    'order'         => Budget::where('user_id', $user->id)->max('order') + 1,
                ]);

                $autoBudget                          = new AutoBudget();
                $autoBudget->budget()->associate($budget);
                $autoBudget->transaction_currency_id = $currency->id;
                $autoBudget->auto_budget_type        = AutoBudgetType::AUTO_BUDGET_RESET->value;
                $autoBudget->amount                  = $def['amount'];
                $autoBudget->period                  = 'monthly';
                $autoBudget->save();

                $this->createBudgetLimit($budget, $currency, $periodStart, $periodEnd, $def['amount']);

                $this->info(sprintf('    Created budget #%d with auto-budget and March limit.', $budget->id));
            }
        }

        // --- 2. Piggy Banks ---
        $this->newLine();
        $this->info('--- PIGGY BANKS ---');
        $this->newLine();

        foreach (self::PIGGY_BANKS as $def) {
            $existing = PiggyBank::where('name', $def['name'])
                ->whereNull('deleted_at')
                ->whereHas('accounts', fn ($q) => $q->where('accounts.user_id', $user->id))
                ->first();

            if (!$existing) {
                $existing = PiggyBank::where('name', $def['name'])
                    ->whereNull('deleted_at')
                    ->first();
            }

            if ($existing) {
                $this->warn(sprintf('  %sPiggy bank "%s" already exists (ID #%d). Skipping.', $prefix, $def['name'], $existing->id));
                continue;
            }

            $this->line(sprintf('  %sCreate piggy bank: "%s"  target AED %s  (linked to account #%d)', $prefix, $def['name'], $def['target'], $def['account_id']));

            if (!$dryRun) {
                $piggy = PiggyBank::create([
                    'name'                    => $def['name'],
                    'target_amount'           => $def['target'],
                    'start_date'              => Carbon::today(),
                    'start_date_tz'           => 'Asia/Dubai',
                    'target_date'             => Carbon::parse('2026-04-16'),
                    'target_date_tz'          => 'Asia/Dubai',
                    'active'                  => true,
                    'order'                   => 1,
                    'transaction_currency_id' => $currency->id,
                ]);

                $piggy->accounts()->attach($def['account_id'], [
                    'current_amount'        => '0',
                    'native_current_amount' => '0',
                ]);

                $this->info(sprintf('    Created piggy bank #%d.', $piggy->id));
            }
        }

        // --- 3. Subscriptions (Bills) + matching Rules ---
        $this->newLine();
        $this->info('--- SUBSCRIPTIONS (bills + rules) ---');
        $this->newLine();

        $ruleGroup = null;
        if (!$dryRun) {
            $ruleGroup = RuleGroup::where('user_id', $user->id)
                ->where('title', 'Subscription Rules')
                ->whereNull('deleted_at')
                ->first();

            if (!$ruleGroup) {
                $ruleGroup = RuleGroup::create([
                    'user_id'       => $user->id,
                    'user_group_id' => $user->user_group_id,
                    'title'         => 'Subscription Rules',
                    'description'   => 'Auto-generated rules to link transactions to subscriptions.',
                    'active'        => true,
                    'order'         => RuleGroup::where('user_id', $user->id)->max('order') + 1,
                ]);
            }
        }

        foreach (self::SUBSCRIPTIONS as $def) {
            $existing = Bill::where('user_id', $user->id)
                ->where('name', $def['name'])
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $this->warn(sprintf('  %sSubscription "%s" already exists (ID #%d). Skipping.', $prefix, $def['name'], $existing->id));
                continue;
            }

            $this->line(sprintf(
                '  %sCreate subscription: "%-30s"  AED %s – %s  %s',
                $prefix,
                $def['name'],
                $def['amount_min'],
                $def['amount_max'],
                $def['repeat_freq']
            ));
            $this->line(sprintf(
                '    %sRule: description contains "%s", amount %s–%s → link to "%s"',
                $prefix,
                $def['match'],
                $def['amount_min'],
                $def['amount_max'],
                $def['name']
            ));

            if (!$dryRun) {
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
                    'order'                   => Bill::where('user_id', $user->id)->max('order') + 1,
                ]);

                $this->createSubscriptionRule($ruleGroup, $bill, $def);
                $this->info(sprintf('    Created subscription #%d + rule.', $bill->id));
            }
        }

        // --- 4. Recurring Transactions ---
        $this->newLine();
        $this->info('--- RECURRING TRANSACTIONS ---');
        $this->newLine();

        foreach (self::RECURRING as $def) {
            $existing = Recurrence::where('user_id', $user->id)
                ->where('title', $def['title'])
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $this->warn(sprintf('  %sRecurring "%s" already exists (ID #%d). Skipping.', $prefix, $def['title'], $existing->id));
                continue;
            }

            $skipLabel = $def['skip'] > 0
                ? sprintf('every %d months', $def['skip'] + 1)
                : 'monthly';

            $this->line(sprintf(
                '  %sCreate recurring: "%-35s"  AED %-10s  %s  starting %s',
                $prefix,
                $def['title'],
                $def['amount'],
                $skipLabel,
                $def['first_date']
            ));
            $this->line(sprintf(
                '    Source: account #%d → Destination: %s',
                $def['source'],
                $def['destination']
            ));

            if (!$dryRun) {
                $this->createRecurrence($user, $currency, $def);
            }
        }

        // --- Summary ---
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════');

        if ($dryRun) {
            $this->info('  DRY RUN complete. No changes were made.');
            $this->info('  Run without --dry-run to create everything.');
        } else {
            $this->info('  All items created successfully.');
        }

        $this->info('═══════════════════════════════════════════════════');
        $this->newLine();

        return 0;
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

    private function createRecurrence(User $user, TransactionCurrency $currency, array $def): void
    {
        $transactionType = \FireflyIII\Models\TransactionType::where('type', ucfirst($def['type']))->first();
        $firstDate       = Carbon::parse($def['first_date']);

        $recurrence = Recurrence::create([
            'user_id'             => $user->id,
            'user_group_id'       => $user->user_group_id,
            'transaction_type_id' => $transactionType->id,
            'title'               => $def['title'],
            'description'         => '',
            'first_date'          => $firstDate->format('Y-m-d'),
            'first_date_tz'       => 'Asia/Dubai',
            'repeat_until'        => null,
            'latest_date'         => null,
            'repetitions'         => 0,
            'apply_rules'         => true,
            'active'              => true,
        ]);

        $recurrence->recurrenceRepetitions()->create([
            'repetition_type'   => 'monthly',
            'repetition_moment' => (string) $firstDate->day,
            'repetition_skip'   => $def['skip'],
            'weekend'           => 1,
        ]);

        $sourceAccount = \FireflyIII\Models\Account::find($def['source']);

        $destAccount = \FireflyIII\Models\Account::where('name', $def['destination'])
            ->where('user_group_id', $user->user_group_id)
            ->first();
        $destAccountId = $destAccount?->id;

        if (!$destAccountId) {
            $destAccount = \FireflyIII\Models\Account::create([
                'user_id'        => $user->id,
                'user_group_id'  => $user->user_group_id,
                'account_type_id' => \FireflyIII\Models\AccountType::where('type', 'Expense account')->first()->id,
                'name'           => $def['destination'],
                'active'         => true,
                'order'          => 0,
            ]);
            $destAccountId = $destAccount->id;
        }

        $recurrence->recurrenceTransactions()->create([
            'transaction_currency_id' => $currency->id,
            'source_id'               => $sourceAccount->id,
            'destination_id'          => $destAccountId,
            'amount'                  => $def['amount'],
            'description'             => $def['title'],
        ]);

        $this->info(sprintf('    Created recurring transaction #%d.', $recurrence->id));
    }

    private function createSubscriptionRule(RuleGroup $ruleGroup, Bill $bill, array $def): void
    {
        $rule = Rule::create([
            'user_id'         => $bill->user_id,
            'user_group_id'   => $bill->user_group_id,
            'rule_group_id'   => $ruleGroup->id,
            'title'           => sprintf('Rule for subscription: %s', $bill->name),
            'description'     => sprintf('Auto-link transactions to "%s" subscription.', $bill->name),
            'order'           => Rule::where('rule_group_id', $ruleGroup->id)->max('order') + 1,
            'active'          => true,
            'strict'          => true,
            'stop_processing' => false,
        ]);

        $triggerOrder = 0;

        RuleTrigger::create([
            'rule_id'         => $rule->id,
            'trigger_type'    => 'user_action',
            'trigger_value'   => 'store-journal',
            'order'           => $triggerOrder++,
            'active'          => true,
            'stop_processing' => false,
        ]);

        RuleTrigger::create([
            'rule_id'         => $rule->id,
            'trigger_type'    => 'description_contains',
            'trigger_value'   => $def['match'],
            'order'           => $triggerOrder++,
            'active'          => true,
            'stop_processing' => false,
        ]);

        if ($def['amount_min'] === $def['amount_max']) {
            RuleTrigger::create([
                'rule_id'         => $rule->id,
                'trigger_type'    => 'amount_exactly',
                'trigger_value'   => $def['amount_min'],
                'order'           => $triggerOrder++,
                'active'          => true,
                'stop_processing' => false,
            ]);
        } else {
            RuleTrigger::create([
                'rule_id'         => $rule->id,
                'trigger_type'    => 'amount_more',
                'trigger_value'   => $def['amount_min'],
                'order'           => $triggerOrder++,
                'active'          => true,
                'stop_processing' => false,
            ]);

            RuleTrigger::create([
                'rule_id'         => $rule->id,
                'trigger_type'    => 'amount_less',
                'trigger_value'   => $def['amount_max'],
                'order'           => $triggerOrder++,
                'active'          => true,
                'stop_processing' => false,
            ]);
        }

        RuleAction::create([
            'rule_id'         => $rule->id,
            'action_type'     => 'link_to_bill',
            'action_value'    => $bill->name,
            'order'           => 1,
            'active'          => true,
            'stop_processing' => false,
        ]);
    }
}
