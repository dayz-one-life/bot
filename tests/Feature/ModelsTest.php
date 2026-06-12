<?php

use App\Models\Player;
use App\Models\Life;
use App\Models\GameSession;

it('exposes open life and open session helpers', function () {
    $player = Player::create(['gamertag' => 'Tag1', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    expect($player->openLife())->toBeNull();
    expect($player->openSession())->toBeNull();

    $life = Life::create(['player_id' => $player->id, 'started_at' => now()]);
    $session = GameSession::create(['player_id' => $player->id, 'life_id' => $life->id, 'connected_at' => now()]);

    expect($player->fresh()->openLife()->id)->toBe($life->id);
    expect($player->fresh()->openSession()->id)->toBe($session->id);

    $life->update(['ended_at' => now()]);
    expect($player->fresh()->openLife())->toBeNull();
});
