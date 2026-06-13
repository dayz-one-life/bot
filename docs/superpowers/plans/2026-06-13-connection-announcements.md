# Connection Announcements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Post a one-line message to a dedicated Discord channel each time a player connects or disconnects (disconnect shows session length), with no @-mentions, announcing only fresh live events — never backfill replay or stale post-restart bursts.

**Architecture:** A `ConnectionNotifier` interface (Discord + Null impls) mirrors the existing `BanNotifier` seam. `LifeTracker::disconnect()` returns the `GameSession` it closed so the ingestor can read its `duration_seconds`. `AdmIngestor` calls the notifier from `processFile()`, gated by the `$isLive` flag it already computes plus a freshness window (`CONNECTIONS_MAX_AGE_MINUTES`). The notifier is wired into `IngestAdmService` via named constructor args.

**Tech Stack:** Laracord (Laravel Zero + DiscordPHP), PHP 8.2+, SQLite, Pest. TDD: failing test first, minimal impl, frequent commits.

---

## File Structure

- **Create** `app/Services/Connection/ConnectionNotifier.php` — the interface.
- **Create** `app/Services/Connection/NullConnectionNotifier.php` — no-op default.
- **Create** `app/Services/Connection/SessionDuration.php` — pure, testable `human(int $seconds): string`.
- **Create** `app/Services/Connection/DiscordConnectionNotifier.php` — posts to `CONNECTIONS_CHANNEL_ID`, no mentions (thin, not unit-tested).
- **Modify** `app/Services/Life/LifeTracker.php` — `disconnect()` returns `?GameSession`.
- **Modify** `app/Services/Adm/AdmIngestor.php` — constructor injects notifier + freshness window; `processFile()` gains `bool $announce` and announces fresh live events.
- **Modify** `app/Services/IngestAdmService.php` — build + inject `DiscordConnectionNotifier`.
- **Modify** `phpunit.xml` — pin `CONNECTIONS_MAX_AGE_MINUTES`.
- **Modify** `CLAUDE.md` — document the two env keys + the no-mention exception.
- **Test** `tests/Unit/SessionDurationTest.php`, `tests/Feature/LifeTrackerTest.php` (append), `tests/Feature/AdmIngestorTest.php` (append).

---

## Task 1: Connection notifier interface, null impl, and duration humanizer

**Files:**
- Create: `app/Services/Connection/ConnectionNotifier.php`
- Create: `app/Services/Connection/NullConnectionNotifier.php`
- Create: `app/Services/Connection/SessionDuration.php`
- Test: `tests/Unit/SessionDurationTest.php`

- [ ] **Step 1: Write the failing test for the duration humanizer**

Create `tests/Unit/SessionDurationTest.php`:

```php
<?php

use App\Services\Connection\SessionDuration;

it('humanizes session durations', function () {
    expect(SessionDuration::human(4980))->toBe('1h 23m'); // 1h 23m
    expect(SessionDuration::human(7200))->toBe('2h 0m');   // exact hours keep 0m
    expect(SessionDuration::human(780))->toBe('13m');      // under an hour
    expect(SessionDuration::human(59))->toBe('<1m');       // sub-minute
    expect(SessionDuration::human(0))->toBe('<1m');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/SessionDurationTest.php`
Expected: FAIL — `Class "App\Services\Connection\SessionDuration" not found`.

- [ ] **Step 3: Create the interface, null impl, and humanizer**

Create `app/Services/Connection/ConnectionNotifier.php`:

```php
<?php

namespace App\Services\Connection;

interface ConnectionNotifier
{
    public function connected(string $gamertag, \DateTimeImmutable $ts): void;

    public function disconnected(string $gamertag, \DateTimeImmutable $ts, ?int $sessionSeconds): void;
}
```

Create `app/Services/Connection/NullConnectionNotifier.php`:

```php
<?php

namespace App\Services\Connection;

class NullConnectionNotifier implements ConnectionNotifier
{
    public function connected(string $gamertag, \DateTimeImmutable $ts): void {}

    public function disconnected(string $gamertag, \DateTimeImmutable $ts, ?int $sessionSeconds): void {}
}
```

Create `app/Services/Connection/SessionDuration.php`:

```php
<?php

namespace App\Services\Connection;

/**
 * Formats a session length in seconds as a compact human string for chat output.
 * Kept pure/static so it has a unit test without a Discord gateway.
 */
class SessionDuration
{
    public static function human(int $seconds): string
    {
        if ($seconds < 60) {
            return '<1m';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;

        return "{$hours}h {$rem}m";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/SessionDurationTest.php`
