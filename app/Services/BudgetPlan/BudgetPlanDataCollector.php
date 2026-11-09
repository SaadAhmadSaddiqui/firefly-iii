<?php

declare(strict_types=1);

namespace FireflyIII\Services\BudgetPlan;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;

class BudgetPlanDataCollector
{
    public function collect(User $user, Carbon $start, Carbon $end): array
    {
        $withdrawals = $this->collectByType('Withdrawal', $start, $end);

        return [
            'withdrawals'      => $withdrawals,
            'deposits'         => $this->collectByType('Deposit', $start, $end),
            'transfers'        => $this->collectByType('Transfer', $start, $end),
            'accounts'         => $this->collectAccounts($user),
            'budgets'          => $this->collectBudgets(),
            'categories'       => $this->collectCategories(),
            'subscriptions'    => $this->collectSubscriptions(),
            'rules'            => $this->collectRules(),
            'spending_report'  => $this->buildSpendingReport($withdrawals, $start, $end),
        ];
    }

    private function collectByType(string $type, Carbon $start, Carbon $end): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector
            ->setRange($start, $end)
            ->setTypes([$type])
            ->withAPIInformation();

        $journals = $collector->getExtractedJournals();
        $result   = [];

        foreach ($journals as $journal) {
            $result[] = [
                'date'                => $journal['date']->format('Y-m-d'),
                'description'         => $journal['description'] ?? '',
                'amount'              => $journal['amount'] ?? '0',
                'currency_code'       => $journal['currency_code'] ?? '',
                'source_account'      => $journal['source_account_name'] ?? '',
                'destination_account' => $journal['destination_account_name'] ?? '',
                'category'            => $journal['category_name'] ?? '',
                'budget'              => $journal['budget_name'] ?? '',
            ];
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $result;
    }

    /**
     * Group withdrawals by merchant (destination account) and compute totals,
     * counts, averages, and monthly breakdowns — mirroring the spending-report
     * artisan command output.
     */
    private function buildSpendingReport(array $withdrawals, Carbon $start, Carbon $end): array
    {
        if (empty($withdrawals)) {
            return [
                'by_merchant'    => [],
                'monthly_totals' => [],
                'monthly_avg'    => 0,
                'total'          => 0,
                'txn_count'      => count($withdrawals),
            ];
        }

        $byMerchant  = [];
        $byMonth     = [];
        $grandTotal  = 0.0;

        foreach ($withdrawals as $w) {
            $merchant = $w['destination_account'] ?: '(unknown)';
            $amount   = abs((float) $w['amount']);
            $month    = substr($w['date'], 0, 7);

            if (!isset($byMerchant[$merchant])) {
                $byMerchant[$merchant] = ['total' => 0.0, 'count' => 0];
            }
            $byMerchant[$merchant]['total'] += $amount;
            $byMerchant[$merchant]['count']++;

            $byMonth[$month] = ($byMonth[$month] ?? 0.0) + $amount;
            $grandTotal     += $amount;
        }

        uasort($byMerchant, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        foreach ($byMerchant as $merchant => &$data) {
            $data['avg'] = $data['count'] > 0 ? round($data['total'] / $data['count'], 2) : 0;
        }
        unset($data);

        ksort($byMonth);
        $numMonths  = max(1, count($byMonth));
        $monthlyAvg = round($grandTotal / $numMonths, 2);

        return [
            'by_merchant'    => $byMerchant,
            'monthly_totals' => $byMonth,
            'monthly_avg'    => $monthlyAvg,
            'total'          => round($grandTotal, 2),
            'txn_count'      => count($withdrawals),
        ];
    }

    private function collectAccounts(User $user): array
    {
        /** @var AccountRepositoryInterface $repo */
        $repo = app(AccountRepositoryInterface::class);

        $assetTypes = [AccountTypeEnum::DEFAULT->value, AccountTypeEnum::ASSET->value];
        $liabilityTypes = [
            AccountTypeEnum::LOAN->value,
            AccountTypeEnum::DEBT->value,
            AccountTypeEnum::CREDITCARD->value,
            AccountTypeEnum::MORTGAGE->value,
        ];

        $assets      = $repo->getActiveAccountsByType($assetTypes);
        $liabilities = $repo->getActiveAccountsByType($liabilityTypes);
        $all         = $assets->merge($liabilities);

        $now      = Carbon::now();
        $balances = Steam::accountsBalancesOptimized($all, $now);

        $result = [];
        foreach ($all as $account) {
            $balanceData = $balances[$account->id] ?? [];
            $currency    = $repo->getAccountCurrency($account);
            $balance     = $balanceData['balance'] ?? '0';

            $result[] = [
                'name'          => $account->name,
                'type'          => $account->accountType->type ?? 'Unknown',
                'balance'       => $balance,
                'currency_code' => $currency?->code ?? 'AED',
                'active'        => $account->active,
            ];
        }

        return $result;
    }

    private function collectBudgets(): array
    {
        /** @var BudgetRepositoryInterface $repo */
        $repo    = app(BudgetRepositoryInterface::class);
        $budgets = $repo->getBudgets();
        $result  = [];

        foreach ($budgets as $budget) {
            $entry = [
                'name'   => $budget->name,
                'active' => $budget->active,
            ];

            if ($budget->autoBudget) {
                $entry['auto_budget_amount'] = (string) $budget->autoBudget->amount;
                $entry['auto_budget_period'] = $budget->autoBudget->period;
            }

            $result[] = $entry;
        }

        return $result;
    }

    private function collectCategories(): array
    {
        /** @var CategoryRepositoryInterface $repo */
        $repo       = app(CategoryRepositoryInterface::class);
        $categories = $repo->getCategories();
        $result     = [];

        foreach ($categories as $category) {
            $result[] = $category->name;
        }

        return $result;
    }

    private function collectSubscriptions(): array
    {
        /** @var BillRepositoryInterface $repo */
        $repo   = app(BillRepositoryInterface::class);
        $bills  = $repo->getBills();
        $result = [];

        foreach ($bills as $bill) {
            $result[] = [
                'name'        => $bill->name,
                'amount_min'  => (string) $bill->amount_min,
                'amount_max'  => (string) $bill->amount_max,
                'repeat_freq' => $bill->repeat_freq,
                'active'      => $bill->active,
            ];
        }

        return $result;
    }

    private function collectRules(): array
    {
        /** @var RuleRepositoryInterface $repo */
        $repo   = app(RuleRepositoryInterface::class);
        $rules  = $repo->getAll();
        $result = [];

        foreach ($rules as $rule) {
            $triggers = [];
            foreach ($rule->ruleTriggers as $trigger) {
                if ('user_action' === $trigger->trigger_type) {
                    continue;
                }
                $triggers[] = $trigger->trigger_type . ': ' . $trigger->trigger_value;
            }

            $actions = [];
            foreach ($rule->ruleActions as $action) {
                $actions[] = $action->action_type . ': ' . $action->action_value;
            }

            $result[] = [
                'title'    => $rule->title,
                'active'   => $rule->active,
                'triggers' => $triggers,
                'actions'  => $actions,
            ];
        }

        return $result;
    }
}
