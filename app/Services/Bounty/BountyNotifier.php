<?php

namespace App\Services\Bounty;

use App\Models\Bounty;
use App\Models\Player;

interface BountyNotifier
{
    /** A bounty was first placed on a player. */
    public function placed(Bounty $bounty, Player $target): void;

    /** The crown moved to a new player (overtake or prior holder dropped). */
    public function moved(Bounty $bounty, Player $target): void;

    /** A non-associate killed the bounty and earned tokens. */
    public function claimed(Bounty $bounty, Player $target, Player $killer, int $tokens): void;

    /** Bounty ended with no reward (died/non-pvp, associate kill, or inactivity). */
    public function ended(Bounty $bounty, Player $target, string $reason): void;
}