Expected: PASS (5 assertions).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Connection/ConnectionNotifier.php app/Services/Connection/NullConnectionNotifier.php app/Services/Connection/SessionDuration.php tests/Unit/SessionDurationTest.php
git commit -m "feat: ConnectionNotifier seam + session duration humanizer"
```

---

## Task 2: `LifeTracker::disconnect()` returns the closed session

**Files:**
- Modify: `app/Services/Life/LifeTracker.php:65-74`
- Test: `tests/Feature/LifeTrackerTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/LifeTrackerTest.php` (add `use App\Models\GameSession;` at the top if not already present):

```php
it('returns the closed session with its duration on disconnect', function () {
    $tracker = new App\Services\Life\LifeTracker();
    $tracker->connect('Alice', new DateTimeImmutable('2026-06-13T10:00:00Z'));

    $closed = $tracker->disconnect('Alice', new DateTimeImmutable('2026-06-13T10:30:00Z'));

    expect($closed)->toBeInstanceOf(App\Models\GameSession::class);
    expect($closed->duration_seconds)->toBe(1800);
    expect($closed->close_reason)->toBe('clean');
});

it('returns null when disconnecting a player with no open session', function () {
    $tracker = new App\Services\Life\LifeTracker();

    $closed = $tracker->disconnect('Ghost', new DateTimeImmutable('2026-06-13T10:00:00Z'));

    expect($closed)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/LifeTrackerTest.php`
Expected: FAIL — `disconnect()` currently returns `void`/`null`, so the first test fails on `toBeInstanceOf`.

- [ ] **Step 3: Make `disconnect()` return the closed session**

In `app/Services/Life/LifeTracker.php`, replace the `disconnect()` method (lines 65-74):

```php
    public function disconnect(string $gamertag, \DateTimeImmutable $ts): ?GameSession
    {
        $player = Player::where('gamertag', $gamertag)->first();
        if (!$player) return null;
        $this->touch($player, $ts);

        if ($open = $player->openSession()) {
            $this->closeSession($open, $ts, 'clean');
            return $open; // closeSession set duration_seconds/close_reason on this instance
        }

        return null;
    }
```

(`GameSession` is already imported at the top of the file.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/LifeTrackerTest.php`
Expected: PASS (all existing + 2 new tests green).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Life/LifeTracker.php tests/Feature/LifeTrackerTest.php
git commit -m "feat: LifeTracker::disconnect returns the closed session"
```

---

## Task 3: `AdmIngestor` announces fresh live connect/disconnect

**Files:**
- Modify: `app/Services/Adm/AdmIngestor.php`
- Test: `tests/Feature/AdmIngestorTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AdmIngestorTest.php`. These use an anonymous fake notifier and `CarbonImmutable::setTestNow()` to control freshness:

```php
function fakeConnectionNotifier(): App\Services\Connection\ConnectionNotifier
{
    return new class implements App\Services\Connection\ConnectionNotifier {
        public array $events = [];

        public function connected(string $gamertag, \DateTimeImmutable $ts): void
        {
            $this->events[] = ['connect', $gamertag, null];
        }

        public function disconnected(string $gamertag, \DateTimeImmutable $ts, ?int $sessionSeconds): void
        {
            $this->events[] = ['disconnect', $gamertag, $sessionSeconds];
        }
    };
}

it('announces fresh live connect and disconnect with session length', function () {
    Carbon\CarbonImmutable::setTestNow('2026-06-13T10:30:05Z');
    $fake = fakeConnectionNotifier();

    $content = implode("\n", [
        'AdminLog started on 2026-06-13 at 09:00:00',
        '10:25:00 | Player "Alice" (id=A=) is connected',
        '10:30:00 | Player "Alice" (id=A=) has been disconnected',
    ]);

    $ingestor = new App\Services\Adm\AdmIngestor(
        new App\Services\Adm\AdmParser(),
        new App\Services\Life\LifeTracker(),
        connections: $fake,
    );
    $fallback = new DateTimeImmutable('2026-06-13T00:00:00Z');

    $ingestor->processFile($content, 0, $fallback, 0, announce: true);

    expect($fake->events)->toBe([
        ['connect', 'Alice', null],
        ['disconnect', 'Alice', 300], // 10:25 -> 10:30 = 300s
    ]);

    Carbon\CarbonImmutable::setTestNow();
});

it('does not announce during backfill (announce=false)', function () {
    Carbon\CarbonImmutable::setTestNow('2026-06-13T10:30:05Z');
    $fake = fakeConnectionNotifier();

    $content = implode("\n", [
        'AdminLog started on 2026-06-13 at 09:00:00',
        '10:25:00 | Player "Alice" (id=A=) is connected',
        '10:30:00 | Player "Alice" (id=A=) has been disconnected',
    ]);

    $ingestor = new App\Services\Adm\AdmIngestor(
        new App\Services\Adm\AdmParser(),
        new App\Services\Life\LifeTracker(),
        connections: $fake,
    );
    $fallback = new DateTimeImmutable('2026-06-13T00:00:00Z');

    // announce omitted -> defaults to false (backfill)
    $ingestor->processFile($content, 0, $fallback, 0);

    expect($fake->events)->toBe([]);

    Carbon\CarbonImmutable::setTestNow();
});

it('does not announce live events older than the freshness window', function () {
    // "now" is two hours after the events; default window is 10 minutes.
    Carbon\CarbonImmutable::setTestNow('2026-06-13T12:30:00Z');
    $fake = fakeConnectionNotifier();

    $content = implode("\n", [
        'AdminLog started on 2026-06-13 at 09:00:00',
        '10:25:00 | Player "Alice" (id=A=) is connected',
        '10:30:00 | Player "Alice" (id=A=) has been disconnected',
    ]);

    $ingestor = new App\Services\Adm\AdmIngestor(
        new App\Services\Adm\AdmParser(),
        new App\Services\Life\LifeTracker(),
        connections: $fake,
    );
    $fallback = new DateTimeImmutable('2026-06-13T00:00:00Z');

    $ingestor->processFile($content, 0, $fallback, 0, announce: true);

    expect($fake->events)->toBe([]);

    Carbon\CarbonImmutable::setTestNow();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/AdmIngestorTest.php`
Expected: FAIL — `AdmIngestor::__construct()` has no `connections` argument and `processFile()` has no `announce` argument.

- [ ] **Step 3: Update `AdmIngestor`**

In `app/Services/Adm/AdmIngestor.php`:

Add the import near the top (after the existing `use` lines):

```php
use App\Services\Connection\ConnectionNotifier;
use App\Services\Connection\NullConnectionNotifier;
use Carbon\CarbonImmutable;
```

Replace the property declaration + constructor (lines 12-20) with:

```php
    private PositionRecorder $positions;

    private ConnectionNotifier $connections;

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

In `tick()`, pass `$isLive` into `processFile`. Replace:

```php
            $newCursor = $this->processFile($content, $cursor, $fallback, $offsetMs);
```

with:

```php
            $newCursor = $this->processFile($content, $cursor, $fallback, $offsetMs, $isLive);
```

Update the `processFile` signature. Replace:

```php
    public function processFile(string $content, int $cursor, \DateTimeImmutable $fallbackDate, int $offsetMs): int
    {
```

with:

```php
    public function processFile(string $content, int $cursor, \DateTimeImmutable $fallbackDate, int $offsetMs, bool $announce = false): int
    {
```

In the per-line event dispatch, replace:

```php
            if ($c = $this->parser->parseConnect($raw)) { $this->tracker->connect($c['gamertag'], $ts); }
            elseif ($d = $this->parser->parseDisconnect($raw)) { $this->tracker->disconnect($d['gamertag'], $ts); }
            elseif ($k = $this->parser->parseDeath($raw)) { $this->tracker->death($k, $ts); }
```

with:

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
                $this->tracker->death($k, $ts);
            }
```

Add a private helper method (e.g. directly after `processFile()`):

```php
    /** True when an event is recent enough to announce (suppresses stale post-restart backlog). */
    private function isFresh(\DateTimeImmutable $ts): bool
    {
        $cutoff = CarbonImmutable::now()->subMinutes($this->announceMaxAgeMinutes)->getTimestamp();

        return $ts->getTimestamp() >= $cutoff;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/AdmIngestorTest.php`
Expected: PASS — new tests green AND the pre-existing `AdmIngestorTest` cases still pass (they call `processFile` with 4 args; `announce` defaults to `false`).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Adm/AdmIngestor.php tests/Feature/AdmIngestorTest.php
git commit -m "feat: AdmIngestor announces fresh live connect/disconnect events"
```

---

## Task 4: `DiscordConnectionNotifier`

**Files:**
- Create: `app/Services/Connection/DiscordConnectionNotifier.php`

This wrapper touches the Discord gateway, so per repo convention it is not unit-tested — verified by `php -l` and a class-load/subclass check only.

- [ ] **Step 1: Create the Discord notifier**

Create `app/Services/Connection/DiscordConnectionNotifier.php`:

```php
<?php

namespace App\Services\Connection;

use Discord\Discord;

/**
 * Posts connect/disconnect lines to the configured connections channel.
 *
 * Deliberately does NOT use PlayerMention: this is a high-volume channel, so we
 * never @-mention linked Discord users (an intentional exception to the repo's
 * "public channel posts mention" rule). Plain backticked gamertag only.
 *
 * Entirely best-effort: a null client, missing channel id, or send failure all
 * silently no-op so ingestion never breaks on a Discord hiccup.
 */
class DiscordConnectionNotifier implements ConnectionNotifier
{
    public function __construct(private ?Discord $discord, private ?string $channelId) {}

    public function connected(string $gamertag, \DateTimeImmutable $ts): void
    {
        $this->toChannel("🟢 `{$gamertag}` connected");
    }

    public function disconnected(string $gamertag, \DateTimeImmutable $ts, ?int $sessionSeconds): void
    {
        $tail = $sessionSeconds === null ? '' : ' · on for '.SessionDuration::human($sessionSeconds);
        $this->toChannel("🔴 `{$gamertag}` disconnected{$tail}");
    }

    private function toChannel(string $content): void
    {
        if (! $this->discord || ! $this->channelId) {
            return;
        }

        try {
            $channel = $this->discord->getChannel($this->channelId);

            if (! $channel) {
                return;
            }

            $channel->sendMessage($content)->otherwise(fn () => null);
        } catch (\Throwable) {
            // best-effort: never propagate to caller
        }
    }
}
```

- [ ] **Step 2: Lint and class-load check**

Run: `php -l app/Services/Connection/DiscordConnectionNotifier.php`
Expected: `No syntax errors detected`.

Run:
```bash
php -r 'require "vendor/autoload.php"; $r = new ReflectionClass(App\Services\Connection\DiscordConnectionNotifier::class); echo $r->implementsInterface(App\Services\Connection\ConnectionNotifier::class) ? "OK\n" : "BAD\n";'
```
Expected: `OK`.

- [ ] **Step 3: Commit**

```bash
git add app/Services/Connection/DiscordConnectionNotifier.php
git commit -m "feat: DiscordConnectionNotifier (no @-mentions, best-effort)"
```

---

## Task 5: Wire into `IngestAdmService`, pin env, document

**Files:**
- Modify: `app/Services/IngestAdmService.php:33-37`
- Modify: `phpunit.xml`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Inject the notifier in the ingest service**

In `app/Services/IngestAdmService.php`, replace:

```php
            $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
```

with:

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

- [ ] **Step 2: Pin the freshness default in phpunit.xml**

In `phpunit.xml`, inside the `<php>` block alongside the other `<env>` entries (e.g. after the `BOUNTY_*` lines), add:

```xml
        <env name="CONNECTIONS_MAX_AGE_MINUTES" value="10"/>
```

- [ ] **Step 3: Document the env keys and the no-mention exception in CLAUDE.md**

In `CLAUDE.md`, in the `.env keys:` paragraph (the line listing `BAN_DURATION_HOURS=12`, `BAN_DRY_RUN`, etc.), add `CONNECTIONS_CHANNEL_ID`, `CONNECTIONS_MAX_AGE_MINUTES=10` to the list.

In the **Architecture** section, under the **Gamertag rendering** bullet, append this note:

```
- **Connection announcements** — `app/Services/Connection/`: `ConnectionNotifier` (interface) +
  `DiscordConnectionNotifier` / `NullConnectionNotifier`. `IngestAdmService` posts a one-line
  `🟢 connected` / `🔴 disconnected · on for 1h 23m` to `CONNECTIONS_CHANNEL_ID` for **live, fresh**
  events only — gated by the ingestor's `$isLive` flag plus a `CONNECTIONS_MAX_AGE_MINUTES` (default
  10) freshness window that suppresses stale post-restart backlog. **Deliberately never @-mentions**
  (high-volume channel) — an intentional exception to the "public posts mention" rule above.
```

- [ ] **Step 4: Run the full suite**

Run: `./vendor/bin/pest`
Expected: all green (PHP 8.5 `DEPR` markers are harmless per CLAUDE.md). Confirm the new Connection/LifeTracker/Ingestor tests are included and passing.

- [ ] **Step 5: Lint the modified service**

Run: `php -l app/Services/IngestAdmService.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/IngestAdmService.php phpunit.xml CLAUDE.md
git commit -m "feat: wire connection announcements into ingest + docs"
```

---

## Final verification

- [ ] **Run the whole suite once more:** `./vendor/bin/pest` — all green.
- [ ] **Manual smoke (optional, requires `.env`):** set `CONNECTIONS_CHANNEL_ID`, run `php laracord`, and confirm a real connect/disconnect posts to the channel with no mention and (for disconnects) a session length.

---

## Notes for the implementer

- **Why `announce` defaults to `false`:** the catch-up tick runs while `mode` is still `backfill` (`$isLive` is read at the top of `tick()` and the flip to `live` happens at the end), and the `adm:verify` console command / existing tests call `processFile` without the flag. Defaulting to `false` keeps all of those silent without change.
- **Why freshness uses `CarbonImmutable::now()`:** it honors `CarbonImmutable::setTestNow()` so the freshness behavior is testable, per the repo's time-handling convention. Never use raw `new DateTime`.
- **No new DB columns / migrations:** announcements ride on the raw parsed log lines and the in-memory closed-session return value — nothing is persisted for this feature.
