<?php

declare(strict_types=1);

namespace FireflyIII\Services\BudgetPlan;

class PromptBuilder
{
    public function build(
        float  $salary,
        float  $bankBalance,
        float  $cashOnHand,
        array  $ccBalances,
        string $budgetPeriodStart,
        string $budgetPeriodEnd,
        string $startDate,
        string $endDate,
        array  $extraExpenses,
        array  $alreadyPaid,
        array  $fixedObligations,
        array  $subscriptionChanges,
        string $goals,
        array  $financialData,
        array  $referencePlans = [],
        string $budgetFor = '',
    ): array {
        $systemPrompt = $this->buildSystemPrompt($referencePlans);
        $userPrompt   = $this->buildUserPrompt(
            $salary, $bankBalance, $cashOnHand, $ccBalances,
            $budgetPeriodStart, $budgetPeriodEnd,
            $startDate, $endDate,
            $extraExpenses, $alreadyPaid, $fixedObligations, $subscriptionChanges,
            $goals, $financialData, $budgetFor,
        );

        return [
            'system' => $systemPrompt,
            'user'   => $userPrompt,
        ];
    }

    private function buildSystemPrompt(array $referencePlans = []): string
    {
        $prompt = <<<'SYSTEM'
You are a strict, experienced financial advisor integrated into Firefly III (a personal finance manager). Your job is to analyze a user's real transaction history, existing financial setup, and stated goals to produce a comprehensive monthly budget plan.

## Category Classification Guide

When categorizing transactions, use these categories and merchant mappings:

| Category | Typical Merchants / Keywords |
|---|---|
| Personal Transfers | Transfers to family/friends — identify mandatory vs one-off |
| Rent | Cheque Payment, housing-related |
| Utilities | e& Digital App, SEWA (Shj Elec Water Auth), internet/mobile |
| Groceries | Noon Minutes, Amazon Grocery, Carrefour, Nesto, Gala, Safeer, supermarkets. Also includes wife's hygiene/personal care products. |
| Food Delivery | talabat.com, Wolt, delivery service subscriptions (talabat pro) |
| Dining Out | Restaurants, cafes, coffee shops (Black Tap, Trio Cafe, Paul, Tim Hortons, Caesars, Lake Tea, Filli, etc.) |
| Business Tools | HighLevel, Google Workspace, AWS EMEA, Envato, professional SaaS |
| Personal Subscriptions | APPLE.COM/BILL, Netflix, Spotify, OpenAI ChatGPT, Google One, PlayStation Network, noon one |
| Education | Preply, UWORLD, courses, exam prep |
| Transportation | Fuel (ADNOC, ENOC, Emarat, Al Maha), taxis (Careem, Union, Yandex), parking |
| Fitness | GymNation (2 people = ~AED 400/month total) |
| Donations | Droplets of Mercy, Impact Guru |
| Shopping | Amazon.ae, clothing (Pull and Bear, Shein), electronics (Laam Technologies), AliExpress, Temu, Sephora |
| Activities/Outings | Museums (Louvre), events (platinumlist), game purchases (Epic/Riot), amusement parks |
| Medical | Pharmacies, doctor visits, hospitals |
| Grooming | Hairdressing, barber, spa |
| Government/Admin | Dubai Police, Ministry of Foreign Affairs, Tasjeel, ICP, visa fees |
| Bank Fees | Card annual fees, monthly service charges |

Use these as guidance — if a new merchant appears, classify it into the most logical category.

## Firefly III Entity Knowledge

When making Firefly III setup recommendations, use these concepts correctly:
- **Budgets**: For variable spending you want to cap (groceries, dining, transport). Use auto-budget monthly reset. Fixed costs don't need budgets.
- **Subscriptions (Bills)**: For auto-charged services. Matched via Rules, NOT the bill's `match` field. Bill `match` must be `MIGRATED_TO_RULES`. Rules must use `strict = true` (AND logic). Each rule needs: `description_contains` trigger + `amount_more`/`amount_less` triggers + `link_to_bill` action.
- **Recurring Transactions**: For payments the user makes themselves on a schedule (rent, family transfers, gym, donations). NOT for subscriptions.
- **Piggy Banks**: For savings goals or large periodic payments (e.g. rent fund). Linked to accounts.
- **APPLE.COM/BILL note**: This single merchant contains MULTIPLE subscriptions (e.g. iCloud Personal, iCloud Wife, YouTube Premium). Differentiate by amount range, not description.

## Output Format

Your output MUST be a single Markdown document with these sections:

### 1. Financial Snapshot
A table showing: current bank balance, cash on hand, credit card balances, the budget period, salary, total income in the analysis period, total withdrawals, monthly average spending, and net position. Account for already-paid items and deduct them from the starting balance.

### 2. Where Your Money Has Been Going (Spending Analysis)
- Group ALL withdrawal transactions into the categories defined above
- For each category: detailed table with merchant names, transaction counts, total amounts, monthly averages
- Include specific merchant-level breakdowns
- Calculate percentages of total spending
- Provide a summary table of all categories with monthly averages

### 3. Budget Allocation for the Upcoming Month
- A detailed table with: category, budgeted amount, comparison to historical average (% change), and reasoning
- The bank balance (minus large obligations and fixed transfers) is the hard spending cap — NOT the salary
- Factor in any out-of-norm expected expenses
- Account for fixed obligations and their active/paused status
- Honor the user's goals and tone
- Show total allocated vs available vs buffer in a summary table

### 4. Category-by-Category Reasoning
For each budget category, explain WHY you set that amount: what the historical spending was, what you cut, and how the user can achieve the target.

### 5. Biggest Levers to Cut Further
A ranked table of actions the user can take if they need to save more, with estimated savings and pain level.

### 6. Firefly III Changes Checklist
Based on your analysis, provide an actionable checklist of what to add, update, or remove:
- **Budget Limits**: Which existing budgets need their monthly amount updated (with old → new values), new budgets to create, budgets to deactivate
- **Subscriptions (Bills + Rules)**: New subscriptions to add (with name, match keywords, amount range, anchor date), subscriptions to deactivate, price changes
- **Recurring Transactions**: New ones to add, existing ones to deactivate, amount changes
- **Piggy Banks**: Target/contribution updates, new savings goals
For each item, note whether it already exists in the user's setup and skip if unchanged.

## Important Rules
- Use AED throughout
- Be data-driven: every recommendation must reference actual transaction data
- The bank balance (not salary) is the starting point for budget calculations
- Cash on hand should be treated as emergency buffer, not primary spending money
- Fixed obligations marked as "active" are non-negotiable costs deducted off the top
- Fixed obligations marked as "paused" or "cancelled" free up that amount
- Already-paid items have been deducted from the balance — don't double-count them
- Be honest and direct about overspending patterns
- The output must be pure Markdown with no code fences wrapping the entire document
- Do NOT wrap the output in ```markdown``` code fences
- Keep markdown tables compact: 3 dashes per column separator is sufficient
- Do NOT generate ASCII art, bar charts, progress bars, or visual decorations of any kind
SYSTEM;

        if (!empty($referencePlans)) {
            $prompt .= "\n\n## Historical Budget Plans\n\n";
            $prompt .= "The user has selected the following past budget plans for reference. Use them to:\n";
            $prompt .= "- Understand the tone, structure, level of detail, and formatting style they prefer\n";
            $prompt .= "- Compare how well they stuck to previous budgets vs actual spending in the transaction data\n";
            $prompt .= "- Call out improvements or regressions from past months\n";
            $prompt .= "- Adapt recommendations based on what worked and what didn't\n\n";
            $prompt .= "Do NOT copy numbers or categories blindly — these are historical context, not templates.\n\n";

            foreach ($referencePlans as $i => $plan) {
                $prompt .= sprintf("<historical_budget_plan_%d title=\"%s\">\n", $i + 1, $plan['name']);
                $prompt .= $plan['content'];
                $prompt .= sprintf("\n</historical_budget_plan_%d>\n\n", $i + 1);
            }
        }

        return $prompt;
    }

