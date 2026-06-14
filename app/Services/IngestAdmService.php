<?php

namespace App\Services;

use App\Services\Adm\AdmIngestor;
use App\Services\Adm\AdmParser;
use App\Services\Life\LifeTracker;
use App\Services\Nitrado\NitradoClient;
use App\Services\State\BotState;
use Laracord\Services\Service;

class IngestAdmService extends Service
{
    /**
     * The loop interval in seconds.
     */
    protected int $interval = 60;

    /**
     * Handle the service tick.
     */
    public function handle(): void
    {
        $token = env('NITRADO_TOKEN');
        $serviceId = (int) env('NITRADO_SERVICE_ID');

        if (! $token || ! $serviceId) {
            $this->console()->error('[ingest] NITRADO_TOKEN / NITRADO_SERVICE_ID not configured.');

            return;
        }

        try {
            $ingestor = new AdmIngestor(
                new AdmParser(),
                new LifeTracker(),
            );
            $client = new NitradoClient($token, $serviceId);
            $state = new BotState();
            $ingestor->tick($client, $state, (int) env('ADM_BACKFILL_BUDGET', 15));

            $bans = new \App\Services\Ban\BanService(
                $client,
                new \App\Services\Ban\DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID')),
                dryRun: filter_var(env('BAN_DRY_RUN', false), FILTER_VALIDATE_BOOL),
            );
            $banned = (new \App\Services\Ban\DeathBanService(
                $bans,
                $state,
                (int) env('BAN_DURATION_HOURS', 12),
                (int) config('lifecycle.ban_min_playtime_minutes', 60) * 60,
            ))->run();
            if ($banned > 0) {
                $this->console()->info("[ingest] issued {$banned} death ban(s).");
            }
        } catch (\Throwable $e) {
            $this->console()->error('[ingest] tick failed: '.$e->getMessage());
        }
    }
}
