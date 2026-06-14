<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Online\OnlineRosterQuery;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

function rosterPlayer(string $tag): Player
{
    return Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('lists online players with session and life seconds, longest session first', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');

    $a = rosterPlayer('Alice');
    $b = rosterPlayer('Bob');
    $c = rosterPlayer('Carol');

    // Alice: open life 600 stored + open session 15:30->16:00 (1800) => life 2400, session 1800
    $al = Life::create(['player_id' => $a->id, 'started_at' => '2026-06-13T10:00:00Z', 'playtime_seconds' => 600]);
    GameSession::create(['player_id' => $a->id, 'life_id' => $al->id, 'connected_at' => '2026-06-13T15:30:00Z']);

    // Bob: open life 0 stored + open session 14:00->16:00 (7200) => life 7200, session 7200
    $bl = Life::create(['player_id' => $b->id, 'started_at' => '2026-06-13T14:00:00Z', 'playtime_seconds' => 0]);
    GameSession::create(['player_id' => $b->id, 'life_id' => $bl->id, 'connected_at' => '2026-06-13T14:00:00Z']);

    // Carol: a CLOSED session -> not online -> excluded.
    $cl = Life::create(['player_id' => $c->id, 'started_at' => '2026-06-13T10:00:00Z', 'playtime_seconds' => 100]);
    GameSession::create([
        'player_id' => $c->id, 'life_id' => $cl->id,
        'connected_at' => '2026-06-13T10:00:00Z', 'disconnected_at' => '2026-06-13T11:00:00Z',
        'duration_seconds' => 3600,
    ]);

    $rows = (new OnlineRosterQuery())->rows();

    expect($rows)->toHaveCount(2);
    expect($rows[0]['gamertag'])->toBe('Bob');          // longest session first
    expect($rows[0]['session_seconds'])->toBe(7200);
    expect($rows[0]['life_seconds'])->toBe(7200);
    expect($rows[1]['gamertag'])->toBe('Alice');
    expect($rows[1]['session_seconds'])->toBe(1800);
    expect($rows[1]['life_seconds'])->toBe(2400);
});

it('returns an empty array when nobody is online', function () {
    expect((new OnlineRosterQuery())->rows())->toBe([]);
});
