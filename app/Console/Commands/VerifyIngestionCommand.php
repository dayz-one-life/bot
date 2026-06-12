<?php

namespace App\Console\Commands;

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Adm\AdmIngestor;
use App\Services\Adm\AdmParser;
use App\Services\Life\LifeTracker;
use App\Services\Nitrado\NitradoClient;
use App\Services\State\BotState;
use Laracord\Console\Commands\Command;

class VerifyIngestionCommand extends Command
{
    protected $signature = 'adm:verify {--ticks=200 : Max ingestion ticks to run} {--budget=50 : Files to drain per tick}';
    protected $description = 'Run a full ADM backfill (no banning) and print a life/playtime/death report.';

    public function handle(): int
    {
        $token = env('NITRADO_TOKEN');
        $serviceId = (int) env('NITRADO_SERVICE_ID');
        if (!$token || !$serviceId) {
            $this->error('Set NITRADO_TOKEN and NITRADO_SERVICE_ID in .env first.');
            return self::FAILURE;
        }

        $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
        $client = new NitradoClient($token, $serviceId);
        $state = new BotState();

        $maxTicks = (int) $this->option('ticks');
        $budget = (int) $this->option('budget');

        $this->info('Backfilling...');
        for ($i = 0; $i < $maxTicks; $i++) {
            $ingestor->tick($client, $state, $budget);
            if ($state->get('mode') === 'live') {
                $this->info("Caught up after ".($i + 1)." tick(s).");
                break;
            }
        }

        $this->report();
        return self::SUCCESS;
    }

    private function report(): void
    {
        $players = Player::count();
        $lives = Life::count();
        $openLives = Life::whereNull('ended_at')->count();
        $sessions = GameSession::count();
        $playHours = round((int) Life::sum('playtime_seconds') / 3600, 1);

        $this->line('');
        $this->line("Players:        {$players}");
        $this->line("Lives:          {$lives} ({$openLives} still alive)");
        $this->line("Sessions:       {$sessions}");
        $this->line("Total playtime: {$playHours} h");
        $this->line('');

        $this->line('Deaths by cause:');
        Life::whereNotNull('death_cause')
            ->selectRaw('death_cause, count(*) as c')
            ->groupBy('death_cause')->orderByDesc('c')->get()
            ->each(fn ($r) => $this->line("  {$r->death_cause}: {$r->c}"));

        $this->line('');
        $this->line('Top 5 by total playtime:');
        Player::query()
            ->select('players.gamertag')
            ->selectRaw('(select sum(playtime_seconds) from lives where lives.player_id = players.id) as secs')
            ->orderByDesc('secs')->limit(5)->get()
            ->each(fn ($p) => $this->line("  {$p->gamertag}: ".round(((int) $p->secs) / 3600, 1)."h"));
    }
}
