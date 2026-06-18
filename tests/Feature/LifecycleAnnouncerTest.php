<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Lifecycle\AnnouncementGenerator;
use App\Services\Lifecycle\LifecycleAnnouncer;
use App\Services\Lifecycle\LifecycleNotifier;
use App\Services\Llm\OpenRouterClient;
use App\Services\Personality\MessagePicker;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class RecordingLifecycleNotifier implements LifecycleNotifier {
    public array $births = [];
    public array $eulogies = [];
    public function publishBirth(array $payload): void { $this->births[] = $payload; }
    public function publishEulogy(array $payload): void { $this->eulogies[] = $payload; }
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-14T12:00:00Z');
    Http::fake(); // no api key in tests => generator falls back; never calls out
    MessagePicker::reset();
    $this->state = new BotState();
    $this->state->set('go_live_at', '2026-06-14T08:00:00+00:00');
    $this->notifier = new RecordingLifecycleNotifier();
});
afterEach(fn () => CarbonImmutable::setTestNow());

function makeAnnouncer($state, $notifier): LifecycleAnnouncer {
    $gen = new AnnouncementGenerator(OpenRouterClient::fromConfig(), new MessagePicker(fn ($p, $a) => 0));
    return new LifecycleAnnouncer($gen, $notifier, $state, graceSeconds: 300, maxAgeMinutes: 30);
}

// A life with a single CLOSED session of $playtime seconds, ended (death) or still open.
function lifeWith(string $tag, int $playtime, ?string $endedAt, ?string $startedAt = null): Life {
    $p = Player::firstOrCreate(['gamertag' => $tag], ['first_seen_at' => now(), 'last_seen_at' => now()]);
    $start = $startedAt ?? '2026-06-14T11:50:00Z';
    $life = Life::create([
        'player_id' => $p->id, 'started_at' => $start, 'ended_at' => $endedAt,
        'death_cause' => $endedAt ? 'pvp' : null, 'death_by_gamertag' => $endedAt ? 'Sniper' : null,
        'playtime_seconds' => $playtime,
    ]);
    GameSession::create([
        'player_id' => $p->id, 'life_id' => $life->id, 'connected_at' => $start,
        'disconnected_at' => $endedAt ?? CarbonImmutable::parse($start)->addSeconds($playtime),
        'duration_seconds' => $playtime,
    ]);
    return $life;
}

it('announces a birth for an open life past the grace window and marks it', function () {
    $life = lifeWith('Sticky', 360, null); // 6 min playtime, still alive
    makeAnnouncer($this->state, $this->notifier)->run();

    expect($this->notifier->births)->toHaveCount(1);
    expect($life->fresh()->birth_announced_at)->not->toBeNull();
    // A newborn carries no stat fields (its "age" is always just the grace window).
    expect($this->notifier->births[0]['fields'])->toBe([]);
    expect($this->notifier->births[0]['title'])->not->toContain('<@');
});

it('does NOT announce a birth before the grace window', function () {
    lifeWith('TooNew', 120, null); // 2 min
    makeAnnouncer($this->state, $this->notifier)->run();
    expect($this->notifier->births)->toBeEmpty();
});

it('eulogizes a real death (>= grace) and marks eulogy_posted', function () {
    $life = lifeWith('Fallen', 2460, '2026-06-14T11:58:00Z'); // 41 min, died 2 min ago
    makeAnnouncer($this->state, $this->notifier)->run();

    expect($this->notifier->eulogies)->toHaveCount(1);
    expect($life->fresh()->eulogy_posted)->toBeTrue();
});

it('does NOT eulogize a reroll death under the grace window', function () {
    lifeWith('Reroll', 40, '2026-06-14T11:59:00Z'); // 40s life, died 1 min ago
    makeAnnouncer($this->state, $this->notifier)->run();
    expect($this->notifier->eulogies)->toBeEmpty();
});

it('does not announce births/eulogies for events before go_live', function () {
    lifeWith('OldDeath', 3000, '2026-06-14T07:00:00Z', '2026-06-14T06:00:00Z'); // before go_live
    makeAnnouncer($this->state, $this->notifier)->run();
    expect($this->notifier->eulogies)->toBeEmpty();
    expect($this->notifier->births)->toBeEmpty();
});

it('does not announce stale eulogies past the freshness window', function () {
    lifeWith('Stale', 3000, '2026-06-14T11:00:00Z', '2026-06-14T10:00:00Z'); // died 60 min ago, window 30
    makeAnnouncer($this->state, $this->notifier)->run();
    expect($this->notifier->eulogies)->toBeEmpty();
});

it('is idempotent across ticks', function () {
    lifeWith('Once', 2460, '2026-06-14T11:58:00Z');
    $a = makeAnnouncer($this->state, $this->notifier);
    $a->run();
    $a->run();
    expect($this->notifier->eulogies)->toHaveCount(1);
});

