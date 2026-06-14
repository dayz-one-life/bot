# Births, LLM Eulogies & Playtime-Gated Bans — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Only ban a player when they die after ≥60 min of playtime; announce "births" of sticky new lives to a births channel; and replace the death feed with substantial, funny, newspaper-style LLM-generated eulogies — all driven by a single "real life" grace threshold that de-dupes spawn-reroll suicides.

**Architecture:** A life "counts" once `playtime_seconds ≥ LIFE_GRACE_MINUTES` (5 min) — that gates births and eulogies and kills reroll-suicide noise. Banning uses a higher gate (`BAN_MIN_PLAYTIME_MINUTES`, 60 min). A new `LifecycleAnnounceService` (60s) scans for due births and eulogies, gated by `go_live_at` + a freshness window (same patterns the death feed already uses), generates copy via an OpenRouter LLM (`app/Services/Llm/OpenRouterClient`) from a hybrid of captured raw ADM log + structured facts, and posts a rich newspaper-style embed with the real `<@id>` ping on a plain content line. On any LLM failure it falls back to canned personality pools. The old `app/Services/DeathFeed/` subsystem is retired.

**Tech Stack:** Laracord v2.3 on Laravel Zero, PHP 8.2+, SQLite, Pest. Outbound HTTP via the `Illuminate\Support\Facades\Http` facade (fakeable). Discord via DiscordPHP `Embed`/`MessageBuilder`. Time via `Carbon\CarbonImmutable`.

**Reference the spec:** `docs/superpowers/specs/2026-06-14-births-eulogies-playtime-bans-design.md`

**Prerequisites:** Run `composer install` first if `vendor/` is absent — every `./vendor/bin/pest`
and `php laracord` command below depends on it. Work on the `feat/births-eulogies-playtime-bans`
branch (already created during brainstorming).

---

## File Structure

**Create:**
- `database/migrations/2026_06_14_010000_add_lifecycle_columns_to_lives.php` — `death_log`, `birth_announced_at`, `eulogy_posted`
- `config/lifecycle.php` — grace, ban gate, channels, enabled, freshness
- `config/llm.php` — OpenRouter key/model/base/timeout
- `app/Services/Adm/DeathLogCapturer.php` — PURE: filter a raw-line buffer to the victim's death window
- `app/Services/Lifecycle/LifeFactsBuilder.php` — PURE: `Life` → structured facts array
- `app/Services/Lifecycle/MentionSubstitutor.php` — PURE: replace `{{PLAYER}}`/`{{KILLER}}` placeholders
- `app/Services/Lifecycle/AnnouncementGenerator.php` — prompt build + LLM call + canned fallback
- `app/Services/Lifecycle/LifecycleNotifier.php` — interface
- `app/Services/Lifecycle/DiscordLifecycleNotifier.php` — embed + content-ping poster
- `app/Services/Lifecycle/NullLifecycleNotifier.php` — no-op
- `app/Services/Lifecycle/LifecycleAnnouncer.php` — scan/gate/idempotency, drives generator + notifier
- `app/Services/LifecycleAnnounceService.php` — thin periodic Service (auto-discovered)
- `app/Services/Llm/OpenRouterClient.php` — Http-facade wrapper
- Tests: `tests/Unit/DeathLogCapturerTest.php`, `tests/Unit/LifeFactsBuilderTest.php`, `tests/Unit/MentionSubstitutorTest.php`, `tests/Feature/OpenRouterClientTest.php`, `tests/Feature/AnnouncementGeneratorTest.php`, `tests/Feature/LifecycleAnnouncerTest.php`

**Modify:**
- `config/personality.php` — add `birth.*` and `eulogy.*` fallback pools; remove `death.*` pools
- `app/Services/Life/LifeTracker.php:35-58` — `death()` accepts an optional `?string $log`
- `app/Services/Adm/AdmIngestor.php` — maintain a raw-line ring buffer; pass captured log into `death()`
- `app/Services/Ban/DeathBanService.php` — add playtime gate; drop the death-feed dependency
- `app/Services/IngestAdmService.php:42-60` — stop constructing the death feed; pass the ban-min-playtime
- `phpunit.xml` — pin new config defaults
- `.env.example` — document new keys

**Delete (Task 13):**
- `app/Services/DeathFeed/DeathFeedComposer.php`, `DeathFeedNotifier.php`, `DiscordDeathFeedNotifier.php`, `NullDeathFeedNotifier.php`
- `tests/Unit/DeathFeedComposerTest.php`

---

## Task 1: Migration — lifecycle columns on `lives`

**Files:**
- Create: `database/migrations/2026_06_14_010000_add_lifecycle_columns_to_lives.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lives', function (Blueprint $t) {
            $t->text('death_log')->nullable()->after('death_distance');
            $t->timestamp('birth_announced_at')->nullable()->after('death_log');
            $t->boolean('eulogy_posted')->default(false)->after('birth_announced_at');
        });
    }

    public function down(): void
    {
        Schema::table('lives', function (Blueprint $t) {
            $t->dropColumn(['death_log', 'birth_announced_at', 'eulogy_posted']);
        });
    }
};
```

- [ ] **Step 2: Run the migration against the dev DB**

Run: `php laracord migrate`
Expected: migration runs without error (a harmless PHP 8.5 `DEPR` line may print; exit 0 is green).

- [ ] **Step 3: Add casts to the `Life` model**

In `app/Models/Life.php`, extend the `$casts` array so the new columns hydrate correctly:

```php
    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'playtime_seconds' => 'integer',
        'ban_issued' => 'boolean',
        'death_distance' => 'float',
        'birth_announced_at' => 'datetime',
        'eulogy_posted' => 'boolean',
    ];
```

- [ ] **Step 4: Verify the suite still migrates and passes**

Run: `./vendor/bin/pest --filter=LifeTracker`
Expected: PASS (the in-memory DB picks up the new migration).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_14_010000_add_lifecycle_columns_to_lives.php app/Models/Life.php
git commit -m "feat: add death_log, birth_announced_at, eulogy_posted to lives"
```

---

## Task 2: Config files + phpunit pins + .env.example

**Files:**
- Create: `config/lifecycle.php`, `config/llm.php`
- Modify: `phpunit.xml`, `.env.example`

- [ ] **Step 1: Create `config/lifecycle.php`**

```php
<?php

return [
    'enabled' => filter_var(env('LIFECYCLE_ENABLED', true), FILTER_VALIDATE_BOOL),

    // A life "counts" (birth announced, death eulogized) once it accrues this much playtime.
    'grace_minutes' => (int) env('LIFE_GRACE_MINUTES', 5),

    // Only ban a death if the life had at least this much playtime.
    'ban_min_playtime_minutes' => (int) env('BAN_MIN_PLAYTIME_MINUTES', 60),

    // Suppress post-downtime backlog: only announce births/eulogies for recent events.
    'max_age_minutes' => (int) env('LIFECYCLE_MAX_AGE_MINUTES', 30),

    // No fallback: unset/empty channel => null => the notifier no-ops for that feed.
    'births_channel_id' => env('BIRTHS_CHANNEL_ID') ?: null,
    'eulogy_channel_id' => env('EULOGY_CHANNEL_ID') ?: null,

    // How often the announce service scans (seconds floor 60).
    'refresh_minutes' => (int) env('LIFECYCLE_REFRESH_MINUTES', 1),
];
```

- [ ] **Step 2: Create `config/llm.php`**

```php
<?php

return [
    'api_key' => env('OPENROUTER_API_KEY') ?: null,
    'model' => env('OPENROUTER_MODEL', 'anthropic/claude-haiku-4.5'),
    'base_url' => rtrim(env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),
    'timeout_seconds' => (int) env('OPENROUTER_TIMEOUT_SECONDS', 20),
    'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 900),
    'temperature' => (float) env('OPENROUTER_TEMPERATURE', 1.0),
];
```

- [ ] **Step 3: Pin defaults in `phpunit.xml`**

Add these `<env>` lines inside the existing `<php>` block (next to the other pinned defaults around line 27):

```xml
        <env name="LIFECYCLE_ENABLED" value="true"/>
        <env name="LIFE_GRACE_MINUTES" value="5"/>
        <env name="BAN_MIN_PLAYTIME_MINUTES" value="60"/>
        <env name="LIFECYCLE_MAX_AGE_MINUTES" value="30"/>
        <env name="OPENROUTER_MODEL" value="anthropic/claude-haiku-4.5"/>
        <env name="OPENROUTER_TIMEOUT_SECONDS" value="20"/>
        <env name="OPENROUTER_MAX_TOKENS" value="900"/>
        <env name="OPENROUTER_TEMPERATURE" value="1.0"/>
