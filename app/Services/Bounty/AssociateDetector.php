<?php

namespace App\Services\Bounty;

use App\Models\AssociateOverride;
use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Models\PlayerPosition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AssociateDetector
{
    /**
     * Request-scoped memo for the per-player / per-pair DB fetches that scoring repeats.
     * associatesOf() compares A against every other player in BOTH directions, so without
     * caching, A's positions/sessions/lives are re-queried for every candidate — ~19
     * queries per candidate (on production: ~2600 queries / ~6.6s for one `/team show`,
     * past Discord's 3s interaction deadline). Every detector instance is short-lived
     * (constructed fresh per slash-command handle, per bounty tick, per life build) and the
     * scoring-window data never mutates mid-scan, so memoising within an instance is safe
     * and collapses the scan to O(players) queries. Keyed by "tag:id:cutoffEpoch".
     *
     * @var array<string,mixed>
     */
    private array $memo = [];

    /** Memoise an expensive fetch by key for the lifetime of this (short-lived) instance. */
    private function remember(string $key, \Closure $fn): mixed
    {
        return $this->memo[$key] ??= $fn();
    }

    /** Weighted blend of the three sub-scores. Directional (uses copresence A->B). 0–1. */
    public function score(Player $a, Player $b, ?CarbonImmutable $now = null): float
    {
        $now = $now ?? CarbonImmutable::now();
        return (float) config('bounty.weight_prox') * $this->proximityScore($a, $b, $now)
            + (float) config('bounty.weight_copres') * $this->copresenceScore($a, $b, $now)
            + (float) config('bounty.weight_killg') * $this->killGraphModifier($a, $b, $now);
    }

    /**
     * Override-aware, order-independent associate decision. A force row wins; otherwise
     * the SYMMETRIC score (max of both directions, since copresence sync is directional)
     * must clear the threshold. Max leans slightly stricter — it flags a pair if the
     * association is strong viewed from either side, protecting the token economy.
     */
    public function areAssociates(Player $a, Player $b, ?CarbonImmutable $now = null): bool
    {
        [$lo, $hi] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
        $override = AssociateOverride::where('player_a_id', $lo)->where('player_b_id', $hi)->first();
        if ($override) return (bool) $override->force;

        $now = $now ?? CarbonImmutable::now();
        $symmetric = max($this->score($a, $b, $now), $this->score($b, $a, $now));
        return $symmetric >= (float) config('bounty.assoc_threshold');
    }

    /** Every other player who clears areAssociates() with $a. */
    public function associatesOf(Player $a, ?CarbonImmutable $now = null): Collection
    {
        // Resolve once so every comparison shares an identical cutoff — keeps the memo keys
        // stable across the scan (otherwise each areAssociates() would default its own
        // microsecond-apart `now`).
        $now = $now ?? CarbonImmutable::now();

        return Player::where('id', '!=', $a->id)->get()
            ->filter(fn (Player $p) => $this->areAssociates($a, $p, $now))
            ->values();
    }

    /** Average of online-time overlap (Jaccard) and connect/disconnect synchrony. 0–1. */
    public function copresenceScore(Player $a, Player $b, CarbonImmutable $now): float
    {
        $cutoff = $now->subDays((int) config('bounty.assoc_window_days'));
        $overlap = $this->overlapScore($a->id, $b->id, $cutoff, $now);
        $sync = $this->syncScore($a->id, $b->id, $cutoff);
        return ($overlap + $sync) / 2;
    }

    /**
     * Sparse confidence modifier, 0–1. Any mutual kill zeroes it (they fight =>
     * not teammates). Otherwise rewards shared victims (players both have killed).
     */
    public function killGraphModifier(Player $a, Player $b, CarbonImmutable $now): float
    {
        $cutoff = $now->subDays((int) config('bounty.assoc_window_days'));
        $ce = $cutoff->getTimestamp();

        [$lo, $hi] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
        $mutual = $this->remember("mut:{$lo}:{$hi}:{$ce}", fn () =>
            Life::whereNotNull('ended_at')->where('ended_at', '>=', $cutoff)
                ->where(function ($q) use ($a, $b) {
                    $q->where(fn ($w) => $w->where('player_id', $b->id)->where('death_by_gamertag', $a->gamertag))
                      ->orWhere(fn ($w) => $w->where('player_id', $a->id)->where('death_by_gamertag', $b->gamertag));
                })->count());
        if ($mutual > 0) return 0.0;

        $shared = $this->victimsOf($a->gamertag, $cutoff)
            ->intersect($this->victimsOf($b->gamertag, $cutoff))->count();
        return $shared > 0 ? min(1.0, $shared / 3.0) : 0.0;
    }

    /** Unique player ids killed by $gamertag within the window. Memoised per scan. */
    private function victimsOf(string $gamertag, CarbonImmutable $cutoff): Collection
    {
        return $this->remember("vic:{$gamertag}:{$cutoff->getTimestamp()}", fn () =>
            Life::whereNotNull('ended_at')->where('ended_at', '>=', $cutoff)
                ->where('death_by_gamertag', $gamertag)->pluck('player_id')->unique());
    }

    /** Fraction of shared 5-min time-bins where the pair were within assoc_radius_m. 0–1. */
    public function proximityScore(Player $a, Player $b, CarbonImmutable $now): float
    {
        $cutoff = $now->subDays((int) config('bounty.assoc_window_days'));
        $radius = (float) config('bounty.assoc_radius_m');
        $binSec = 300;

        $aBins = $this->binnedPositions($a->id, $cutoff, $binSec);
        $bBins = $this->binnedPositions($b->id, $cutoff, $binSec);

        $shared = 0;
        $colocated = 0;
        foreach ($aBins as $bin => $pa) {
            if (! isset($bBins[$bin])) continue;
            $shared++;
            $pb = $bBins[$bin];
            $dist = sqrt(($pa['x'] - $pb['x']) ** 2 + ($pa['y'] - $pb['y']) ** 2);
            if ($dist <= $radius) $colocated++;
        }
        return $shared === 0 ? 0.0 : $colocated / $shared;
    }

    /** @return array<int,array{x:float,y:float}> one representative position per time-bin (last sample wins). */
    private function binnedPositions(int $playerId, CarbonImmutable $cutoff, int $binSec): array
    {
        return $this->remember("pos:{$playerId}:{$cutoff->getTimestamp()}:{$binSec}", function () use ($playerId, $cutoff, $binSec) {
            $rows = PlayerPosition::where('player_id', $playerId)
                ->where('recorded_at', '>=', $cutoff)
                ->orderBy('recorded_at')
                ->get();

            $bins = [];
            foreach ($rows as $r) {
                $bin = intdiv($r->recorded_at->getTimestamp(), $binSec);
                $bins[$bin] = ['x' => (float) $r->x, 'y' => (float) $r->y];
            }
            return $bins;
        });
    }

    /** @return array<int,array{0:int,1:int}> online intervals (epoch sec), clipped to window; open sessions end at $now. */
    private function intervals(int $playerId, CarbonImmutable $cutoff, CarbonImmutable $now): array
    {
        return $this->remember("int:{$playerId}:{$cutoff->getTimestamp()}:{$now->getTimestamp()}", function () use ($playerId, $cutoff, $now) {
            $rows = GameSession::where('player_id', $playerId)
                ->where(fn ($q) => $q->whereNull('disconnected_at')->orWhere('disconnected_at', '>=', $cutoff))
                ->get();

            $out = [];
            foreach ($rows as $s) {
                $start = max($s->connected_at->getTimestamp(), $cutoff->getTimestamp());
                $end = $s->disconnected_at?->getTimestamp() ?? $now->getTimestamp();
                if ($end > $start) $out[] = [$start, $end];
            }
            return $out;
        });
    }

    private function overlapScore(int $aId, int $bId, CarbonImmutable $cutoff, CarbonImmutable $now): float
    {
        $ia = $this->intervals($aId, $cutoff, $now);
        $ib = $this->intervals($bId, $cutoff, $now);

        $overlap = 0;
        foreach ($ia as [$s1, $e1]) {
            foreach ($ib as [$s2, $e2]) {
                $o = min($e1, $e2) - max($s1, $s2);
                if ($o > 0) $overlap += $o;
            }
        }
        $sumA = array_sum(array_map(fn ($i) => $i[1] - $i[0], $ia));
        $sumB = array_sum(array_map(fn ($i) => $i[1] - $i[0], $ib));
        $union = $sumA + $sumB - $overlap;
        // Clamp to 1.0 defensively: LifeTracker guarantees non-self-overlapping
        // sessions, but a malformed/external data source must not push score() > 1.
        return $union <= 0 ? 0.0 : min(1.0, $overlap / $union);
    }

    /** @return array<int,int> connect & disconnect epoch-sec events within the window. */
    private function events(int $playerId, CarbonImmutable $cutoff): array
    {
        return $this->remember("ev:{$playerId}:{$cutoff->getTimestamp()}", function () use ($playerId, $cutoff) {
            $rows = GameSession::where('player_id', $playerId)
                ->where(fn ($q) => $q->where('connected_at', '>=', $cutoff)->orWhere('disconnected_at', '>=', $cutoff))
                ->get();

            $ev = [];
            foreach ($rows as $s) {
                if ($s->connected_at && $s->connected_at->getTimestamp() >= $cutoff->getTimestamp()) {
                    $ev[] = $s->connected_at->getTimestamp();
                }
                if ($s->disconnected_at && $s->disconnected_at->getTimestamp() >= $cutoff->getTimestamp()) {
                    $ev[] = $s->disconnected_at->getTimestamp();
                }
            }
            return $ev;
        });
    }

    /** Fraction of A's events that have a B event within sync_window_min. 0–1. */
    private function syncScore(int $aId, int $bId, CarbonImmutable $cutoff): float
    {
        $ea = $this->events($aId, $cutoff);
        $eb = $this->events($bId, $cutoff);
        if (empty($ea)) return 0.0;

        $window = (int) config('bounty.sync_window_min') * 60;
        $matched = 0;
        foreach ($ea as $t) {
            foreach ($eb as $u) {
                if (abs($t - $u) <= $window) { $matched++; break; }
            }
        }
        return $matched / count($ea);
    }
}
