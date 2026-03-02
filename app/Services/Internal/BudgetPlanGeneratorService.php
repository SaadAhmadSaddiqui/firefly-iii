<?php

declare(strict_types=1);

namespace FireflyIII\Services\Internal;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\Bill;
use FireflyIII\Models\PiggyBank;
use FireflyIII\User;
use Illuminate\Support\Facades\Log;

class BudgetPlanGeneratorService
{
    private User $user;

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    /**
     * Extract and aggregate spending data from Firefly III transaction history.
     *
     * @return array{period: array, transactions: int, total_spent: string, by_category: array, by_month: array, top_merchants: array, piggy_banks: array, bills: array}
     */
    public function extractData(int $months = 3, ?string $endDate = null): array
    {
        $end   = $endDate ? Carbon::parse($endDate) : Carbon::now();
        $start = $end->copy()->subDays($months * 30);

        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)
            ->setRange($start, $end)
            ->setTypes([TransactionTypeEnum::WITHDRAWAL->value])
            ->withAccountInformation()
            ->withCategoryInformation()
            ->withBudgetInformation();

        $journals  = $collector->getExtractedJournals();

        $byCategory  = [];
        $byMonth     = [];
        $byMerchant  = [];
        $totalSpent  = '0';

        foreach ($journals as $journal) {
            $amount      = abs((float) ($journal['amount'] ?? 0));
            $totalSpent  = bcadd($totalSpent, (string) $amount, 2);
            $category    = $journal['category_name'] ?? 'Uncategorized';
            $merchant    = $journal['destination_account_name'] ?? 'Unknown';
            $month       = substr($journal['date'] ?? '', 0, 7);

            if (!isset($byCategory[$category])) {
                $byCategory[$category] = ['total' => 0, 'count' => 0, 'merchants' => []];
            }
            $byCategory[$category]['total'] += $amount;
            $byCategory[$category]['count']++;
            if (!isset($byCategory[$category]['merchants'][$merchant])) {
                $byCategory[$category]['merchants'][$merchant] = ['total' => 0, 'count' => 0];
            }
            $byCategory[$category]['merchants'][$merchant]['total'] += $amount;
            $byCategory[$category]['merchants'][$merchant]['count']++;

            if (!isset($byMerchant[$merchant])) {
                $byMerchant[$merchant] = ['total' => 0, 'count' => 0, 'category' => $category];
            }
            $byMerchant[$merchant]['total'] += $amount;
            $byMerchant[$merchant]['count']++;

            if ('' !== $month) {
                $byMonth[$month] = ($byMonth[$month] ?? 0) + $amount;
            }
        }

