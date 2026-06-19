<?php

namespace App\Services;

use App\Services\Llm\OpenRouterClient;
use App\Services\Newspaper\DiscordNewspaperNotifier;
use App\Services\Newspaper\NewspaperComposer;
use App\Services\Newspaper\NewspaperGenerator;
use App\Services\Newspaper\NewspaperNotifier;
use App\Services\Newspaper\NullNewspaperNotifier;
use App\Services\Newspaper\WeeklyFactsBuilder;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Laracord\Laracord;
use Laracord\Services\Service;

/**
 * Publishes The One Life Tribune once per ISO week, at/after the configured publish moment
 * (Fri 22:00 UTC by default). Idempotent via bot_state.last_newspaper_week. Gated by go_live_at so
 * backfill never triggers an issue. Hourly tick; the publish itself is once-per-week.
 */
class NewspaperService extends Service
{
    protected int $interval = 3600;

    private BotState $state;
    private WeeklyFactsBuilder $facts;
    private NewspaperGenerator $generator;
    private ?NewspaperNotifier $notifier;

    public function __construct(
        ?Laracord $bot = null,
        ?BotState $state = null,
        ?WeeklyFactsBuilder $facts = null,
        ?NewspaperGenerator $generator = null,
        ?NewspaperNotifier $notifier = null,
    ) {
        if ($bot !== null) {
            parent::__construct($bot);
        }
        $this->state = $state ?? new BotState();
        $this->facts = $facts ?? new WeeklyFactsBuilder();
        $this->generator = $generator ?? new NewspaperGenerator(OpenRouterClient::fromConfig());
        $this->notifier = $notifier;
    }

    public function handle(): void
    {
        try {
            $this->run(CarbonImmutable::now());
        } catch (\Throwable $e) {
            $this->console()->error('[tribune] weekly issue failed: '.$e->getMessage());
        }
    }

    /** Testable core: publish if due + not already published this ISO week. */
    public function run(CarbonImmutable $now): void
    {
        if (! config('newspaper.enabled', true)) {
            return;
        }
        if (! $this->state->get('go_live_at')) {
            return; // never publish during backfill
        }
        if (! $this->due($now)) {
            return;
        }

        $weekKey = $now->format('o-\WW');
        if ($this->state->get('last_newspaper_week') === $weekKey) {
            return; // already published this week
        }

        $facts = $this->facts->build($now);
        $facts['previous_week'] = $this->facts->build($now->subWeek());
        $priorIssue = $this->decodePriorIssue();
        $prose = $this->generator->generate($facts, $priorIssue);
        $issueNumber = $this->state->getInt('newspaper_issue_count', 0) + 1;
        $embeds = (new NewspaperComposer())->compose($facts, $prose, $issueNumber);

        $this->resolveNotifier()->publish($embeds);

        $this->state->set('last_newspaper_week', $weekKey);
        $this->state->setInt('newspaper_issue_count', $issueNumber);
        $this->state->set('last_newspaper_issue', json_encode([
            'week' => $weekKey,
            'editorial' => $prose['editorial'],
            'recap' => $prose['recap'],
            'classifieds' => $prose['classifieds'],
        ]));
    }

    /** Decoded prior-issue prose ({week,editorial,recap,classifieds}), or null when unset/malformed. */
    private function decodePriorIssue(): ?array
    {
        $raw = $this->state->get('last_newspaper_issue');
        if (! $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** True once we're at/after this ISO week's publish moment (dow + utc hour). */
    public function due(CarbonImmutable $now): bool
    {
        $dow = (int) config('newspaper.publish_dow', 5);
        $hour = (int) config('newspaper.publish_hour_utc', 22);

        $moment = $now->utc()->startOfWeek(CarbonImmutable::MONDAY)
            ->addDays($dow - 1)->setTime($hour, 0, 0);

        return $now->utc()->greaterThanOrEqualTo($moment);
    }

    private function resolveNotifier(): NewspaperNotifier
    {
        if ($this->notifier !== null) {
            return $this->notifier;
        }

        $channel = config('newspaper.channel_id');

        return $channel
            ? new DiscordNewspaperNotifier($this->discord(), $channel)
            : new NullNewspaperNotifier();
    }
}
