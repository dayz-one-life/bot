<?php

namespace App\Console\Commands;

use App\Services\Llm\OpenRouterClient;
use App\Services\Newspaper\NewspaperComposer;
use App\Services\Newspaper\NewspaperGenerator;
use App\Services\Newspaper\WeeklyFactsBuilder;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Laracord\Console\Commands\Command;

class NewsPublishCommand extends Command
{
    protected $signature = 'news:publish {--dry-run : Preview the composed issue (same as default behaviour)} {--force : (Deprecated/no-op standalone) live posting is handled by the running bot}';

    protected $description = 'Build and preview a Tribune issue on demand.';

    public function handle(): int
    {
        try {
            $now = CarbonImmutable::now();
            $state = new BotState();

            if ($this->option('force')) {
                $this->warn('--force has no effect: a standalone command has no live Discord gateway. This always previews; the running bot does the actual weekly posting.');
            }

            $facts = (new WeeklyFactsBuilder())->build($now);
            $prose = (new NewspaperGenerator(OpenRouterClient::fromConfig()))->generate($facts);
            $issue = $state->getInt('newspaper_issue_count', 0) + 1;
            $embeds = (new NewspaperComposer())->compose($facts, $prose, $issue);

            foreach ($embeds as $embed) {
                $this->line("\n=== {$embed['title']} ===");
                $this->line($embed['description']);
            }

            $this->newLine();
            $this->info('Preview only — live Discord posting is handled automatically by the running bot on its weekly schedule. This command never posts or records state.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to build issue: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