        uasort($byCategory, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        arsort($byMerchant);

        foreach ($byCategory as &$cat) {
            uasort($cat['merchants'], static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
            $cat['merchants'] = array_slice($cat['merchants'], 0, 15, true);
        }
        unset($cat);

        $topMerchants = array_slice($byMerchant, 0, 30, true);
        ksort($byMonth);

        $piggyBanks = PiggyBank::whereHas('account', fn ($q) => $q->where('user_id', $this->user->id))
            ->where('active', true)
            ->get()
            ->map(fn (PiggyBank $p) => [
                'name'           => $p->name,
                'target_amount'  => (float) $p->target_amount,
                'current_amount' => (float) $p->current_amount,
                'percentage'     => $p->target_amount > 0 ? round(($p->current_amount / $p->target_amount) * 100) : 0,
            ])
            ->toArray();

        $bills = Bill::where('user_id', $this->user->id)
            ->where('active', true)
            ->get()
            ->map(fn (Bill $b) => [
                'name'        => $b->name,
                'amount_min'  => (float) $b->amount_min,
                'amount_max'  => (float) $b->amount_max,
                'repeat_freq' => $b->repeat_freq ?? '',
            ])
            ->toArray();

        return [
            'period'        => [
                'start'  => $start->format('Y-m-d'),
                'end'    => $end->format('Y-m-d'),
                'months' => $months,
            ],
            'transactions'  => count($journals),
            'total_spent'   => $totalSpent,
            'by_category'   => $byCategory,
            'by_month'      => $byMonth,
            'top_merchants' => $topMerchants,
            'piggy_banks'   => $piggyBanks,
            'bills'         => $bills,
        ];
    }

    /**
     * Build the LLM prompt from extracted data and user inputs.
     */
    public function buildPrompt(array $data, string $userMessage, ?float $salary = null, string $currency = 'AED'): string
    {
        $promptTemplate = $this->loadPromptTemplate();
        $monthCount     = max(count($data['by_month']), 1);

        $spendingText = '';
        foreach ($data['by_category'] as $cat => $info) {
            $avg          = round($info['total'] / $monthCount, 2);
            $spendingText .= "\n### {$cat}\n";
            $spendingText .= "Total: " . number_format($info['total'], 2) . " | Transactions: {$info['count']} | Monthly avg: " . number_format($avg, 2) . "\n";
            $spendingText .= "| Merchant | Total | Count |\n|----------|-------|-------|\n";
            foreach ($info['merchants'] as $merchant => $mData) {
                $spendingText .= "| {$merchant} | " . number_format($mData['total'], 2) . " | {$mData['count']} |\n";
            }
        }

        $monthlyText = '';
        foreach ($data['by_month'] as $month => $total) {
            $monthlyText .= "- {$month}: " . number_format($total, 2) . "\n";
        }
        $spendingText .= "\n### Monthly Totals\n{$monthlyText}";

        $goalsText = '';
        if (!empty($data['piggy_banks'])) {
            foreach ($data['piggy_banks'] as $g) {
                $goalsText .= "- {$g['name']}: " . number_format($g['current_amount'], 2) . " / " . number_format($g['target_amount'], 2) . " ({$g['percentage']}% complete)\n";
            }
        } else {
            $goalsText = 'No piggy bank goals defined.';
        }

        $billsText = '';
        if (!empty($data['bills'])) {
            $billsText = "| Name | Min | Max | Frequency |\n|------|-----|-----|----------|\n";
            foreach ($data['bills'] as $b) {
                $billsText .= "| {$b['name']} | {$currency} " . number_format($b['amount_min'], 2) . " | {$currency} " . number_format($b['amount_max'], 2) . " | {$b['repeat_freq']} |\n";
            }
        } else {
            $billsText = 'No recurring bills found.';
        }

        $replacements = [
            '{{EXTRACTION_DATE}}'   => now()->format('Y-m-d'),
            '{{PERIOD_START}}'      => $data['period']['start'],
            '{{PERIOD_END}}'        => $data['period']['end'],
            '{{PERIOD_MONTHS}}'     => (string) $data['period']['months'],
            '{{CURRENCY}}'          => $currency,
            '{{SALARY}}'            => $salary ? number_format($salary, 2) : 'Not provided',
            '{{ACCOUNTS}}'          => 'See user message below for current balances.',
            '{{SPENDING_ANALYSIS}}' => $spendingText,
            '{{GOALS}}'            => $goalsText,
            '{{BILLS}}'            => $billsText,
            '{{TRANSACTION_COUNT}}' => (string) $data['transactions'],
            '{{TOTAL_SPENT}}'       => number_format((float) $data['total_spent'], 2),
            '{{USER_CONTEXT}}'      => $userMessage,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $promptTemplate);
    }

    /**
     * Call the configured LLM backend and return the full response.
     */
    public function generate(string $prompt): string
    {
        $backend = (string) config('budget-planner.backend', 'ollama');
        $model   = (string) config('budget-planner.model', '');

        return match ($backend) {
            'gemini' => $this->callGemini($prompt, $model ?: 'gemini-2.0-flash'),
            'groq'   => $this->callGroq($prompt, $model ?: 'llama-3.3-70b-versatile'),
            default  => $this->callOllama($prompt, $model ?: 'llama3.1'),
        };
    }

    /**
     * Stream LLM response via a generator (yields partial text chunks).
     *
     * @return \Generator<int, string>
     */
    public function stream(string $prompt): \Generator
    {
        $backend = (string) config('budget-planner.backend', 'ollama');
        $model   = (string) config('budget-planner.model', '');

        if ('ollama' === $backend) {
            yield from $this->streamOllama($prompt, $model ?: 'llama3.1');
        } else {
            yield $this->generate($prompt);
        }
    }

    public function savePlan(string $content, string $filename): string
    {
        $dir = storage_path('budget-plans');
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $path = $dir . '/' . $filename;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Push budget plans to Google Drive if configured and connected.
     */
    public function pushToGoogleDrive(): array
    {
        $service = app(GoogleDriveService::class);
        $service->setUser($this->user);

        if (!$service->isConnected()) {
            return ['success' => false, 'message' => 'Google Drive not connected.'];
        }

        $result = $service->pushBudgetPlans();

        return ['success' => true, 'uploaded' => $result['uploaded'], 'files' => $result['files']];
    }

    private function loadPromptTemplate(): string
    {
        $paths = [
            base_path('.cursor/skills/generate-budget-plan/prompt-template.md'),
            resource_path('budget-planner/prompt-template.md'),
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return file_get_contents($path);
            }
        }

        return $this->defaultPromptTemplate();
    }

    private function callOllama(string $prompt, string $model): string
    {
        $url     = rtrim((string) config('budget-planner.ollama_url', 'http://localhost:11434'), '/');
        $payload = json_encode([
            'model'   => $model,
            'prompt'  => $prompt,
            'stream'  => false,
            'options' => ['temperature' => 0.3, 'num_predict' => 8192],
        ], JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 300,
            ],
        ]);

