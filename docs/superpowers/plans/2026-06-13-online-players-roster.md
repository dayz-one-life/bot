# Online-Players Roster Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the per-event connect/disconnect feed with a single online-players roster message that is refreshed every 5 minutes and edited in place.

**Architecture:** Mirror the leaderboard subsystem — a periodic `Laracord\Services\Service` reads the DB (open `game_sessions`) via a tested read query, a pure composer builds a Discord-agnostic embed payload, and a notifier posts one message and edits it in place (message id persisted in `bot_state`). The roster is fully decoupled from ingestion; ingest keeps writing `game_sessions` via `LifeTracker`.

**Tech Stack:** Laracord v2.3.0 / Laravel Zero, PHP 8.2+, SQLite, Eloquent, Pest (Feature tests use `RefreshDatabase` + in-memory SQLite; time-dependent tests use `CarbonImmutable::setTestNow()`).

---

## File Structure

**Create:**
- `config/online.php` — feature config (channel, refresh cadence, enabled flag).
- `app/Services/Online/OnlineRosterQuery.php` — read query → rows for online players.
- `app/Services/Online/OnlineRosterComposer.php` — pure → Discord-agnostic embed payload.
- `app/Services/Online/OnlineRosterNotifier.php` — interface.
- `app/Services/Online/DiscordOnlineRosterNotifier.php` — post-or-edit one message.
- `app/Services/Online/NullOnlineRosterNotifier.php` — no-op for tests.
- `app/Services/OnlinePlayersService.php` — periodic `Service` (auto-discovered).
- `tests/Feature/OnlineRosterQueryTest.php`
- `tests/Unit/OnlineRosterComposerTest.php`

**Modify:**
- `phpunit.xml` — env pins.
- `app/Services/Adm/AdmIngestor.php` — strip connection-notifier wiring.
- `app/Services/IngestAdmService.php` — drop connection-notifier construction.
- `tests/Feature/AdmIngestorTest.php` — remove connection-announcement tests.

**Delete:**
- `app/Services/Connection/ConnectionNotifier.php`
- `app/Services/Connection/DiscordConnectionNotifier.php`
- `app/Services/Connection/NullConnectionNotifier.php`

(`app/Services/Connection/SessionDuration.php` **stays** — still imported by `StatsCommand`, `LeaderboardComposer`, and the new composer.)

---

## Task 1: Feature config

**Files:**
- Create: `config/online.php`
- Modify: `phpunit.xml` (env block, currently lines ~14-24)

- [ ] **Step 1: Create the config file**

Create `config/online.php`:

```php
<?php

return [
    'enabled' => filter_var(env('CONNECTIONS_ENABLED', true), FILTER_VALIDATE_BOOL),
    // No fallback: unset/empty channel => null => the notifier no-ops (feature off).
    'channel_id' => env('CONNECTIONS_CHANNEL_ID') ?: null,
    'refresh_minutes' => (int) env('CONNECTIONS_REFRESH_MINUTES', 5),
];
```

- [ ] **Step 2: Pin env defaults in phpunit.xml**

In `phpunit.xml`, inside the `<php>` block, replace the line:

```xml
        <env name="CONNECTIONS_MAX_AGE_MINUTES" value="10"/>
```

with:

```xml
        <env name="CONNECTIONS_ENABLED" value="true"/>
        <env name="CONNECTIONS_REFRESH_MINUTES" value="5"/>
```

(`CONNECTIONS_MAX_AGE_MINUTES` is removed because the freshness gate it fed is deleted in Task 6.)

- [ ] **Step 3: Verify config loads**

Run: `php laracord tinker --execute="echo config('online.refresh_minutes');"`
Expected: prints `5` (or your `.env` override). If tinker is unavailable, run `php -l config/online.php` and expect `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add config/online.php phpunit.xml
git commit -m "feat: add online-roster config (channel/refresh/enabled)"
```

---

## Task 2: OnlineRosterQuery (read query)

**Files:**
- Create: `app/Services/Online/OnlineRosterQuery.php`
- Test: `tests/Feature/OnlineRosterQueryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OnlineRosterQueryTest.php`:

