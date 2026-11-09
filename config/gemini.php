<?php

declare(strict_types=1);

return [
    'api_key'  => env('GEMINI_API_KEY', ''),
    'model'    => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
    'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models/',
    'timeout'  => 300,
];
