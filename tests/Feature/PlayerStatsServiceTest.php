<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Stats\PlayerStatsService;
use Carbon\CarbonImmutable;

beforeEach(fn () => $this->svc = new PlayerStatsService());

it('reports not found for an unknown gamertag', function () {
    expect($this->svc->statsFor('Nobody')['found'])->toBeFalse();
});

it('aggregates lives, playtime, deaths, and alive status', function () {
    $p = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour(), 'death_cause' => 'pvp', 'playtime_seconds' => 1800]);
    Life::create(['player_id' => $p->id, 'started_at' => now(), 'playtime_seconds' => 600]);

    $s = $this->svc->statsFor('Alice');
    expect($s['found'])->toBeTrue();
    expect($s['lives'])->toBe(2);
    expect($s['deaths'])->toBe(1);
    expect($s['playtime_seconds'])->toBe(2400);
    expect($s['current_life_seconds'])->toBe(600);
    expect($s['alive'])->toBeTrue();
    expect($s['linked'])->toBeFalse();
});

it('reports null current life when the player has no open life', function () {
    $p = Player::create(['gamertag' => 'Carol', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour(), 'death_cause' => 'pvp', 'playtime_seconds' => 1800]);

    $s = $this->svc->statsFor('Carol');
    expect($s['alive'])->toBeFalse();
    expect($s['current_life_seconds'])->toBeNull();
});

it('reports linked status', function () {
    Player::create(['gamertag' => 'Bob', 'discord_user_id' => 'd-bob', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    expect($this->svc->statsFor('Bob')['linked'])->toBeTrue();
});

it('lists current-life sessions oldest-first with computed open duration', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:58:00Z');
    $p = Player::create(['gamertag' => 'Dana', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-13T14:00:00Z', 'playtime_seconds' => 600]);

    // Closed session: 14:02 -> 15:25 = 4980s
    GameSession::create([
        'player_id' => $p->id, 'life_id' => $life->id,
        'connected_at' => '2026-06-13T14:02:00Z', 'disconnected_at' => '2026-06-13T15:25:00Z',
        'duration_seconds' => 4980, 'close_reason' => 'reboot',
    ]);
    // Open session: 16:40 -> now(16:58) = 1080s, no duration stored
    GameSession::create([
        'player_id' => $p->id, 'life_id' => $life->id,
        'connected_at' => '2026-06-13T16:40:00Z', 'disconnected_at' => null, 'duration_seconds' => null,
    ]);

    $s = (new PlayerStatsService())->statsFor('Dana');

    expect($s['current_life_session_total'])->toBe(2);
    expect($s['current_life_sessions'])->toHaveCount(2);

    expect($s['current_life_sessions'][0]['connected_at'])->toStartWith('2026-06-13T14:02:00');
    expect($s['current_life_sessions'][0]['duration_seconds'])->toBe(4980);
    expect($s['current_life_sessions'][0]['is_open'])->toBeFalse();

    expect($s['current_life_sessions'][1]['connected_at'])->toStartWith('2026-06-13T16:40:00');
    expect($s['current_life_sessions'][1]['duration_seconds'])->toBe(1080);
    expect($s['current_life_sessions'][1]['is_open'])->toBeTrue();

    CarbonImmutable::setTestNow();
});

it('returns no current-life sessions for a dead player', function () {
    $p = Player::create(['gamertag' => 'Eve', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour(), 'death_cause' => 'pvp', 'playtime_seconds' => 1800]);
    GameSession::create([
        'player_id' => $p->id, 'life_id' => $life->id,
        'connected_at' => now()->subDay(), 'disconnected_at' => now()->subDay()->addHour(),
        'duration_seconds' => 3600, 'close_reason' => 'clean',
    ]);

    $s = (new PlayerStatsService())->statsFor('Eve');
    expect($s['alive'])->toBeFalse();
    expect($s['current_life_sessions'])->toBe([]);
    expect($s['current_life_session_total'])->toBe(0);
});
