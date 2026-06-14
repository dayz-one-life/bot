<?php

use App\Services\Lifecycle\AnnouncementGenerator;
use App\Services\Llm\OpenRouterClient;
use App\Services\Personality\MessagePicker;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => MessagePicker::reset());

function genFacts(array $over = []): array {
    return array_merge([
        'gamertag' => 'Doomed', 'linked' => true, 'cause' => 'pvp', 'killer' => 'Sniper',
        'weapon' => 'SVD', 'distance_m' => 312.5,
        'playtime_human' => '41 minutes', 'playtime_seconds' => 2460, 'associates' => ['Buddy'],
        'prior_death' => null, 'raw_log' => "00:02 hit\n00:03 killed by Sniper",
        'witnesses' => ['Charlie', 'Dave'],
    ], $over);
}

it('parses the LLM output into headline + body (first line is the headline)', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => "LOCAL MAN MEETS SVD\n📰 The late {{PLAYER}}..."]]],
    ])]);
    $gen = new AnnouncementGenerator(new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0), new MessagePicker());

    $out = $gen->generate('eulogy', genFacts());

    expect($out['headline'])->toBe('LOCAL MAN MEETS SVD');
    expect($out['body'])->toContain('{{PLAYER}}');
    // Age is the life clock (playtime) only — never wall-clock.
    Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'], 'age_playtime')
        && ! str_contains($r['messages'][1]['content'], 'age_wall_clock'));
});

it('falls back to a canned eulogy when the client throws', function () {
    Http::fake(['*/chat/completions' => Http::response([], 500)]);
    $chooser = fn (array $pool, ?int $avoid) => 0; // deterministic
    $gen = new AnnouncementGenerator(
        new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0),
        new MessagePicker($chooser),
    );

    $out = $gen->generate('eulogy', genFacts());

    // First entry of eulogy.pvp pool.
    expect($out['headline'])->toContain('{{PLAYER}}');
    expect($out['body'])->toContain('{{KILLER}}');
});

it('birth prompt for a first life omits age and never implies a prior life', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => "WELCOME\n📰 {{PLAYER}} arrives."]]],
    ])]);
    $gen = new AnnouncementGenerator(new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0), new MessagePicker());

    $gen->generate('birth', genFacts(['is_first_life' => true, 'prior_death' => null]));

    Http::assertSent(function ($r) {
        $user = $r['messages'][1]['content'];
        return str_contains($user, '"is_first_life_ever": true')
            && ! str_contains($user, 'age_playtime')    // newborn age is never sent
            && ! str_contains($user, 'age_wall_clock')
            && ! str_contains($user, '41 minutes');      // no current-life age leaks in
    });
});

it('passes real active survivors for witness quotes (births and eulogies)', function () {
    Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => "H\n📰 body"]]]])]);
    $gen = new AnnouncementGenerator(new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0), new MessagePicker());

    $gen->generate('eulogy', genFacts(['witnesses' => ['Charlie', 'Dave']]));
    $gen->generate('birth', genFacts(['is_first_life' => true, 'witnesses' => ['Eve']]));

    Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'], 'real_survivors_for_quotes')
        && str_contains($r['messages'][1]['content'], 'Charlie') && str_contains($r['messages'][1]['content'], 'Dave'));
    Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'], '"real_survivors_for_quotes"')
        && str_contains($r['messages'][1]['content'], 'Eve'));
});

it('birth prompt for a respawn passes the real prior-life summary', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => "BACK\n📰 {{PLAYER}} returns."]]],
    ])]);
    $gen = new AnnouncementGenerator(new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0), new MessagePicker());

    $gen->generate('birth', genFacts(['is_first_life' => false, 'prior_death' => 'previous life ended (pvp) after 18 minutes']));

    Http::assertSent(function ($r) {
        $user = $r['messages'][1]['content'];
        return str_contains($user, '"is_first_life_ever": false')
            && str_contains($user, 'previous life ended (pvp) after 18 minutes');
    });
});

it('falls back to a canned birth when there is no api key', function () {
    Http::fake();
    $gen = new AnnouncementGenerator(
        new OpenRouterClient(null, 'm', 'https://x/api/v1', 20, 900, 1.0),
        new MessagePicker(fn (array $pool, ?int $avoid) => 0),
    );

    $out = $gen->generate('birth', genFacts(['cause' => null, 'killer' => null]));

    expect($out['headline'])->not->toBe('');
    expect($out['body'])->toContain('{{PLAYER}}');
    Http::assertNothingSent();
});
