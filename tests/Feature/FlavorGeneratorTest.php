<?php

use App\Services\Llm\FlavorGenerator;
use App\Services\Llm\OpenRouterClient;
use App\Services\Personality\MessagePicker;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => MessagePicker::reset());

// Deterministic chooser (index 0) so canned-fallback assertions are stable.
function flavorGen(?string $key = 'sk'): FlavorGenerator {
    return new FlavorGenerator(
        new OpenRouterClient($key, 'm', 'https://x/api/v1', 20, 900, 1.0),
        new MessagePicker(fn (array $pool, ?int $avoid) => 0),
    );
}

it('substitutes placeholder tokens into the LLM line', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '🎯 **Bounty** is now on {{TARGET}} — go get them!']]],
    ])]);

    $line = flavorGen()->line('bounty.placed', ['target' => '<@123>'], 'FB');

    expect($line)->toContain('<@123>')->and($line)->not->toContain('{{TARGET}}');
});

it('falls back to the canned pool when the client throws', function () {
    Http::fake(['*/chat/completions' => Http::response([], 500)]);

    $line = flavorGen()->line('bounty.placed', ['target' => '<@123>'], 'FB');

    $expected = strtr(array_values(config('personality.bounty.placed'))[0], [':target' => '<@123>']);
    expect($line)->toBe($expected);
});

it('falls back when the LLM leaves an unprovided placeholder in the output', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '{{TARGET}} fell to {{KILLER}}']]],
    ])]);

    // Only 'target' is provided, so {{KILLER}} survives substitution -> treated as a failure.
    $line = flavorGen()->line('bounty.placed', ['target' => '<@1>'], 'FB');

    $expected = strtr(array_values(config('personality.bounty.placed'))[0], [':target' => '<@1>']);
    expect($line)->toBe($expected)->and($line)->not->toContain('{{KILLER}}');
});

it('falls back to canned copy with no api key and makes no HTTP call', function () {
    Http::fake();

    $line = flavorGen(key: null)->line('ban.unbanned', ['who' => '<@9>', 'reason' => 'expired'], 'FB');

    $expected = strtr(array_values(config('personality.ban.unbanned'))[0], [':who' => '<@9>', ':reason' => 'expired']);
    expect($line)->toBe($expected);
    Http::assertNothingSent();
});

it('instructs the model to stay neutral for bounty.ended', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '🏳️ The bounty on {{TARGET}} is over.']]],
    ])]);

    flavorGen()->line('bounty.ended', ['target' => '<@1>'], 'FB');

    Http::assertSent(function ($r) {
        $user = $r['messages'][1]['content'];
        return str_contains($user, 'neutral')
            && str_contains($user, 'do NOT')
            && str_contains($user, '{{TARGET}}');
    });
});

it('routes ban.death through the generator and substitutes its tokens', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '⚰️ {{WHO}} is benched until {{EXPIRES}} ({{REASON}}).']]],
    ])]);

    $line = flavorGen()->line('ban.death', ['who' => '<@7>', 'reason' => 'One life autoban', 'expires' => '<t:123:f>'], 'FB');

    expect($line)->toContain('<@7>')->and($line)->toContain('<t:123:f>')->and($line)->not->toContain('{{');
});
