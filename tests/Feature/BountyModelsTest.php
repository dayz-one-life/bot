<?php

use App\Models\Bounty;
use App\Models\Life;
use App\Models\Player;
use App\Models\PlayerPosition;

it('creates a position bound to a player', function () {
    $p = Player::create(['gamertag' => 'Tag', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $pos = PlayerPosition::create(['player_id' => $p->id, 'x' => 1.5, 'y' => 2.5, 'recorded_at' => now()]);
    expect($pos->player->id)->toBe($p->id);
    expect((float) $pos->x)->toBe(1.5);
});

it('exposes the single active bounty via active()', function () {
    $p = Player::create(['gamertag' => 'Tag2', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => now()]);
    expect(Bounty::active())->toBeNull();
    $b = Bounty::create(['player_id' => $p->id, 'life_id' => $life->id, 'placed_at' => now()]);
    expect(Bounty::active()->id)->toBe($b->id);
    $b->update(['ended_at' => now(), 'end_reason' => 'died']);
    expect(Bounty::active())->toBeNull();
});
