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

it('breaks ties on the alive board by earliest started_at', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');

    // Two open lives with identical stored playtime and no open session -> equal seconds.
    $early = lbPlayer('Early');
    $late = lbPlayer('Late');
    Life::create(['player_id' => $late->id, 'started_at' => '2026-06-13T12:00:00Z', 'playtime_seconds' => 1000]);
    Life::create(['player_id' => $early->id, 'started_at' => '2026-06-13T08:00:00Z', 'playtime_seconds' => 1000]);

    $rows = $this->svc->aliveLongestLives(5);

    expect($rows[0]['gamertag'])->toBe('Early'); // earlier started_at wins the tie
    expect($rows[1]['gamertag'])->toBe('Late');
});

it('ranks all-time longest lives with one entry per player (best life)', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');

    $a = lbPlayer('Alice');
    $b = lbPlayer('Bob');

    // Alice has two lives: 1000 and 4000 -> her best (4000) should be the only Alice entry
    Life::create(['player_id' => $a->id, 'started_at' => '2026-06-10T00:00:00Z', 'ended_at' => '2026-06-10T01:00:00Z', 'playtime_seconds' => 1000]);
    Life::create(['player_id' => $a->id, 'started_at' => '2026-06-11T00:00:00Z', 'ended_at' => '2026-06-11T02:00:00Z', 'playtime_seconds' => 4000]);

    // Bob: one ended life of 3000
    Life::create(['player_id' => $b->id, 'started_at' => '2026-06-12T00:00:00Z', 'ended_at' => '2026-06-12T01:00:00Z', 'playtime_seconds' => 3000]);

    $rows = $this->svc->allTimeLongestLives(5);

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toMatchArray(['gamertag' => 'Alice', 'seconds' => 4000]);
    expect($rows[1])->toMatchArray(['gamertag' => 'Bob', 'seconds' => 3000]);
});

it('includes open lives (live playtime) on the all-time board', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');

    $a = lbPlayer('Alice');
    // Open life: 600 stored + open session 15:00->16:00 (3600) = 4200
    $life = Life::create(['player_id' => $a->id, 'started_at' => '2026-06-13T10:00:00Z', 'playtime_seconds' => 600]);
    GameSession::create(['player_id' => $a->id, 'life_id' => $life->id, 'connected_at' => '2026-06-13T15:00:00Z']);

    expect($this->svc->allTimeLongestLives(5)[0])->toMatchArray(['gamertag' => 'Alice', 'seconds' => 4200]);
});

/** Record a PvP kill: a victim life ended, killed by $killer. */
function lbKill(Player $victim, string $killer, ?float $distance = null, ?string $weapon = null): void
{
    Life::create([
        'player_id' => $victim->id,
        'started_at' => now()->subHour(),
        'ended_at' => now(),
        'death_cause' => 'pvp',
        'death_by_gamertag' => $killer,
        'death_weapon' => $weapon,
        'death_distance' => $distance,
    ]);
}

it('counts PvP kills per killer gamertag, most first', function () {
    $alice = lbPlayer('Alice');
    $bob = lbPlayer('Bob');
    $carol = lbPlayer('Carol');

    // Bob kills 3, Alice kills 1
    lbKill($carol, 'Bob');
    lbKill($alice, 'Bob');
    $extra = lbPlayer('Dave');
    lbKill($extra, 'Bob');
    lbKill($bob, 'Alice');

    $rows = $this->svc->mostKills(5);

    expect($rows[0])->toMatchArray(['gamertag' => 'Bob', 'kills' => 3]);
    expect($rows[1])->toMatchArray(['gamertag' => 'Alice', 'kills' => 1]);
});

it('excludes suicides, environment deaths, and self-kills from kill counts', function () {
    $alice = lbPlayer('Alice');

    // Suicide (cause != pvp) — excluded
    Life::create(['player_id' => $alice->id, 'started_at' => now()->subHour(), 'ended_at' => now(), 'death_cause' => 'suicide', 'death_by_gamertag' => null]);
    // Self-attributed pvp (killer == victim) — excluded
    Life::create(['player_id' => $alice->id, 'started_at' => now()->subHour(), 'ended_at' => now(), 'death_cause' => 'pvp', 'death_by_gamertag' => 'Alice']);

    expect($this->svc->mostKills(5))->toBe([]);
});

it('ranks single kills by distance, longest first, with killer/victim/weapon', function () {
    $bob = lbPlayer('Bob');
    $carol = lbPlayer('Carol');
    $dave = lbPlayer('Dave');

    lbKill($carol, 'Bob', distance: 412.7, weapon: 'M24');
    lbKill($dave, 'Bob', distance: 88.0, weapon: 'AKM');
    // pvp kill with null distance — excluded
    lbKill($bob, 'Carol', distance: null, weapon: 'Knife');

    $rows = $this->svc->longestKills(5);

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toMatchArray(['killer' => 'Bob', 'victim' => 'Carol', 'weapon' => 'M24', 'distance' => 412.7]);
    expect($rows[1])->toMatchArray(['killer' => 'Bob', 'victim' => 'Dave', 'weapon' => 'AKM', 'distance' => 88.0]);
});

it('computes the longest kill streak within a single life, per player', function () {
    CarbonImmutable::setTestNow('2026-06-13T20:00:00Z');

    $hunter = lbPlayer('Hunter');

    // Hunter life #1: 10:00 -> 12:00 (2 kills inside)
    Life::create(['player_id' => $hunter->id, 'started_at' => '2026-06-13T10:00:00Z', 'ended_at' => '2026-06-13T12:00:00Z', 'playtime_seconds' => 7200]);
    // Hunter life #2: 14:00 -> open (3 kills inside) -> streak 3
    Life::create(['player_id' => $hunter->id, 'started_at' => '2026-06-13T14:00:00Z', 'playtime_seconds' => 1000]);

    $mkV = function (string $tag, string $endedAt) {
        $v = lbPlayer($tag);
        Life::create(['player_id' => $v->id, 'started_at' => '2026-06-13T09:00:00Z', 'ended_at' => $endedAt, 'death_cause' => 'pvp', 'death_by_gamertag' => 'Hunter']);
    };
    $mkV('V1', '2026-06-13T10:30:00Z');
    $mkV('V2', '2026-06-13T11:45:00Z');
    $mkV('V3', '2026-06-13T15:00:00Z');
    $mkV('V4', '2026-06-13T16:00:00Z');
    $mkV('V5', '2026-06-13T19:00:00Z');

    expect($this->svc->longestKillStreaks(5)[0])->toMatchArray(['gamertag' => 'Hunter', 'streak' => 3]);
});

it('omits players with no kills from the streak board', function () {
    CarbonImmutable::setTestNow('2026-06-13T20:00:00Z');
    $p = lbPlayer('Quiet');
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-13T10:00:00Z', 'playtime_seconds' => 100]);

    expect($this->svc->longestKillStreaks(5))->toBe([]);
});
