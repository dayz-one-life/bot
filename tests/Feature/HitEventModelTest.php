<?php

use App\Models\HitEvent;
use App\Models\Player;
use Carbon\CarbonImmutable;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('persists a hit event linked to a player', function () {
    $p = Player::create(['gamertag' => 'Victim']);
    $hit = HitEvent::create([
        'victim_player_id' => $p->id,
        'victim_gamertag' => 'Victim',
        'attacker_gamertag' => 'Attacker',
        'attacker_type' => 'player',
        'attacker_label' => null,
        'body_part' => 'Torso',
        'victim_hp' => 50,
        'victim_x' => 100.5,
        'victim_y' => 200.0,
        'occurred_at' => CarbonImmutable::parse('2026-06-10 10:00:00'),
    ]);
    expect($hit->fresh()->attacker_type)->toBe('player');
    expect($hit->fresh()->victim_x)->toBe(100.5);
});
