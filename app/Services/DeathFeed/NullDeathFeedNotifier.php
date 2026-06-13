<?php

namespace App\Services\DeathFeed;

use App\Models\Ban;
use App\Models\Life;

class NullDeathFeedNotifier implements DeathFeedNotifier
{
    public function died(Life $life, Ban $ban): void
    {
        // no-op
    }
}
