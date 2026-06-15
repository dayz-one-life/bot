<?php

use App\Models\HitEvent;
use App\Models\Life;
use App\Models\Player;
use App\Services\Newspaper\WeeklyFactsBuilder;
use Carbon\CarbonImmutable;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function seedWeek(): void
{
    $p = Player::create(['gamertag' => 'DustOffMike']);
    Player::create(['gamertag' => 'SaltShaker77']);
    Life::create([
        'player_id' => $p->id,
        'started_at' => CarbonImmutable::parse('2026-06-08 10:00:00'),
        'ended_at' => CarbonImmutable::parse('2026-06-10 10:00:00'),
        'playtime_seconds' => 7200,
        'death_cause' => 'pvp',
        'death_by_gamertag' => 'SaltShaker77',
        'death_weapon' => 'M4',
        'death_distance' => 412.0,
        'death_log' => '10:00:00 | Player "DustOffMike" (DEAD) (id=D= pos=<6700.0, 2500.0, 1.0>) killed by Player "SaltShaker77"',
    ]);
    foreach (range(1, 3) as $i) {
        HitEvent::create([
            'victim_player_id' => $p->id, 'victim_gamertag' => 'DustOffMike',
            'attacker_gamertag' => null, 'attacker_type' => 'infected', 'attacker_label' => 'an infected jogger',
            'body_part' => 'Torso', 'victim_hp' => 50, 'victim_x' => 6700.0, 'victim_y' => 2500.0,
            'occurred_at' => CarbonImmutable::parse('2026-06-09 12:00:00'),
        ]);
    }
}

it('aggregates the trailing week with deltas', function () {
    CarbonImmutable::setTestNow('2026-06-13 22:00:00');
    seedWeek();
    $facts = (new WeeklyFactsBuilder())->build(CarbonImmutable::now());

    expect($facts['counts']['lives_lost'])->toBe(1);
    expect($facts['counts']['infected_attacks'])->toBe(3);
    expect($facts['superlatives']['deadliest_player'])->toMatchArray(['gamertag' => 'SaltShaker77', 'kills' => 1]);
    expect($facts['superlatives']['furthest_kill']['distance'])->toBe(412.0);
    CarbonImmutable::setTestNow();
});

it('exposes location only as anonymized region trends (no player names, no coordinates)', function () {
    CarbonImmutable::setTestNow('2026-06-13 22:00:00');
    seedWeek();
    $facts = (new WeeklyFactsBuilder())->build(CarbonImmutable::now());

    expect($facts['location_trends']['infected_by_region'])->toHaveKey('Chernogorsk');
    expect($facts['location_trends']['infected_by_region']['Chernogorsk'])->toBe(3);

    $json = json_encode($facts);
    expect($json)->not->toContain('pos=<');
    expect($json)->not->toContain('6700');
    CarbonImmutable::setTestNow();
});

it('returns a quiet-week shape with zero counts when nothing happened', function () {
    CarbonImmutable::setTestNow('2026-06-13 22:00:00');
    $facts = (new WeeklyFactsBuilder())->build(CarbonImmutable::now());
    expect($facts['counts']['lives_lost'])->toBe(0);
    expect($facts['superlatives']['deadliest_player'])->toBeNull();
    CarbonImmutable::setTestNow();
});
