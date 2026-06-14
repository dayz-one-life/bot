<?php

namespace App\Services;

use App\Services\Online\DiscordOnlineRosterNotifier;
use App\Services\Online\OnlineRosterComposer;
use App\Services\Online\OnlineRosterNotifier;
use App\Services\Online\OnlineRosterQuery;
use Laracord\Laracord;
use Laracord\Services\Service;

/**
 * Refreshes the online-players roster message every few minutes. Thin wiring shim
 * over the tested OnlineRosterQuery/Composer/Notifier. Read-only — not gated by
 * BAN_DRY_RUN. Auto-discovered by Laracord from app/Services/.
 */
class OnlinePlayersService extends Service
{
    /** Refresh cadence in seconds; overridden from config in the constructor. */
    protected int $interval = 300;

    /**
     * Allow no-arg instantiation in tests (parent ctor requires a bot).
     */
    public function __construct(?Laracord $bot = null)
    {
        if ($bot !== null) {
            parent::__construct($bot);
        }

        $this->interval = max(60, (int) config('online.refresh_minutes', 5) * 60);
    }

    public function handle(): void
    {
        if (! config('online.enabled', true)) {
            return;
        }

        try {
            $this->compose(new DiscordOnlineRosterNotifier($this->discord(), config('online.channel_id')));
        } catch (\Throwable $e) {
            $this->console()->error('[online] tick failed: '.$e->getMessage());
        }
    }

    /**
     * Build the payload and hand it to the notifier. Split out so tests can inject
     * a NullOnlineRosterNotifier.
     */
    public function compose(OnlineRosterNotifier $notifier): void
    {
        $rows = (new OnlineRosterQuery())->rows();
        $payload = (new OnlineRosterComposer())->compose($rows);

        $notifier->publish($payload);
    }
}