        $response = @file_get_contents("{$url}/api/generate", false, $context);

        if (false === $response) {
            throw new \RuntimeException('Ollama request failed. Is Ollama running at ' . $url . '?');
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        return $data['response'] ?? '';
    }

    /**
     * @return \Generator<int, string>
     */
    private function streamOllama(string $prompt, string $model): \Generator
    {
        $url     = rtrim((string) config('budget-planner.ollama_url', 'http://localhost:11434'), '/');
        $payload = json_encode([
            'model'   => $model,
            'prompt'  => $prompt,
            'stream'  => true,
            'options' => ['temperature' => 0.3, 'num_predict' => 8192],
        ], JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 300,
            ],
        ]);

        $stream = @fopen("{$url}/api/generate", 'r', false, $context);

        if (false === $stream) {
            throw new \RuntimeException('Ollama streaming request failed. Is Ollama running at ' . $url . '?');
        }

        try {
            while (!feof($stream)) {
                $line = fgets($stream);
                if (false === $line || '' === trim($line)) {
                    continue;
                }

                $data = json_decode($line, true);
                if (null !== $data && isset($data['response'])) {
                    yield $data['response'];
                    if (true === ($data['done'] ?? false)) {
                        break;
                    }
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private function callGemini(string $prompt, string $model): string
    {
        $apiKey  = (string) config('budget-planner.gemini_api_key', '');
        if ('' === $apiKey) {
            throw new \RuntimeException('GEMINI_API_KEY not configured. Get a free key at https://aistudio.google.com/app/apikey');
        }

        $url     = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $payload = json_encode([
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 8192],
        ], JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 120,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if (false === $response) {
            throw new \RuntimeException('Gemini API request failed.');
        }

        $data       = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        $candidates = $data['candidates'] ?? [];

        return $candidates[0]['content']['parts'][0]['text'] ?? '';
    }

    private function callGroq(string $prompt, string $model): string
    {
        $apiKey = (string) config('budget-planner.groq_api_key', '');
        if ('' === $apiKey) {
            throw new \RuntimeException('GROQ_API_KEY not configured. Get a free key at https://console.groq.com/keys');
        }

        $payload = json_encode([
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => 'You are an expert financial planner. Generate detailed, actionable budget plans in markdown format.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            'max_tokens'  => 8192,
        ], JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
                'content' => $payload,
                'timeout' => 120,
            ],
        ]);

        $response = @file_get_contents('https://api.groq.com/openai/v1/chat/completions', false, $context);
        if (false === $response) {
            throw new \RuntimeException('Groq API request failed.');
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        return $data['choices'][0]['message']['content'] ?? '';
    }

    private function defaultPromptTemplate(): string
    {
        return <<<'PROMPT'
You are an expert personal finance analyst. Generate a comprehensive monthly budget plan in markdown format.

Analysis Period: {{PERIOD_START}} to {{PERIOD_END}} ({{PERIOD_MONTHS}} months)
Currency: {{CURRENCY}}
Monthly Salary: {{CURRENCY}} {{SALARY}}
Total Transactions: {{TRANSACTION_COUNT}}
Total Spent: {{CURRENCY}} {{TOTAL_SPENT}}

Spending Breakdown:
{{SPENDING_ANALYSIS}}

Savings Goals:
{{GOALS}}

Recurring Bills:
{{BILLS}}

User Context:
{{USER_CONTEXT}}

Generate a detailed budget plan with: financial snapshot, category analysis with reasoning, budget allocation table, category-by-category reasoning, emergency buffer analysis, levers for further cuts, and Firefly III setup instructions.
PROMPT;
    }
}
