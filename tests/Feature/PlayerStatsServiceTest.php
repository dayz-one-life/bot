<?php

use App\Models\Life;
use App\Models\Player;
use App\Services\Stats\PlayerStatsService;

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
    expect($s['alive'])->toBeTrue();
    expect($s['linked'])->toBeFalse();
});

it('reports linked status', function () {
    Player::create(['gamertag' => 'Bob', 'discord_user_id' => 'd-bob', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    expect($this->svc->statsFor('Bob')['linked'])->toBeTrue();
});
