<?php

namespace App\Services\Admin;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

class AdminService
{
    /** @return array{status:string, gamertag?:string} — linked | gamertag_not_found */
    public function forceLink(string $discordUserId, string $gamertag): array
    {
        $player = Player::where('gamertag', $gamertag)->first();
        if (! $player) {
            return ['status' => 'gamertag_not_found'];
        }

        return DB::transaction(function () use ($player, $discordUserId) {
            // Preserve the 1:1 invariant: clear any other gamertag this user holds.
            Player::where('discord_user_id', $discordUserId)
                ->where('id', '!=', $player->id)
                ->update(['discord_user_id' => null]);

            $player->discord_user_id = $discordUserId;
            $player->save();

            return ['status' => 'linked', 'gamertag' => $player->gamertag];
        });
    }

    /** @return array{status:string, gamertag?:string} — unlinked | not_linked */
    public function unlink(string $discordUserId): array
    {
        $player = Player::where('discord_user_id', $discordUserId)->first();
        if (! $player) {
            return ['status' => 'not_linked'];
        }
        $player->discord_user_id = null;
        $player->save();

        return ['status' => 'unlinked', 'gamertag' => $player->gamertag];
    }

    /** @return array{status:string, balance?:int} — granted | gamertag_not_found */
    public function grantTokens(string $gamertag, int $amount): array
    {
        $player = Player::where('gamertag', $gamertag)->first();
        if (! $player) {
            return ['status' => 'gamertag_not_found'];
        }
        $player->unban_tokens = max(0, $player->unban_tokens + $amount);
        $player->save();

        return ['status' => 'granted', 'balance' => $player->unban_tokens];
    }
}
