<?php

namespace App\Services\Stats;

use App\Models\Player;

class PlayerStatsService
{
    /**
     * @return array{found:bool, gamertag?:string, lives?:int, deaths?:int,
     *               playtime_seconds?:int, alive?:bool, linked?:bool, last_seen_at?:?string}
     */
    public function statsFor(string $gamertag): array
    {
        $player = Player::where('gamertag', $gamertag)->withCount([
            'lives',
            'lives as deaths_count' => fn ($q) => $q->whereNotNull('ended_at'),
            'lives as open_lives_count' => fn ($q) => $q->whereNull('ended_at'),
        ])->first();

        if (! $player) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'gamertag' => $player->gamertag,
            'lives' => (int) $player->lives_count,
            'deaths' => (int) $player->deaths_count,
            'playtime_seconds' => (int) $player->lives()->sum('playtime_seconds'),
            'alive' => $player->open_lives_count > 0,
            'linked' => $player->discord_user_id !== null,
            'last_seen_at' => $player->last_seen_at?->toIso8601String(),
        ];
    }
}
