<?php

// All imports for the WHOLE AssociateDetectorTest file are declared here once.
// Later tasks append test bodies + helpers but must NOT re-declare these `use`s.
use App\Models\AssociateOverride;
use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Models\PlayerPosition;
use App\Services\Bounty\AssociateDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function makePlayer(string $tag): Player {
    return Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

/**
 * A closed session [from, to] for a player. A real Life is created (and reused per
 * player) so the game_sessions.life_id foreign key is satisfied. Detection never
 * joins through lives, so one shared dummy life per player is fine.
 *
 * Named gameSession() rather than session() to avoid colliding with Laravel Zero's
 * global session() helper defined in foundation/helpers.php.
 */
function gameSession(Player $p, string $from, string $to): void {
    $life = Life::firstOrCreate(['player_id' => $p->id], ['started_at' => $from]);
    GameSession::create([
        'player_id' => $p->id, 'life_id' => $life->id,
        'connected_at' => $from, 'disconnected_at' => $to,
    ]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    $this->now = CarbonImmutable::now();
    $this->detector = new AssociateDetector();
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('scores full online overlap as 1.0', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    gameSession($a, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    gameSession($b, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    // copresence = (overlap=1.0 + sync=1.0) / 2 = 1.0 (identical sessions sync perfectly)
    expect($this->detector->copresenceScore($a, $b, $this->now))->toBe(1.0);
});

it('scores disjoint sessions as 0.0', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    gameSession($a, '2026-06-12T00:00:00Z', '2026-06-12T01:00:00Z');
    gameSession($b, '2026-06-12T08:00:00Z', '2026-06-12T09:00:00Z');
    expect($this->detector->copresenceScore($a, $b, $this->now))->toBe(0.0);
});

function playerPos(Player $p, string $at, float $x, float $y): void {
    PlayerPosition::create(['player_id' => $p->id, 'x' => $x, 'y' => $y, 'recorded_at' => $at]);
}

it('scores co-located players near 1.0', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    // three different 5-min bins, always within 150m
    playerPos($a, '2026-06-12T09:00:00Z', 1000, 1000); playerPos($b, '2026-06-12T09:00:30Z', 1050, 1000);
    playerPos($a, '2026-06-12T09:06:00Z', 2000, 2000); playerPos($b, '2026-06-12T09:06:30Z', 2010, 2000);
    playerPos($a, '2026-06-12T09:12:00Z', 3000, 3000); playerPos($b, '2026-06-12T09:12:30Z', 3000, 3030);
    expect($this->detector->proximityScore($a, $b, $this->now))->toBe(1.0);
});

it('scores far-apart players as 0.0', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    playerPos($a, '2026-06-12T09:00:00Z', 0, 0); playerPos($b, '2026-06-12T09:00:30Z', 9000, 9000);
    expect($this->detector->proximityScore($a, $b, $this->now))->toBe(0.0);
});

it('scores proximity 0.0 when bins never overlap', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    playerPos($a, '2026-06-12T09:00:00Z', 0, 0); playerPos($b, '2026-06-12T10:00:00Z', 0, 0);
    expect($this->detector->proximityScore($a, $b, $this->now))->toBe(0.0);
});

it('returns 0.0 kill-graph when the pair killed each other', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    // A killed B
    Life::create(['player_id' => $b->id, 'started_at' => '2026-06-12T08:00:00Z',
        'ended_at' => '2026-06-12T09:00:00Z', 'death_cause' => 'pvp', 'death_by_gamertag' => 'A']);
    expect($this->detector->killGraphModifier($a, $b, $this->now))->toBe(0.0);
});

it('rewards shared victims in the kill-graph', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    $v = makePlayer('Victim');
    // both A and B have killed Victim (two separate lives)
    Life::create(['player_id' => $v->id, 'started_at' => '2026-06-12T07:00:00Z',
        'ended_at' => '2026-06-12T07:30:00Z', 'death_cause' => 'pvp', 'death_by_gamertag' => 'A']);
    Life::create(['player_id' => $v->id, 'started_at' => '2026-06-12T08:00:00Z',
        'ended_at' => '2026-06-12T08:30:00Z', 'death_cause' => 'pvp', 'death_by_gamertag' => 'B']);
    expect($this->detector->killGraphModifier($a, $b, $this->now))->toBeGreaterThan(0.0);
});