```

(Leave `OPENROUTER_API_KEY`, `BIRTHS_CHANNEL_ID`, `EULOGY_CHANNEL_ID` unset in tests — unset → fallback / no-op, which is what the tests exercise.)

- [ ] **Step 4: Document new keys in `.env.example`**

Append this block to `.env.example`:

```bash

# --- Lifecycle: births + eulogies + playtime-gated bans ---
LIFECYCLE_ENABLED=true
LIFE_GRACE_MINUTES=5            # a life counts (birth/eulogy) after this much playtime
BAN_MIN_PLAYTIME_MINUTES=60     # only ban a death after this much playtime
LIFECYCLE_MAX_AGE_MINUTES=30    # freshness gate to suppress post-downtime backlog
BIRTHS_CHANNEL_ID=              # unset => no birth posts
EULOGY_CHANNEL_ID=              # unset => no eulogy posts (replaces the old death feed)

# OpenRouter (LLM) — unset key => every post uses the canned fallback automatically
OPENROUTER_API_KEY=
OPENROUTER_MODEL=anthropic/claude-haiku-4.5
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_TIMEOUT_SECONDS=20
OPENROUTER_MAX_TOKENS=900
OPENROUTER_TEMPERATURE=1.0
```

- [ ] **Step 5: Verify config loads**

Run: `php -r "require 'vendor/autoload.php'; \$a=require 'config/lifecycle.php'; \$b=require 'config/llm.php'; var_dump(\$a['grace_minutes'], \$b['model']);"`
Expected: `int(5)` and `string(...) "anthropic/claude-haiku-4.5"`.

- [ ] **Step 6: Commit**

```bash
git add config/lifecycle.php config/llm.php phpunit.xml .env.example
git commit -m "feat: lifecycle + llm config and env docs"
```

---

## Task 3: Personality pools — add `birth.*` and `eulogy.*` fallbacks

**Files:**
- Modify: `config/personality.php`

These pools are the **fallback copy** used only when OpenRouter is unavailable. They use the
`{{PLAYER}}` / `{{KILLER}}` placeholders (substituted later) so the same pipeline applies. Each pool
entry is `['headline' => ..., 'body' => ...]` so a fallback yields a full newspaper-shaped post.

- [ ] **Step 1: Add the pools**

In `config/personality.php`, inside the top-level `return [ ... ]` array (e.g. after the existing
`'death' => [...]` block — which is removed in Task 13, but adding alongside now is fine), add:

```php
    'birth' => [
        'fallback' => [
            ['headline' => '👶 A NEW SOUL STUMBLES ONTO THE COAST', 'body' => "📰 *Chernarus, today* — {{PLAYER}} has clawed their way back into the land of the living. The locals are unimpressed. The bears are hungry. Welcome to the one life, again."],
            ['headline' => '🎉 IT LIVES! {{PLAYER}} RESPAWNS', 'body' => "📰 Against all odds and most of their better judgment, {{PLAYER}} draws breath once more on the coast. Bookmakers are already taking bets on the cause of death."],
            ['headline' => '🌅 FRESH MEAT REPORTS FOR DUTY', 'body' => "📰 A brand-new {{PLAYER}} blinks awake on the shoreline with nothing but a flashlight and unearned confidence. History suggests this ends poorly."],
            ['headline' => '🧍 ANOTHER OPTIMIST ENTERS THE WORLD', 'body' => "📰 {{PLAYER}} has spawned. The coast welcomes them with damp socks and the distant sound of gunfire. Good luck out there — you'll need it."],
            ['headline' => '🐣 THE CYCLE CONTINUES', 'body' => "📰 {{PLAYER}} is alive. For now. Survivors are advised to introduce themselves quickly, before the introduction becomes an obituary."],
        ],
    ],

    'eulogy' => [
        'pvp' => [
            ['headline' => '💀 {{PLAYER}} DROPPED — {{KILLER}} DECLINES COMMENT', 'body' => "📰 *Obituary* — {{PLAYER}} met their end at the hands of {{KILLER}}. A life of promise, ended with grim efficiency. They are survived by their loot, which {{KILLER}} now owns."],
            ['headline' => '⚰️ THE LATE {{PLAYER}}: A LIFE, INTERRUPTED', 'body' => "📰 {{KILLER}} has ended the storied run of {{PLAYER}}. Witnesses report it was over quickly. Funeral arrangements are pending; the body is currently being looted."],
            ['headline' => '🪦 {{PLAYER}} LOGS OFF PERMANENTLY', 'body' => "📰 In a development surprising no one, {{PLAYER}} has died — courtesy of {{KILLER}}. The coast observes a moment of silence, then resumes shooting."],
        ],
        'suicide' => [
            ['headline' => '💀 {{PLAYER}} BEATS THE QUEUE, TAKES OWN LIFE', 'body' => "📰 *Obituary* — {{PLAYER}} has died by their own hand, cutting out the middleman entirely. Efficient. Bleak. On brand for Chernarus."],
            ['headline' => '⚰️ {{PLAYER}} DECIDED THE LOBBY LOOKED NICER', 'body' => "📰 No killer required. {{PLAYER}} handled their own departure. The community is not so much mourning as quietly nodding."],
            ['headline' => '🪦 {{PLAYER}} OPTS OUT', 'body' => "📰 {{PLAYER}} has self-deleted from the living. We hardly knew ye, and apparently neither did ye."],
        ],
        'environment' => [
            ['headline' => '💀 {{PLAYER}} VS. THE MAP: THE MAP WINS', 'body' => "📰 *Obituary* — {{PLAYER}} was claimed not by a player but by Chernarus itself. No killcam. No glory. Just the quiet indignity of the great outdoors."],
            ['headline' => '🐻 NATURE 1, {{PLAYER}} 0', 'body' => "📰 {{PLAYER}} lost a disagreement with the environment. The environment was unavailable for comment, being a fall, a wolf, or simple bad luck."],
            ['headline' => '🪦 {{PLAYER}} UNDONE BY SCENERY', 'body' => "📰 In an ending devoid of witnesses, {{PLAYER}} was filed under 'deceased' by the world at large. A humble exit for a humble survivor."],
        ],
        'misc' => [
            ['headline' => '💀 {{PLAYER}} HAS DIED', 'body' => "📰 *Obituary* — the run of {{PLAYER}} has come to its end. The precise circumstances are murky, but the result is permanent. Rest easy, survivor."],
            ['headline' => '⚰️ THE BOOK CLOSES ON {{PLAYER}}', 'body' => "📰 {{PLAYER}} is no longer with us. Cause uncertain, outcome final. The coast moves on, as it always does."],
            ['headline' => '🪦 {{PLAYER}}, GONE TOO SOON (OR NOT SOON ENOUGH)', 'body' => "📰 {{PLAYER}} has shuffled off the Chernarus coil. We raise a warm, expired can of beans in their memory."],
        ],
    ],
```

- [ ] **Step 2: Verify the config parses**

Run: `php -r "require 'vendor/autoload.php'; \$p=require 'config/personality.php'; var_dump(count(\$p['birth']['fallback']), array_keys(\$p['eulogy']));"`
Expected: `int(5)` and an array of keys `pvp, suicide, environment, misc`.

- [ ] **Step 3: Commit**

```bash
git add config/personality.php
git commit -m "feat: birth + eulogy fallback personality pools"
```

---

## Task 4: `DeathLogCapturer` (pure) — filter the death window from a raw-line buffer

**Files:**
- Create: `app/Services/Adm/DeathLogCapturer.php`
- Test: `tests/Unit/DeathLogCapturerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Adm\DeathLogCapturer;

it('keeps only lines mentioning the victim plus the death line, newest-last', function () {
    $buffer = [
        '10:00:00 | Player "Victim" (id=V=) is connected',
        '10:01:00 | Player "Other" (id=O=) is connected',
        '10:02:00 | Player "Victim" (id=V= pos=<1,2,3>)[HP: 50] hit by Player "Killer" (id=K=) into Torso',
        '10:02:30 | Player "Other" (id=O=) pos=<9,9,9>',
    ];
    $deathLine = '10:03:00 | Player "Victim" (DEAD) (id=V=) killed by Player "Killer" (id=K=) with M4A1 from 153.4 meters';

    $log = (new DeathLogCapturer())->capture($buffer, 'Victim', $deathLine);

    expect($log)->toContain('hit by Player "Killer"');
    expect($log)->toContain('is connected');
    expect($log)->not->toContain('Other');
    expect($log)->toEndWith($deathLine);
});

