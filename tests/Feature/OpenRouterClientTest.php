<?php

use App\Services\Llm\OpenRouterClient;
use Illuminate\Support\Facades\Http;

it('posts a chat completion and returns the message content', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => "HEADLINE\nbody text"]]],
        ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'anthropic/claude-haiku-4.5', 'https://openrouter.ai/api/v1', 20, 900, 1.0);
    $out = $client->complete('you are a columnist', 'write an obituary');

    expect($out)->toBe("HEADLINE\nbody text");
    Http::assertSent(function ($r) {
        return $r->hasHeader('Authorization', 'Bearer sk-test')
            && $r['model'] === 'anthropic/claude-haiku-4.5'
            && $r['messages'][0]['role'] === 'system'
            && $r['messages'][1]['content'] === 'write an obituary';
    });
});

it('throws when the api key is empty (never calls out)', function () {
    Http::fake();
    $client = new OpenRouterClient(null, 'm', 'https://x/api/v1', 20, 900, 1.0);

    expect(fn () => $client->complete('s', 'u'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('throws on a non-2xx response', function () {
    Http::fake(['*/chat/completions' => Http::response(['error' => 'nope'], 500)]);
    $client = new OpenRouterClient('sk-test', 'm', 'https://x/api/v1', 20, 900, 1.0);

    expect(fn () => $client->complete('s', 'u'))->toThrow(RuntimeException::class);
});

it('throws when the response has no content', function () {
    Http::fake(['*/chat/completions' => Http::response(['choices' => []])]);
    $client = new OpenRouterClient('sk-test', 'm', 'https://x/api/v1', 20, 900, 1.0);

    expect(fn () => $client->complete('s', 'u'))->toThrow(RuntimeException::class);
});
