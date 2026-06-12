<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Bounty\AssociateDetector;
use App\Services\Bounty\BountyService;
use App\Services\Bounty\NullBountyNotifier;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    $this->now = CarbonImmutable::now();
    $this->state = new BotState();
    $this->state->set('go_live_at', '2026-06-10T00:00:00+00:00');
    $this->svc = new BountyService(new AssociateDetector(), $this->state, new NullBountyNotifier(), 1);
});
afterEach(fn () => CarbonImmutable::setTestNow());

/** Player active `lastSeenHoursAgo` ago, with an open life of `committed` playtime seconds. */
function activeLife(string $tag, int $committed, int $lastSeenHoursAgo = 0): Life {
    $p = Player::create([
        'gamertag' => $tag, 'first_seen_at' => now()->subDays(5),
        'last_seen_at' => CarbonImmutable::now()->subHours($lastSeenHoursAgo),
    ]);
    return Life::create(['player_id' => $p->id, 'started_at' => now()->subDays(2),
        'ended_at' => null, 'playtime_seconds' => $committed]);
}

it('adds open-session elapsed to committed playtime', function () {
    $life = activeLife('A', 3600);
    GameSession::create(['player_id' => $life->player_id, 'life_id' => $life->id,
        'connected_at' => CarbonImmutable::now()->subMinutes(30), 'disconnected_at' => null]);
    expect($this->svc->livePlaytime($life, $this->now))->toBe(3600 + 1800);
});

it('picks the highest live-playtime recently-active eligible life', function () {
    activeLife('Low', 3 * 3600);
    $high = activeLife('High', 10 * 3600);
    expect($this->svc->currentLeader($this->now)->id)->toBe($high->id);
});

it('excludes players below the playtime floor', function () {
    activeLife('TooNew', 1800); // 30 min < 2h floor
    expect($this->svc->currentLeader($this->now))->toBeNull();
});

it('excludes players who are not recently active', function () {
    activeLife('Stale', 10 * 3600, lastSeenHoursAgo: 72); // > 48h window
    expect($this->svc->currentLeader($this->now))->toBeNull();
});
