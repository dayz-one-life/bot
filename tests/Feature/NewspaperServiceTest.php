<?php

use App\Services\Llm\OpenRouterClient;
use App\Services\Newspaper\NewspaperGenerator;
use App\Services\Newspaper\NewspaperNotifier;
use App\Services\Newspaper\WeeklyFactsBuilder;
use App\Services\NewspaperService;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function capturingNotifier(): NewspaperNotifier
{
    return new class implements NewspaperNotifier {
        public array $published = [];
        public int $calls = 0;
        public function publish(array $embeds): void { $this->calls++; $this->published = $embeds; }
    };
}

function makeService(BotState $state, NewspaperNotifier $notifier): NewspaperService
{
    Http::fake(['*' => Http::response('nope', 500)]); // force canned fallback (deterministic, no real API)
    $gen = new NewspaperGenerator(new OpenRouterClient('k', 'm', 'https://x/api/v1'));
    return new NewspaperService(null, $state, new WeeklyFactsBuilder(), $gen, $notifier);
}

it('does not publish before the weekly publish moment', function () {
    CarbonImmutable::setTestNow('2026-06-12 21:00:00'); // Friday, but BEFORE 22:00 UTC
    $state = new BotState();
    $state->set('go_live_at', '2026-01-01T00:00:00+00:00');
    $notifier = capturingNotifier();
    makeService($state, $notifier)->run(CarbonImmutable::now());
    expect($notifier->calls)->toBe(0);
    CarbonImmutable::setTestNow();
});

it('publishes once at/after the weekly moment and is idempotent', function () {
    CarbonImmutable::setTestNow('2026-06-12 22:00:00'); // Friday 22:00 UTC
    $state = new BotState();
    $state->set('go_live_at', '2026-01-01T00:00:00+00:00');
    $notifier = capturingNotifier();
    $svc = makeService($state, $notifier);

    $svc->run(CarbonImmutable::now());
    expect($notifier->calls)->toBe(1);

    $svc->run(CarbonImmutable::now()); // same week: no re-publish
    expect($notifier->calls)->toBe(1);

    CarbonImmutable::setTestNow('2026-06-19 22:00:00'); // next Friday
    $svc->run(CarbonImmutable::now());
    expect($notifier->calls)->toBe(2);
    CarbonImmutable::setTestNow();
});

it('never publishes before go_live', function () {
    CarbonImmutable::setTestNow('2026-06-12 22:00:00');
    $state = new BotState();
    $state->delete('go_live_at');
    $notifier = capturingNotifier();
    makeService($state, $notifier)->run(CarbonImmutable::now());
    expect($notifier->calls)->toBe(0);
    CarbonImmutable::setTestNow();
});

function spyGenerator(): NewspaperGenerator
{
    return new class(new OpenRouterClient('k', 'm', 'https://x/api/v1')) extends NewspaperGenerator {
        public array $lastFacts = [];
        public ?array $lastPrior = null;
        public function generate(array $facts, ?array $priorIssue = null): array
        {
            $this->lastFacts = $facts;
            $this->lastPrior = $priorIssue;
            return ['editorial' => 'NEW ED', 'recap' => 'NEW RECAP', 'classifieds' => 'NEW ADS'];
        }
    };
}

it('passes the prior issue + previous_week facts to the generator and persists the new prose', function () {
    CarbonImmutable::setTestNow('2026-06-19 22:00:00'); // Friday 22:00 UTC → ISO 2026-W25
    $state = new BotState();
    $state->set('go_live_at', '2026-01-01T00:00:00+00:00');
    $state->set('last_newspaper_issue', json_encode([
        'week' => '2026-W24', 'editorial' => 'OLD ED', 'recap' => 'OLD RECAP', 'classifieds' => 'OLD ADS',
    ]));
    $notifier = capturingNotifier();
    $gen = spyGenerator();

    (new NewspaperService(null, $state, new WeeklyFactsBuilder(), $gen, $notifier))->run(CarbonImmutable::now());

    expect($notifier->calls)->toBe(1);
    expect($gen->lastPrior['recap'])->toBe('OLD RECAP');        // prior issue handed to generator
    expect($gen->lastFacts)->toHaveKey('previous_week');         // prior-week facts built

    $stored = json_decode($state->get('last_newspaper_issue'), true);
    expect($stored['week'])->toBe('2026-W25');                   // persisted under THIS week
    expect($stored['recap'])->toBe('NEW RECAP');
    CarbonImmutable::setTestNow();
});

it('treats a malformed stored prior issue as none', function () {
    CarbonImmutable::setTestNow('2026-06-19 22:00:00');
    $state = new BotState();
    $state->set('go_live_at', '2026-01-01T00:00:00+00:00');
    $state->set('last_newspaper_issue', 'not json{');
    $notifier = capturingNotifier();
    $gen = spyGenerator();

    (new NewspaperService(null, $state, new WeeklyFactsBuilder(), $gen, $notifier))->run(CarbonImmutable::now());

    expect($gen->lastPrior)->toBeNull();
    CarbonImmutable::setTestNow();
});

it('does not publish when the feature is disabled', function () {
    config()->set('newspaper.enabled', false);
    CarbonImmutable::setTestNow('2026-06-12 22:00:00'); // Friday 22:00 UTC — otherwise due
    $state = new BotState();
    $state->set('go_live_at', '2026-01-01T00:00:00+00:00');
    $notifier = capturingNotifier();
    makeService($state, $notifier)->run(CarbonImmutable::now());
    expect($notifier->calls)->toBe(0);
    config()->set('newspaper.enabled', true);
    CarbonImmutable::setTestNow();
});
