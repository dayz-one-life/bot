<?php

use App\Models\Bounty;
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

it('places a bounty on the leader when none is active', function () {
    $life = activeLife('Leader', 10 * 3600);
    $this->svc->run($this->now);
    $b = Bounty::active();
    expect($b)->not->toBeNull();
    expect($b->life_id)->toBe($life->id);
});

it('does nothing before go_live', function () {
    $this->state->delete('go_live_at');
    activeLife('Leader', 10 * 3600);
    $this->svc->run($this->now);
    expect(Bounty::count())->toBe(0);
});

it('moves the bounty when a challenger leads by more than the margin', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    activeLife('Challenger', 12 * 3600); // +2h > 5min margin
    $this->svc->run($this->now);
    expect(Bounty::active()->player->gamertag)->toBe('Challenger');
    expect(Bounty::where('end_reason', 'moved')->count())->toBe(1);
});

it('does not move for a sub-margin lead', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    activeLife('Barely', 10 * 3600 + 60); // +1min < 5min margin
    $this->svc->run($this->now);
    expect(Bounty::active()->life_id)->toBe($held->id);
});

it('drops a stale holder as inactive and moves on', function () {
    $held = activeLife('Holder', 10 * 3600, lastSeenHoursAgo: 72); // now stale
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subDay()]);
    $fresh = activeLife('Fresh', 4 * 3600);
    $this->svc->run($this->now);
    expect(Bounty::where('end_reason', 'inactive')->count())->toBe(1);
    expect(Bounty::active()->life_id)->toBe($fresh->id);
});

/** End the bounty holder's life as a PvP kill by $killerTag. */
function killHolder(Life $life, string $killerTag): void {
    $life->update(['ended_at' => CarbonImmutable::now(), 'death_cause' => 'pvp', 'death_by_gamertag' => $killerTag]);
}

it('awards a token when a non-associate kills the bounty', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    $killer = Player::create(['gamertag' => 'Hunter', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    killHolder($held, 'Hunter');

    $this->svc->run($this->now);

    expect($killer->fresh()->unban_tokens)->toBe(1);
    $b = Bounty::where('end_reason', 'claimed')->first();
    expect($b)->not->toBeNull();
    expect($b->token_awarded)->toBeTrue();
    expect($b->claimed_by_player_id)->toBe($killer->id);
});

it('awards no token when an associate kills the bounty', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    $killer = Player::create(['gamertag' => 'Buddy', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    // force associates
    [$lo, $hi] = $held->player_id < $killer->id ? [$held->player_id, $killer->id] : [$killer->id, $held->player_id];
    App\Models\AssociateOverride::create(['player_a_id' => $lo, 'player_b_id' => $hi, 'force' => true]);
    killHolder($held, 'Buddy');

    $this->svc->run($this->now);

    expect($killer->fresh()->unban_tokens)->toBe(0);
    expect(Bounty::where('end_reason', 'claimed_by_associate')->count())->toBe(1);
});

it('awards no token for a non-pvp bounty death', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    $held->update(['ended_at' => CarbonImmutable::now(), 'death_cause' => 'bled_out', 'death_by_gamertag' => null]);

    $this->svc->run($this->now);

    expect(Bounty::where('end_reason', 'died')->count())->toBe(1);
});

it('is idempotent — a resolved bounty is not paid twice', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    $killer = Player::create(['gamertag' => 'Hunter', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    killHolder($held, 'Hunter');

    $this->svc->run($this->now);
    $this->svc->run($this->now); // second tick

    expect($killer->fresh()->unban_tokens)->toBe(1);
});

it('reports no active bounty', function () {
    expect($this->svc->status($this->now))->toBe(['active' => false]);
});

it('reports the active bounty with runner-up gap', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()]);
    activeLife('Runner', 7 * 3600);
    $s = $this->svc->status($this->now);
    expect($s['active'])->toBeTrue();
    expect($s['gamertag'])->toBe('Holder');
    expect($s['playtime_seconds'])->toBe(10 * 3600);
    expect($s['runner_up_gap_seconds'])->toBe(3 * 3600);
});

it('ignores sub-floor players when computing the runner-up gap', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()]);
    activeLife('SubFloor', 1800); // 30 min < 2h floor — must NOT count as runner-up
    $s = $this->svc->status($this->now);
    expect($s['runner_up_gap_seconds'])->toBeNull();
});
