<?php

namespace App\Services\Leaderboard;

use App\Models\Life;
use App\Models\Player;
use App\Services\Life\LivePlaytime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queries powering the five leaderboard boards. All computed from the
 * existing lives / game_sessions / players tables (no kills table). Heavily
 * Feature-tested; the periodic Service and Discord notifier are thin wrappers.
 */
class LeaderboardStatsService
{
    /**
     * Open lives ranked by live playtime (stored + open-session elapsed), desc.
     * Tie-break: earliest started_at.
     *
     * @return array<int, array{gamertag:string, seconds:int}>
     */
    public function aliveLongestLives(int $limit): array
    {
        $rows = Life::query()
            ->whereNull('ended_at')
            ->with('player:id,gamertag')
            ->get()
            ->map(fn (Life $life) => [
                'gamertag' => $life->player->gamertag,
                'seconds' => LivePlaytime::forLife($life),
                'started_at' => $life->started_at->getTimestamp(),
            ])
            ->all();

        return $this->rankBySeconds($rows, $limit);
    }

    /**
     * All lives ranked by playtime, deduped to the best life per player, desc.
     * Open lives use live playtime; ended lives use the stored value (no extra
     * query). Tie-break: earliest started_at.
     *
     * @return array<int, array{gamertag:string, seconds:int}>
     */
    public function allTimeLongestLives(int $limit): array
    {
        $best = []; // gamertag => ['gamertag','seconds','started_at']

        Life::query()->with('player:id,gamertag')->get()->each(function (Life $life) use (&$best) {
            $tag = $life->player->gamertag;
            $seconds = $life->ended_at === null
                ? LivePlaytime::forLife($life)
                : (int) $life->playtime_seconds;

            if (! isset($best[$tag]) || $seconds > $best[$tag]['seconds']) {
                $best[$tag] = [
                    'gamertag' => $tag,
                    'seconds' => $seconds,
                    'started_at' => $life->started_at->getTimestamp(),
                ];
            }
        });

        return $this->rankBySeconds(array_values($best), $limit);
    }

    /**
     * Count of PvP kills credited to each killer gamertag, desc.
     * Excludes suicides/environment (cause != pvp), null killers, and self-kills
     * (killer == victim gamertag). Tie-break: earliest kill (min ended_at).
     *
     * @return array<int, array{gamertag:string, kills:int}>
     */
    public function mostKills(int $limit): array
    {
        return DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->where('lives.death_cause', 'pvp')
            ->whereNotNull('lives.death_by_gamertag')
            ->whereColumn('lives.death_by_gamertag', '!=', 'players.gamertag')
            ->groupBy('lives.death_by_gamertag')
            ->orderByDesc('kills')
            ->orderByRaw('MIN(lives.ended_at) ASC')
            ->limit($limit)
            ->get([
                'lives.death_by_gamertag as gamertag',
                DB::raw('COUNT(*) as kills'),
            ])
            ->map(fn ($r) => ['gamertag' => $r->gamertag, 'kills' => (int) $r->kills])
            ->all();
    }

    /**
     * Top single PvP kills by death_distance, desc. NOT deduped (a board of
     * individual shots). Tie-break: earliest kill (ended_at asc).
     *
     * @return array<int, array{killer:string, victim:string, weapon:?string, distance:float}>
     */
    public function longestKills(int $limit): array
    {
        return DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->where('lives.death_cause', 'pvp')
            ->whereNotNull('lives.death_by_gamertag')
            ->whereNotNull('lives.death_distance')
            ->whereColumn('lives.death_by_gamertag', '!=', 'players.gamertag')
            ->orderByDesc('lives.death_distance')
            ->orderBy('lives.ended_at')
            ->limit($limit)
            ->get([
                'lives.death_by_gamertag as killer',
                'players.gamertag as victim',
                'lives.death_weapon as weapon',
                'lives.death_distance as distance',
            ])
            ->map(fn ($r) => [
                'killer' => $r->killer,
                'victim' => $r->victim,
                'weapon' => $r->weapon,
                'distance' => (float) $r->distance,
            ])
            ->all();
    }

    /**
     * Longest run of kills inside a single life, one entry per killer (their best
     * life). A kill counts toward the killer's life whose window
     * [started_at, ended_at ?? now) contains the victim's ended_at.
     * Tie-break: earliest life start.
     *
     * @return array<int, array{gamertag:string, streak:int}>
     */
    public function longestKillStreaks(int $limit): array
    {
        $now = CarbonImmutable::now()->getTimestamp();

        // All kills as (killer => list of kill unix-timestamps).
        $killsByKiller = [];
        DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->where('lives.death_cause', 'pvp')
            ->whereNotNull('lives.death_by_gamertag')
            ->whereNotNull('lives.ended_at')
            ->whereColumn('lives.death_by_gamertag', '!=', 'players.gamertag')
            ->get(['lives.death_by_gamertag as killer', 'lives.ended_at as ts'])
            ->each(function ($row) use (&$killsByKiller) {
                $killsByKiller[$row->killer][] = CarbonImmutable::parse($row->ts)->getTimestamp();
            });

        $rows = [];
        foreach ($killsByKiller as $killer => $timestamps) {
            $player = Player::where('gamertag', $killer)->first();
            if (! $player) {
                continue; // killer never tracked as a player -> no life windows
            }

            $best = 0;
            $bestStart = null;
            foreach ($player->lives as $life) {
                $start = $life->started_at->getTimestamp();
                $end = $life->ended_at?->getTimestamp() ?? $now;

                $count = 0;
                foreach ($timestamps as $ts) {
                    if ($ts >= $start && $ts < $end) {
                        $count++;
                    }
                }

                if ($count > $best || ($count === $best && $bestStart !== null && $start < $bestStart)) {
                    $best = $count;
                    $bestStart = $start;
                }
            }

            if ($best > 0) {
                $rows[] = ['gamertag' => $killer, 'streak' => $best, 'started_at' => $bestStart];
            }
        }

        usort($rows, fn ($a, $b) => $b['streak'] <=> $a['streak'] ?: $a['started_at'] <=> $b['started_at']);

        return array_map(
            fn ($r) => ['gamertag' => $r['gamertag'], 'streak' => $r['streak']],
            array_slice($rows, 0, $limit)
        );
    }

