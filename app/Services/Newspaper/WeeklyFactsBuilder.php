<?php

namespace App\Services\Newspaper;

use App\Models\HitEvent;
use App\Models\Life;
use App\Models\Player;
use App\Services\Connection\SessionDuration;
use App\Services\Geo\ChernarusRegions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates a trailing 7-day window into the structured facts the newspaper LLM (and the canned
 * fallback) consume. PRIVACY: the returned array NEVER contains a coordinate, a grid, or a
 * (player, place) pair. Locations appear only inside `location_trends` as anonymized region=>count
 * maps with no player names. Per-player facts carry distances, never places.
 */
class WeeklyFactsBuilder
{
    public function build(CarbonImmutable $now): array
    {
        $end = $now;
        $start = $now->subDays(7);
        $prevStart = $start->subDays(7);

        return [
            'period' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
            'counts' => $this->counts($start, $end, $prevStart),
            'superlatives' => $this->superlatives($start, $end),
            'location_trends' => $this->locationTrends($start, $end),
            'notable_events' => $this->notableEvents($start, $end),
            'witnesses' => $this->witnesses($end),
        ];
    }

    private function counts(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $prevStart): array
    {
        $lostThis = Life::whereNotNull('ended_at')->whereBetween('ended_at', [$start, $end])->count();
        $lostPrev = Life::whereNotNull('ended_at')->whereBetween('ended_at', [$prevStart, $start])->count();
        $playtime = (int) Life::whereBetween('started_at', [$start, $end])->sum('playtime_seconds');
        $infected = HitEvent::where('attacker_type', 'infected')->whereBetween('occurred_at', [$start, $end])->count();
        $infectedPrev = HitEvent::where('attacker_type', 'infected')->whereBetween('occurred_at', [$prevStart, $start])->count();
        $pvpHits = HitEvent::where('attacker_type', 'player')->whereBetween('occurred_at', [$start, $end])->count();
        $bunker = DB::table('bunker_visits')->whereBetween('visited_at', [$start, $end])->count();
        $alive = Life::whereNull('ended_at')->count();

        return [
            'lives_lost' => $lostThis, 'lives_lost_prev' => $lostPrev,
            'playtime_human' => SessionDuration::human($playtime),
            'infected_attacks' => $infected, 'infected_attacks_prev' => $infectedPrev,
            'pvp_hits' => $pvpHits,
            'bunker_descents' => $bunker,
            'souls_alive' => $alive,
        ];
    }

    private function superlatives(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $deadliest = DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->where('lives.death_cause', 'pvp')
            ->whereNotNull('lives.death_by_gamertag')
            ->whereColumn('lives.death_by_gamertag', '!=', 'players.gamertag')
            ->whereBetween('lives.ended_at', [$start, $end])
            ->groupBy('lives.death_by_gamertag')
            ->orderByDesc('kills')
            ->limit(1)
            ->get(['lives.death_by_gamertag as gamertag', DB::raw('COUNT(*) as kills')])
            ->first();

        $furthest = DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->where('lives.death_cause', 'pvp')
            ->whereNotNull('lives.death_distance')
            ->whereColumn('lives.death_by_gamertag', '!=', 'players.gamertag')
            ->whereBetween('lives.ended_at', [$start, $end])
            ->orderByDesc('lives.death_distance')
            ->limit(1)
            ->get(['lives.death_by_gamertag as killer', 'players.gamertag as victim', 'lives.death_weapon as weapon', 'lives.death_distance as distance'])
            ->first();

        $longest = DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->whereNotNull('lives.ended_at')
            ->whereBetween('lives.ended_at', [$start, $end])
            ->orderByDesc('lives.playtime_seconds')
            ->limit(1)
            ->get(['players.gamertag as gamertag', 'lives.playtime_seconds as seconds'])
            ->first();

        return [
            'deadliest_player' => $deadliest ? ['gamertag' => $deadliest->gamertag, 'kills' => (int) $deadliest->kills] : null,
            'furthest_kill' => $furthest ? [
                'killer' => $furthest->killer, 'victim' => $furthest->victim,
                'weapon' => $furthest->weapon, 'distance' => (float) $furthest->distance,
            ] : null,
            'longest_life_ended' => $longest ? [
                'gamertag' => $longest->gamertag, 'duration_human' => SessionDuration::human((int) $longest->seconds),
            ] : null,
            'most_travelled' => $this->mostTravelled($start, $end),
        ];
    }

