<?php

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use App\Services\Leaderboard\LeaderboardStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedVisit(string $tag, string $lifeStart, string $visitedAt): void
{
    $player = Player::firstOrCreate(['gamertag' => $tag]);
    $life = Life::create(['player_id' => $player->id, 'started_at' => CarbonImmutable::parse($lifeStart)]);
    BunkerVisit::create([
        'player_id' => $player->id,
        'life_id' => $life->id,
        'visited_at' => CarbonImmutable::parse($visitedAt),
    ]);
}

it('ranks most bunker visits desc, tie-break earliest first visit', function () {
    seedVisit('Alice', '2026-06-14 00:00:00', '2026-06-14 00:10:00');
    seedVisit('Alice', '2026-06-14 01:00:00', '2026-06-14 01:10:00');
    seedVisit('Bob', '2026-06-13 00:00:00', '2026-06-13 00:10:00'); // 1 visit, earliest

    $rows = (new LeaderboardStatsService())->mostBunkerVisits(5);

    expect($rows)->toBe([
        ['gamertag' => 'Alice', 'bunker_visits' => 2],
        ['gamertag' => 'Bob', 'bunker_visits' => 1],
    ]);
});

it('ranks quickest new-life-to-bunker ascending, one row per player (best life)', function () {
    // Alice: slow life (10min) then fast life (2min) -> best = 120s
    seedVisit('Alice', '2026-06-14 00:00:00', '2026-06-14 00:10:00');
    seedVisit('Alice', '2026-06-14 01:00:00', '2026-06-14 01:02:00');
    // Bob: 5min
    seedVisit('Bob', '2026-06-14 02:00:00', '2026-06-14 02:05:00');

    $rows = (new LeaderboardStatsService())->quickestNewLifeToBunker(5);

    expect($rows)->toBe([
        ['gamertag' => 'Alice', 'seconds' => 120],
        ['gamertag' => 'Bob', 'seconds' => 300],
    ]);
});

it('excludes visits with no life from the quickest board but counts them in totals', function () {
    Player::create(['gamertag' => 'Ghost']);
    BunkerVisit::create([
        'player_id' => Player::where('gamertag', 'Ghost')->value('id'),
        'life_id' => null,
        'visited_at' => CarbonImmutable::parse('2026-06-14 00:10:00'),
    ]);

    expect((new LeaderboardStatsService())->quickestNewLifeToBunker(5))->toBe([])
        ->and((new LeaderboardStatsService())->mostBunkerVisits(5))
        ->toBe([['gamertag' => 'Ghost', 'bunker_visits' => 1]]);
});

it('breaks most-visits ties by earliest first visit', function () {
    // Both players have 2 visits; Bob's earliest visit (00:05) precedes Alice's (00:10).
    seedVisit('Bob', '2026-06-14 00:00:00', '2026-06-14 00:05:00');
    seedVisit('Bob', '2026-06-14 01:00:00', '2026-06-14 01:05:00');
    seedVisit('Alice', '2026-06-14 00:00:00', '2026-06-14 00:10:00');
    seedVisit('Alice', '2026-06-14 01:00:00', '2026-06-14 01:10:00');

    $rows = (new App\Services\Leaderboard\LeaderboardStatsService())->mostBunkerVisits(5);

    expect($rows)->toBe([
        ['gamertag' => 'Bob', 'bunker_visits' => 2],
        ['gamertag' => 'Alice', 'bunker_visits' => 2],
    ]);
});

it('breaks quickest-to-bunker ties by earliest life start', function () {
    // Both reach the bunker in 120s; Bob's life started earlier, so Bob ranks first.
    seedVisit('Alice', '2026-06-14 01:00:00', '2026-06-14 01:02:00'); // 120s
    seedVisit('Bob', '2026-06-14 00:00:00', '2026-06-14 00:02:00');   // 120s

    $rows = (new App\Services\Leaderboard\LeaderboardStatsService())->quickestNewLifeToBunker(5);

    expect($rows)->toBe([
        ['gamertag' => 'Bob', 'seconds' => 120],
        ['gamertag' => 'Alice', 'seconds' => 120],
    ]);
});
