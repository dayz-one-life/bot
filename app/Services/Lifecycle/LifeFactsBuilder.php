<?php

namespace App\Services\Lifecycle;

use App\Models\Life;
use App\Services\Bounty\AssociateDetector;
use App\Services\Connection\SessionDuration;
use App\Services\Life\LivePlaytime;

/**
 * PURE-ish (reads the DB, no side effects): turns a Life into the structured-facts array fed to
 * the announcement LLM (and the canned fallback). Associates come from the bounty detector,
 * best-effort — an empty/failed lookup just yields [].
 */
class LifeFactsBuilder
{
    public function __construct(private ?AssociateDetector $associates = null) {}

    /** @return array<string,mixed> */
    public function build(Life $life): array
    {
        $player = $life->player;
        $playtime = $life->ended_at ? (int) $life->playtime_seconds : LivePlaytime::forLife($life);

        $prior = $this->priorDeath($life);

        return [
            'gamertag' => $player?->gamertag ?? '?',
            'linked' => (bool) ($player?->discord_user_id),
            'cause' => $life->death_cause,
            'killer' => $life->death_by_gamertag,
            'weapon' => $life->death_weapon,
            'distance_m' => $life->death_distance,
            // "Age" is the LIFE clock — actual playtime (Σ sessions), NOT wall-clock. A life can span
            // days of real time but only hours played; the playtime is the meaningful age here.
            'playtime_human' => SessionDuration::human($playtime),
            'playtime_seconds' => $playtime,
            'associates' => $this->associatesOf($life),
            'prior_death' => $prior,
            // No prior ended life => this is the player's very first life (a life only ends on death,
            // so "no prior death" == "first life"). Used so the birth notice doesn't invent a past life.
            'is_first_life' => $prior === null,
            'raw_log' => $this->stripLocations($life->death_log),
        ];
    }

    /**
     * Strip ADM position/coordinate data from a raw-log excerpt so a birth/death post can never
     * reveal a player's map location (where they died, their body, their base). Distances like
     * "from 153.4 meters" are NOT locations and are kept.
     */
    private function stripLocations(?string $log): ?string
    {
        if ($log === null || $log === '') {
            return $log;
        }

        $clean = preg_replace('/\s*pos=<[^>]*>/', '', $log);                            // "pos=<x, y, z>"
        $clean = preg_replace('/\s*<\s*-?[\d.]+(?:\s*,\s*-?[\d.]+){1,2}\s*>/', '', $clean); // bare <a, b, c> (teleport to:/from:)

        return $clean;
    }

    /** @return string[] */
    private function associatesOf(Life $life): array
    {
        $player = $life->player;
        if (! $player) return [];

        try {
            $detector = $this->associates ?? new AssociateDetector();
            return $detector->associatesOf($player)
                ->take(3)
                ->map(fn ($p) => $p->gamertag)
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function priorDeath(Life $life): ?string
    {
        $player = $life->player;
        if (! $player) return null;

        $prev = $player->lives()
            ->whereNotNull('ended_at')
            ->where('id', '!=', $life->id)
            ->where('started_at', '<', $life->started_at)
            ->latest('started_at')
            ->first();

        if (! $prev) return null;

        $by = $prev->death_by_gamertag ? " by {$prev->death_by_gamertag}" : '';
        return "previous life ended ({$prev->death_cause}{$by}) after ".SessionDuration::human((int) $prev->playtime_seconds);
    }
}