it('caps the excerpt to the most recent N matching lines', function () {
    $buffer = [];
    for ($i = 0; $i < 80; $i++) {
        $buffer[] = "10:00:{$i} | Player \"Victim\" (id=V=) pos=<{$i},0,0>";
    }
    $log = (new DeathLogCapturer())->capture($buffer, 'Victim', 'DEATH', maxLines: 40);

    // 39 most-recent buffer matches + the death line = 40 lines.
    expect(substr_count($log, "\n") + 1)->toBe(40);
    expect($log)->toContain('pos=<79,0,0>');
    expect($log)->not->toContain('pos=<39,0,0>');
});

it('returns just the death line when nothing in the buffer matches', function () {
    $log = (new DeathLogCapturer())->capture(['10:00:00 | Player "Z" (id=Z=) is connected'], 'Victim', 'DEATH');
    expect($log)->toBe('DEATH');
});
```

- [ ] **Step 2: Run it to verify failure**

Run: `./vendor/bin/pest tests/Unit/DeathLogCapturerTest.php`
Expected: FAIL — class `App\Services\Adm\DeathLogCapturer` not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Adm;

/**
 * PURE. Given a buffer of recent raw ADM lines (oldest-first), the victim's gamertag, and the
 * raw death line, returns a newline-joined excerpt of the lines that mention the victim plus the
 * death line appended last. This is the "death window" handed to the eulogy LLM for color.
 * Coordinate-/format-independent: matches on the quoted gamertag string.
 */
class DeathLogCapturer
{
    public function capture(array $buffer, string $victim, string $deathLine, int $maxLines = 40): string
    {
        $needle = '"'.$victim.'"';
        $matches = array_values(array_filter(
            $buffer,
            fn ($line) => is_string($line) && $line !== '' && str_contains($line, $needle)
        ));

        // Reserve one slot for the death line.
        $keep = max(0, $maxLines - 1);
        if (count($matches) > $keep) {
            $matches = array_slice($matches, -$keep);
        }

        $matches[] = $deathLine;

        return implode("\n", $matches);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Unit/DeathLogCapturerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Adm/DeathLogCapturer.php tests/Unit/DeathLogCapturerTest.php
git commit -m "feat: DeathLogCapturer extracts the death window from a raw-line buffer"
```

---

## Task 5: Capture the raw log in `AdmIngestor` and persist it via `LifeTracker::death`

**Files:**
- Modify: `app/Services/Life/LifeTracker.php:35-58`
- Modify: `app/Services/Adm/AdmIngestor.php`
- Test: `tests/Feature/AdmIngestorTest.php` (add a case)

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/AdmIngestorTest.php` (it already has `RefreshDatabase` + the ingestor set up; this case drives `processFile` directly and asserts the death's `death_log`):

```php
it('captures a death-window log onto the life', function () {
    $content = implode("\n", [
        '00:00:00 | Player "Doomed" (id=D=) is connected',
        '00:01:00 | Player "Doomed" (id=D= pos=<1,2,3>)[HP: 30] hit by Player "Shooter" (id=S=) into Torso',
        '00:02:00 | Player "Doomed" (DEAD) (id=D=) killed by Player "Shooter" (id=S=) with M4A1 from 100.0 meters',
    ]);

    $ingestor = new App\Services\Adm\AdmIngestor(new App\Services\Adm\AdmParser(), new App\Services\Life\LifeTracker());
    $ingestor->processFile($content, 0, new DateTimeImmutable('2026-06-14T00:00:00Z'), 0);

    $life = App\Models\Life::whereNotNull('ended_at')->first();
    expect($life)->not->toBeNull();
    expect($life->death_log)->toContain('hit by Player "Shooter"');
    expect($life->death_log)->toContain('killed by Player "Shooter"');
    expect($life->death_log)->not->toContain('Shooter" (id=S=) is connected'); // only victim-mentioning lines
});
```

- [ ] **Step 2: Run it to verify failure**

Run: `./vendor/bin/pest tests/Feature/AdmIngestorTest.php --filter="captures a death-window"`
Expected: FAIL — `death_log` is null.

- [ ] **Step 3: Extend `LifeTracker::death` to accept an optional log**

In `app/Services/Life/LifeTracker.php`, change the signature and the update array:

```php
    /**
     * @param array{victim:string,cause:string,killer:?string,weapon?:?string,distance?:?float} $death
     */
    public function death(array $death, \DateTimeImmutable $ts, ?string $log = null): void
    {
        $player = Player::where('gamertag', $death['victim'])->first();
        if (! $player) return; // never-seen player, only a (duplicate) death line — ignore
        $this->touch($player, $ts);

        if ($open = $player->openSession()) {
            $this->closeSession($open, $ts, 'clean');
        }

        $life = $player->openLife();
        if (! $life) return;

        $life->update([
            'ended_at' => $ts,
            'death_cause' => $death['cause'],
            'death_by_gamertag' => $death['killer'],
            'death_weapon' => $death['weapon'] ?? null,
            'death_distance' => $death['distance'] ?? null,
            'death_log' => $log,
        ]);
    }
```

- [ ] **Step 4: Maintain a ring buffer in `AdmIngestor` and pass the captured log**

In `app/Services/Adm/AdmIngestor.php`:

a) Add a use + a `DeathLogCapturer` property, initialised in the constructor:

```php
use App\Services\Adm\DeathLogCapturer;
```

```php
    private PositionRecorder $positions;
    private BunkerVisitService $bunkerVisits;
    private DeathLogCapturer $deathLog;

    public function __construct(
        private AdmParser $parser,
        private LifeTracker $tracker,
        ?PositionRecorder $positions = null,
        ?BunkerVisitService $bunkerVisits = null,
        ?DeathLogCapturer $deathLog = null,
    ) {
        $this->positions = $positions ?? new PositionRecorder();
        $this->bunkerVisits = $bunkerVisits ?? new BunkerVisitService();
        $this->deathLog = $deathLog ?? new DeathLogCapturer();
    }
```

b) Inside `processFile`, keep a rolling buffer of recent raw lines and feed the death capture. Replace the line-processing loop body so the buffer is appended before parsing and the death call passes the captured log. Concretely, initialise the buffer just before the `for` loop:

```php
        $tsByLine = $this->parser->assignTimestamps($lines, $fallbackDate);

        $recent = [];          // rolling buffer of recent raw lines (this file)
        $recentCap = 200;      // bounded so memory stays flat on huge files

        for ($i = 0; $i < $total; $i++) {
            if ($i < $cursor) continue;
            $raw = $lines[$i];
            if ($raw === '' || $raw === null) continue;
```

c) Still inside the loop, change the death branch to capture, and append to the buffer at the end of each iteration. Replace the existing connect/disconnect/death `elseif` chain with:

```php
            if ($c = $this->parser->parseConnect($raw)) {
                $this->tracker->connect($c['gamertag'], $ts);
            } elseif ($d = $this->parser->parseDisconnect($raw)) {
                $this->tracker->disconnect($d['gamertag'], $ts);
            } elseif ($k = $this->parser->parseDeath($raw)) {
                $log = $this->deathLog->capture($recent, $k['victim'], $raw);
                $this->tracker->death($k, $ts, $log);
            }
```

d) At the very end of the loop body (after the position + bunker checks, before the loop closes), append the raw line to the buffer and trim:

```php
            $recent[] = $raw;
            if (count($recent) > $recentCap) {
                $recent = array_slice($recent, -$recentCap);
            }
        }

        return $total;
```

(Note: the death line itself is captured from `$raw` directly, so it does not matter that it is appended to `$recent` after the death branch runs.)

- [ ] **Step 5: Run the ingestor tests**

Run: `./vendor/bin/pest tests/Feature/AdmIngestorTest.php`
Expected: PASS (existing cases + the new death-window case).

- [ ] **Step 6: Run the life tracker tests (signature change)**

Run: `./vendor/bin/pest tests/Feature/LifeTrackerTest.php`
Expected: PASS — the new `$log` param is optional, so existing `death($death, $ts)` calls are unaffected.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Life/LifeTracker.php app/Services/Adm/AdmIngestor.php tests/Feature/AdmIngestorTest.php
git commit -m "feat: capture raw death-window log during ingestion"
```

---

## Task 6: Playtime-gate the ban and remove the death-feed coupling

**Files:**
- Modify: `app/Services/Ban/DeathBanService.php`
- Modify: `app/Services/IngestAdmService.php:42-60`
- Test: `tests/Feature/DeathBanServiceTest.php` (rewrite feed-coupled cases)

After this task `DeathBanService` only bans (gated by playtime) — it no longer posts a feed.
Eulogies are taken over by `LifecycleAnnouncer` (Task 12). The death-feed test cases that asserted
posting are removed here; equivalent eulogy behavior is covered in `LifecycleAnnouncerTest`.

