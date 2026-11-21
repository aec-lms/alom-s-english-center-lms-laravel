<?php

declare(strict_types=1);

return [
    'openai' => ['api_key' => (string) env('OPENAI_API_KEY', '')],
    'gemini' => ['api_key' => (string) env('GEMINI_API_KEY', '')],
];
