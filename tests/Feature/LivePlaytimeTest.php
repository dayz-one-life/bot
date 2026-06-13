<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Life\LivePlaytime;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

it('returns stored playtime for a life with no open session', function () {
    $p = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => now()->subHour(), 'playtime_seconds' => 1800]);

    expect(LivePlaytime::forLife($life))->toBe(1800);
});

it('adds the open session elapsed time to stored playtime', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');
    $p = Player::create(['gamertag' => 'Bob', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-13T14:00:00Z', 'playtime_seconds' => 600]);
    // Open session connected at 15:40 -> 20 minutes elapsed by 16:00 = 1200s
    GameSession::create(['player_id' => $p->id, 'life_id' => $life->id, 'connected_at' => '2026-06-13T15:40:00Z']);

    expect(LivePlaytime::forLife($life))->toBe(600 + 1200);
});