- [ ] **Step 1: Rewrite the test for the new behavior**

Replace the entire body of `tests/Feature/DeathBanServiceTest.php` with:

```php
<?php

use App\Models\Ban;
use App\Models\Life;
use App\Models\Player;
use App\Services\Ban\BanService;
use App\Services\Ban\DeathBanService;
use App\Services\Ban\NullBanNotifier;
use App\Services\Nitrado\NitradoClient;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    Http::fake(['*/gameservers/settings' => function ($r) {
        if ($r->method() === 'POST') return Http::response(['status' => 'success', 'data' => []]);
        return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => '']]]]);
    }]);
    $this->state = new BotState();
    $this->state->set('go_live_at', '2026-06-12T10:00:00+00:00');
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    // banHours 12, banMinPlaytime 3600s (60 min).
    $this->deathBans = new DeathBanService($bans, $this->state, 12, 3600);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function endedLife(string $tag, string $endedAt, int $playtime = 7200, bool $banIssued = false): void {
    $p = Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create([
        'player_id' => $p->id, 'started_at' => $endedAt, 'ended_at' => $endedAt,
        'death_cause' => 'pvp', 'playtime_seconds' => $playtime, 'ban_issued' => $banIssued,
    ]);
}

it('bans a death with >= 60 min playtime after go_live', function () {
    endedLife('Veteran', '2026-06-12T11:00:00Z', playtime: 3600);
    expect($this->deathBans->run())->toBe(1);
    expect(Ban::where('source', 'auto_death')->count())->toBe(1);
    expect(Life::first()->ban_issued)->toBeTrue();
});

it('does NOT ban a death under 60 min playtime', function () {
    endedLife('Rookie', '2026-06-12T11:00:00Z', playtime: 3599);
    expect($this->deathBans->run())->toBe(0);
    expect(Ban::count())->toBe(0);
    // The life is left unmarked so it is not silently considered "handled".
    expect(Life::first()->ban_issued)->toBeFalse();
});

it('does not ban deaths before go_live even with enough playtime', function () {
    endedLife('Old', '2026-06-12T09:00:00Z', playtime: 7200);
    expect($this->deathBans->run())->toBe(0);
    expect(Ban::count())->toBe(0);
});

it('is idempotent — already-issued lives are skipped', function () {
    endedLife('Already', '2026-06-12T11:00:00Z', playtime: 7200, banIssued: true);
    expect($this->deathBans->run())->toBe(0);
});

it('does nothing before go_live is set', function () {
    $state = new BotState();
    $state->delete('go_live_at');
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    endedLife('Whoever', '2026-06-12T11:00:00Z', playtime: 7200);
    expect((new DeathBanService($bans, $state, 12, 3600))->run())->toBe(0);
    expect(Ban::count())->toBe(0);
});
```

- [ ] **Step 2: Run it to verify failure**

Run: `./vendor/bin/pest tests/Feature/DeathBanServiceTest.php`
Expected: FAIL — `DeathBanService` constructor still expects `(…, int $banHours, ?DeathFeedNotifier, int)`; the `playtime_seconds` gate does not exist yet.

- [ ] **Step 3: Rewrite `DeathBanService`**

Replace `app/Services/Ban/DeathBanService.php` with:

```php
<?php

namespace App\Services\Ban;

use App\Models\Life;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;

/**
 * Bans players whose lives ended after go_live with enough playtime, and aren't yet banned.
 * Death ANNOUNCEMENTS (eulogies) are owned by App\Services\Lifecycle\LifecycleAnnouncer — this
 * service is now purely the ban decision. Returns the count banned.
 */
class DeathBanService
{
    public function __construct(
        private BanService $bans,
        private BotState $state,
        private int $banHours = 12,
        private int $banMinPlaytimeSeconds = 3600,
    ) {}

    public function run(): int
    {
        $goLive = $this->state->get('go_live_at');
        if (! $goLive) return 0; // not live yet — never retro-ban history

        $cutoff = CarbonImmutable::parse($goLive);

        $lives = Life::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '>', $cutoff)
            ->where('playtime_seconds', '>=', $this->banMinPlaytimeSeconds)
            ->where('ban_issued', false)
            ->with('player')
            ->orderBy('ended_at')
            ->get();

        $count = 0;
        foreach ($lives as $life) {
            $gamertag = $life->player?->gamertag;
            if (! $gamertag) { $life->update(['ban_issued' => true]); continue; }

            $this->bans->ban($gamertag, $this->banHours, 'One life autoban', 'auto_death');
            $life->update(['ban_issued' => true]);
            $count++;
        }

        return $count;
    }
}
```

- [ ] **Step 4: Update the wiring in `IngestAdmService`**

In `app/Services/IngestAdmService.php`, remove the `$deathFeed` construction (lines ~47-50) and the
feed/maxage args. The ban block becomes:

```php
            $bans = new \App\Services\Ban\BanService(
                $client,
                new \App\Services\Ban\DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID')),
                dryRun: filter_var(env('BAN_DRY_RUN', false), FILTER_VALIDATE_BOOL),
            );
            $banned = (new \App\Services\Ban\DeathBanService(
                $bans,
                $state,
                (int) env('BAN_DURATION_HOURS', 12),
                (int) config('lifecycle.ban_min_playtime_minutes', 60) * 60,
            ))->run();
            if ($banned > 0) {
                $this->console()->info("[ingest] issued {$banned} death ban(s).");
            }
```

- [ ] **Step 5: Run the death-ban tests**

Run: `./vendor/bin/pest tests/Feature/DeathBanServiceTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Confirm nothing else references the removed feed coupling yet**

Run: `php -l app/Services/IngestAdmService.php && grep -rn "DeathFeedNotifier" app/Services/Ban || echo "clean"`
Expected: `No syntax errors` and `clean`.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Ban/DeathBanService.php app/Services/IngestAdmService.php tests/Feature/DeathBanServiceTest.php
git commit -m "feat: gate death bans on 60-min playtime; decouple from death feed"
```

---

## Task 7: `LifeFactsBuilder` (pure) — structured facts from a `Life`

**Files:**
- Create: `app/Services/Lifecycle/LifeFactsBuilder.php`
- Test: `tests/Unit/LifeFactsBuilderTest.php` (uses `RefreshDatabase` — needs DB for associates/prior life)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Life;
use App\Models\Player;
use App\Services\Lifecycle\LifeFactsBuilder;
use Carbon\CarbonImmutable;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-06-14T12:00:00Z'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('builds facts for a pvp death', function () {
    $p = Player::create(['gamertag' => 'Doomed', 'discord_user_id' => '123', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create([
        'player_id' => $p->id,
        'started_at' => '2026-06-14T11:13:00Z', // 47 min wall-clock
        'ended_at' => '2026-06-14T12:00:00Z',
        'death_cause' => 'pvp', 'death_by_gamertag' => 'Sniper', 'death_weapon' => 'SVD',
        'death_distance' => 312.5, 'playtime_seconds' => 2460, // 41 min
        'death_log' => "raw line A\nraw line B",
    ]);

    $facts = (new LifeFactsBuilder())->build($life);

    expect($facts['gamertag'])->toBe('Doomed');
    expect($facts['linked'])->toBeTrue();
    expect($facts['cause'])->toBe('pvp');
    expect($facts['killer'])->toBe('Sniper');
    expect($facts['weapon'])->toBe('SVD');
    expect($facts['distance_m'])->toBe(312.5);
    expect($facts['wall_age_human'])->toContain('47');
    expect($facts['playtime_human'])->toContain('41');
    expect($facts['raw_log'])->toContain('raw line A');
    expect($facts['associates'])->toBeArray();
});

