<?php

namespace App\Services\Lifecycle;

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Adm\DayzNameHumanizer;
use App\Services\Bounty\AssociateDetector;
use App\Services\Connection\SessionDuration;
use App\Services\Life\LivePlaytime;
use Carbon\CarbonImmutable;
use Closure;

/**
 * PURE-ish (reads the DB, no side effects): turns a Life into the structured-facts array fed to
 * the announcement LLM (and the canned fallback). Associates come from the bounty detector,
 * best-effort — an empty/failed lookup just yields [].
 */
class LifeFactsBuilder
{
    /** @param Closure(string[]):string[]|null $shuffle order-randomizer for witnesses (injectable for tests) */
    public function __construct(
        private ?AssociateDetector $associates = null,
        private ?Closure $shuffle = null,
    ) {}

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
            // Defense-in-depth: a real PvP gamertag passes through unchanged; an infected/animal
            // class name (should one ever land here) gets humanized like the raw log below.
            'killer' => DayzNameHumanizer::token($life->death_by_gamertag),
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
            'raw_log' => $this->rawLog($life->death_log),
            // Real, recently-active survivors the LLM may quote as witnesses (never invent anonymous
            // ones). Excludes the subject and the killer. Plain names — not pinged.
            'witnesses' => $this->witnesses($life),
            'population_at_spawn' => $this->populationAtSpawn($life),
            'births_this_week' => $this->birthsThisWeek($life),
            'deaths_this_week' => $this->deathsThisWeek($life),
            'time_of_day' => $this->timeOfDay($life),
        ];
    }

    /**
     * Recently-active gamertags (seen within 14 days), excluding the subject and the killer, as a
     * handful of real names the LLM may attribute quotes to. We pull a larger recent pool and then
     * SHUFFLE before taking a few: ordering by recency alone is stable across posts minutes apart, and
     * the model is biased to quote whoever is listed first — so a fixed order means the same survivor
     * gets quoted every time. Shuffling varies who heads the list, breaking that rut.
     *
     * @return string[]
     */
    private function witnesses(Life $life): array
    {
        $subject = $life->player?->gamertag;
        $killer = $life->death_by_gamertag;
        $cutoff = CarbonImmutable::now()->subDays(14);

        $pool = Player::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $cutoff)
            ->when($subject, fn ($q) => $q->where('gamertag', '!=', $subject))
            ->when($killer, fn ($q) => $q->where('gamertag', '!=', $killer))
            ->orderByDesc('last_seen_at')
            ->limit(25)
            ->pluck('gamertag')
            ->all();

        $shuffled = ($this->shuffle ?? self::defaultShuffle(...))($pool);

        return array_slice($shuffled, 0, 6);
    }

    /**
     * @param  string[]  $names
     * @return string[]
     */
    private static function defaultShuffle(array $names): array
    {
        shuffle($names);

        return $names;
    }

    /**
     * Strip ADM position/coordinate data from a raw-log excerpt so a birth/death post can never
     * reveal a player's map location (where they died, their body, their base). Distances like
     * "from 153.4 meters" are NOT locations and are kept.
     */
    /** Coordinate-stripped, class-name-humanized raw log excerpt fed to the LLM (null stays null). */
    private function rawLog(?string $log): ?string
    {
        $clean = $this->stripLocations($log);

        return $clean === null ? null : DayzNameHumanizer::text($clean);
    }

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

        // Deliberately name-free: the LLM is told never to write a real name, so any gamertag we feed
        // it here gets rendered as the {{KILLER}} token — but the CURRENT life has no killer to
        // substitute (a birth) or a DIFFERENT killer (a eulogy), leaking/mis-pointing the token.
        return "previous life ended ({$prev->death_cause}) after ".SessionDuration::human((int) $prev->playtime_seconds);
    }

    /** Distinct OTHER players whose session spans the spawn instant — "the world they spawned into". */
    private function populationAtSpawn(Life $life): int
    {
        $at = $life->started_at;

        return GameSession::query()
            ->where('player_id', '!=', $life->player_id) // exclude the subject's own session
            ->where('connected_at', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('disconnected_at')->orWhere('disconnected_at', '>', $at);
            })
            ->distinct()
            ->count('player_id');
    }

    private function birthsThisWeek(Life $life): int
    {
        $start = $life->started_at->copy()->subDays(7);

        return Life::query()
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $life->started_at)
            ->count();
    }

    private function deathsThisWeek(Life $life): int
    {
        $start = $life->started_at->copy()->subDays(7);

        return Life::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '>=', $start)
            ->where('ended_at', '<', $life->started_at)
            ->count();
    }

    /** Pure: UTC spawn hour -> atmospheric bucket. */
    private function timeOfDay(Life $life): string
    {
        $hour = (int) $life->started_at->copy()->utc()->format('G');

        return match (true) {
            $hour >= 5 && $hour < 8 => 'dawn',
            $hour >= 8 && $hour < 17 => 'day',
            $hour >= 17 && $hour < 20 => 'dusk',
            default => 'night',
        };
    }
}
