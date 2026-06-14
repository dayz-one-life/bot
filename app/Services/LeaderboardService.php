<?php

namespace App\Services;

use App\Services\Leaderboard\DiscordLeaderboardNotifier;
use App\Services\Leaderboard\LeaderboardComposer;
use App\Services\Leaderboard\LeaderboardNotifier;
use App\Services\Leaderboard\LeaderboardStatsService;
use Laracord\Laracord;
use Laracord\Services\Service;

class LeaderboardService extends Service
{
    /** Refresh cadence in seconds; overridden from config in the constructor. */
    protected int $interval = 900;

    /**
     * Allow no-arg instantiation in tests (parent ctor requires a bot).
     */
    public function __construct(?Laracord $bot = null)
    {
        if ($bot !== null) {
            parent::__construct($bot);
        }

        $this->interval = max(60, (int) config('leaderboard.refresh_minutes', 15) * 60);
    }

    public function handle(): void
    {
        if (! config('leaderboard.enabled', true)) {
            return;
        }

        try {
            $this->compose(new DiscordLeaderboardNotifier($this->discord(), config('leaderboard.channel_id')));
        } catch (\Throwable $e) {
            $this->console()->error('[leaderboard] tick failed: '.$e->getMessage());
        }
    }

    /**
     * Build the 7 board payloads from the seven stat boards and hand them to the
     * notifier. Split out so tests can inject a NullLeaderboardNotifier.
     */
    public function compose(LeaderboardNotifier $notifier): void
    {
        $top = (int) config('leaderboard.top_count', 25);
        $stats = new LeaderboardStatsService();

        $payloads = (new LeaderboardComposer())->composeBoards([
            'alive' => $stats->aliveLongestLives($top),
            'all_time' => $stats->allTimeLongestLives($top),
            'kills' => $stats->mostKills($top),
            'streak' => $stats->longestKillStreaks($top),
            'distance' => $stats->longestKills($top),
            'bunker_visits' => $stats->mostBunkerVisits($top),
            'quickest_bunker' => $stats->quickestNewLifeToBunker($top),
        ]);

        $notifier->publish($payloads);
    }
}