    private function buildUserPrompt(
        float  $salary,
        float  $bankBalance,
        float  $cashOnHand,
        array  $ccBalances,
        string $budgetPeriodStart,
        string $budgetPeriodEnd,
        string $startDate,
        string $endDate,
        array  $extraExpenses,
        array  $alreadyPaid,
        array  $fixedObligations,
        array  $subscriptionChanges,
        string $goals,
        array  $financialData,
        string $budgetFor = '',
    ): string {
        $parts = [];

        // --- Header ---
        if ('' !== $budgetFor) {
            $parts[] = sprintf("# Budget Plan for %s\n", $budgetFor);
        }

        // --- Financial Situation ---
        $parts[] = "## My Financial Situation\n";
        $parts[] = sprintf("- **Current bank balance (Emirates NBD):** AED %.2f", $bankBalance);
        $parts[] = "  (This is my total balance RIGHT NOW — salary already received, do NOT add salary.)";
        $parts[] = sprintf("- **Cash on hand:** AED %.2f (emergency buffer — only use if bank runs out)", $cashOnHand);
        $parts[] = "- **Credit card balances:**";
        foreach ($ccBalances as $label => $balance) {
            $parts[] = sprintf("  - %s: AED %.2f", $label, $balance);
        }
        $parts[] = sprintf("- **Monthly salary:** AED %.2f (income cap for calculations)", $salary);
        $parts[] = sprintf("- **Budget period:** %s to %s (payday to payday)\n", $budgetPeriodStart, $budgetPeriodEnd);

        // --- Already Paid Today ---
        if (!empty($alreadyPaid)) {
            $parts[] = "## Already Paid Today\n";
            $parts[] = "These payments already went through and are reflected in the balance above:";
            foreach ($alreadyPaid as $item) {
                $account = !empty($item['account']) ? sprintf(' (from %s)', $item['account']) : '';
                $parts[] = sprintf("- %s: AED %.2f%s", $item['name'] ?? 'Unknown', (float) ($item['amount'] ?? 0), $account);
            }
            $parts[] = '';
        }

        // --- Out-of-Norm Expenses ---
        if (!empty($extraExpenses)) {
            $parts[] = "## Large Upcoming Obligations This Period\n";
            foreach ($extraExpenses as $expense) {
                $parts[] = sprintf("- %s: AED %.2f", $expense['name'] ?? 'Unknown', (float) ($expense['amount'] ?? 0));
            }
            $parts[] = '';
        }

        // --- Fixed Obligations ---
        if (!empty($fixedObligations)) {
            $parts[] = "## Fixed Monthly Obligations\n";
            foreach ($fixedObligations as $ob) {
                $statusLabel = match ($ob['status'] ?? 'active') {
                    'paused'    => 'CAN pause this month',
                    'cancelled' => 'CANCELLED / Stopped',
                    default     => 'Active (mandatory)',
                };
                $parts[] = sprintf("- %s: AED %.2f — **%s**", $ob['label'] ?? $ob['key'], (float) ($ob['amount'] ?? 0), $statusLabel);
            }
            $parts[] = '';
        }

        // --- Subscription Changes ---
        if (!empty($subscriptionChanges)) {
            $parts[] = "## Subscription Changes Since Last Month\n";
            foreach ($subscriptionChanges as $change) {
                $action = strtoupper($change['action'] ?? 'unknown');
                $amount = !empty($change['amount']) ? sprintf(' — AED %.2f', (float) $change['amount']) : '';
                $parts[] = sprintf("- **%s** %s%s", $action, $change['name'] ?? 'Unknown', $amount);
            }
            $parts[] = '';
        }

        // --- Goals ---
        if (!empty(trim($goals))) {
            $parts[] = "## Goals & Instructions\n";
            $parts[] = $goals;
            $parts[] = '';
        }

        // --- Divider ---
        $parts[] = "---\n";

        // --- Analysis Period ---
        $parts[] = sprintf("## Transaction Analysis Period: %s to %s\n", $startDate, $endDate);

        // --- Spending Report Summary ---
        $parts[] = $this->formatSpendingReport($financialData['spending_report'] ?? []);

        // --- Firefly III Data ---
        $parts[] = "## Firefly III Data\n";
        $parts[] = $this->formatAccounts($financialData['accounts'] ?? []);
        $parts[] = $this->formatTransactionGroup('Withdrawals (Expenses)', $financialData['withdrawals'] ?? []);
        $parts[] = $this->formatTransactionGroup('Deposits (Income)', $financialData['deposits'] ?? []);
        $parts[] = $this->formatTransactionGroup('Transfers (Between Accounts)', $financialData['transfers'] ?? []);
        $parts[] = $this->formatBudgets($financialData['budgets'] ?? []);
        $parts[] = $this->formatCategories($financialData['categories'] ?? []);
        $parts[] = $this->formatSubscriptions($financialData['subscriptions'] ?? []);
        $parts[] = $this->formatRules($financialData['rules'] ?? []);

        return implode("\n", $parts);
    }

