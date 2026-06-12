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
            $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
            $client = new NitradoClient($token, $serviceId);
            $ingestor->tick($client, new BotState(), (int) env('ADM_BACKFILL_BUDGET', 15));
        } catch (\Throwable $e) {
            $this->console()->error('[ingest] tick failed: '.$e->getMessage());
        }
    }
}
