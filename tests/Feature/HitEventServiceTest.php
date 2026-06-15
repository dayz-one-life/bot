<?php

use App\Models\HitEvent;
use App\Models\Player;
use App\Services\Hit\HitEventService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => config()->set('hits.enabled', true));

it('records a player hit and links the victim by gamertag', function () {
    Player::create(['gamertag' => 'Victim']);
    $svc = new HitEventService();
    $hit = $svc->record([
        'victim' => 'Victim', 'victim_hp' => 50, 'victim_x' => 1.0, 'victim_y' => 2.0,
        'body_part' => 'Torso', 'attacker_gamertag' => 'Attacker',
        'attacker_type' => 'player', 'attacker_label' => null,
    ], new DateTimeImmutable('2026-06-10 10:00:00'));

    expect($hit)->not->toBeNull();
    expect($hit->victim_player_id)->toBe(Player::where('gamertag', 'Victim')->first()->id);
    expect(HitEvent::count())->toBe(1);
});

it('records an infected hit even when the victim player is unknown', function () {
    $svc = new HitEventService();
    $hit = $svc->record([
        'victim' => 'Stranger', 'victim_hp' => 30, 'victim_x' => null, 'victim_y' => null,
        'body_part' => 'Leg', 'attacker_gamertag' => null,
        'attacker_type' => 'infected', 'attacker_label' => 'an infected jogger',
    ], new DateTimeImmutable('2026-06-10 10:05:00'));

    expect($hit->victim_player_id)->toBeNull();
    expect($hit->victim_gamertag)->toBe('Stranger');
});

it('no-ops when hit tracking is disabled', function () {
    config()->set('hits.enabled', false);
    $svc = new HitEventService();
    $hit = $svc->record([
        'victim' => 'Victim', 'victim_hp' => 50, 'victim_x' => null, 'victim_y' => null,
        'body_part' => null, 'attacker_gamertag' => null,
        'attacker_type' => 'environment', 'attacker_label' => 'a fall',
    ], new DateTimeImmutable('2026-06-10 10:00:00'));
    expect($hit)->toBeNull();
    expect(HitEvent::count())->toBe(0);
});
