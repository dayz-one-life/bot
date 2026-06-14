<?php

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a bunker visit with player, life and visited_at', function () {
    $player = Player::create(['gamertag' => 'Alice']);
    $life = Life::create(['player_id' => $player->id, 'started_at' => CarbonImmutable::parse('2026-06-14 01:00:00')]);

    $visit = BunkerVisit::create([
        'player_id' => $player->id,
        'life_id' => $life->id,
        'visited_at' => CarbonImmutable::parse('2026-06-14 02:30:35'),
    ]);

    expect($visit->fresh()->visited_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($visit->player->gamertag)->toBe('Alice')
        ->and($visit->life->id)->toBe($life->id);
});

it('allows a null life_id', function () {
    $player = Player::create(['gamertag' => 'Bob']);
    $visit = BunkerVisit::create([
        'player_id' => $player->id,
        'life_id' => null,
        'visited_at' => CarbonImmutable::parse('2026-06-14 02:30:35'),
    ]);
    expect($visit->fresh()->life_id)->toBeNull();
});
