<?php

namespace App\Services\Bounty;

use App\Models\Bounty;
use App\Models\Player;

class NullBountyNotifier implements BountyNotifier
{
    public function placed(Bounty $bounty, Player $target): void {}
    public function moved(Bounty $bounty, Player $target): void {}
    public function claimed(Bounty $bounty, Player $target, Player $killer, int $tokens): void {}
    public function ended(Bounty $bounty, Player $target, string $reason): void {}
}
