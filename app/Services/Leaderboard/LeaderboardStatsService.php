<?php

namespace App\Services\Leaderboard;

use App\Models\Life;
use App\Services\Life\LivePlaytime;
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
