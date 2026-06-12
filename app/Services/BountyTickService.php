<?php

namespace App\Services;

use App\Models\PlayerPosition;
use App\Services\Bounty\AssociateDetector;
use App\Services\Bounty\BountyService;
use App\Services\Bounty\DiscordBountyNotifier;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Laracord\Laracord;
use Laracord\Services\Service;

class BountyTickService extends Service
{
    protected int $interval = 60;

    /** Allow no-arg instantiation in tests (parent ctor requires a bot). */
    public function __construct(?Laracord $bot = null)
    {
        if ($bot !== null) {
            parent::__construct($bot);
        }
    }

    public function handle(): void
    {
        $state = new BotState();
        if (! $state->get('go_live_at')) return;

        try {
            $notifier = new DiscordBountyNotifier($this->discord(), config('bounty.channel_id'));
            $svc = new BountyService(new AssociateDetector(), $state, $notifier, (int) config('bounty.token_reward'));
            $svc->run();

            $this->prunePositions();
        } catch (\Throwable $e) {
            $this->console()->error('[bounty] tick failed: '.$e->getMessage());
        }
    }

    /**
     * Delete position samples older than position_retention_days. Returns the count
     * deleted. Retention 0 (default) means keep forever — no pruning. Retention is
     * intentionally separate from the detector's assoc_window_days scoring window.
     */
    public function prunePositions(?CarbonImmutable $now = null): int
    {
        $retention = (int) config('bounty.position_retention_days');
        if ($retention <= 0) return 0;

        $now = $now ?? CarbonImmutable::now();
        return PlayerPosition::where('recorded_at', '<', $now->subDays($retention))->delete();
    }
}
