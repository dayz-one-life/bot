<?php

use App\Models\Life;
use App\Models\Player;
use App\Services\Lifecycle\LifeFactsBuilder;
use Carbon\CarbonImmutable;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-06-14T12:00:00Z'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('builds facts for a pvp death', function () {
    $p = Player::create(['gamertag' => 'Doomed', 'discord_user_id' => '123', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create([
        'player_id' => $p->id,
        'started_at' => '2026-06-14T11:13:00Z', // 47 min wall-clock
        'ended_at' => '2026-06-14T12:00:00Z',
        'death_cause' => 'pvp', 'death_by_gamertag' => 'Sniper', 'death_weapon' => 'SVD',
        'death_distance' => 312.5, 'playtime_seconds' => 2460, // 41 min
        'death_log' => "raw line A\nraw line B",
    ]);

    $facts = (new LifeFactsBuilder())->build($life);

    expect($facts['gamertag'])->toBe('Doomed');
    expect($facts['linked'])->toBeTrue();
    expect($facts['cause'])->toBe('pvp');
    expect($facts['killer'])->toBe('Sniper');
    expect($facts['weapon'])->toBe('SVD');
    expect($facts['distance_m'])->toBe(312.5);
    expect($facts['wall_age_human'])->toContain('47');
    expect($facts['playtime_human'])->toContain('41');
    expect($facts['raw_log'])->toContain('raw line A');
    expect($facts['associates'])->toBeArray();
});

it('marks a sole life as the first life (no prior death)', function () {
    $p = Player::create(['gamertag' => 'Newbie', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $only = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T11:50:00Z', 'playtime_seconds' => 360]);

    $facts = (new LifeFactsBuilder())->build($only);

    expect($facts['is_first_life'])->toBeTrue();
    expect($facts['prior_death'])->toBeNull();
});

it('summarises the prior death for a respawn and marks it NOT the first life', function () {
    $p = Player::create(['gamertag' => 'Reborn', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T09:00:00Z', 'ended_at' => '2026-06-14T10:00:00Z', 'death_cause' => 'environment', 'playtime_seconds' => 3000]);
    $current = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T11:50:00Z', 'playtime_seconds' => 360]);

    $facts = (new LifeFactsBuilder())->build($current);

    expect($facts['linked'])->toBeFalse();
    expect($facts['prior_death'])->toContain('environment');
    expect($facts['is_first_life'])->toBeFalse();
});
