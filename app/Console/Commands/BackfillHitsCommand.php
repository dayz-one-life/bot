<?php

namespace App\Console\Commands;

use App\Services\Adm\AdmParser;
use App\Services\Adm\HitBackfillService;
use App\Services\Hit\HitEventService;
use App\Services\Nitrado\NitradoClient;
use Laracord\Console\Commands\Command;

class BackfillHitsCommand extends Command
{
    protected $signature = 'adm:backfill-hits {--since-days= : Only scan ADM files newer than N days (default: all)}';
    protected $description = 'Backfill hit events from ADM history (no banning, no life changes).';

    public function handle(): int
    {
        $token = env('NITRADO_TOKEN');
        $serviceId = (int) env('NITRADO_SERVICE_ID');
        if (! $token || ! $serviceId) {
            $this->error('Set NITRADO_TOKEN and NITRADO_SERVICE_ID in .env first.');
            return self::FAILURE;
        }

        $sinceDays = $this->option('since-days') !== null ? (int) $this->option('since-days') : null;

        $client = new NitradoClient($token, $serviceId);
        $svc = new HitBackfillService(new AdmParser());
        $hits = new HitEventService();

        $scope = $sinceDays !== null ? "last {$sinceDays} day(s)" : 'all history';
        $this->info("Backfilling hit events — {$scope}...");

        $result = $svc->backfillAll($client, $hits, $sinceDays, function (string $name, int $n) {
            if ($n > 0) $this->line("  {$name}: {$n} hit(s)");
        });

        $this->info("Done. {$result['files']} file(s) scanned, {$result['hits']} hit(s) recorded.");
        return self::SUCCESS;
    }
}
