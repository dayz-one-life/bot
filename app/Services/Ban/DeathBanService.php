<?php

namespace App\Services\Ban;

use App\Models\Life;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;

class DeathBanService
{
    public function __construct(
        private BanService $bans,
        private BotState $state,
        private int $banHours = 12,
    ) {}

    /** Ban players whose lives ended after go_live and aren't yet banned. Returns count banned. */
    public function run(): int
    {
        $goLive = $this->state->get('go_live_at');
        if (! $goLive) return 0; // not live yet — never retro-ban history

        $cutoff = CarbonImmutable::parse($goLive);

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
            $this->bans->ban($gamertag, $this->banHours, 'One life autoban', 'auto_death');
            $life->update(['ban_issued' => true]);
            $count++;
        }

        return $count;
    }
}
