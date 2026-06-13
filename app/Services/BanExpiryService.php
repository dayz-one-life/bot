<?php

namespace App\Services;

use App\Models\Ban;
use App\Services\Ban\BanService;
use App\Services\Ban\DiscordBanNotifier;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;
use Laracord\Laracord;
use Laracord\Services\Service;

class BanExpiryService extends Service
{
    protected int $interval = 60;

    /**
     * Override parent constructor so the service can be instantiated without
     * a bot in unit tests (sweep() is the testable surface; handle() needs bot).
     */
    public function __construct(?Laracord $bot = null)
    {
        if ($bot !== null) {
            parent::__construct($bot);
        }
    }

    public function handle(): void
    {
        $token = env('NITRADO_TOKEN');
        $serviceId = (int) env('NITRADO_SERVICE_ID');
        if (! $token || ! $serviceId) return;

        $nitrado = new NitradoClient($token, $serviceId);
        $bans = new BanService(
            $nitrado,
            new DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID')),
            dryRun: filter_var(env('BAN_DRY_RUN', false), FILTER_VALIDATE_BOOL),
        );

        try {
            $this->sweep($bans, $nitrado);
        } catch (\Throwable $e) {
            $this->console()->error('[ban-expiry] sweep failed: '.$e->getMessage());
        }
    }

    /** Testable core: expire due bans, then reconcile the Nitrado ban list. */
    public function sweep(BanService $bans, NitradoClient $nitrado): void
    {
        $now = CarbonImmutable::now();

        // 1) Lift expired bans.
        Ban::query()
            ->where('expired', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->with('player')
            ->get()
            ->each(function (Ban $ban) use ($bans) {
                if ($gamertag = $ban->player?->gamertag) {
                    $bans->unban($gamertag, 'Ban expired');
                }
            });

        // 2) Reconcile: every still-active ban must be present in Nitrado.
        // Skip entirely in dry-run mode — pushing intended (DB-only) bans to the live
        // server here would silently defeat BAN_DRY_RUN (the reconciler bypassed the
        // BanService dry-run gate by calling the Nitrado client directly).
        if ($bans->isDryRun()) return;

        $activeTags = Ban::query()
            ->where('expired', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->with('player')
            ->get()
            ->map(fn (Ban $b) => $b->player?->gamertag)
            ->filter()
            ->unique();

        if ($activeTags->isEmpty()) return;

        $present = collect($nitrado->getBans());
        foreach ($activeTags->diff($present) as $missing) {
            $nitrado->addBan($missing);
        }
    }
}
