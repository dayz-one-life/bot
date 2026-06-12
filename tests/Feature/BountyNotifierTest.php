<?php

use App\Models\Bounty;
use App\Models\Life;
use App\Models\Player;
use App\Services\Bounty\BountyNotifier;
use App\Services\Bounty\NullBountyNotifier;

it('NullBountyNotifier satisfies the interface and does nothing', function () {
    $n = new NullBountyNotifier();
    expect($n)->toBeInstanceOf(BountyNotifier::class);
    $p = Player::create(['gamertag' => 'T', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => now()]);
    $b = Bounty::create(['player_id' => $p->id, 'life_id' => $life->id, 'placed_at' => now()]);
    $n->placed($b, $p);
    $n->moved($b, $p);
    $n->claimed($b, $p, $p, 1);
    $n->ended($b, $p, 'died');
    expect(true)->toBeTrue();
});