it('summarises the prior death for a birth (new open life)', function () {
    $p = Player::create(['gamertag' => 'Reborn', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T09:00:00Z', 'ended_at' => '2026-06-14T10:00:00Z', 'death_cause' => 'environment', 'playtime_seconds' => 3000]);
    $current = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T11:50:00Z', 'playtime_seconds' => 360]);

    $facts = (new LifeFactsBuilder())->build($current);

    expect($facts['linked'])->toBeFalse();
    expect($facts['prior_death'])->toContain('environment');
});
```

- [ ] **Step 2: Run it to verify failure**

Run: `./vendor/bin/pest tests/Unit/LifeFactsBuilderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Lifecycle;

use App\Models\Life;
use App\Services\Bounty\AssociateDetector;
use App\Services\Connection\SessionDuration;
use App\Services\Life\LivePlaytime;

/**
 * PURE-ish (reads the DB, no side effects): turns a Life into the structured-facts array fed to
 * the announcement LLM (and the canned fallback). Associates come from the bounty detector,
 * best-effort — an empty/failed lookup just yields [].
 */
class LifeFactsBuilder
{
    public function __construct(private ?AssociateDetector $associates = null) {}

    /** @return array<string,mixed> */
    public function build(Life $life): array
    {
        $player = $life->player;
        $playtime = $life->ended_at ? (int) $life->playtime_seconds : LivePlaytime::forLife($life);

        $wallSeconds = $life->ended_at
            ? max(0, $life->ended_at->getTimestamp() - $life->started_at->getTimestamp())
            : max(0, now()->getTimestamp() - $life->started_at->getTimestamp());

        return [
            'gamertag' => $player?->gamertag ?? '?',
            'linked' => (bool) ($player?->discord_user_id),
            'cause' => $life->death_cause,
            'killer' => $life->death_by_gamertag,
            'weapon' => $life->death_weapon,
            'distance_m' => $life->death_distance,
            'wall_age_human' => SessionDuration::human($wallSeconds),
            'playtime_human' => SessionDuration::human($playtime),
            'playtime_seconds' => $playtime,
            'associates' => $this->associatesOf($life),
            'prior_death' => $this->priorDeath($life),
            'raw_log' => $life->death_log,
        ];
    }

    /** @return string[] */
    private function associatesOf(Life $life): array
    {
        $player = $life->player;
        if (! $player) return [];

        try {
            $detector = $this->associates ?? new AssociateDetector();
            return $detector->associatesOf($player)
                ->take(3)
                ->map(fn ($p) => $p->gamertag)
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function priorDeath(Life $life): ?string
    {
        $player = $life->player;
        if (! $player) return null;

        $prev = $player->lives()
            ->whereNotNull('ended_at')
            ->where('id', '!=', $life->id)
            ->where('started_at', '<', $life->started_at)
            ->latest('started_at')
            ->first();

        if (! $prev) return null;

        $by = $prev->death_by_gamertag ? " by {$prev->death_by_gamertag}" : '';
        return "previous life ended ({$prev->death_cause}{$by}) after ".SessionDuration::human((int) $prev->playtime_seconds);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Unit/LifeFactsBuilderTest.php`
Expected: PASS (2 tests). (`SessionDuration::human(2460)` renders minutes, so it contains "41".)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Lifecycle/LifeFactsBuilder.php tests/Unit/LifeFactsBuilderTest.php
git commit -m "feat: LifeFactsBuilder assembles structured facts from a life"
```

---

## Task 8: `MentionSubstitutor` (pure) — placeholders → mentions

**Files:**
- Create: `app/Services/Lifecycle/MentionSubstitutor.php`
- Test: `tests/Unit/MentionSubstitutorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Player;
use App\Services\Lifecycle\MentionSubstitutor;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('replaces PLAYER with a mention when linked, backtick when not', function () {
    Player::create(['gamertag' => 'Linked', 'discord_user_id' => '999']);
    $sub = new MentionSubstitutor();

    $linked = $sub->apply('RIP {{PLAYER}} forever', ['{{PLAYER}}' => 'Linked']);
    $plain = $sub->apply('RIP {{PLAYER}} forever', ['{{PLAYER}}' => 'Unknown']);

    expect($linked)->toBe('RIP <@999> forever');
    expect($plain)->toBe('RIP `Unknown` forever');
});

it('replaces multiple placeholders and leaves text without placeholders untouched', function () {
    Player::create(['gamertag' => 'K', 'discord_user_id' => '7']);
    $out = (new MentionSubstitutor())->apply(
        '{{KILLER}} dropped {{PLAYER}}',
        ['{{PLAYER}}' => 'V', '{{KILLER}}' => 'K']
    );
    expect($out)->toBe('<@7> dropped `V`');
});
```

- [ ] **Step 2: Run it to verify failure**

Run: `./vendor/bin/pest tests/Unit/MentionSubstitutorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Lifecycle;

use App\Services\Lookup\PlayerMention;

/**
 * PURE-ish: replaces {{PLAYER}} / {{KILLER}} placeholders in generated copy with a rendered
 * gamertag — a real <@id> mention for linked players, a backticked tag otherwise — via the
 * shared PlayerMention rule. Each map value is a gamertag (or null).
 */
class MentionSubstitutor
{
    public function __construct(private ?PlayerMention $mention = null) {}

    /**
     * @param array<string,?string> $map placeholder => gamertag
     */
    public function apply(string $text, array $map): string
    {
        $mention = $this->mention ?? new PlayerMention();
        $replacements = [];
        foreach ($map as $placeholder => $gamertag) {
            if ($gamertag === null || $gamertag === '') continue;
            $replacements[$placeholder] = $mention->for($gamertag);
        }

        return strtr($text, $replacements);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Unit/MentionSubstitutorTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Lifecycle/MentionSubstitutor.php tests/Unit/MentionSubstitutorTest.php
git commit -m "feat: MentionSubstitutor swaps placeholders for mentions"
```

---

## Task 9: `OpenRouterClient` — Http-facade wrapper

**Files:**
- Create: `app/Services/Llm/OpenRouterClient.php`
- Test: `tests/Feature/OpenRouterClientTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Llm\OpenRouterClient;
use Illuminate\Support\Facades\Http;

it('posts a chat completion and returns the message content', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => "HEADLINE\nbody text"]]],
        ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'anthropic/claude-haiku-4.5', 'https://openrouter.ai/api/v1', 20, 900, 1.0);
    $out = $client->complete('you are a columnist', 'write an obituary');

    expect($out)->toBe("HEADLINE\nbody text");
    Http::assertSent(function ($r) {
        return $r->hasHeader('Authorization', 'Bearer sk-test')
            && $r['model'] === 'anthropic/claude-haiku-4.5'
            && $r['messages'][0]['role'] === 'system'
            && $r['messages'][1]['content'] === 'write an obituary';
    });
});

it('throws when the api key is empty (never calls out)', function () {
    Http::fake();
    $client = new OpenRouterClient(null, 'm', 'https://x/api/v1', 20, 900, 1.0);

    expect(fn () => $client->complete('s', 'u'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('throws on a non-2xx response', function () {
    Http::fake(['*/chat/completions' => Http::response(['error' => 'nope'], 500)]);
    $client = new OpenRouterClient('sk-test', 'm', 'https://x/api/v1', 20, 900, 1.0);

    expect(fn () => $client->complete('s', 'u'))->toThrow(RuntimeException::class);
});

it('throws when the response has no content', function () {
    Http::fake(['*/chat/completions' => Http::response(['choices' => []])]);
    $client = new OpenRouterClient('sk-test', 'm', 'https://x/api/v1', 20, 900, 1.0);

    expect(fn () => $client->complete('s', 'u'))->toThrow(RuntimeException::class);
});
```

- [ ] **Step 2: Run it to verify failure**

Run: `./vendor/bin/pest tests/Feature/OpenRouterClientTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;

/**
 * Minimal OpenRouter (OpenAI-compatible) chat-completions client. Throws RuntimeException on any
 * problem (no key, network/timeout, non-2xx, empty content) so callers can fall back cleanly.
 */
class OpenRouterClient
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $baseUrl,
        private int $timeoutSeconds = 20,
        private int $maxTokens = 900,
        private float $temperature = 1.0,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('llm.api_key'),
            config('llm.model', 'anthropic/claude-haiku-4.5'),
            config('llm.base_url', 'https://openrouter.ai/api/v1'),
            (int) config('llm.timeout_seconds', 20),
            (int) config('llm.max_tokens', 900),
            (float) config('llm.temperature', 1.0),
        );
    }

    public function complete(string $system, string $user): string
    {
        if (! $this->apiKey) {
            throw new \RuntimeException('OpenRouter API key not configured');
        }

        $res = Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->withHeaders([
                'HTTP-Referer' => 'https://github.com/dayz-one-life',
                'X-Title' => 'DayZ One Life Bot',
            ])
            ->post($this->baseUrl.'/chat/completions', [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        if (! $res->ok()) {
            throw new \RuntimeException("OpenRouter HTTP {$res->status()}");
        }

        $content = $res->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new \RuntimeException('OpenRouter returned no content');
        }

        return $content;
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/OpenRouterClientTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Llm/OpenRouterClient.php tests/Feature/OpenRouterClientTest.php
git commit -m "feat: OpenRouterClient chat-completions wrapper"
```

---

## Task 10: `AnnouncementGenerator` — prompt + LLM call + canned fallback

**Files:**
- Create: `app/Services/Lifecycle/AnnouncementGenerator.php`
- Test: `tests/Feature/AnnouncementGeneratorTest.php`

Returns `['headline' => string, 'body' => string]` with `{{PLAYER}}` / `{{KILLER}}` placeholders
intact (substitution happens in the announcer). On any client failure, falls back to the
`birth.*` / `eulogy.*` personality pools.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Lifecycle\AnnouncementGenerator;
use App\Services\Llm\OpenRouterClient;
use App\Services\Personality\MessagePicker;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => MessagePicker::reset());

function genFacts(array $over = []): array {
    return array_merge([
        'gamertag' => 'Doomed', 'linked' => true, 'cause' => 'pvp', 'killer' => 'Sniper',
        'weapon' => 'SVD', 'distance_m' => 312.5, 'wall_age_human' => '47 minutes',
        'playtime_human' => '41 minutes', 'playtime_seconds' => 2460, 'associates' => ['Buddy'],
        'prior_death' => null, 'raw_log' => "00:02 hit\n00:03 killed by Sniper",
    ], $over);
}

it('parses the LLM output into headline + body (first line is the headline)', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => "LOCAL MAN MEETS SVD\n📰 The late {{PLAYER}}..."]]],
    ])]);
    $gen = new AnnouncementGenerator(new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0), new MessagePicker());

    $out = $gen->generate('eulogy', genFacts());

    expect($out['headline'])->toBe('LOCAL MAN MEETS SVD');
    expect($out['body'])->toContain('{{PLAYER}}');
});