    /**
     * Total counted bunker visits per player, desc. Tie-break: earliest first visit.
     *
     * @return array<int, array{gamertag:string, bunker_visits:int}>
     */
    public function mostBunkerVisits(int $limit): array
    {
        return DB::table('bunker_visits')
            ->join('players', 'players.id', '=', 'bunker_visits.player_id')
            ->groupBy('bunker_visits.player_id', 'players.gamertag')
            ->orderByDesc('visits')
            ->orderByRaw('MIN(bunker_visits.visited_at) ASC')
            ->limit($limit)
            ->get([
                'players.gamertag as gamertag',
                DB::raw('COUNT(*) as visits'),
            ])
            ->map(fn ($r) => ['gamertag' => $r->gamertag, 'bunker_visits' => (int) $r->visits])
            ->all();
    }

    /**
     * Each player's fastest new-life-to-bunker time, ascending. One row per player
     * (their best life). The time is PLAYTIME accrued from life start to the first
     * bunker visit — i.e. summed connected session time, with offline gaps excluded —
     * consistent with how life duration is measured everywhere else (LivePlaytime).
     * (Wall-clock `visited_at - started_at` would count hours a player was logged off
     * and can exceed the life's own playtime.) Visits with a null life_id, and lives
     * with no recorded session time before the visit (playtime 0, e.g. a backfilled
     * visit whose sessions weren't reconstructed), are excluded. Tie-break: earliest
     * life start.
     *
     * @return array<int, array{gamertag:string, seconds:int}>
     */
    public function quickestNewLifeToBunker(int $limit): array
    {
        // First bunker visit per life (null-life visits excluded).
        $firstVisits = DB::table('bunker_visits')
            ->whereNotNull('life_id')
            ->groupBy('life_id')
            ->get(['life_id', DB::raw('MIN(visited_at) as first_visit')]);

        if ($firstVisits->isEmpty()) {
            return [];
        }

        $visitTsByLife = [];
        foreach ($firstVisits as $r) {
            $visitTsByLife[$r->life_id] = CarbonImmutable::parse($r->first_visit)->getTimestamp();
        }
        $lifeIds = array_keys($visitTsByLife);

        $lives = DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->whereIn('lives.id', $lifeIds)
            ->get(['lives.id as id', 'players.gamertag as gamertag', 'lives.started_at as started_at']);

        $sessionsByLife = DB::table('game_sessions')
            ->whereIn('life_id', $lifeIds)
            ->get(['life_id', 'connected_at', 'disconnected_at'])
            ->groupBy('life_id');

        $best = []; // gamertag => ['gamertag','seconds','started_at']
        foreach ($lives as $life) {
            $visitTs = $visitTsByLife[$life->id];

            // Sum each session's connected time clamped to the visit moment; sessions
            // that begin at/after the visit (incl. the bunker spawn-in session) add 0.
            $seconds = 0;
            foreach (($sessionsByLife[$life->id] ?? []) as $s) {
                $start = CarbonImmutable::parse($s->connected_at)->getTimestamp();
                if ($start >= $visitTs) {
                    continue;
                }
                $end = $s->disconnected_at !== null
                    ? CarbonImmutable::parse($s->disconnected_at)->getTimestamp()
                    : $visitTs;
                $end = min($end, $visitTs);
                if ($end > $start) {
                    $seconds += $end - $start;
                }
            }

            if ($seconds <= 0) {
                continue; // no measurable playtime before the visit
            }

            $startTs = CarbonImmutable::parse($life->started_at)->getTimestamp();
            if (! isset($best[$life->gamertag]) || $seconds < $best[$life->gamertag]['seconds']) {
                $best[$life->gamertag] = ['gamertag' => $life->gamertag, 'seconds' => $seconds, 'started_at' => $startTs];
            }
        }

        $out = array_values($best);
        usort($out, fn ($a, $b) => $a['seconds'] <=> $b['seconds'] ?: $a['started_at'] <=> $b['started_at']);

        return array_map(
            fn ($r) => ['gamertag' => $r['gamertag'], 'seconds' => $r['seconds']],
            array_slice($out, 0, $limit)
        );
    }

    /**
     * Sort by seconds desc, tie-break started_at asc, strip the sort key, take $limit.
     *
     * @param  array<int, array{gamertag:string, seconds:int, started_at:int}>  $rows
     * @return array<int, array{gamertag:string, seconds:int}>
     */
    private function rankBySeconds(array $rows, int $limit): array
    {
        usort($rows, fn ($a, $b) => $b['seconds'] <=> $a['seconds'] ?: $a['started_at'] <=> $b['started_at']);

        return array_map(
            fn ($r) => ['gamertag' => $r['gamertag'], 'seconds' => $r['seconds']],
            array_slice($rows, 0, $limit)
        );
    }
}