    private function formatSpendingReport(array $report): string
    {
        if (empty($report) || empty($report['by_merchant'])) {
            return "### Spending Report Summary\nNo withdrawal data available.\n";
        }

        $lines   = [];
        $lines[] = "### Spending Report Summary\n";
        $lines[] = sprintf(
            "**Total spending:** AED %.2f across %d transactions | **Monthly average:** AED %.2f\n",
            $report['total'],
            $report['txn_count'],
            $report['monthly_avg']
        );

        // Monthly totals
        if (!empty($report['monthly_totals'])) {
            $lines[] = '**Monthly breakdown:**';
            foreach ($report['monthly_totals'] as $month => $total) {
                $lines[] = sprintf('- %s: AED %.2f', $month, $total);
            }
            $lines[] = '';
        }

        // Top merchants
        $lines[] = '**Spending by merchant** (sorted by total, descending):';
        $lines[] = '';
        $lines[] = '| Merchant | Total (AED) | Txns | Avg/Txn |';
        $lines[] = '| --- | --- | --- | --- |';

        foreach ($report['by_merchant'] as $merchant => $data) {
            $lines[] = sprintf(
                '| %s | %.2f | %d | %.2f |',
                $this->escape((string) $merchant),
                $data['total'],
                $data['count'],
                $data['avg']
            );
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function formatAccounts(array $accounts): string
    {
        if (empty($accounts)) {
            return "### Current Account Balances\nNo accounts found.\n";
        }

        $lines   = [];
        $lines[] = sprintf("### Current Account Balances (%d accounts)\n", count($accounts));
        $lines[] = '| Account Name | Type | Balance | Currency |';
        $lines[] = '| --- | --- | --- | --- |';

        foreach ($accounts as $a) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $this->escape($a['name']),
                $a['type'],
                $a['balance'],
                $a['currency_code'],
            );
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function formatTransactionGroup(string $title, array $transactions): string
    {
        if (empty($transactions)) {
            return sprintf("### %s\nNone in the analysis period.\n", $title);
        }

        $lines   = [];
        $lines[] = sprintf("### %s (%d transactions)\n", $title, count($transactions));
        $lines[] = '| Date | Description | Amount | Currency | Source Account | Destination Account | Category | Budget |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';

        foreach ($transactions as $t) {
            $amount = ltrim((string) ($t['amount'] ?? '0'), '-');
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | %s | %s |',
                $t['date'],
                $this->escape($t['description']),
                $amount,
                $t['currency_code'],
                $this->escape($t['source_account']),
                $this->escape($t['destination_account']),
                $this->escape($t['category']),
                $this->escape($t['budget']),
            );
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function formatBudgets(array $budgets): string
    {
        if (empty($budgets)) {
            return "### Existing Budgets\nNo budgets configured.\n";
        }

        $lines   = [];
        $lines[] = sprintf("### Existing Budgets (%d)\n", count($budgets));
        $lines[] = '| Budget Name | Active | Auto-Budget Amount | Period |';
        $lines[] = '| --- | --- | --- | --- |';

        foreach ($budgets as $b) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $this->escape($b['name']),
                $b['active'] ? 'Yes' : 'No',
                $b['auto_budget_amount'] ?? 'N/A',
                $b['auto_budget_period'] ?? 'N/A',
            );
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function formatCategories(array $categories): string
    {
        if (empty($categories)) {
            return "### Existing Categories\nNo categories configured.\n";
        }

        $lines   = [];
        $lines[] = sprintf("### Existing Categories (%d)\n", count($categories));

        foreach ($categories as $name) {
            $lines[] = '- ' . $this->escape($name);
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function formatSubscriptions(array $subscriptions): string
    {
        if (empty($subscriptions)) {
            return "### Existing Subscriptions\nNo subscriptions configured.\n";
        }

        $lines   = [];
        $lines[] = sprintf("### Existing Subscriptions (%d)\n", count($subscriptions));
        $lines[] = '| Subscription | Min Amount | Max Amount | Frequency | Active |';
        $lines[] = '| --- | --- | --- | --- | --- |';

        foreach ($subscriptions as $s) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                $this->escape($s['name']),
                $s['amount_min'],
                $s['amount_max'],
                $s['repeat_freq'],
                $s['active'] ? 'Yes' : 'No',
            );
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function formatRules(array $rules): string
    {
        if (empty($rules)) {
            return "### Existing Rules\nNo rules configured.\n";
        }

        $lines   = [];
        $lines[] = sprintf("### Existing Rules (%d)\n", count($rules));

        foreach ($rules as $r) {
            $lines[] = sprintf('- **%s** (%s)', $this->escape($r['title']), $r['active'] ? 'active' : 'inactive');

            if (!empty($r['triggers'])) {
                $lines[] = '  Triggers: ' . implode(', ', $r['triggers']);
            }
            if (!empty($r['actions'])) {
                $lines[] = '  Actions: ' . implode(', ', $r['actions']);
            }
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function escape(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }
}
