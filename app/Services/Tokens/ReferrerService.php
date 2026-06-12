<?php

namespace App\Services\Tokens;

use App\Models\Player;

class ReferrerService
{
    /** @return array{status:string, referrer?:string} — set | not_linked | already_set | invalid_referrer */
    public function setReferrer(string $discordUserId, string $referrerGamertag): array
    {
        $player = Player::where('discord_user_id', $discordUserId)->first();
        if (! $player) {
            return ['status' => 'not_linked'];
        }
        if ($player->referrer_id !== null) {
            return ['status' => 'already_set'];
        }
        if (strcasecmp($referrerGamertag, $player->gamertag) === 0) {
            return ['status' => 'invalid_referrer'];
        }
        $referrer = Player::where('gamertag', $referrerGamertag)->whereNotNull('discord_user_id')->first();
        if (! $referrer) {
            return ['status' => 'invalid_referrer'];
        }

        $player->referrer_id = $referrer->id;
        $player->save();

        return ['status' => 'set', 'referrer' => $referrer->gamertag];
    }
}
