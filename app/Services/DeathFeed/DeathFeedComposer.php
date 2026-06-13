<?php

namespace App\Services\DeathFeed;

use App\Models\Life;
use App\Services\Lookup\PlayerMention;
use App\Services\Personality\MessagePicker;
use Carbon\CarbonInterface;

/**
 * Pure builder for a death-feed line: picks the personality pool from the death cause,
 * renders victim/killer via PlayerMention (public channel → mentions linked players),
 * and interpolates weapon / distance / humanized cause / relative expiry. No I/O.
 */
class DeathFeedComposer
{
    private PlayerMention $mention;

    public function __construct(private MessagePicker $picker, ?PlayerMention $mention = null)
    {
        $this->mention = $mention ?? new PlayerMention();
    }

    public function compose(Life $life, CarbonInterface $expiresAt): string
    {
        $victim = $this->mention->forPlayer($life->player, $life->player?->gamertag);
        $killer = $this->mention->for($life->death_by_gamertag);
        $expires = "<t:{$expiresAt->getTimestamp()}:R>";

        $key = $this->keyFor($life);
        $tokens = [
            ':victim' => $victim,
            ':killer' => $killer,
            ':weapon' => $life->death_weapon,
            ':distance' => $life->death_distance !== null ? (string) (int) round($life->death_distance) : '',
            ':cause' => $this->humanCause($life->death_cause),
            ':expires' => $expires,
        ];

        return $this->picker->pick($key, $tokens, "💀 {$victim} died — back {$expires}.");
    }

    private function keyFor(Life $life): string
    {
        return match ($life->death_cause) {
            'pvp' => ($life->death_weapon !== null) ? 'death.pvp' : 'death.pvp_noweapon',
            'suicide' => 'death.suicide',
            'environment' => 'death.environment',
            default => 'death.misc', // bled_out / drowned / died / unknown
        };
    }

    private function humanCause(?string $cause): string
    {
        return match ($cause) {
            'bled_out' => 'bled out',
            'drowned' => 'drowned',
            'died' => 'died',
            default => 'died',
        };
    }
}
