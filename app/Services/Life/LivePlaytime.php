<?php

namespace App\Services\Life;

use App\Models\Life;
use Carbon\CarbonImmutable;

/**
 * Live playtime for a single life. lives.playtime_seconds only accrues when a
 * session CLOSES (LifeTracker::closeSession), so an open life's currently-open
 * session is not yet counted. This adds that session's elapsed-so-far.
 * Kept separate from PlayerStatsService (which uses the stored value as-is for
 * its current_life_seconds) so existing behaviour is untouched.
 */
class LivePlaytime
{
    public static function forLife(Life $life): int
    {
        $seconds = (int) $life->playtime_seconds;

        $open = $life->sessions()->whereNull('disconnected_at')->first();
        if ($open) {
            $seconds += max(0, CarbonImmutable::now()->getTimestamp() - $open->connected_at->getTimestamp());
        }

        return $seconds;
    }
}
