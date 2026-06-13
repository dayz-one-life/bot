<?php

namespace App\Services\Lookup;

use App\Models\Player;

/**
 * Renders a gamertag for Discord output: a real mention (<@id>) when the gamertag
 * is linked, otherwise the gamertag in backticks. Keeps the linked-vs-unlinked
 * presentation rule in ONE place so every command/notifier is consistent.
 */
class PlayerMention
{
    /** Look up by gamertag, then render. Unknown gamertag → backticked as-is. */
    public function for(?string $gamertag): string
    {
        if ($gamertag === null || $gamertag === '') {
            return '`?`';
        }
        return $this->forPlayer(Player::where('gamertag', $gamertag)->first(), $gamertag);
    }

    /** Render a known Player model (no DB lookup). Null → backticked fallback. */
    public function forPlayer(?Player $player, ?string $fallbackGamertag = null): string
    {
        if (! $player) {
            return '`'.($fallbackGamertag ?? '?').'`';
        }
        return $player->discord_user_id ? "<@{$player->discord_user_id}>" : "`{$player->gamertag}`";
    }
}
