<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;

/**
 * Minimal OpenRouter (OpenAI-compatible) chat-completions client. Throws RuntimeException on any
 * problem (no key, network/timeout, non-2xx, empty content) so callers can fall back cleanly.
 */
class OpenRouterClient
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $baseUrl,
        private int $timeoutSeconds = 20,
        private int $maxTokens = 900,
        private float $temperature = 1.0,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('llm.api_key'),
            config('llm.model', 'anthropic/claude-haiku-4.5'),
            config('llm.base_url', 'https://openrouter.ai/api/v1'),
            (int) config('llm.timeout_seconds', 20),
            (int) config('llm.max_tokens', 900),
            (float) config('llm.temperature', 1.0),
        );
    }

    public function complete(string $system, string $user): string
    {
        if (! $this->apiKey) {
            throw new \RuntimeException('OpenRouter API key not configured');
        }

        $res = Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->withHeaders([
                'HTTP-Referer' => 'https://github.com/dayz-one-life',
                'X-Title' => 'DayZ One Life Bot',
            ])
            ->post($this->baseUrl.'/chat/completions', [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        if (! $res->ok()) {
            throw new \RuntimeException("OpenRouter HTTP {$res->status()}");
        }

        $content = $res->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new \RuntimeException('OpenRouter returned no content');
        }

        return $content;
    }
}
