<?php

namespace App\Console\Commands;

use App\Services\Adm\AdmParser;
use App\Services\Adm\BunkerVisitBackfillService;
use App\Services\Bunker\BunkerVisitService;
use App\Services\Nitrado\NitradoClient;
use Laracord\Console\Commands\Command;

class BackfillBunkerVisitsCommand extends Command
{
    protected $signature = 'adm:backfill-bunker-visits {--since-days= : Only scan ADM files newer than N days (default: all)}';
    protected $description = 'Backfill bunker visits from ADM history (no banning, no life changes). Idempotent.';

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
        $svc = new BunkerVisitBackfillService(new AdmParser());
        $visits = new BunkerVisitService();

        $scope = $sinceDays !== null ? "last {$sinceDays} day(s)" : 'all history';
        $this->info("Backfilling bunker visits — {$scope}...");

        $result = $svc->backfillAll($client, $visits, $sinceDays, function (string $name, int $n) {
            if ($n > 0) $this->line("  {$name}: {$n} visit(s)");
        });

        $this->info("Done. {$result['files']} file(s) scanned, {$result['visits']} visit(s) recorded.");
        return self::SUCCESS;
    }
}