```php
<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Online\OnlineRosterQuery;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

function rosterPlayer(string $tag): Player
{
    return Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('lists online players with session and life seconds, longest session first', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');

    $a = rosterPlayer('Alice');
    $b = rosterPlayer('Bob');
    $c = rosterPlayer('Carol');

    // Alice: open life 600 stored + open session 15:30->16:00 (1800) => life 2400, session 1800
    $al = Life::create(['player_id' => $a->id, 'started_at' => '2026-06-13T10:00:00Z', 'playtime_seconds' => 600]);
    GameSession::create(['player_id' => $a->id, 'life_id' => $al->id, 'connected_at' => '2026-06-13T15:30:00Z']);

    // Bob: open life 0 stored + open session 14:00->16:00 (7200) => life 7200, session 7200
    $bl = Life::create(['player_id' => $b->id, 'started_at' => '2026-06-13T14:00:00Z', 'playtime_seconds' => 0]);
    GameSession::create(['player_id' => $b->id, 'life_id' => $bl->id, 'connected_at' => '2026-06-13T14:00:00Z']);

    // Carol: a CLOSED session -> not online -> excluded.
    $cl = Life::create(['player_id' => $c->id, 'started_at' => '2026-06-13T10:00:00Z', 'playtime_seconds' => 100]);
    GameSession::create([
        'player_id' => $c->id, 'life_id' => $cl->id,
        'connected_at' => '2026-06-13T10:00:00Z', 'disconnected_at' => '2026-06-13T11:00:00Z',
        'duration_seconds' => 3600,
    ]);

    $rows = (new OnlineRosterQuery())->rows();

    expect($rows)->toHaveCount(2);
    expect($rows[0]['gamertag'])->toBe('Bob');          // longest session first
    expect($rows[0]['session_seconds'])->toBe(7200);
    expect($rows[0]['life_seconds'])->toBe(7200);
    expect($rows[1]['gamertag'])->toBe('Alice');
    expect($rows[1]['session_seconds'])->toBe(1800);
    expect($rows[1]['life_seconds'])->toBe(2400);
});

it('returns an empty array when nobody is online', function () {
    expect((new OnlineRosterQuery())->rows())->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/OnlineRosterQueryTest.php`
Expected: FAIL with class `App\Services\Online\OnlineRosterQuery` not found.

- [ ] **Step 3: Write minimal implementation**

Create `app/Services/Online/OnlineRosterQuery.php`:

```php
<?php

namespace App\Services\Online;

use App\Models\GameSession;
use App\Services\Life\LivePlaytime;
use Carbon\CarbonImmutable;

/**
 * Read-only snapshot of who is currently online: one row per open game_session
 * (disconnected_at IS NULL). session_seconds is elapsed-since-connect; life_seconds
 * is the open life's live playtime (stored + open session so far). Sorted by
 * longest current session first. Pure DB read — no Discord, no side effects.
 */
class OnlineRosterQuery
{
    /**
     * @return array<int, array{gamertag:string, session_seconds:int, life_seconds:int}>
     */
    public function rows(): array
    {
        $now = CarbonImmutable::now()->getTimestamp();

        $sessions = GameSession::with('player')
            ->whereNull('disconnected_at')
            ->get();

        $rows = [];
        foreach ($sessions as $session) {
            $player = $session->player;
            if (! $player) {
                continue;
            }

            $sessionSeconds = max(0, $now - $session->connected_at->getTimestamp());

            $life = $player->openLife();
            $lifeSeconds = $life ? LivePlaytime::forLife($life) : $sessionSeconds;

            $rows[] = [
                'gamertag' => $player->gamertag,
                'session_seconds' => $sessionSeconds,
                'life_seconds' => $lifeSeconds,
            ];
        }

        usort($rows, fn ($a, $b) => $b['session_seconds'] <=> $a['session_seconds']);

        return $rows;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/OnlineRosterQueryTest.php`
Expected: PASS (2 passed). PHP 8.5 `DEPR` markers in output are harmless.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Online/OnlineRosterQuery.php tests/Feature/OnlineRosterQueryTest.php
git commit -m "feat: add OnlineRosterQuery for online-players snapshot"
```

---

## Task 3: OnlineRosterComposer (pure)

**Files:**
- Create: `app/Services/Online/OnlineRosterComposer.php`
- Test: `tests/Unit/OnlineRosterComposerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/OnlineRosterComposerTest.php`:

```php
<?php

use App\Services\Online\OnlineRosterComposer;

