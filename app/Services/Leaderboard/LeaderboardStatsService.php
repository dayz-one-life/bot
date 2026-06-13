<?php

namespace App\Services\Leaderboard;

use App\Models\Life;
use App\Services\Life\LivePlaytime;

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
