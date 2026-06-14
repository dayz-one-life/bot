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
    expect($facts['playtime_human'])->toContain('41'); // age == playtime (life clock), not wall-clock
    expect($facts['raw_log'])->toContain('raw line A');
    expect($facts['associates'])->toBeArray();
});

it('strips map coordinates from the raw log fed to the LLM', function () {
    $p = Player::create(['gamertag' => 'Loc', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create([
        'player_id' => $p->id, 'started_at' => '2026-06-14T11:00:00Z', 'ended_at' => '2026-06-14T11:30:00Z',
        'death_cause' => 'pvp', 'playtime_seconds' => 1800,
        'death_log' => '11:30 | Player "Loc" (id=L= pos=<5154.0, 1075.1, 56.3>) killed by Player "K" (id=K= pos=<900.0, 950.0, 5.0>) with M4A1 from 153.4 meters',
    ]);

    $facts = (new LifeFactsBuilder())->build($life);

    expect($facts['raw_log'])->not->toContain('pos=<');
    expect($facts['raw_log'])->not->toContain('5154');
    expect($facts['raw_log'])->not->toContain('950.0');
    expect($facts['raw_log'])->toContain('from 153.4 meters'); // distance kept — it's a range, not a location
    expect($facts['raw_log'])->toContain('killed by Player "K"');
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
