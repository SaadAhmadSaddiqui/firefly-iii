<?php

declare(strict_types=1);

return [
    'backend'          => env('BUDGET_LLM_BACKEND', 'ollama'),
    'model'            => env('BUDGET_LLM_MODEL', ''),
    'ollama_url'       => env('BUDGET_LLM_OLLAMA_URL', 'http://ollama:11434'),
    'gemini_api_key'   => env('GEMINI_API_KEY', ''),
    'groq_api_key'     => env('GROQ_API_KEY', ''),
    'default_currency' => env('BUDGET_CURRENCY', 'AED'),
];
