<?php

namespace App\Services\Bounty;

use App\Models\Bounty;
use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BountyService
{
    public function __construct(
        private AssociateDetector $detector,
        private BotState $state,
        private BountyNotifier $notifier,
        private int $tokenReward = 1,
    ) {}

    /** Committed playtime plus the elapsed time of the current open session (if any). */
    public function livePlaytime(Life $life, CarbonImmutable $now): int
    {
        $pt = (int) $life->playtime_seconds;
        $open = GameSession::where('life_id', $life->id)->whereNull('disconnected_at')
            ->latest('connected_at')->first();
        if ($open) {
            $pt += max(0, $now->getTimestamp() - $open->connected_at->getTimestamp());
        }
        return $pt;
    }

    /** Highest live-playtime open life among recently-active players above the floor; null if none. */
    public function currentLeader(CarbonImmutable $now): ?Life
    {
        $cutoff = $now->subHours((int) config('bounty.activity_window_hours'));
        $floor = (int) config('bounty.min_playtime_hours') * 3600;

        $lives = Life::whereNull('ended_at')
            ->whereHas('player', fn ($q) => $q->where('last_seen_at', '>=', $cutoff))
            ->get();

        $best = null;
        $bestPt = -1;
        foreach ($lives as $life) {
            $pt = $this->livePlaytime($life, $now);
            if ($pt < $floor) continue;
            if ($pt > $bestPt || ($pt === $bestPt && $best && $life->started_at < $best->started_at)) {
                $best = $life;
                $bestPt = $pt;
            }
        }
        return $best;
    }
}
