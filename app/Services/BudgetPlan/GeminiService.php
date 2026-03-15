<?php

declare(strict_types=1);

namespace FireflyIII\Services\BudgetPlan;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class GeminiService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int    $timeout;

    public function __construct()
    {
        $this->apiKey  = (string) config('gemini.api_key');
        $this->model   = (string) config('gemini.model');
        $this->baseUrl = (string) config('gemini.base_url');
        $this->timeout = (int) config('gemini.timeout', 120);

        if ('' === $this->apiKey) {
            throw new RuntimeException('Gemini API key is not configured. Set GEMINI_API_KEY in your .env file.');
        }
    }

    /**
     * @throws RuntimeException
     */
    public function generate(string $systemPrompt, string $userPrompt): string
    {
        $url  = sprintf('%s%s:generateContent?key=%s', $this->baseUrl, $this->model, $this->apiKey);

        $body = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'     => 0.7,
                'maxOutputTokens' => 65536,
                'thinkingConfig'  => [
                    'thinkingBudget' => 8192,
                ],
            ],
        ];

        $client = new Client(['timeout' => $this->timeout]);

        try {
            $response = $client->post($url, [
                'json'    => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gemini API request failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        $parts = $data['candidates'][0]['content']['parts'] ?? [];

        $textParts = [];
        foreach ($parts as $part) {
            if (!empty($part['thought'])) {
                continue;
            }
            if (isset($part['text']) && '' !== $part['text']) {
                $textParts[] = $part['text'];
            }
        }

        $text = implode("\n", $textParts);

        if ('' === $text) {
            $finishReason = $data['candidates'][0]['finishReason'] ?? 'UNKNOWN';
            throw new RuntimeException(sprintf('Gemini returned no content. Finish reason: %s', $finishReason));
        }

        $text = preg_replace('/\A```(?:markdown)?\s*\n/', '', $text);
        $text = preg_replace('/\n```\s*\z/', '', $text);

        $text = $this->sanitizeOutput($text);

        return trim($text);
    }

    private function sanitizeOutput(string $text): string
    {
        $lines      = explode("\n", $text);
        $cleaned    = [];
        $maxLineLen = 1000;

        foreach ($lines as $line) {
            if (mb_strlen($line) > $maxLineLen) {
                $line = $this->truncateLine($line, $maxLineLen);
            }

            if ('' === $line && !empty($cleaned) && '' === end($cleaned)) {
                continue;
            }

            $cleaned[] = $line;
        }

        $text = implode("\n", $cleaned);

        // Strip trailing unicode block/box-drawing characters
        $text = preg_replace('/[\x{2580}-\x{259F}\x{2500}-\x{257F}─━│┃═]{30,}.*$/su', '', $text);

        return $text;
    }

    /**
     * Truncate an excessively long line, preserving markdown table structure if present.
     */
    private function truncateLine(string $line, int $max): string
    {
        $trimmed = rtrim($line);

        // For markdown table separator rows (|---|---|), rebuild with sane widths
        if (1 === preg_match('/^\|[\s:]*-{3,}/', $trimmed)) {
            $cells = explode('|', trim($trimmed, '|'));
            $rebuilt = array_map(function (string $cell): string {
                $cell = trim($cell);
                if (preg_match('/^:?-+:?$/', $cell)) {
                    $prefix = str_starts_with($cell, ':') ? ':' : '';
                    $suffix = str_ends_with($cell, ':') ? ':' : '';

                    return ' ' . $prefix . str_repeat('-', 20) . $suffix . ' ';
                }

                return ' ' . $cell . ' ';
            }, $cells);

            return '|' . implode('|', $rebuilt) . '|';
        }

        // For markdown table data rows, trim cell content
        if (str_starts_with($trimmed, '|')) {
            $cells = explode('|', trim($trimmed, '|'));
            $rebuilt = array_map(static function (string $cell): string {
                $cell = trim($cell);
                if (mb_strlen($cell) > 200) {
                    $cell = mb_substr($cell, 0, 197) . '...';
                }

                return ' ' . $cell . ' ';
            }, $cells);

            return '|' . implode('|', $rebuilt) . '|';
        }

        // Generic truncation for any other line
        return mb_substr($trimmed, 0, $max);
    }
}
