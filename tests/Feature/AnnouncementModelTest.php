<?php

use App\Models\Announcement;
use App\Models\Life;
use App\Models\Player;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('persists an announcement and links it to its life', function () {
    $p = Player::create(['gamertag' => 'Tag', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => now(), 'playtime_seconds' => 0]);

    $a = Announcement::create([
        'life_id' => $life->id,
        'kind' => 'birth',
        'headline' => 'WELCOME',
        'body' => '{{PLAYER}} arrives.',
        'was_fallback' => true,
        'model' => null,
    ]);

    expect($a->was_fallback)->toBeTrue();          // boolean cast
    expect($a->model)->toBeNull();
    expect($life->announcements()->count())->toBe(1);
    expect($life->announcements->first()->kind)->toBe('birth');
});
