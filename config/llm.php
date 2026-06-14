<?php

return [
    'api_key' => env('OPENROUTER_API_KEY') ?: null,
    'model' => env('OPENROUTER_MODEL', 'anthropic/claude-haiku-4.5'),
    'base_url' => rtrim(env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),
    'timeout_seconds' => (int) env('OPENROUTER_TIMEOUT_SECONDS', 20),
    'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 900),
    'temperature' => (float) env('OPENROUTER_TEMPERATURE', 1.0),
];