it('falls back to a canned eulogy when the client throws', function () {
    Http::fake(['*/chat/completions' => Http::response([], 500)]);
    $chooser = fn (array $pool, ?int $avoid) => 0; // deterministic
    $gen = new AnnouncementGenerator(
        new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0),
        new MessagePicker($chooser),
    );

    $out = $gen->generate('eulogy', genFacts());

    // First entry of eulogy.pvp pool.
    expect($out['headline'])->toContain('{{PLAYER}}');
    expect($out['body'])->toContain('{{KILLER}}');
});

it('falls back to a canned birth when there is no api key', function () {
    Http::fake();
    $gen = new AnnouncementGenerator(
        new OpenRouterClient(null, 'm', 'https://x/api/v1', 20, 900, 1.0),
        new MessagePicker(fn (array $pool, ?int $avoid) => 0),
    );

    $out = $gen->generate('birth', genFacts(['cause' => null, 'killer' => null]));

    expect($out['headline'])->not->toBe('');
    expect($out['body'])->toContain('{{PLAYER}}');
    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run it to verify failure**

Run: `./vendor/bin/pest tests/Feature/AnnouncementGeneratorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Lifecycle;

use App\Services\Llm\OpenRouterClient;
use App\Services\Personality\MessagePicker;

/**
 * Builds the birth/eulogy prompt from structured facts (+ raw log), calls OpenRouter, and parses
 * the result into headline + body. Any failure (no key, timeout, non-2xx, empty) falls back to the
 * canned `birth.` / `eulogy.` personality pools. Placeholders {{PLAYER}}/{{KILLER}} are left intact for
 * the announcer to substitute.
 */
class AnnouncementGenerator
{
    private const SYSTEM = <<<'TXT'
You are the staff obituary and society columnist for "The One Life Tribune", a savage, witty
post-apocalyptic newspaper covering a hardcore DayZ "one life" server. Players get ONE life; when
they die they are banned for a while, so every death is a real funeral and every respawn is a
genuine rebirth.

Write a SUBSTANTIAL, newspaper-style piece — NOT a one-liner. Aim for 150-350 words across 2-4 short
paragraphs. Be funny, a little roasty, and creative. Use vivid Discord markdown formatting: a
dateline, **bold**, *italics*, the occasional `> blockquote` from a fictional witness, and plenty of
fitting emojis (🕯️💀🐻⚰️🎉👶📰). 

Rules:
- Refer to the SUBJECT only as the literal token {{PLAYER}} and the KILLER (if any) only as {{KILLER}}.
  Never invent or alter these tokens; never write a real name in their place.
- Use the facts you are given; do not fabricate weapons, distances, or killers that weren't provided.
- Output EXACTLY this shape: the FIRST line is a punchy ALL-CAPS tabloid HEADLINE (no markdown, no
  leading emoji required), then a blank line, then the article body. Do not label the sections.
TXT;

    public function __construct(
        private OpenRouterClient $client,
        private ?MessagePicker $picker = null,
    ) {}

    /**
     * @param 'birth'|'eulogy' $kind
     * @param array<string,mixed> $facts
     * @return array{headline:string,body:string}
     */
    public function generate(string $kind, array $facts): array
    {
        try {
            $raw = $this->client->complete(self::SYSTEM, $this->userPrompt($kind, $facts));
            return $this->split($raw);
        } catch (\Throwable) {
            return $this->fallback($kind, $facts);
        }
    }

    private function userPrompt(string $kind, array $facts): string
    {
        $payload = [
            'kind' => $kind,
            'subject_placeholder' => '{{PLAYER}}',
            'killer_placeholder' => $facts['killer'] ? '{{KILLER}}' : null,
            'facts' => [
                'cause_of_death' => $facts['cause'],
                'killer' => $facts['killer'],
                'weapon' => $facts['weapon'],
                'distance_meters' => $facts['distance_m'],
                'age_wall_clock' => $facts['wall_age_human'],
                'age_playtime' => $facts['playtime_human'],
                'associates_left_behind' => $facts['associates'],
                'prior_life' => $facts['prior_death'],
            ],
            'raw_admin_log_excerpt' => $facts['raw_log'],
        ];

        $intro = $kind === 'birth'
            ? "Write a BIRTH ANNOUNCEMENT celebrating (and roasting) a survivor who just respawned onto the coast."
            : "Write an OBITUARY for a survivor who just died, using how they died, how old they were, who killed them and with what, and any associates left behind.";

        return $intro."\n\nDETAILS (JSON):\n".json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{headline:string,body:string} */
    private function split(string $raw): array
    {
        $raw = trim($raw);
        $parts = preg_split('/\r\n|\r|\n/', $raw, 2);
        $headline = trim($parts[0] ?? '');
        $body = trim($parts[1] ?? '');
        if ($body === '') {
            $body = $headline;
            $headline = '📰 THE ONE LIFE TRIBUNE';
        }

        return ['headline' => $headline, 'body' => $body];
    }

    /** @return array{headline:string,body:string} */
    private function fallback(string $kind, array $facts): array
    {
        $picker = $this->picker ?? new MessagePicker();

        if ($kind === 'birth') {
            $key = 'birth.fallback';
        } else {
            $cause = $facts['cause'];
            $bucket = match (true) {
                $cause === 'pvp' && $facts['killer'] => 'pvp',
                $cause === 'suicide' => 'suicide',
                in_array($cause, ['environment', 'bled_out', 'drowned'], true) => 'environment',
                default => 'misc',
            };
            $key = "eulogy.{$bucket}";
        }

        $pool = config("personality.{$key}", []);
        if (! is_array($pool) || $pool === []) {
            return ['headline' => '📰 THE ONE LIFE TRIBUNE', 'body' => 'Another chapter closes on the coast. {{PLAYER}}.'];
        }

        $pool = array_values($pool);
        // Reuse MessagePicker's anti-repeat chooser by indexing into the structured pool ourselves.
        $index = $picker->indexFor($key, count($pool));
        $entry = $pool[$index];

        return ['headline' => $entry['headline'], 'body' => $entry['body']];
    }
}
```

- [ ] **Step 4: Add a small `indexFor` helper to `MessagePicker`**

The fallback pools hold `['headline'=>, 'body'=>]` entries, so we need the picker's anti-repeat
*index* rather than its string interpolation. Add this method to
`app/Services/Personality/MessagePicker.php` (it reuses the same `$last` state + injected chooser):

```php
    /**
     * Anti-repeat index into a structured pool of $count entries for $key. Mirrors pick()'s
     * chooser/anti-repeat behavior but returns the chosen index so callers can index their own
     * (non-string) pools. Returns 0 when the pool is empty/singleton.
     */
    public function indexFor(string $key, int $count): int
    {
        if ($count <= 0) return 0;
        $pool = array_fill(0, $count, null);
        $index = ($this->chooser)($pool, self::$last[$key] ?? null);
        self::$last[$key] = $index;

        return $index;
    }
```

- [ ] **Step 5: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/AnnouncementGeneratorTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Run the personality tests to confirm `indexFor` didn't break `pick`**

Run: `./vendor/bin/pest --filter=Personality && ./vendor/bin/pest tests/Unit/MentionSubstitutorTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Lifecycle/AnnouncementGenerator.php app/Services/Personality/MessagePicker.php tests/Feature/AnnouncementGeneratorTest.php
git commit -m "feat: AnnouncementGenerator builds prompts with canned fallback"
```

---

## Task 11: Lifecycle notifiers (interface, Null, Discord embed + ping)

**Files:**
- Create: `app/Services/Lifecycle/LifecycleNotifier.php`
- Create: `app/Services/Lifecycle/NullLifecycleNotifier.php`
- Create: `app/Services/Lifecycle/DiscordLifecycleNotifier.php`

No dedicated test (Discord-mechanical, like the other notifiers — covered indirectly via the
announcer with a recording double in Task 12).

- [ ] **Step 1: Create the interface**

```php
<?php

namespace App\Services\Lifecycle;

/**
 * Posts a birth or eulogy. Payload shape:
 *   ['ping' => ?string, 'title' => string, 'description' => string,
 *    'fields' => array<int,array{name:string,value:string}>, 'color' => int, 'footer' => string]
 * `ping` is a plain-content line carrying a real <@id> mention (or null when unlinked) — Discord
 * does NOT notify on mentions inside an embed, so the ping must ride on the message content.
 */
interface LifecycleNotifier
{
    public function publishBirth(array $payload): void;

    public function publishEulogy(array $payload): void;
}
```

- [ ] **Step 2: Create the Null notifier**

```php
<?php

namespace App\Services\Lifecycle;

class NullLifecycleNotifier implements LifecycleNotifier
{
    public function publishBirth(array $payload): void {}

    public function publishEulogy(array $payload): void {}
}
```

- [ ] **Step 3: Create the Discord notifier**

```php
<?php

namespace App\Services\Lifecycle;

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Embed\Embed;

/**
 * Posts births to the births channel and eulogies to the eulogy channel as a rich newspaper-style
 * embed, with the real mention ping (if any) on a plain content line above it. One-shot posts (no
 * edit-in-place). Entirely best-effort: null client / missing channel / send failure all no-op.
 */
class DiscordLifecycleNotifier implements LifecycleNotifier
{
    public function __construct(
        private ?Discord $discord,
        private ?string $birthsChannelId,
        private ?string $eulogyChannelId,
    ) {}

    public function publishBirth(array $payload): void
    {
        $this->post($this->birthsChannelId, $payload);
    }

    public function publishEulogy(array $payload): void
    {
        $this->post($this->eulogyChannelId, $payload);
    }

    private function post(?string $channelId, array $payload): void
    {
        if (! $this->discord || ! $channelId) {
            return;
        }

        try {
            $channel = $this->discord->getChannel($channelId);
            if (! $channel) {
                return;
            }

            $embed = new Embed($this->discord);
            $embed->setTitle($this->trim($payload['title'], 256));
            $embed->setDescription($this->trim($payload['description'], 4096));
            $embed->setColor($payload['color'] ?? 0x2B2D31);
            if (! empty($payload['footer'])) {
                $embed->setFooter($payload['footer']);
            }
            foreach ($payload['fields'] ?? [] as $field) {
                $embed->addFieldValues($field['name'], $field['value'], true);
            }

            $builder = MessageBuilder::new()->addEmbed($embed);
            if (! empty($payload['ping'])) {
                $builder->setContent($payload['ping']);
            }

            $channel->sendMessage($builder)->otherwise(fn () => null);
        } catch (\Throwable) {
            // best-effort: never propagate to caller
        }
    }

    private function trim(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
```

- [ ] **Step 4: Verify the files load**

Run: `php -l app/Services/Lifecycle/DiscordLifecycleNotifier.php && php -r "require 'vendor/autoload.php'; class_exists(App\Services\Lifecycle\NullLifecycleNotifier::class) && print 'ok\n';"`
Expected: `No syntax errors` and `ok`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Lifecycle/LifecycleNotifier.php app/Services/Lifecycle/NullLifecycleNotifier.php app/Services/Lifecycle/DiscordLifecycleNotifier.php
git commit -m "feat: lifecycle notifiers (interface, null, discord embed + ping)"
```

---

## Task 12: `LifecycleAnnouncer` — scan, gate, generate, post, mark

**Files:**
- Create: `app/Services/Lifecycle/LifecycleAnnouncer.php`
- Test: `tests/Feature/LifecycleAnnouncerTest.php`

This is the business core: it selects due births and eulogies (gated by grace, go_live, freshness,
and the idempotency markers), builds the payload (facts → fields + substituted copy + ping), hands
it to the notifier, and stamps `birth_announced_at` / `eulogy_posted`.

- [ ] **Step 1: Write the failing test**

```php
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

    expect($this->notifier->eulogies[0]['ping'])->toContain('<@555>');
    expect($this->notifier->eulogies[0]['description'])->not->toContain('{{PLAYER}}'); // substituted
});
```

- [ ] **Step 2: Run it to verify failure**

Run: `./vendor/bin/pest tests/Feature/LifecycleAnnouncerTest.php`
Expected: FAIL — class `LifecycleAnnouncer` not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Lifecycle;

use App\Models\Life;
use App\Services\Life\LivePlaytime;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;

/**
 * Scans for due births and eulogies and posts them, idempotently. A life "counts" (birth + eulogy)
 * once its playtime >= grace; both are additionally gated by go_live_at and a freshness window
 * (mirrors the death feed's anti-backlog behavior). Births fire ~grace after a sticky spawn;
 * eulogies fire when a counted life ends in death.
 */
class LifecycleAnnouncer
{
    private const BIRTH_COLOR = 0x57F287;  // green
    private const EULOGY_COLOR = 0x2B2D31; // near-black

    public function __construct(
        private AnnouncementGenerator $generator,
        private LifecycleNotifier $notifier,
        private BotState $state,
        private int $graceSeconds = 300,
        private int $maxAgeMinutes = 30,
        private ?LifeFactsBuilder $facts = null,
        private ?MentionSubstitutor $substitutor = null,
    ) {}

    public function run(): void
    {
        $goLive = $this->state->get('go_live_at');
        if (! $goLive) return; // not live — never retro-announce backfill

        $cutoff = CarbonImmutable::parse($goLive);
        $fresh = CarbonImmutable::now()->subMinutes($this->maxAgeMinutes);

        $this->announceBirths($cutoff, $fresh);
        $this->announceEulogies($cutoff, $fresh);
    }

    private function announceBirths(CarbonImmutable $goLive, CarbonImmutable $fresh): void
    {
        // Candidates: not yet announced, started after go_live, recent.
        $candidates = Life::query()
            ->whereNull('birth_announced_at')
            ->where('started_at', '>', $goLive)
            ->where('started_at', '>=', $fresh)
            ->with('player')
            ->orderBy('started_at')
            ->get();

        foreach ($candidates as $life) {
            $playtime = $life->ended_at ? (int) $life->playtime_seconds : LivePlaytime::forLife($life);
            if ($playtime < $this->graceSeconds) continue;

            $facts = $this->factsBuilder()->build($life);
            $copy = $this->generator->generate('birth', $facts);
            $this->notifier->publishBirth($this->payload($copy, $facts, self::BIRTH_COLOR, $life, 'born'));
            $life->update(['birth_announced_at' => CarbonImmutable::now()]);
        }
    }

    private function announceEulogies(CarbonImmutable $goLive, CarbonImmutable $fresh): void
    {
        $candidates = Life::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '>', $goLive)
            ->where('ended_at', '>=', $fresh)
            ->where('playtime_seconds', '>=', $this->graceSeconds)
            ->where('eulogy_posted', false)
            ->with('player')
            ->orderBy('ended_at')
            ->get();

        foreach ($candidates as $life) {
            $facts = $this->factsBuilder()->build($life);
            $copy = $this->generator->generate('eulogy', $facts);
            $this->notifier->publishEulogy($this->payload($copy, $facts, self::EULOGY_COLOR, $life, 'died'));
            $life->update(['eulogy_posted' => true]);
        }
    }

    /**
     * @param array{headline:string,body:string} $copy
     * @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    private function payload(array $copy, array $facts, int $color, Life $life, string $verb): array
    {
        $sub = $this->substitutor ?? new MentionSubstitutor();
        $map = ['{{PLAYER}}' => $facts['gamertag'], '{{KILLER}}' => $facts['killer']];

        return [
            'ping' => $this->ping($facts, $verb),
            'title' => $sub->apply($copy['headline'], $map),
            'description' => $sub->apply($copy['body'], $map),
            'fields' => $this->fields($facts, $verb),
            'color' => $color,
            'footer' => $this->footer($life, $verb),
        ];
    }

    private function ping(array $facts, string $verb): ?string
    {
        if (! $facts['linked']) return null;
        $sub = $this->substitutor ?? new MentionSubstitutor();
        $mention = $sub->apply('{{PLAYER}}', ['{{PLAYER}}' => $facts['gamertag']]);
        return $verb === 'born' ? "🎉 {$mention} enters the world." : "🕯️ {$mention} has fallen.";
    }

    /** @return array<int,array{name:string,value:string}> */
    private function fields(array $facts, string $verb): array
    {
        $fields = [['name' => '🎂 Age', 'value' => "{$facts['wall_age_human']} ({$facts['playtime_human']} played)"]];

        if ($verb === 'died') {
            $fields[] = ['name' => '☠️ Cause', 'value' => ucfirst((string) ($facts['cause'] ?? 'unknown'))];
            if ($facts['killer']) {
                $weapon = $facts['weapon'] ? " with {$facts['weapon']}" : '';
                $dist = $facts['distance_m'] !== null ? ' @ '.round((float) $facts['distance_m']).'m' : '';
                $fields[] = ['name' => '🔫 Killer', 'value' => "`{$facts['killer']}`{$weapon}{$dist}"];
            }
        }
        if (! empty($facts['associates'])) {
            $fields[] = ['name' => '🤝 Known associates', 'value' => '`'.implode('`, `', $facts['associates']).'`'];
        }

        return $fields;
    }

    private function footer(Life $life, string $verb): string
    {
        $when = $verb === 'born' ? $life->started_at : ($life->ended_at ?? CarbonImmutable::now());
        return 'The One Life Tribune · '.$verb.' '.CarbonImmutable::parse($when)->diffForHumans();
    }

    private function factsBuilder(): LifeFactsBuilder
    {
        return $this->facts ?? new LifeFactsBuilder();
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/LifecycleAnnouncerTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Lifecycle/LifecycleAnnouncer.php tests/Feature/LifecycleAnnouncerTest.php
git commit -m "feat: LifecycleAnnouncer scans + posts births and eulogies idempotently"
```

