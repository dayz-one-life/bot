<?php

use App\Services\Llm\OpenRouterClient;
use App\Services\Newspaper\NewspaperGenerator;
use Illuminate\Support\Facades\Http;

it('parses the three sections from a well-formed LLM response', function () {
    Http::fake([
        '*' => Http::response(['choices' => [['message' => ['content' =>
            "## EDITORIAL\nIs Cherno safe? No.\n\n## RECAP\nMike died on Wednesday.\n\n## CLASSIFIEDS\nFOR SALE: one M4."
        ]]]], 200),
    ]);

    $gen = new NewspaperGenerator(new OpenRouterClient('key', 'm', 'https://x/api/v1'));
    $out = $gen->generate(['counts' => ['lives_lost' => 5, 'playtime_human' => '300h']]);

    expect($out['editorial'])->toContain('Is Cherno safe');
    expect($out['recap'])->toContain('Mike died');
    expect($out['classifieds'])->toContain('FOR SALE');
});

it('falls back to canned pools when the API fails', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $gen = new NewspaperGenerator(new OpenRouterClient('key', 'm', 'https://x/api/v1'));
    $out = $gen->generate(['counts' => ['lives_lost' => 5, 'playtime_human' => '300h']]);

    expect($out['editorial'])->toContain('5');
    expect($out['recap'])->not->toBe('');
    expect($out['classifieds'])->not->toBe('');
});

it('falls back per-section when a delimiter is missing', function () {
    Http::fake([
        '*' => Http::response(['choices' => [['message' => ['content' =>
            "## EDITORIAL\nOnly an editorial here."
        ]]]], 200),
    ]);

    $gen = new NewspaperGenerator(new OpenRouterClient('key', 'm', 'https://x/api/v1'));
    $out = $gen->generate(['counts' => ['lives_lost' => 2, 'playtime_human' => '10h']]);

    expect($out['editorial'])->toContain('Only an editorial');
    expect($out['recap'])->not->toBe('');
    expect($out['classifieds'])->not->toBe('');
});