it('pings linked players on the content line, not unlinked', function () {
    $p = Player::where('gamertag', 'LinkedDead')->first()
        ?? Player::create(['gamertag' => 'LinkedDead', 'discord_user_id' => '555', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T11:50:00Z', 'ended_at' => '2026-06-14T11:58:00Z', 'death_cause' => 'pvp', 'playtime_seconds' => 480]);
    GameSession::create(['player_id' => $p->id, 'life_id' => $life->id, 'connected_at' => '2026-06-14T11:50:00Z', 'disconnected_at' => '2026-06-14T11:58:00Z', 'duration_seconds' => 480]);

    makeAnnouncer($this->state, $this->notifier)->run();

    $e = $this->notifier->eulogies[0];
    expect($e['ping'])->toContain('<@555>');                 // real mention only on the content line
    expect($e['description'])->not->toContain('{{PLAYER}}');  // placeholder substituted
    // The embed itself uses PLAIN gamertags — a <@id> would render as raw text in the title.
    expect($e['title'])->not->toContain('<@');
    expect($e['description'])->not->toContain('<@');
    expect($e['title'].$e['description'])->toContain('LinkedDead');
});

it('also pings a linked killer on the eulogy content line', function () {
    // lifeWith() sets death_by_gamertag => 'Sniper' for an ended life. Link Sniper so the eulogy
    // ping notifies the killer too; the victim here is unlinked, isolating the killer ping.
    Player::create(['gamertag' => 'Sniper', 'discord_user_id' => '777', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    lifeWith('FreshVictim', 600, '2026-06-14T11:58:00Z');

    makeAnnouncer($this->state, $this->notifier)->run();

    expect($this->notifier->eulogies[0]['ping'])->toContain('<@777>'); // killer notified
});

it('posts BOTH a eulogy for the dead life and a birth for an immediate respawn', function () {
    // Normal player behavior: die, then click respawn and start over. The dead life (A) earns
    // its eulogy; the fresh respawn (B) earns its own birth the moment it passes the grace mark.
    // These are independent lives — the birth is NOT suppressed just because a death just happened.
    $lifeA = lifeWith('Comeback', 2460, '2026-06-14T11:58:00Z', '2026-06-14T11:10:00Z'); // 41 min, just died
    $lifeA->update(['birth_announced_at' => '2026-06-14T11:15:00Z']); // A was already born earlier in its own life
    $lifeB = lifeWith('Comeback', 360, null, '2026-06-14T11:58:30Z'); // respawn, 6 min in, still alive

    makeAnnouncer($this->state, $this->notifier)->run();

    expect($this->notifier->eulogies)->toHaveCount(1);            // for the life that died
    expect($this->notifier->births)->toHaveCount(1);             // for the immediate respawn
    expect($lifeB->fresh()->birth_announced_at)->not->toBeNull();
});

it('strips any unsubstituted placeholder token the LLM leaves behind', function () {
    // A birth has no killer to substitute, but the model can still emit a stray {{KILLER}} (e.g. when
    // narrating the prior life). The published copy must never contain a raw "{{...}}" token.
    lifeWith('Returner', 360, null); // open life past grace

    // A generator that emits copy with a stray {{KILLER}} the announcer cannot map to a name.
    $gen = new class(OpenRouterClient::fromConfig()) extends AnnouncementGenerator {
        public function generate(string $kind, array $facts): array {
            return ['headline' => 'REBORN — {{KILLER}} WEEPS', 'body' => '{{PLAYER}} returns, cut short last time by {{KILLER}}. Welcome back.'];
        }
    };
    $announcer = new LifecycleAnnouncer($gen, $this->notifier, $this->state, graceSeconds: 300, maxAgeMinutes: 30);
    $announcer->run();

    $birth = $this->notifier->births[0];
    expect($birth['description'])->not->toContain('{{');
    expect($birth['description'])->not->toContain('}}');
    expect($birth['title'])->not->toContain('{{');
    expect($birth['description'])->toContain('Returner'); // {{PLAYER}} still substituted
});

it('does NOT birth an immediate respawn that rerolls under the grace mark', function () {
    // The flip side: a respawn that suicides again before 5 min (spawn reroll) gets no birth.
    $lifeA = lifeWith('Rerollman', 2460, '2026-06-14T11:58:00Z', '2026-06-14T11:10:00Z');
    $lifeA->update(['birth_announced_at' => '2026-06-14T11:15:00Z']);
    lifeWith('Rerollman', 90, '2026-06-14T11:59:45Z', '2026-06-14T11:58:15Z'); // 90s reroll, died

    makeAnnouncer($this->state, $this->notifier)->run();

    expect($this->notifier->eulogies)->toHaveCount(1); // only the real life A
    expect($this->notifier->births)->toBeEmpty();      // the 90s reroll never reached grace
});
