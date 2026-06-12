<?php

namespace App\Services\Tokens;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

class LinkService
{
    /**
     * @return array{status:string, gamertag?:string, referrer?:?string, tokenGranted?:bool}
     * status ∈ linked | already_linked | gamertag_not_found | invalid_referrer
     */
    public function link(string $discordUserId, string $gamertag, ?string $referrerGamertag): array
    {
        if (Player::where('discord_user_id', $discordUserId)->exists()) {
            return ['status' => 'already_linked'];
        }

        $player = Player::where('gamertag', $gamertag)->whereNull('discord_user_id')->first();
        if (! $player) {
            return ['status' => 'gamertag_not_found'];
        }

        $referrer = null;
        if ($referrerGamertag !== null && $referrerGamertag !== '') {
            if (strcasecmp($referrerGamertag, $gamertag) === 0) {
                return ['status' => 'invalid_referrer'];
            }
            $referrer = Player::where('gamertag', $referrerGamertag)
                ->whereNotNull('discord_user_id')->first();
            if (! $referrer) {
                return ['status' => 'invalid_referrer'];
            }
        }

        return DB::transaction(function () use ($player, $discordUserId, $referrer) {
            $player->discord_user_id = $discordUserId;
            if ($referrer && $player->referrer_id === null) {
                $player->referrer_id = $referrer->id;
            }
            $granted = false;
            if (! $player->link_rewarded) {
                $player->unban_tokens += 1;
                $player->link_rewarded = true;
                $granted = true;
            }
            $player->save();

            return [
                'status' => 'linked',
                'gamertag' => $player->gamertag,
                'referrer' => $referrer?->gamertag,
                'tokenGranted' => $granted,
            ];
        });
    }
}
