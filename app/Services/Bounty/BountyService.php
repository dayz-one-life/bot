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

    /** One reconciliation tick: resolve an ended bounty, then place/move. No-op before go_live. */
    public function run(?CarbonImmutable $now = null): void
    {
        $now = $now ?? CarbonImmutable::now();
        if (! $this->state->get('go_live_at')) return;

        DB::transaction(function () use ($now) {
            // 1) Resolve a bounty whose life has ended (claim/death). Filled in Task 13.
            $this->resolveEnded($now);

            // 2) Place or move.
            $active = Bounty::active();
            $leader = $this->currentLeader($now);

            if (! $active) {
                if ($leader) $this->place($leader, $now);
                return;
            }

            if (! $this->eligible($active->player_id, $now)) {
                $this->close($active, 'inactive', $now);
                if ($leader) $this->move($leader, $now);
                return;
            }

            if ($leader && $leader->id !== $active->life_id) {
                $holderLife = Life::find($active->life_id);
                $margin = (int) config('bounty.move_margin_min') * 60;
                if ($this->livePlaytime($leader, $now) - $this->livePlaytime($holderLife, $now) >= $margin) {
                    $this->close($active, 'moved', $now);
                    $this->move($leader, $now);
                }
            }
        });
    }

    /** Placeholder filled in Task 13. */
    protected function resolveEnded(CarbonImmutable $now): void
    {
        // no-op until Task 13
    }

    private function eligible(int $playerId, CarbonImmutable $now): bool
    {
        $cutoff = $now->subHours((int) config('bounty.activity_window_hours'));
        return Player::where('id', $playerId)->where('last_seen_at', '>=', $cutoff)->exists();
    }

    private function place(Life $leader, CarbonImmutable $now): void
    {
        $b = Bounty::create(['player_id' => $leader->player_id, 'life_id' => $leader->id, 'placed_at' => $now]);
        $this->notifier->placed($b, Player::find($leader->player_id));
    }

    private function move(Life $leader, CarbonImmutable $now): void
    {
        $b = Bounty::create(['player_id' => $leader->player_id, 'life_id' => $leader->id, 'placed_at' => $now]);
        $this->notifier->moved($b, Player::find($leader->player_id));
    }

    private function close(Bounty $b, string $reason, CarbonImmutable $now): void
    {
        $b->update(['ended_at' => $now, 'end_reason' => $reason]);
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