    private function mostTravelled(CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        $rows = DB::table('player_positions')
            ->join('players', 'players.id', '=', 'player_positions.player_id')
            ->whereBetween('recorded_at', [$start, $end])
            ->orderBy('player_positions.player_id')
            ->orderBy('recorded_at')
            ->get(['players.gamertag as gamertag', 'player_positions.player_id as pid', 'x', 'y']);

        $dist = [];
        $prev = [];
        foreach ($rows as $r) {
            if (isset($prev[$r->pid])) {
                [$px, $py] = $prev[$r->pid];
                $dist[$r->pid]['m'] = ($dist[$r->pid]['m'] ?? 0) + sqrt(($r->x - $px) ** 2 + ($r->y - $py) ** 2);
                $dist[$r->pid]['gamertag'] = $r->gamertag;
            }
            $prev[$r->pid] = [$r->x, $r->y];
        }

        if ($dist === []) {
            return null;
        }

        usort($dist, fn ($a, $b) => ($b['m'] ?? 0) <=> ($a['m'] ?? 0));
        $top = $dist[0];

        return ['gamertag' => $top['gamertag'], 'km' => round(($top['m'] ?? 0) / 1000, 1)];
    }

    private function locationTrends(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $infectedByRegion = [];
        HitEvent::where('attacker_type', 'infected')
            ->whereBetween('occurred_at', [$start, $end])
            ->get(['victim_x', 'victim_y'])
            ->each(function ($h) use (&$infectedByRegion) {
                $region = ChernarusRegions::regionFor($h->victim_x, $h->victim_y);
                if ($region !== null) {
                    $infectedByRegion[$region] = ($infectedByRegion[$region] ?? 0) + 1;
                }
            });

        $deathsByRegion = [];
        Life::whereNotNull('ended_at')
            ->whereBetween('ended_at', [$start, $end])
            ->whereNotNull('death_log')
            ->get(['death_log'])
            ->each(function ($l) use (&$deathsByRegion) {
                [$x, $y] = $this->coordFromLog($l->death_log);
                $region = ChernarusRegions::regionFor($x, $y);
                if ($region !== null) {
                    $deathsByRegion[$region] = ($deathsByRegion[$region] ?? 0) + 1;
                }
            });

        arsort($infectedByRegion);
        arsort($deathsByRegion);

        return [
            'infected_by_region' => $infectedByRegion,
            'deaths_by_region' => $deathsByRegion,
            'infected_hotspot' => array_key_first($infectedByRegion),
            'deadliest_region' => array_key_first($deathsByRegion),
        ];
    }

    /** @return array{0:?float,1:?float} */
    private function coordFromLog(?string $log): array
    {
        if ($log !== null && preg_match('/pos=<\s*(-?[\d.]+),\s*(-?[\d.]+)/u', $log, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }

        return [null, null];
    }

    private function notableEvents(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return Life::whereNotNull('ended_at')
            ->whereBetween('ended_at', [$start, $end])
            ->with('player:id,gamertag')
            ->orderByDesc('ended_at')
            ->limit(15)
            ->get()
            ->map(fn (Life $l) => [
                'victim' => $l->player?->gamertag,
                'cause' => $l->death_cause,
                'killer' => $l->death_by_gamertag,
                'weapon' => $l->death_weapon,
                'distance' => $l->death_distance !== null ? (float) $l->death_distance : null,
                'lived_human' => SessionDuration::human((int) $l->playtime_seconds),
            ])
            ->all();
    }

    /** @return string[] */
    private function witnesses(CarbonImmutable $end): array
    {
        return Player::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $end->subDays(14))
            ->orderByDesc('last_seen_at')
            ->limit(8)
            ->pluck('gamertag')
            ->all();
    }
}
