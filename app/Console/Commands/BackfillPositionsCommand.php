<?php

namespace App\Console\Commands;

use App\Services\Adm\AdmParser;
use App\Services\Adm\PositionBackfillService;
use App\Services\Nitrado\NitradoClient;
use Laracord\Console\Commands\Command;

class BackfillPositionsCommand extends Command
{
    protected $signature = 'adm:backfill-positions {--keep : Append to existing positions instead of truncating first} {--since-days= : Only backfill ADM files newer than N days (default: all)}';
    protected $description = 'Backfill player position samples from ADM history (no banning, no life changes).';

    public function handle(): int
    {
        $token = env('NITRADO_TOKEN');
        $serviceId = (int) env('NITRADO_SERVICE_ID');
        if (! $token || ! $serviceId) {
            $this->error('Set NITRADO_TOKEN and NITRADO_SERVICE_ID in .env first.');
            return self::FAILURE;
        }

        $fresh = ! $this->option('keep');
        $sinceDays = $this->option('since-days') !== null ? (int) $this->option('since-days') : null;

        $client = new NitradoClient($token, $serviceId);
        $svc = new PositionBackfillService(new AdmParser());

        $scope = $sinceDays !== null ? "last {$sinceDays} day(s)" : 'all history';
        $mode = $fresh ? 'fresh (truncate first)' : 'append';
        $this->info("Backfilling positions — {$scope}, {$mode}...");

        $result = $svc->backfillAll($client, $sinceDays, $fresh, function (string $name, int $n) {
            $this->line("  {$name}: {$n} positions");
        });

        $this->info("Done. {$result['files']} file(s), {$result['positions']} position(s) inserted.");
        return self::SUCCESS;
    }
}
