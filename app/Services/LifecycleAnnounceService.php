<?php

namespace App\Services;

use App\Services\Lifecycle\AnnouncementGenerator;
use App\Services\Lifecycle\DiscordLifecycleNotifier;
use App\Services\Lifecycle\LifecycleAnnouncer;
use App\Services\Llm\OpenRouterClient;
use App\Services\Personality\MessagePicker;
use App\Services\State\BotState;
use Laracord\Laracord;
use Laracord\Services\Service;

/**
 * Posts births + eulogies every tick. Thin wiring shim over LifecycleAnnouncer. Not gated by
 * BAN_DRY_RUN (channel posts are independent of real Nitrado bans). Auto-discovered from
 * app/Services/. With no OPENROUTER_API_KEY the generator falls back to canned copy automatically.
 */
class LifecycleAnnounceService extends Service
{
    protected int $interval = 60;

    public function __construct(?Laracord $bot = null)
    {
        if ($bot !== null) {
            parent::__construct($bot);
        }

        $this->interval = max(60, (int) config('lifecycle.refresh_minutes', 1) * 60);
    }

    public function handle(): void
    {
        if (! config('lifecycle.enabled', true)) {
            return;
        }

        try {
            $notifier = new DiscordLifecycleNotifier(
                $this->discord(),
                config('lifecycle.births_channel_id'),
                config('lifecycle.eulogy_channel_id'),
            );
            $generator = new AnnouncementGenerator(OpenRouterClient::fromConfig(), new MessagePicker());

            (new LifecycleAnnouncer(
                $generator,
                $notifier,
                new BotState(),
                graceSeconds: (int) config('lifecycle.grace_minutes', 5) * 60,
                maxAgeMinutes: (int) config('lifecycle.max_age_minutes', 30),
            ))->run();
        } catch (\Throwable $e) {
            $this->console()->error('[lifecycle] tick failed: '.$e->getMessage());
        }
    }
}