---

## Task 13: `LifecycleAnnounceService` (thin Service) + retire the death feed

**Files:**
- Create: `app/Services/LifecycleAnnounceService.php`
- Delete: `app/Services/DeathFeed/*` and `tests/Unit/DeathFeedComposerTest.php`
- Modify: `config/personality.php` (remove the now-unused `death.*` pools)

- [ ] **Step 1: Create the periodic Service**

```php
<?php

namespace App\Services;

use App\Services\Lifecycle\AnnouncementGenerator;
use App\Services\Lifecycle\DiscordLifecycleNotifier;
use App\Services\Lifecycle\LifecycleAnnouncer;
use App\Services\Llm\OpenRouterClient;
use App\Services\Personality\MessagePicker;
use App\Services\State\BotState;
use Laracord\Laracord;
use Laracord\Services\Service;

/**
 * Posts births + eulogies every tick. Thin wiring shim over LifecycleAnnouncer. Not gated by
 * BAN_DRY_RUN (channel posts are independent of real Nitrado bans). Auto-discovered from
 * app/Services/. With no OPENROUTER_API_KEY the generator falls back to canned copy automatically.
 */
class LifecycleAnnounceService extends Service
{
    protected int $interval = 60;

    public function __construct(?Laracord $bot = null)
    {
        if ($bot !== null) {
            parent::__construct($bot);
        }

        $this->interval = max(60, (int) config('lifecycle.refresh_minutes', 1) * 60);
    }

    public function handle(): void
    {
        if (! config('lifecycle.enabled', true)) {
            return;
        }

        try {
            $notifier = new DiscordLifecycleNotifier(
                $this->discord(),
                config('lifecycle.births_channel_id'),
                config('lifecycle.eulogy_channel_id'),
            );
            $generator = new AnnouncementGenerator(OpenRouterClient::fromConfig(), new MessagePicker());

            (new LifecycleAnnouncer(
                $generator,
                $notifier,
                new BotState(),
                graceSeconds: (int) config('lifecycle.grace_minutes', 5) * 60,
                maxAgeMinutes: (int) config('lifecycle.max_age_minutes', 30),
            ))->run();
        } catch (\Throwable $e) {
            $this->console()->error('[lifecycle] tick failed: '.$e->getMessage());
        }
    }
}
```

