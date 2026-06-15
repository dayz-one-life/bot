<?php

namespace App\Console\Commands;

use App\Services\Llm\OpenRouterClient;
use App\Services\NewspaperService;
use App\Services\Newspaper\DiscordNewspaperNotifier;
use App\Services\Newspaper\NewspaperComposer;
use App\Services\Newspaper\NewspaperGenerator;
use App\Services\Newspaper\NullNewspaperNotifier;
use App\Services\Newspaper\WeeklyFactsBuilder;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Laracord\Console\Commands\Command;

class NewsPublishCommand extends Command
{
    protected $signature = 'news:publish {--dry-run : Print the composed issue instead of posting} {--force : Ignore the weekly rollover guard}';

    protected $description = 'Build and publish (or preview) a Tribune issue on demand.';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $state = new BotState();

        if ($this->option('dry-run')) {
            // Headless preview — build, generate, compose, print. No bot, no state writes.
            $facts = (new WeeklyFactsBuilder())->build($now);
            $prose = (new NewspaperGenerator(OpenRouterClient::fromConfig()))->generate($facts);
            $issue = $state->getInt('newspaper_issue_count', 0) + 1;
            $embeds = (new NewspaperComposer())->compose($facts, $prose, $issue);

            foreach ($embeds as $embed) {
                $this->line("\n=== {$embed['title']} ===");
                $this->line($embed['description']);
            }

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            // Build and publish immediately, bypassing the weekly rollover guard.
            $facts = (new WeeklyFactsBuilder())->build($now);
            $prose = (new NewspaperGenerator(OpenRouterClient::fromConfig()))->generate($facts);
            $issue = $state->getInt('newspaper_issue_count', 0) + 1;
            $embeds = (new NewspaperComposer())->compose($facts, $prose, $issue);

            // No bot accessor available in a console command — resolve via config only.
            // DiscordNewspaperNotifier no-ops when discord is null; use Null when no channel set.
            $channel = config('newspaper.channel_id');
            $notifier = $channel
                ? new DiscordNewspaperNotifier(null, $channel)
                : new NullNewspaperNotifier();

            $notifier->publish($embeds);

            $state->set('last_newspaper_week', $now->format('o-\WW'));
            $state->setInt('newspaper_issue_count', $issue);

            $this->info("Published issue {$issue}.");
            return self::SUCCESS;
        }

        // Default: delegate to the service's normal rollover check.
        (new NewspaperService(null, $state))->run($now);
        $this->info('Publish check complete.');
        return self::SUCCESS;
    }
}
