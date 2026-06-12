<?php

namespace App\Services\Ban;

use App\Models\Ban;
use App\Models\Player;

class NullBanNotifier implements BanNotifier
{
    public function banned(Ban $ban, Player $player, bool $isExtension): void {}

    public function unbanned(Player $player, string $reason, ?string $originalReason): void {}
}
