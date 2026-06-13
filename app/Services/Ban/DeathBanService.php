<?php

namespace App\Services\Ban;

use App\Models\Life;
use App\Services\DeathFeed\DeathFeedNotifier;
use App\Services\DeathFeed\NullDeathFeedNotifier;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;

class DeathBanService
{
    private DeathFeedNotifier $feed;

    public function __construct(
        private BanService $bans,
        private BotState $state,
        private int $banHours = 12,
        ?DeathFeedNotifier $feed = null,
        private int $feedMaxAgeMinutes = 10,
    ) {
        $this->feed = $feed ?? new NullDeathFeedNotifier();
    }

    /** Ban players whose lives ended after go_live and aren't yet banned. Returns count banned. */
    public function run(): int
    {
        $goLive = $this->state->get('go_live_at');
        if (! $goLive) return 0; // not live yet — never retro-ban history

        $cutoff = CarbonImmutable::parse($goLive);
        $freshAfter = CarbonImmutable::now()->subMinutes($this->feedMaxAgeMinutes);

        $lives = Life::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '>', $cutoff)
            ->where('ban_issued', false)
            ->with('player')
            ->orderBy('ended_at')
            ->get();

        $count = 0;
        foreach ($lives as $life) {
            $gamertag = $life->player?->gamertag;
            if (! $gamertag) { $life->update(['ban_issued' => true]); continue; }

            $ban = $this->bans->ban($gamertag, $this->banHours, 'One life autoban', 'auto_death');
            $life->update(['ban_issued' => true]);
            $count++;

            // Death feed posts independently of BAN_DRY_RUN (the Ban row, with its
            // expiry, exists even in dry run). Skip stale deaths to avoid a post-downtime
            // backlog flood — they are still banned above, just not announced.
            if ($life->ended_at->greaterThanOrEqualTo($freshAfter)) {
                $this->feed->died($life, $ban);
            }
        }

        return $count;
    }
}
