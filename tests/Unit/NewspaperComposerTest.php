<?php

use App\Services\Newspaper\NewspaperComposer;

function sampleFacts(): array
{
    return [
        'period' => ['start' => '2026-06-06T22:00:00+00:00', 'end' => '2026-06-13T22:00:00+00:00'],
        'counts' => [
            'lives_lost' => 41, 'lives_lost_prev' => 32, 'playtime_human' => '318h',
            'infected_attacks' => 162, 'infected_attacks_prev' => 108, 'pvp_hits' => 90,
            'bunker_descents' => 23, 'souls_alive' => 6,
        ],
        'superlatives' => [
            'deadliest_player' => ['gamertag' => 'SaltShaker77', 'kills' => 7],
            'furthest_kill' => ['killer' => 'RailgunRandy', 'victim' => 'carl', 'weapon' => 'Mosin', 'distance' => 412.0],
            'longest_life_ended' => ['gamertag' => 'DustOffMike', 'duration_human' => '3d 4h'],
            'most_travelled' => ['gamertag' => 'RoamerRick', 'km' => 14.0],
        ],
    ];
}

it('composes a masthead + four section embeds', function () {
    $prose = ['editorial' => 'Ed body', 'recap' => 'Recap body', 'classifieds' => 'Ads body'];
    $embeds = (new NewspaperComposer())->compose(sampleFacts(), $prose, 12);

    expect($embeds)->toHaveCount(5);
    expect($embeds[0]['title'])->toContain('THE ONE LIFE TRIBUNE');
    expect($embeds[0]['title'])->toContain('No.12');
    expect($embeds[2]['title'])->toContain('NUMBERS');
    expect($embeds[2]['description'])->toContain('41');
    expect($embeds[2]['description'])->toContain('SaltShaker77');
    expect($embeds[1]['description'])->toContain('Ed body');
    expect($embeds[3]['description'])->toContain('Recap body');
    expect($embeds[4]['description'])->toContain('Ads body');
});

it('never @-mentions', function () {
    $prose = ['editorial' => 'a', 'recap' => 'b', 'classifieds' => 'c'];
    $embeds = (new NewspaperComposer())->compose(sampleFacts(), $prose, 1);
    foreach ($embeds as $e) {
        expect($e['description'] ?? '')->not->toContain('<@');
        expect($e['title'] ?? '')->not->toContain('<@');
    }
});

it('hides empty (zero-count / null-superlative) categories in the Week in Numbers box', function () {
    $facts = sampleFacts();
    $facts['counts']['infected_attacks'] = 0;
    $facts['counts']['bunker_descents'] = 0;
    $facts['superlatives']['furthest_kill'] = null;
    $facts['superlatives']['most_travelled'] = null;

    $embeds = (new NewspaperComposer())->compose($facts, ['editorial' => 'a', 'recap' => 'b', 'classifieds' => 'c'], 1);
    $numbers = $embeds[2]['description'];

    // Empty categories are omitted entirely.
    expect($numbers)->not->toContain('Infected attacks');
    expect($numbers)->not->toContain('Bunker descents');
    expect($numbers)->not->toContain('Furthest kill');
    expect($numbers)->not->toContain('Most travelled');

    // Non-empty categories remain.
    expect($numbers)->toContain('Lives lost');
    expect($numbers)->toContain('Deadliest player');
    expect($numbers)->toContain('Longest life ended');
});

it('handles a quiet week with empty prose and null superlatives', function () {
    $facts = [
        'period' => ['start' => '2026-06-06T22:00:00+00:00', 'end' => '2026-06-13T22:00:00+00:00'],
        'counts' => [
            'lives_lost' => 0, 'lives_lost_prev' => 0, 'playtime_human' => '0m',
            'infected_attacks' => 0, 'infected_attacks_prev' => 0, 'pvp_hits' => 0,
            'bunker_descents' => 0, 'souls_alive' => 0,
        ],
        'superlatives' => [
            'deadliest_player' => null, 'furthest_kill' => null,
            'longest_life_ended' => null, 'most_travelled' => null,
        ],
    ];
    $prose = ['editorial' => '', 'recap' => '', 'classifieds' => ''];
    $embeds = (new NewspaperComposer())->compose($facts, $prose, 3);
    expect($embeds)->toHaveCount(5);
    // Empty prose collapses to a placeholder, not a crash.
    expect($embeds[1]['description'])->not->toBe('');
});
