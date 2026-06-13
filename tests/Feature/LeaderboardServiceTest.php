<?php

use App\Models\Life;
use App\Models\Player;
use App\Services\Leaderboard\NullLeaderboardNotifier;
use App\Services\LeaderboardService;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

it('composes all five boards into the notifier payload', function () {
    $p = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => now()->subHours(2), 'playtime_seconds' => 4000]); // open

    $notifier = new NullLeaderboardNotifier();
    (new LeaderboardService())->compose($notifier);

    expect($notifier->lastPayload['fields'])->toHaveCount(5);
    expect($notifier->lastPayload['title'])->toContain('Leaderboard');
    // Alice's open life shows on the alive board (field 0)
    expect($notifier->lastPayload['fields'][0]['value'])->toContain('Alice');
});
