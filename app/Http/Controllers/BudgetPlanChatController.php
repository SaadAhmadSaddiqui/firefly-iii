<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use FireflyIII\Services\Internal\BudgetPlanGeneratorService;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BudgetPlanChatController extends Controller
{
    private BudgetPlanGeneratorService $generator;

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            $this->generator = app(BudgetPlanGeneratorService::class);

            return $next($request);
        });
    }

    /**
     * SSE endpoint that extracts data and streams the LLM response.
     */
    public function generate(Request $request): StreamedResponse
    {
        $request->validate([
            'message'  => 'required|string|max:5000',
            'months'   => 'integer|min:1|max:24',
            'salary'   => 'nullable|numeric|min:0',
            'currency' => 'string|max:10',
        ]);

        /** @var User $user */
        $user     = auth()->user();
        $months   = (int) $request->input('months', 3);
        $salary   = $request->input('salary') ? (float) $request->input('salary') : null;
        $currency = $request->input('currency', config('budget-planner.default_currency', 'AED'));
        $message  = $request->input('message', '');

        $this->generator->setUser($user);

        return response()->stream(function () use ($months, $salary, $currency, $message): void {
            $this->sendSSE('status', json_encode(['text' => 'Extracting transaction data...']));

            try {
                $data = $this->generator->extractData($months);
                $this->sendSSE('status', json_encode([
                    'text' => "Found {$data['transactions']} transactions totaling {$currency} " . number_format((float) $data['total_spent'], 2) . " over {$months} months.",
                ]));

                $prompt = $this->generator->buildPrompt($data, $message, $salary, $currency);
                $this->sendSSE('status', json_encode(['text' => 'Generating budget plan...']));

                $fullContent = '';
                foreach ($this->generator->stream($prompt) as $chunk) {
                    $fullContent .= $chunk;
                    $this->sendSSE('chunk', json_encode(['text' => $chunk]));
                }

                $this->sendSSE('done', json_encode(['text' => $fullContent]));
            } catch (\Throwable $e) {
                Log::error('Budget plan generation failed: ' . $e->getMessage());
                $this->sendSSE('error', json_encode(['text' => $e->getMessage()]));
            }
        }, 200, [
            'Content-Type'                => 'text/event-stream',
            'Cache-Control'               => 'no-cache',
            'Connection'                  => 'keep-alive',
            'X-Accel-Buffering'           => 'no',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Save generated plan to storage and optionally push to Google Drive.
     */
    public function save(Request $request): JsonResponse
    {
        $request->validate([
            'content'  => 'required|string',
            'filename' => 'required|string|regex:/^[A-Za-z0-9_\-]+\.md$/',
            'push'     => 'boolean',
        ]);

        /** @var User $user */
        $user = auth()->user();
        $this->generator->setUser($user);

        $filename = $request->input('filename');
        $content  = $request->input('content');
        $push     = (bool) $request->input('push', false);

        $path = $this->generator->savePlan($content, $filename);

        $result = [
            'success'  => true,
            'filename' => $filename,
            'path'     => $path,
            'pushed'   => false,
        ];

        if ($push) {
            $driveResult      = $this->generator->pushToGoogleDrive();
            $result['pushed'] = $driveResult['success'] ?? false;
            $result['drive']  = $driveResult;
        }

        return response()->json($result);
    }

    private function sendSSE(string $event, string $data): void
    {
        echo "event: {$event}\ndata: {$data}\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