- [ ] **Step 2: Verify the Service loads and is a valid Service subclass**

Run: `php -l app/Services/LifecycleAnnounceService.php && php -r "require 'vendor/autoload.php'; \$r=new ReflectionClass(App\Services\LifecycleAnnounceService::class); var_dump(\$r->isSubclassOf(Laracord\Services\Service::class));"`
Expected: `No syntax errors` and `bool(true)`.

- [ ] **Step 3: Delete the retired death-feed subsystem and its test**

```bash
git rm app/Services/DeathFeed/DeathFeedComposer.php \
       app/Services/DeathFeed/DeathFeedNotifier.php \
       app/Services/DeathFeed/DiscordDeathFeedNotifier.php \
       app/Services/DeathFeed/NullDeathFeedNotifier.php \
       tests/Unit/DeathFeedComposerTest.php
```

- [ ] **Step 4: Remove the now-unused `death.*` pools from `config/personality.php`**

Delete the entire top-level `'death' => [ ... ]` block (the `pvp`, `pvp_noweapon`, `suicide`,
`environment`, `misc` pools — superseded by `eulogy.*`). Leave `birth.*` and `eulogy.*` in place.

- [ ] **Step 5: Confirm nothing still references the deleted classes or pools**

Run: `grep -rn "DeathFeed\|personality.death\|'death'" app/ tests/ config/ | grep -v "death_cause\|death_by\|death_weapon\|death_distance\|death_log\|auto_death\|DeathBanService\|DeathLogCapturer" || echo "clean"`
Expected: `clean` (no references to the removed `DeathFeed` namespace or `death.*` personality pools).

- [ ] **Step 6: Run the FULL suite**

Run: `./vendor/bin/pest`
Expected: PASS — all green (harmless `DEPR` lines may appear; exit 0).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: LifecycleAnnounceService; retire the death feed subsystem"
```

---

## Task 14: Manual wiring verification & CLAUDE.md update

**Files:**
- Modify: `CLAUDE.md` (document the new subsystem)

- [ ] **Step 1: Full suite once more from a clean state**

Run: `./vendor/bin/pest`
Expected: all PASS.

- [ ] **Step 2: Lint the touched app files**

Run: `for f in app/Services/Lifecycle/*.php app/Services/Llm/*.php app/Services/LifecycleAnnounceService.php app/Services/Ban/DeathBanService.php app/Services/Adm/AdmIngestor.php; do php -l "$f"; done`
Expected: `No syntax errors detected` for every file.

- [ ] **Step 3: Document the feature in `CLAUDE.md`**

Under the `## Architecture` bullet list, add a bullet describing the lifecycle subsystem
(births + LLM eulogies), the `app/Services/Llm/OpenRouterClient`, the grace/ban thresholds, the
`death_log` capture, and that the old `DeathFeed/` is retired and `death.*` pools replaced by
`eulogy.*` + `birth.*`. Note `LIFE_GRACE_MINUTES=5`, `BAN_MIN_PLAYTIME_MINUTES=60`, the two channel
env keys, and the `OPENROUTER_*` block. Mention births are delayed ~grace by design, the LLM falls
back to canned copy when unconfigured, and the subsystem is **not** gated by `BAN_DRY_RUN`.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: document births/eulogies/playtime-ban subsystem in CLAUDE.md"
```

- [ ] **Step 5: Push the branch and open a PR (only if the user asks)**

```bash
git push -u origin feat/births-eulogies-playtime-bans
```

---

## Self-Review Notes (for the implementer)

- **Spec coverage:** playtime ban gate (Task 6), births channel (Tasks 11–13), LLM eulogies replacing
  the death feed (Tasks 9–13), de-dup via grace (Task 12), hybrid raw-log + facts context (Tasks 4–7,
  10), mentions on the content line (Tasks 8, 11, 12), newspaper embed format (Tasks 3, 11, 12),
  canned fallback (Tasks 3, 10), config + env + phpunit pins (Task 2). The gender/"it's a boy"
  gimmick is intentionally dropped (no data) per the spec.
- **Idempotency markers:** `birth_announced_at` (births), `eulogy_posted` (eulogies), `ban_issued`
  (bans) — each prevents cross-tick duplicates.
- **Gating consistency:** births gate on `started_at > go_live`; eulogies and bans gate on
  `ended_at > go_live`; all three honor freshness/playtime as specified.
- **Type consistency:** `generate(string $kind, array $facts): array{headline,body}` is used the same
  way in Tasks 10 and 12; `MentionSubstitutor::apply(string, array): string` and
  `DeathLogCapturer::capture(array,string,string,int): string` are consistent across their callers.
