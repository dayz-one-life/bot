<?php

use App\Models\Player;
use App\Models\PlayerPosition;
use App\Services\BountyTickService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    $this->now = CarbonImmutable::now();
    $p = Player::create(['gamertag' => 'P', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    // one recent (1 day old) and one ancient (100 days old) position
    PlayerPosition::create(['player_id' => $p->id, 'x' => 1, 'y' => 1, 'recorded_at' => $this->now->subDay()]);
    PlayerPosition::create(['player_id' => $p->id, 'x' => 2, 'y' => 2, 'recorded_at' => $this->now->subDays(100)]);
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('keeps everything when retention is 0', function () {
    config(['bounty.position_retention_days' => 0]);
    expect((new BountyTickService())->prunePositions($this->now))->toBe(0);
    expect(PlayerPosition::count())->toBe(2);
});

it('prunes positions older than the retention window', function () {
    config(['bounty.position_retention_days' => 30]);
    expect((new BountyTickService())->prunePositions($this->now))->toBe(1); // the 100-day-old one
    expect(PlayerPosition::count())->toBe(1);
});
