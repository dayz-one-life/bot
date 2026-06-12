<?php

namespace App\Services\Bounty;

use App\Models\GameSession;
use App\Models\Player;
use App\Models\PlayerPosition;
use Carbon\CarbonImmutable;

class AssociateDetector
{
    /** Average of online-time overlap (Jaccard) and connect/disconnect synchrony. 0–1. */
    public function copresenceScore(Player $a, Player $b, CarbonImmutable $now): float
    {
        $cutoff = $now->subDays((int) config('bounty.assoc_window_days'));
        $overlap = $this->overlapScore($a->id, $b->id, $cutoff, $now);
        $sync = $this->syncScore($a->id, $b->id, $cutoff);
        return ($overlap + $sync) / 2;
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
    }

    /** @return array<int,array{0:int,1:int}> online intervals (epoch sec), clipped to window; open sessions end at $now. */
    private function intervals(int $playerId, CarbonImmutable $cutoff, CarbonImmutable $now): array
    {
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
        return $union <= 0 ? 0.0 : $overlap / $union;
    }

    /** @return array<int,int> connect & disconnect epoch-sec events within the window. */
    private function events(int $playerId, CarbonImmutable $cutoff): array
    {
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
