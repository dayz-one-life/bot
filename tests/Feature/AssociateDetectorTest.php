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