it('blends sub-scores with configured weights', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    // full overlap+sync => copresence 1.0; no positions => prox 0; no kills => killg 0
    gameSession($a, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    gameSession($b, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    config(['bounty.weight_prox' => 0.5, 'bounty.weight_copres' => 0.5, 'bounty.weight_killg' => 0.0]);
    expect($this->detector->score($a, $b, $this->now))->toBe(0.5); // 0.5*0 + 0.5*1 + 0
});

it('treats a score at/above threshold as associates', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    gameSession($a, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    gameSession($b, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    config(['bounty.weight_prox' => 0.0, 'bounty.weight_copres' => 1.0, 'bounty.weight_killg' => 0.0,
            'bounty.assoc_threshold' => 0.5]);
    expect($this->detector->areAssociates($a, $b, $this->now))->toBeTrue();
});

it('makes the associate decision order-independent (symmetric)', function () {
    $a = makePlayer('Heavy'); $b = makePlayer('Light');
    gameSession($a, '2026-06-12T00:00:00Z', '2026-06-12T06:00:00Z');
    gameSession($b, '2026-06-12T00:00:00Z', '2026-06-12T02:00:00Z');
    config(['bounty.weight_prox' => 0.0, 'bounty.weight_copres' => 1.0, 'bounty.weight_killg' => 0.0,
            'bounty.assoc_threshold' => 0.5]);
    expect($this->detector->areAssociates($a, $b, $this->now))
        ->toBe($this->detector->areAssociates($b, $a, $this->now));
});

it('force-true override makes a pair associates regardless of score', function () {
    $a = makePlayer('A'); $b = makePlayer('B'); // no shared data => score 0
    [$lo, $hi] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    AssociateOverride::create(['player_a_id' => $lo, 'player_b_id' => $hi, 'force' => true]);
    expect($this->detector->areAssociates($a, $b, $this->now))->toBeTrue();
});

it('force-false override denies a pair even with a high score', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    gameSession($a, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    gameSession($b, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    [$lo, $hi] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    AssociateOverride::create(['player_a_id' => $lo, 'player_b_id' => $hi, 'force' => false]);
    config(['bounty.weight_copres' => 1.0, 'bounty.assoc_threshold' => 0.1]);
    expect($this->detector->areAssociates($a, $b, $this->now))->toBeFalse();
});

it('does not re-query the same player data across the associatesOf scan', function () {
    // Regression for the `/team show` "application did not respond" timeout: associatesOf
    // scanned every player and, for each pair, recomputed A's positions/sessions/lives
    // from scratch in BOTH score directions — ~19 queries per candidate. On production
    // (138 players) that was ~2600 queries / ~6.6s, well past Discord's 3s ACK deadline.
    // Per-player DB fetches must be memoised within a single scan: well under 10 queries
    // per candidate.
    $a = makePlayer('Subject');
    gameSession($a, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    playerPos($a, '2026-06-12T09:00:00Z', 1000, 1000);

    $others = 25;
    for ($i = 0; $i < $others; $i++) {
        $p = makePlayer("Other{$i}");
        gameSession($p, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
        playerPos($p, '2026-06-12T09:00:30Z', 1050, 1000);
    }

    DB::enableQueryLog();
    $this->detector->associatesOf($a, $this->now);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Pre-fix this was ~19/candidate; memoised it is a small constant per candidate.
    expect($count)->toBeLessThan(10 * ($others + 1));
});

it('associatesOf returns every player clearing the bar', function () {
    $a = makePlayer('A'); $mate = makePlayer('Mate'); $stranger = makePlayer('Stranger');
    gameSession($a, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    gameSession($mate, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    gameSession($stranger, '2026-06-12T20:00:00Z', '2026-06-12T21:00:00Z');
    config(['bounty.weight_prox' => 0.0, 'bounty.weight_copres' => 1.0, 'bounty.weight_killg' => 0.0,
            'bounty.assoc_threshold' => 0.5]);
    $result = $this->detector->associatesOf($a, $this->now)->pluck('gamertag')->all();
    expect($result)->toContain('Mate');
    expect($result)->not->toContain('Stranger');
});
