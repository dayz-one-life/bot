<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Online\NullOnlineRosterNotifier;
use App\Services\OnlinePlayersService;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

it('composes the roster payload from open sessions and publishes it', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');

    $p = Player::create(['gamertag' => 'Zed', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-13T15:00:00Z', 'playtime_seconds' => 0]);
    GameSession::create(['player_id' => $p->id, 'life_id' => $life->id, 'connected_at' => '2026-06-13T15:00:00Z']);

    $notifier = new NullOnlineRosterNotifier();
    (new OnlinePlayersService())->compose($notifier);

    expect($notifier->lastPayload['title'])->toBe('🟢 Online — 1');
    expect($notifier->lastPayload['description'])->toContain('`Zed` · on 1h 0m · alive 1h 0m');
});
