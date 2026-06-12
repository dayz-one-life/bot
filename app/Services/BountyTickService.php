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

            // Prune position samples older than the detection window.
            $cutoff = CarbonImmutable::now()->subDays((int) config('bounty.assoc_window_days'));
            PlayerPosition::where('recorded_at', '<', $cutoff)->delete();
        } catch (\Throwable $e) {
            $this->console()->error('[bounty] tick failed: '.$e->getMessage());
        }
    }
}
