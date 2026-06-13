<?php

namespace App\Services\DeathFeed;

use App\Models\Ban;
use App\Models\Life;

interface DeathFeedNotifier
{
    /** Announce a death (with kill detail) and the resulting ban's return time. */
    public function died(Life $life, Ban $ban): void;
}
