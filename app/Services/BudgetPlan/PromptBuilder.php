<?php

declare(strict_types=1);

namespace FireflyIII\Services\BudgetPlan;

class PromptBuilder
{
    public function build(
        float  $salary,
        string $startDate,
        string $endDate,
        array  $extraExpenses,
        string $goals,
        array  $financialData,
        array  $referencePlans = [],
        string $budgetFor = '',
    ): array {
        $systemPrompt = $this->buildSystemPrompt($referencePlans);
        $userPrompt   = $this->buildUserPrompt($salary, $startDate, $endDate, $extraExpenses, $goals, $financialData, $budgetFor);

        return [
            'system' => $systemPrompt,
            'user'   => $userPrompt,
        ];
    }

    private function buildSystemPrompt(array $referencePlans = []): string
    {
        $prompt = <<<'SYSTEM'
You are a strict, experienced financial advisor integrated into Firefly III (a personal finance manager). Your job is to analyze a user's real transaction history, existing financial setup, and stated goals to produce a comprehensive monthly budget plan.

## Your output MUST be a single Markdown document with these sections:

### 1. Financial Snapshot
A table showing: current salary, current account balances (all asset and liability accounts), the analysis period, total income received in the period, total withdrawals in the period, monthly average spending, and net position (salary vs average spending). Include a breakdown of transfers between accounts (e.g. credit card payments).

### 2. Where Your Money Has Been Going (Spending Analysis)
- Group ALL transactions from the analysis period into meaningful categories (Groceries, Dining Out, Food Delivery, Transportation, Utilities, Subscriptions, Business Tools, Shopping, Entertainment, etc.)
- For each category, provide a detailed table with: merchant names, transaction counts, total amounts, and monthly averages
- Include specific merchant-level breakdowns within each category
- Calculate percentages of total spending
- Provide a summary table of all categories with monthly averages

### 3. Budget Allocation for the Upcoming Month
- A detailed table with: category, budgeted amount, comparison to historical average, and reasoning
- Account for the user's salary as the hard income cap
- Factor in any out-of-norm expected expenses the user specified
- Honor the user's goals and tone (if they say "tight month", be strict; if they say "comfortable", allow more flexibility)
- Show total allocated vs available vs buffer in a summary table

### 4. Category-by-Category Reasoning
For each budget category, explain WHY you set that amount: what the historical spending was, what you cut, and how the user can achieve the target.

### 5. Biggest Levers to Cut Further
A ranked table of actions the user can take if they need to save more, with estimated savings and pain level.

### 6. Firefly III Setup Recommendations
Based on your analysis, recommend:
- **Budgets to create** (with monthly amounts) — only if they don't already exist
- **Categories to create** — only if transactions need better categorization
- **Subscriptions (Bills) to create** — for recurring charges you identified that aren't tracked yet
- **Rules to create** — for auto-categorizing transactions
- **Piggy banks** — for savings goals or large upcoming expenses
- **Recurring transactions** — for predictable fixed costs

For each recommendation, note whether it already exists in the user's setup (and skip it if so).

## Important rules:
- Use the SAME currency throughout (match the user's transaction currency)
- Be data-driven: every recommendation must reference actual transaction data
- Never exceed the stated salary in your budget unless the user explicitly mentions additional income sources
- If additional income is mentioned, note it as emergency-only and don't include it in the primary budget
- Be honest and direct about overspending patterns
- The output must be pure Markdown with no code fences wrapping the entire document
- Do NOT wrap the output in ```markdown``` code fences
- Keep markdown tables compact: no excessive padding, no repeating dashes beyond what is needed for a standard table separator (3 dashes per column is sufficient)
- Do NOT generate ASCII art, bar charts, progress bars, or visual decorations of any kind
- Each table cell should contain only the necessary data, no trailing spaces or padding
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
        string $startDate,
        string $endDate,
        array  $extraExpenses,
        string $goals,
        array  $financialData,
        string $budgetFor = '',
    ): string {
        $parts = [];

        $parts[] = "## My Financial Inputs\n";

        if ('' !== $budgetFor) {
            $parts[] = sprintf("**Budget For:** %s\n", $budgetFor);
        }

        $parts[] = sprintf("**Monthly Salary:** %.2f\n", $salary);
        $parts[] = sprintf("**Analysis Period:** %s to %s\n", $startDate, $endDate);

        if (!empty($extraExpenses)) {
            $parts[] = "**Expected Out-of-Norm Expenses for Next Month:**";
            foreach ($extraExpenses as $expense) {
                $name   = $expense['name'] ?? 'Unknown';
                $amount = $expense['amount'] ?? 0;
                $parts[] = sprintf("- %s: %.2f", $name, (float) $amount);
            }
            $parts[] = '';
        }

        if (!empty(trim($goals))) {
            $parts[] = "**My Goals / Instructions for This Budget:**";
            $parts[] = $goals;
            $parts[] = '';
        }

        $parts[] = "---\n";
        $parts[] = "## My Firefly III Data\n";

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
