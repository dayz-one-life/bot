<?php

use App\Services\Llm\OpenRouterClient;
use App\Services\Newspaper\NewspaperGenerator;
use App\Services\Personality\MessagePicker;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => MessagePicker::reset());

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

    // Deterministic picker (always index 0) so the :lives_lost interpolation assertion below
    // doesn't silently depend on which random template the anti-repeat chooser lands on.
    $picker = new MessagePicker(fn (array $pool, ?int $avoid) => 0);
    $gen = new NewspaperGenerator(new OpenRouterClient('key', 'm', 'https://x/api/v1'), $picker);
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

it('includes last week\'s issue and the continuity clause when a prior issue is provided', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' =>
        "## EDITORIAL\nx\n## RECAP\ny\n## CLASSIFIEDS\nz"
    ]]]], 200)]);

    $gen = new NewspaperGenerator(new OpenRouterClient('key', 'm', 'https://x/api/v1'));
    $gen->generate(
        ['counts' => ['lives_lost' => 3, 'playtime_human' => '9h'], 'previous_week' => ['counts' => ['lives_lost' => 7]]],
        ['week' => '2026-W24', 'editorial' => 'last ed', 'recap' => 'last recap about Mike', 'classifieds' => 'last ads'],
    );

    Http::assertSent(function ($r) {
        $user = $r['messages'][1]['content'];
        $system = $r['messages'][0]['content'];
        return str_contains($user, 'last_week_issue')
            && str_contains($user, 'last recap about Mike')
            && str_contains($user, 'previous_week')
            && str_contains($system, 'CONTINUITY');
    });
});

it('omits last_week_issue from the prompt when no prior issue is provided', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' =>
        "## EDITORIAL\nx\n## RECAP\ny\n## CLASSIFIEDS\nz"
    ]]]], 200)]);

    $gen = new NewspaperGenerator(new OpenRouterClient('key', 'm', 'https://x/api/v1'));
    $out = $gen->generate(['counts' => ['lives_lost' => 1, 'playtime_human' => '2h']]);

    Http::assertSent(fn ($r) => ! str_contains($r['messages'][1]['content'], 'last_week_issue'));
    expect($out['editorial'])->toContain('x');
});
