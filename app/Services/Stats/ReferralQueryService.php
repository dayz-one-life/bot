<?php

namespace App\Services\Stats;

use App\Models\Player;
use Carbon\CarbonImmutable;

class ReferralQueryService
{
    /**
     * @return array{linked:bool, referrals?:array<int,array{gamertag:string,active:bool}>, activeCount?:int}
     */
    public function forDiscordUser(string $discordUserId): array
    {
        $player = Player::where('discord_user_id', $discordUserId)->first();
        if (! $player) {
            return ['linked' => false];
        }

        $now = CarbonImmutable::now();
        $prevStart = $now->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $now->startOfMonth();

        $referrals = Player::where('referrer_id', $player->id)
            ->withCount(['sessions as active_count' => fn ($q) => $q
                ->where('connected_at', '>=', $prevStart)->where('connected_at', '<', $prevEnd)])
            ->orderBy('gamertag')
            ->get()
            ->map(fn (Player $r) => ['gamertag' => $r->gamertag, 'active' => $r->active_count > 0])
            ->all();

        return [
            'linked' => true,
            'referrals' => $referrals,
            'activeCount' => collect($referrals)->where('active', true)->count(),
        ];
    }
}
