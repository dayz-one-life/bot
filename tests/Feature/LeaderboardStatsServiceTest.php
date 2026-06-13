<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Leaderboard\LeaderboardStatsService;
use Carbon\CarbonImmutable;

beforeEach(fn () => $this->svc = new LeaderboardStatsService());
afterEach(fn () => CarbonImmutable::setTestNow());

/** Helper: create a player with a single life and optional kills against others. */
function lbPlayer(string $tag, ?string $discord = null): Player
{
    return Player::create(['gamertag' => $tag, 'discord_user_id' => $discord, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('ranks alive players by live playtime, longest first', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');

    $a = lbPlayer('Alice');
    $b = lbPlayer('Bob');
    $c = lbPlayer('Carol');

    // Alice: open life, 600 stored + open session 15:00->16:00 (3600) = 4200
    $al = Life::create(['player_id' => $a->id, 'started_at' => '2026-06-13T10:00:00Z', 'playtime_seconds' => 600]);
    GameSession::create(['player_id' => $a->id, 'life_id' => $al->id, 'connected_at' => '2026-06-13T15:00:00Z']);

    // Bob: open life, 5000 stored, no open session = 5000
    Life::create(['player_id' => $b->id, 'started_at' => '2026-06-13T09:00:00Z', 'playtime_seconds' => 5000]);

    // Carol: ENDED life — must be excluded from the alive board
    Life::create(['player_id' => $c->id, 'started_at' => '2026-06-13T08:00:00Z', 'ended_at' => '2026-06-13T09:00:00Z', 'playtime_seconds' => 9999]);

    $rows = $this->svc->aliveLongestLives(5);

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toMatchArray(['gamertag' => 'Bob', 'seconds' => 5000]);
    expect($rows[1])->toMatchArray(['gamertag' => 'Alice', 'seconds' => 4200]);
});

it('honours the limit on the alive board', function () {
    foreach (['P1' => 100, 'P2' => 200, 'P3' => 300] as $tag => $secs) {
        $p = lbPlayer($tag);
        Life::create(['player_id' => $p->id, 'started_at' => now()->subHour(), 'playtime_seconds' => $secs]);
    }

    expect($this->svc->aliveLongestLives(2))->toHaveCount(2);
});
