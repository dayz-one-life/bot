<?php

use App\Models\Life;
use App\Models\Player;
use App\Services\Leaderboard\NullLeaderboardNotifier;
use App\Services\LeaderboardService;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

it('composes all seven boards into the notifier payloads', function () {
    $p = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => now()->subHours(2), 'playtime_seconds' => 4000]); // open

    $notifier = new NullLeaderboardNotifier();
    (new LeaderboardService())->compose($notifier);

    expect($notifier->lastPayloads)->toHaveCount(7);
    // Board 0 = alive; its title is fixed and its rows include Alice's open life.
    expect($notifier->lastPayloads[0]['title'])->toContain('Longest Life');
    expect($notifier->lastPayloads[0]['description'])->toContain('Alice');
});