it('composes a roster with backticked tags and durations, never @-mentioning', function () {
    $payload = (new OnlineRosterComposer())->compose([
        ['gamertag' => 'Bob', 'session_seconds' => 7200, 'life_seconds' => 7200],
        ['gamertag' => 'Alice', 'session_seconds' => 1800, 'life_seconds' => 2400],
    ]);

    expect($payload['title'])->toBe('🟢 Online — 2');
    expect($payload['description'])->toContain('`Bob` · on 2h 0m · alive 2h 0m');
    expect($payload['description'])->toContain('`Alice` · on 30m · alive 40m');
    expect($payload['description'])->not->toContain('<@'); // high-volume channel: never @-mention
});

it('shows a friendly empty state when nobody is online', function () {
    $payload = (new OnlineRosterComposer())->compose([]);

    expect($payload['title'])->toBe('🟢 Online — 0');
    expect($payload['description'])->toBe("Nobody's online right now.");
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/OnlineRosterComposerTest.php`
Expected: FAIL with class `App\Services\Online\OnlineRosterComposer` not found.

- [ ] **Step 3: Write minimal implementation**

Create `app/Services/Online/OnlineRosterComposer.php`:

```php
<?php

namespace App\Services\Online;

use App\Services\Connection\SessionDuration;

/**
 * Turns online-roster rows into a Discord-agnostic embed payload {title, description}.
 * Pure/testable — the notifier turns this into an actual Discord Embed. Players are
 * rendered as plain backticked gamertags; the roster NEVER @-mentions (high-volume,
 * frequently-edited message — the same intentional exception the old connection feed had).
 */
class OnlineRosterComposer
{
    /**
     * @param  array<int, array{gamertag:string, session_seconds:int, life_seconds:int}>  $rows
     * @return array{title:string, description:string}
     */
    public function compose(array $rows): array
    {
        if ($rows === []) {
            return [
                'title' => '🟢 Online — 0',
                'description' => "Nobody's online right now.",
            ];
        }

        $lines = [];
        foreach ($rows as $r) {
            $session = SessionDuration::human((int) $r['session_seconds']);
            $life = SessionDuration::human((int) $r['life_seconds']);
            $lines[] = "`{$r['gamertag']}` · on {$session} · alive {$life}";
        }

        return [
            'title' => '🟢 Online — '.count($rows),
            'description' => implode("\n", $lines),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/OnlineRosterComposerTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Online/OnlineRosterComposer.php tests/Unit/OnlineRosterComposerTest.php
git commit -m "feat: add OnlineRosterComposer (pure embed payload)"
```

---

## Task 4: Notifier interface + Null + Discord

**Files:**
- Create: `app/Services/Online/OnlineRosterNotifier.php`
- Create: `app/Services/Online/NullOnlineRosterNotifier.php`
- Create: `app/Services/Online/DiscordOnlineRosterNotifier.php`

No unit test (no Discord gateway in tests — `NullOnlineRosterNotifier` is the test seam, exercised in Task 5).

- [ ] **Step 1: Create the interface**

Create `app/Services/Online/OnlineRosterNotifier.php`:

```php
<?php

namespace App\Services\Online;

interface OnlineRosterNotifier
{
    /**
     * Publish (post or edit) the online roster.
     *
     * @param  array{title:string, description:string}  $payload
     */
    public function publish(array $payload): void;
}
```

- [ ] **Step 2: Create the null notifier**

Create `app/Services/Online/NullOnlineRosterNotifier.php`:

```php
<?php

namespace App\Services\Online;

class NullOnlineRosterNotifier implements OnlineRosterNotifier
{
    /** @var array{title:string, description:string}|null */
    public ?array $lastPayload = null;

    public function publish(array $payload): void
    {
        $this->lastPayload = $payload;
    }
}
```

- [ ] **Step 3: Create the Discord notifier**

Create `app/Services/Online/DiscordOnlineRosterNotifier.php`:

```php
<?php

namespace App\Services\Online;

use App\Services\State\BotState;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Embed\Embed;

/**
 * Posts the online-roster embed once and edits it in place thereafter. The live
 * message id + channel are persisted in bot_state; if the stored message is gone
 * or the channel changed, a fresh message is posted and re-stored. Entirely
 * best-effort: null client, missing channel, or any failure no-ops.
 */
class DiscordOnlineRosterNotifier implements OnlineRosterNotifier
{
    private BotState $state;

    public function __construct(private ?Discord $discord, private ?string $channelId, ?BotState $state = null)
    {
        $this->state = $state ?? new BotState();
    }

    public function publish(array $payload): void
    {
        if (! $this->discord || ! $this->channelId) {
            return;
        }

        try {
            $channel = $this->discord->getChannel($this->channelId);
            if (! $channel) {
                return;
            }

            $embed = $this->buildEmbed($payload);
            $messageId = $this->state->get('online_roster_message_id');
            $storedChannel = $this->state->get('online_roster_channel_id');

            if ($messageId && $storedChannel === $this->channelId) {
                $channel->messages->fetch($messageId)->then(
                    fn ($message) => $message->edit(MessageBuilder::new()->addEmbed($embed)),
                    fn () => $this->post($channel, $embed) // fetch failed (deleted) -> repost
                );

                return;
            }

            $this->post($channel, $embed);
        } catch (\Throwable) {
            // best-effort: never propagate to caller
        }
    }

    private function post($channel, Embed $embed): void
    {
        $channel->sendMessage(MessageBuilder::new()->addEmbed($embed))
            ->then(function ($message) {
                $this->state->set('online_roster_message_id', (string) $message->id);
                $this->state->set('online_roster_channel_id', (string) $this->channelId);
            })
            ->otherwise(fn () => null);
    }

    private function buildEmbed(array $payload): Embed
    {
        $embed = new Embed($this->discord);
        $embed->setTitle($payload['title']);

        if (! empty($payload['description'])) {
            $embed->setDescription($payload['description']);
        }

        return $embed;
    }
}
```

- [ ] **Step 4: Lint the new files**

Run: `php -l app/Services/Online/OnlineRosterNotifier.php && php -l app/Services/Online/NullOnlineRosterNotifier.php && php -l app/Services/Online/DiscordOnlineRosterNotifier.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Online/OnlineRosterNotifier.php app/Services/Online/NullOnlineRosterNotifier.php app/Services/Online/DiscordOnlineRosterNotifier.php
git commit -m "feat: add online-roster notifier (interface + null + discord post-or-edit)"
```

---

## Task 5: OnlinePlayersService (periodic Service)

**Files:**
- Create: `app/Services/OnlinePlayersService.php`
- Test: `tests/Feature/OnlinePlayersServiceTest.php`

The service is auto-discovered by Laracord from `app/Services/` (it subclasses `Laracord\Services\Service`). We test only the `compose()` seam with the null notifier (no gateway).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OnlinePlayersServiceTest.php`:

```php
<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Online\NullOnlineRosterNotifier;
use App\Services\OnlinePlayersService;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

it('composes the roster payload from open sessions and publishes it', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');

    $p = Player::create(['gamertag' => 'Zed', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-13T15:00:00Z', 'playtime_seconds' => 0]);
    GameSession::create(['player_id' => $p->id, 'life_id' => $life->id, 'connected_at' => '2026-06-13T15:00:00Z']);

    $notifier = new NullOnlineRosterNotifier();
    (new OnlinePlayersService())->compose($notifier);

    expect($notifier->lastPayload['title'])->toBe('🟢 Online — 1');
    expect($notifier->lastPayload['description'])->toContain('`Zed` · on 1h 0m · alive 1h 0m');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/OnlinePlayersServiceTest.php`
Expected: FAIL with class `App\Services\OnlinePlayersService` not found.

- [ ] **Step 3: Write minimal implementation**

Create `app/Services/OnlinePlayersService.php`:

```php
<?php

namespace App\Services;

use App\Services\Online\DiscordOnlineRosterNotifier;
use App\Services\Online\OnlineRosterComposer;
use App\Services\Online\OnlineRosterNotifier;
use App\Services\Online\OnlineRosterQuery;
use Laracord\Laracord;
use Laracord\Services\Service;

/**
 * Refreshes the online-players roster message every few minutes. Thin wiring shim
 * over the tested OnlineRosterQuery/Composer/Notifier. Read-only — not gated by
 * BAN_DRY_RUN. Auto-discovered by Laracord from app/Services/.
 */
class OnlinePlayersService extends Service
{
    /** Refresh cadence in seconds; overridden from config in the constructor. */
    protected int $interval = 300;

    /**
     * Allow no-arg instantiation in tests (parent ctor requires a bot).
     */
    public function __construct(?Laracord $bot = null)
    {
        if ($bot !== null) {
            parent::__construct($bot);
        }

        $this->interval = max(60, (int) config('online.refresh_minutes', 5) * 60);
    }

    public function handle(): void
    {
        if (! config('online.enabled', true)) {
            return;
        }

        try {
            $this->compose(new DiscordOnlineRosterNotifier($this->discord(), config('online.channel_id')));
        } catch (\Throwable $e) {
            $this->console()->error('[online] tick failed: '.$e->getMessage());
        }
    }

    /**
     * Build the payload and hand it to the notifier. Split out so tests can inject
     * a NullOnlineRosterNotifier.
     */
    public function compose(OnlineRosterNotifier $notifier): void
    {
        $rows = (new OnlineRosterQuery())->rows();
        $payload = (new OnlineRosterComposer())->compose($rows);

        $notifier->publish($payload);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/OnlinePlayersServiceTest.php`
Expected: PASS (1 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/OnlinePlayersService.php tests/Feature/OnlinePlayersServiceTest.php
git commit -m "feat: add OnlinePlayersService periodic roster refresh"
```

---

## Task 6: Remove the old connect/disconnect feed

**Files:**
- Modify: `app/Services/Adm/AdmIngestor.php`
- Modify: `app/Services/IngestAdmService.php`
- Modify: `tests/Feature/AdmIngestorTest.php` (remove lines 116–266: the `fakeConnectionNotifier` helper + 6 connection tests)
- Delete: `app/Services/Connection/ConnectionNotifier.php`, `DiscordConnectionNotifier.php`, `NullConnectionNotifier.php`

- [ ] **Step 1: Remove connection wiring from `AdmIngestor`**

In `app/Services/Adm/AdmIngestor.php`:

(a) Remove these three `use` lines:

```php
use App\Services\Connection\ConnectionNotifier;
use App\Services\Connection\NullConnectionNotifier;
use Carbon\CarbonImmutable;
```

(b) Remove the property declaration:

```php
    private ConnectionNotifier $connections;
```

(c) Change the constructor from:

```php
    public function __construct(
        private AdmParser $parser,
        private LifeTracker $tracker,
        ?PositionRecorder $positions = null,
        ?ConnectionNotifier $connections = null,
        private int $announceMaxAgeMinutes = 10,
    ) {
        $this->positions = $positions ?? new PositionRecorder();
        $this->connections = $connections ?? new NullConnectionNotifier();
    }
```

to:

```php
    public function __construct(
        private AdmParser $parser,
        private LifeTracker $tracker,
        ?PositionRecorder $positions = null,
    ) {
        $this->positions = $positions ?? new PositionRecorder();
    }
```

(d) In `tick()`, change the `processFile` call from:

```php
            $newCursor = $this->processFile($content, $cursor, $fallback, $offsetMs, $isLive);
```

to:

```php
            $newCursor = $this->processFile($content, $cursor, $fallback, $offsetMs);
```

(`$isLive` is still computed and used for the backfill gating above — leave that.)

(e) Change the `processFile` signature from:

```php
    public function processFile(string $content, int $cursor, \DateTimeImmutable $fallbackDate, int $offsetMs, bool $announce = false): int
```

to:

```php
    public function processFile(string $content, int $cursor, \DateTimeImmutable $fallbackDate, int $offsetMs): int
```

(f) Inside `processFile`, change the connect branch from:

```php
            if ($c = $this->parser->parseConnect($raw)) {
                $this->tracker->connect($c['gamertag'], $ts);
                if ($announce && $this->isFresh($ts)) {
                    $this->connections->connected($c['gamertag'], $ts);
                }
            } elseif ($d = $this->parser->parseDisconnect($raw)) {
                $closed = $this->tracker->disconnect($d['gamertag'], $ts);
                if ($announce && $this->isFresh($ts)) {
                    $this->connections->disconnected($d['gamertag'], $ts, $closed?->duration_seconds);
                }
            } elseif ($k = $this->parser->parseDeath($raw)) {
```

to:

```php
            if ($c = $this->parser->parseConnect($raw)) {
                $this->tracker->connect($c['gamertag'], $ts);
            } elseif ($d = $this->parser->parseDisconnect($raw)) {
                $this->tracker->disconnect($d['gamertag'], $ts);
            } elseif ($k = $this->parser->parseDeath($raw)) {
```

(g) Remove the now-unused `isFresh` helper entirely:

```php
    /** True when an event is recent enough to announce (suppresses stale post-restart backlog). */
    private function isFresh(\DateTimeImmutable $ts): bool
    {
        $cutoff = CarbonImmutable::now()->subMinutes($this->announceMaxAgeMinutes)->getTimestamp();

        return $ts->getTimestamp() >= $cutoff;
    }
```

- [ ] **Step 2: Remove connection wiring from `IngestAdmService`**

In `app/Services/IngestAdmService.php`, change:

```php
            $ingestor = new AdmIngestor(
                new AdmParser(),
                new LifeTracker(),
                connections: new \App\Services\Connection\DiscordConnectionNotifier(
                    $this->discord(),
                    env('CONNECTIONS_CHANNEL_ID'),
                ),
                announceMaxAgeMinutes: (int) env('CONNECTIONS_MAX_AGE_MINUTES', 10),
            );
```

to:

```php
            $ingestor = new AdmIngestor(
                new AdmParser(),
                new LifeTracker(),
            );
```

- [ ] **Step 3: Delete the three connection-notifier files**

```bash
git rm app/Services/Connection/ConnectionNotifier.php \
       app/Services/Connection/DiscordConnectionNotifier.php \
       app/Services/Connection/NullConnectionNotifier.php
```

(Leave `app/Services/Connection/SessionDuration.php` in place.)

- [ ] **Step 4: Remove the connection tests from `AdmIngestorTest`**

Open `tests/Feature/AdmIngestorTest.php` and delete everything from line 116 (the
`function fakeConnectionNotifier(): App\Services\Connection\ConnectionNotifier` line) through
the end of the file. The last kept test is the budget/backfill test that ends with:

```php
    expect(Player::where('gamertag', 'Carol')->first())->not->toBeNull();               // applied in order
});
```

Everything after that closing `});` (the `fakeConnectionNotifier` helper and the six
`it('announces ...')` / `it('does not announce ...')` / `it('... announces ...')` tests) must be
removed.

- [ ] **Step 5: Verify nothing else references the deleted classes**

Run: `grep -rn "ConnectionNotifier\|announceMaxAgeMinutes\|CONNECTIONS_MAX_AGE_MINUTES" app/ tests/`
Expected: **no output** (empty). If anything prints, remove that reference too.

- [ ] **Step 6: Run the affected suites**

Run: `./vendor/bin/pest tests/Feature/AdmIngestorTest.php`
Expected: PASS — only the non-connection ingestion tests remain and they're green.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Adm/AdmIngestor.php app/Services/IngestAdmService.php tests/Feature/AdmIngestorTest.php
git commit -m "refactor: remove per-event connect/disconnect feed (replaced by roster)"
```

---

## Task 7: Full-suite verification

**Files:** none (verification only)

- [ ] **Step 1: Run the whole test suite**

Run: `./vendor/bin/pest`
Expected: all tests PASS. PHP 8.5 `DEPR` markers are harmless; exit code 0 = green.

- [ ] **Step 2: Lint the touched/created source files**

Run: `php -l app/Services/Adm/AdmIngestor.php && php -l app/Services/IngestAdmService.php && php -l app/Services/OnlinePlayersService.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 3: Confirm the new Service is discoverable**

Run: `php laracord tinker --execute="echo (new App\Services\OnlinePlayersService() instanceof Laracord\Services\Service) ? 'ok' : 'no';"`
Expected: prints `ok` (subclass check; slash/periodic Services don't show in `php laracord list`).

- [ ] **Step 4: Final commit (if any uncommitted changes remain)**

```bash
git status
# if clean, nothing to do; otherwise:
git add -A && git commit -m "chore: online-players roster verification pass"
```

---

## Notes for the implementer

- **Stack gotchas** (from `CLAUDE.md`): periodic background work subclasses `Laracord\Services\Service` (NOT `Task`); the no-arg test ctor pattern (`if ($bot) parent::__construct($bot)`) is required because the parent ctor needs a bot. PHP 8.5 `DEPR` lines in test output are harmless.
- **Domain:** "online" = an open `game_sessions` row (`disconnected_at IS NULL`). The state machine closes a player's prior open session on reconnect (`superseded`), so there is at most one open session per player.
- **`LivePlaytime::forLife`** already adds the open session's elapsed-so-far to the life's stored `playtime_seconds`; don't double-count.
- **Mention policy:** the roster deliberately never @-mentions (high-volume, frequently-edited message) — plain backticked gamertags only, asserted by the composer test.
- **Channel reuse:** the roster posts to the same `CONNECTIONS_CHANNEL_ID` the old feed used.
```
