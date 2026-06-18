# Birth Facts Enrichment + Announcement Persistence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make each birth announcement distinct by feeding the LLM four location-safe varying facts, and persist all generated birth/eulogy text (with a fallback flag) for auditing.

**Architecture:** `LifeFactsBuilder` gains four cheap, deterministic facts derived from `life.started_at`. `AnnouncementGenerator::birthPrompt()` sends them and now reports whether it fell back to canned copy. `LifecycleAnnouncer` writes one `announcements` row per published birth and eulogy.

**Tech Stack:** Laracord (Laravel Zero) · PHP 8.2+ · SQLite · Eloquent · Pest.

## Global Constraints

- **TDD:** failing test first, then implementation. Feature tests use `RefreshDatabase` + in-memory SQLite; time-dependent tests use `CarbonImmutable::setTestNow()`.
- **Time:** use `Carbon\CarbonImmutable::now()`, never raw `new DateTime`.
- **Location safety:** no fact may be a coordinate, grid ref, or in-world place name. The enriched `prior_death` must stay **name-free** — never include a killer gamertag.
- **Facts feed the BIRTH prompt only.** The eulogy prompt is unchanged; eulogy *text* is still persisted.
- **No backfill:** the ~40 existing births have no stored text; the table populates going forward only.
- Run the full suite with `./vendor/bin/pest`. PHP 8.5 `DEPR` markers in output are harmless.

---

### Task 1: `announcements` table, model, and `Life` relation

**Files:**
- Create: `database/migrations/2026_06_18_000000_create_announcements_table.php`
- Create: `app/Models/Announcement.php`
- Modify: `app/Models/Life.php` (add relation)
- Test: `tests/Feature/AnnouncementModelTest.php`

**Interfaces:**
- Produces: `App\Models\Announcement` with fillable columns `life_id`, `kind`, `headline`, `body`, `was_fallback` (cast bool), `model` (nullable string); `Announcement::life()` belongsTo; `Life::announcements()` hasMany.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/AnnouncementModelTest.php

use App\Models\Announcement;
use App\Models\Life;
use App\Models\Player;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('persists an announcement and links it to its life', function () {
    $p = Player::create(['gamertag' => 'Tag', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => now(), 'playtime_seconds' => 0]);

    $a = Announcement::create([
        'life_id' => $life->id,
        'kind' => 'birth',
        'headline' => 'WELCOME',
        'body' => '{{PLAYER}} arrives.',
        'was_fallback' => true,
        'model' => null,
    ]);

    expect($a->was_fallback)->toBeTrue();          // boolean cast
    expect($a->model)->toBeNull();
    expect($life->announcements()->count())->toBe(1);
    expect($life->announcements->first()->kind)->toBe('birth');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/AnnouncementModelTest.php`
Expected: FAIL — `Class "App\Models\Announcement" not found` (and no `announcements` table).

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_06_18_000000_create_announcements_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            // cascade: an announcement is meaningless once its life is gone.
            $t->foreignId('life_id')->constrained()->cascadeOnDelete();
            $t->string('kind');              // 'birth' | 'eulogy'
            $t->text('headline');
            $t->text('body');
            $t->boolean('was_fallback')->default(false); // true => LLM failed, canned copy used
            $t->string('model')->nullable();             // e.g. 'anthropic/claude-haiku-4.5'; null when fallback
            $t->timestamps();
            $t->index(['life_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php
// app/Models/Announcement.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $guarded = [];
    protected $casts = ['was_fallback' => 'boolean'];

    public function life() { return $this->belongsTo(Life::class); }
}
```

- [ ] **Step 5: Add the relation to `Life`**

In `app/Models/Life.php`, add alongside the existing `sessions()` relation:

```php
    public function announcements() { return $this->hasMany(Announcement::class); }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/AnnouncementModelTest.php`
Expected: PASS (1 passed).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_18_000000_create_announcements_table.php app/Models/Announcement.php app/Models/Life.php tests/Feature/AnnouncementModelTest.php
git commit -m "feat: announcements table + model for persisting birth/eulogy text"
```

---

### Task 2: New facts — population, weekly counts, time of day

**Files:**
- Modify: `app/Services/Lifecycle/LifeFactsBuilder.php`
- Test: `tests/Unit/LifeFactsBuilderTest.php` (add cases)

**Interfaces:**
- Produces: `LifeFactsBuilder::build()` output gains keys `population_at_spawn` (int), `births_this_week` (int), `deaths_this_week` (int), `time_of_day` (string: `dawn`|`day`|`dusk`|`night`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/LifeFactsBuilderTest.php`:

```php
it('counts the world the player spawned into, excluding their own session', function () {
    $subject = Player::create(['gamertag' => 'New', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $a = Player::create(['gamertag' => 'A', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $b = Player::create(['gamertag' => 'B', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $life = Life::create(['player_id' => $subject->id, 'started_at' => '2026-06-14T12:00:00Z', 'playtime_seconds' => 0]);

    // A is online across the spawn instant (open session) -> counts.
    \App\Models\GameSession::create(['player_id' => $a->id, 'life_id' => $life->id, 'connected_at' => '2026-06-14T11:50:00Z', 'disconnected_at' => null]);
    // B logged out before spawn -> does not count.
    \App\Models\GameSession::create(['player_id' => $b->id, 'life_id' => $life->id, 'connected_at' => '2026-06-14T10:00:00Z', 'disconnected_at' => '2026-06-14T11:00:00Z']);
    // Subject's own open session -> excluded.
    \App\Models\GameSession::create(['player_id' => $subject->id, 'life_id' => $life->id, 'connected_at' => '2026-06-14T12:00:00Z', 'disconnected_at' => null]);

    $facts = (new LifeFactsBuilder())->build($life);

    expect($facts['population_at_spawn'])->toBe(1); // only A
});

it('counts births and deaths in the 7 days before the spawn', function () {
    $p = Player::create(['gamertag' => 'P', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    // Within the window [spawn-7d, spawn): 2 births, 1 death.
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-10T00:00:00Z', 'ended_at' => '2026-06-11T00:00:00Z', 'death_cause' => 'pvp', 'playtime_seconds' => 60]);
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-12T00:00:00Z', 'playtime_seconds' => 60]);
    // Outside the window (older than 7 days): ignored.
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-01T00:00:00Z', 'ended_at' => '2026-06-02T00:00:00Z', 'death_cause' => 'pvp', 'playtime_seconds' => 60]);

    $life = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T12:00:00Z', 'playtime_seconds' => 0]);

    $facts = (new LifeFactsBuilder())->build($life);

    expect($facts['births_this_week'])->toBe(2); // the two inside the window (the subject's own life starts AT the boundary, excluded by `<`)
    expect($facts['deaths_this_week'])->toBe(1); // only the 06-11 death (06-02 is >7d before)
});

it('buckets the spawn hour into a time of day', function () {
    $p = Player::create(['gamertag' => 'Q', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $cases = [
        '2026-06-14T06:00:00Z' => 'dawn',
        '2026-06-14T12:00:00Z' => 'day',
        '2026-06-14T18:00:00Z' => 'dusk',
        '2026-06-14T23:00:00Z' => 'night',
        '2026-06-14T03:00:00Z' => 'night',
    ];
    foreach ($cases as $ts => $expected) {
        $life = Life::create(['player_id' => $p->id, 'started_at' => $ts, 'playtime_seconds' => 0]);
        expect((new LifeFactsBuilder())->build($life)['time_of_day'])->toBe($expected);
    }
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/LifeFactsBuilderTest.php`
Expected: FAIL — `Undefined array key "population_at_spawn"` (and the other new keys).

- [ ] **Step 3: Implement the new facts**

In `app/Services/Lifecycle/LifeFactsBuilder.php`, add the import near the top:

```php
use App\Models\GameSession;
```

Add these four keys to the array returned by `build()` (place them after `'witnesses' => $this->witnesses($life),`):

```php
            'population_at_spawn' => $this->populationAtSpawn($life),
            'births_this_week' => $this->birthsThisWeek($life),
            'deaths_this_week' => $this->deathsThisWeek($life),
            'time_of_day' => $this->timeOfDay($life),
```

Add these private methods to the class:

```php
    /** Distinct OTHER players whose session spans the spawn instant — "the world they spawned into". */
    private function populationAtSpawn(Life $life): int
    {
        $at = $life->started_at;

        return GameSession::query()
            ->where('player_id', '!=', $life->player_id) // exclude the subject's own session
            ->where('connected_at', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('disconnected_at')->orWhere('disconnected_at', '>', $at);
            })
            ->distinct()
            ->count('player_id');
    }

    private function birthsThisWeek(Life $life): int
    {
        $start = $life->started_at->copy()->subDays(7);

        return Life::query()
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $life->started_at)
            ->count();
    }

    private function deathsThisWeek(Life $life): int
    {
        $start = $life->started_at->copy()->subDays(7);

        return Life::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '>=', $start)
            ->where('ended_at', '<', $life->started_at)
            ->count();
    }

    /** Pure: UTC spawn hour -> atmospheric bucket. */
    private function timeOfDay(Life $life): string
    {
        $hour = (int) $life->started_at->copy()->utc()->format('G');

        return match (true) {
            $hour >= 5 && $hour < 8 => 'dawn',
            $hour >= 8 && $hour < 17 => 'day',
            $hour >= 17 && $hour < 20 => 'dusk',
            default => 'night',
        };
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/LifeFactsBuilderTest.php`
Expected: PASS (all cases, including the pre-existing ones).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Lifecycle/LifeFactsBuilder.php tests/Unit/LifeFactsBuilderTest.php
git commit -m "feat: add population/weekly-count/time-of-day facts to lives"
```

---

### Task 3: Enriched, name-free `prior_death`

**Files:**
- Modify: `app/Services/Lifecycle/LifeFactsBuilder.php` (change `priorDeath()` return shape)
- Test: `tests/Unit/LifeFactsBuilderTest.php` (update existing assertions)

**Interfaces:**
- Produces: `prior_death` is now `null` (first life) or `array{cause:?string, weapon:?string, distance_m:?float, playtime_human:string}` — **no killer gamertag**. `is_first_life` is still `$prior === null`.
- Consumes: nothing new.

- [ ] **Step 1: Update the existing failing tests**

In `tests/Unit/LifeFactsBuilderTest.php`, the prior-death cases currently assert a string. Replace the two `toContain` assertions:

- The case asserting `expect($facts['prior_death'])->toContain('environment');` becomes:

```php
    expect($facts['prior_death']['cause'])->toBe('environment');
```

- The case asserting the prior killer never leaks (currently `->toContain('pvp')` then `->not->toContain('PriorSniper')`) becomes:

```php
    expect($facts['prior_death']['cause'])->toBe('pvp');
    expect(json_encode($facts['prior_death']))->not->toContain('PriorSniper'); // name-free: no killer gamertag in any field
```

Leave the `expect($facts['prior_death'])->toBeNull();` first-life case unchanged.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/LifeFactsBuilderTest.php`
Expected: FAIL — the array-access assertions fail because `prior_death` is still a string (`Cannot access offset 'cause' on string` / type error).

- [ ] **Step 3: Change `priorDeath()` to return structured, name-free data**

In `app/Services/Lifecycle/LifeFactsBuilder.php`, replace the body of `priorDeath()` (keep the query that finds `$prev`) so the return is:

```php
        if (! $prev) return null;

        // Name-free by design: never include who ended the prior life. The birth/eulogy prompt
        // renders any gamertag as a {{KILLER}} token, which would leak or mis-point on a birth
        // (no killer) or a different-killer eulogy. Cause/weapon/distance/age name nobody.
        return [
            'cause' => $prev->death_cause,
            'weapon' => $prev->death_weapon,
            'distance_m' => $prev->death_distance,
            'playtime_human' => SessionDuration::human((int) $prev->playtime_seconds),
        ];
```

Update the method's PHPDoc return type to `@return array<string,mixed>|null`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/LifeFactsBuilderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Lifecycle/LifeFactsBuilder.php tests/Unit/LifeFactsBuilderTest.php
git commit -m "feat: enrich prior_death into structured name-free facts"
```

---

### Task 4: Birth prompt uses new facts + generator reports fallback

**Files:**
- Modify: `app/Services/Lifecycle/AnnouncementGenerator.php`
- Test: `tests/Feature/AnnouncementGeneratorTest.php` (add cases + update respawn case)

**Interfaces:**
- Consumes: facts keys `population_at_spawn`, `births_this_week`, `deaths_this_week`, `time_of_day`, and the structured `prior_death` (Tasks 2–3).
- Produces: `generate()` returns `array{headline:string, body:string, fallback:bool}` — `fallback` is `true` when canned copy was used, `false` on a successful LLM completion. `payload()` callers that destructure only `headline`/`body` are unaffected.

- [ ] **Step 1: Write/update the failing tests**

In `tests/Feature/AnnouncementGeneratorTest.php`:

(a) Update the genFacts helper default so the new facts exist for every case — add these keys inside the `array_merge` defaults:

```php
        'population_at_spawn' => 4, 'births_this_week' => 3, 'deaths_this_week' => 5, 'time_of_day' => 'dusk',
```

(b) Replace the respawn case (the one passing `'prior_death' => 'previous life ended (pvp) after 18 minutes'`) with the structured shape:

```php
it('birth prompt for a respawn passes the real prior-life summary', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => "BACK AGAIN\n📰 {{PLAYER}} returns."]]],
    ])]);
    $gen = new AnnouncementGenerator(new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0), new MessagePicker());

    $gen->generate('birth', genFacts([
        'is_first_life' => false,
        'prior_death' => ['cause' => 'pvp', 'weapon' => 'AKM', 'distance_m' => 18.0, 'playtime_human' => '18 minutes'],
    ]));

    Http::assertSent(function ($r) {
        $user = $r['messages'][1]['content'];
        return str_contains($user, '"is_first_life_ever": false')
            && str_contains($user, '"cause": "pvp"')
            && str_contains($user, '18 minutes');
    });
});
```

(c) Add a case asserting the new facts reach the birth prompt:

```php
it('birth prompt includes population, weekly counts, and time of day', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => "DAWN ARRIVAL\n📰 {{PLAYER}} appears."]]],
    ])]);
    $gen = new AnnouncementGenerator(new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0), new MessagePicker());

    $gen->generate('birth', genFacts([
        'is_first_life' => true,
        'population_at_spawn' => 7, 'births_this_week' => 2, 'deaths_this_week' => 9, 'time_of_day' => 'dawn',
    ]));

    Http::assertSent(function ($r) {
        $user = $r['messages'][1]['content'];
        return str_contains($user, '"players_online_at_spawn": 7')
            && str_contains($user, '"births_this_week": 2')
            && str_contains($user, '"deaths_this_week": 9')
            && str_contains($user, '"time_of_day": "dawn"');
    });
});
```

(d) Add cases asserting the `fallback` flag:

```php
it('reports fallback=false on a successful completion', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => "H\n📰 body"]]],
    ])]);
    $gen = new AnnouncementGenerator(new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0), new MessagePicker());

    expect($gen->generate('birth', genFacts(['is_first_life' => true]))['fallback'])->toBeFalse();
});

it('reports fallback=true when the client throws', function () {
    Http::fake(['*/chat/completions' => Http::response([], 500)]);
    $gen = new AnnouncementGenerator(
        new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0),
        new MessagePicker(fn (array $pool, ?int $avoid) => 0),
    );

    expect($gen->generate('eulogy', genFacts())['fallback'])->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/AnnouncementGeneratorTest.php`
Expected: FAIL — missing `players_online_at_spawn` key in the prompt and missing `fallback` key in the result.

- [ ] **Step 3: Add the facts to the birth payload**

In `app/Services/Lifecycle/AnnouncementGenerator.php`, inside `birthPrompt()`, extend the `$payload` array with the new facts:

```php
        $payload = [
            'kind' => 'birth',
            'subject_placeholder' => '{{PLAYER}}',
            'is_first_life_ever' => $isFirst,
            'previous_life' => $isFirst ? null : $facts['prior_death'],
            'players_online_at_spawn' => $facts['population_at_spawn'],
            'births_this_week' => $facts['births_this_week'],
            'deaths_this_week' => $facts['deaths_this_week'],
            'time_of_day' => $facts['time_of_day'],
            'real_survivors_for_quotes' => $facts['witnesses'] ?? [],
        ];
```

- [ ] **Step 4: Make `generate()` report fallback**

In `AnnouncementGenerator.php`, change `generate()` so both paths return the flag:

```php
    public function generate(string $kind, array $facts): array
    {
        try {
            $raw = $this->client->complete(self::SYSTEM, $this->userPrompt($kind, $facts));

            return $this->split($raw) + ['fallback' => false];
        } catch (\Throwable) {
            return $this->fallback($kind, $facts) + ['fallback' => true];
        }
    }
```

Update the `generate()` PHPDoc `@return` to `array{headline:string,body:string,fallback:bool}`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/AnnouncementGeneratorTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Lifecycle/AnnouncementGenerator.php tests/Feature/AnnouncementGeneratorTest.php
git commit -m "feat: feed new facts to birth prompt + report canned fallback"
```

---

### Task 5: Persist announcements in the announcer

**Files:**
- Modify: `app/Services/Lifecycle/LifecycleAnnouncer.php`
- Test: `tests/Feature/LifecycleAnnouncerTest.php` (add cases)

**Interfaces:**
- Consumes: `generate()` returning `{headline, body, fallback}` (Task 4); `App\Models\Announcement` (Task 1); `config('llm.model')`.
- Produces: one `announcements` row per published birth/eulogy.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/LifecycleAnnouncerTest.php`. This file already provides `lifeWith($tag, $playtime, $endedAt, $startedAt)`, the `RecordingLifecycleNotifier` class, and a `beforeEach` that sets `$this->state` (`go_live_at = 2026-06-14T08:00:00+00:00`), `$this->notifier`, and test-now `2026-06-14T12:00:00Z`.

The existing `makeAnnouncer()` helper uses `OpenRouterClient::fromConfig()`, which has **no API key in tests**, so it always falls back. The first test below therefore builds its own announcer with a **keyed** client plus a 2xx `Http::fake` so we exercise the real LLM path (`was_fallback=false`, `model` set). The second test reuses `makeAnnouncer()` to confirm the fallback path records `was_fallback=true`, `model=null`.

```php
use App\Models\Announcement;

it('persists a birth and eulogy row via the real LLM path', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => "HEAD\n📰 {{PLAYER}} body"]]],
    ])]);

    $birth = lifeWith('Born', 360, null);                          // open, 6 min, started 11:50 (due)
    $death = lifeWith('Dead', 360, '2026-06-14T11:55:00Z');        // ended 11:55, 6 min (due)

    $gen = new AnnouncementGenerator(
        new OpenRouterClient('sk', 'm', 'https://x/api/v1', 20, 900, 1.0),
        new MessagePicker(fn ($p, $a) => 0),
    );
    (new LifecycleAnnouncer($gen, $this->notifier, $this->state, graceSeconds: 300, maxAgeMinutes: 30))->run();

    $b = Announcement::where('life_id', $birth->id)->where('kind', 'birth')->first();
    expect($b)->not->toBeNull();
    expect($b->was_fallback)->toBeFalse();
    expect($b->model)->toBe(config('llm.model'));
    expect($b->headline)->toBe('HEAD');
    expect($b->body)->toContain('{{PLAYER}}'); // raw template stored, pre-substitution

    expect(Announcement::where('life_id', $death->id)->where('kind', 'eulogy')->count())->toBe(1);
});

it('records was_fallback=true and null model on the canned path', function () {
    $birth = lifeWith('Canned', 360, null); // due birth; fromConfig client has no key -> fallback

    makeAnnouncer($this->state, $this->notifier)->run();

    $b = Announcement::where('life_id', $birth->id)->where('kind', 'birth')->first();
    expect($b)->not->toBeNull();
    expect($b->was_fallback)->toBeTrue();
    expect($b->model)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LifecycleAnnouncerTest.php`
Expected: FAIL — no `announcements` rows written (count 0 / null).

- [ ] **Step 3: Persist the row in both announce paths**

In `app/Services/Lifecycle/LifecycleAnnouncer.php`, add the import:

```php
use App\Models\Announcement;
```

In `announceBirths()`, replace the publish+marker block with one that records the row first:

```php
            $facts = $this->factsBuilder()->build($life);
            $copy = $this->generator->generate('birth', $facts);
            $this->notifier->publishBirth($this->payload($copy, $facts, self::BIRTH_COLOR, $life, 'born'));
            $this->record($life, 'birth', $copy);
            $life->update(['birth_announced_at' => CarbonImmutable::now()]);
```

In `announceEulogies()`, similarly:

```php
            $facts = $this->factsBuilder()->build($life);
            $copy = $this->generator->generate('eulogy', $facts);
            $this->notifier->publishEulogy($this->payload($copy, $facts, self::EULOGY_COLOR, $life, 'died'));
            $this->record($life, 'eulogy', $copy);
            $life->update(['eulogy_posted' => true]);
```

Add the helper method to the class:

```php
    /**
     * Persist the raw generated copy (pre-substitution, so the stored text is the canonical
     * {{PLAYER}}-templated output) for auditing and repetition checks. model is null on fallback.
     *
     * @param array{headline:string,body:string,fallback:bool} $copy
     */
    private function record(Life $life, string $kind, array $copy): void
    {
        Announcement::create([
            'life_id' => $life->id,
            'kind' => $kind,
            'headline' => $copy['headline'],
            'body' => $copy['body'],
            'was_fallback' => $copy['fallback'],
            'model' => $copy['fallback'] ? null : config('llm.model'),
        ]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LifecycleAnnouncerTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full suite**

Run: `./vendor/bin/pest`
Expected: green (0 failures; `DEPR` markers are harmless).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Lifecycle/LifecycleAnnouncer.php tests/Feature/LifecycleAnnouncerTest.php
git commit -m "feat: persist generated birth/eulogy text to announcements table"
```

---

## Self-Review

**Spec coverage:**
- Facts: `population_at_spawn`, `births_this_week`, `deaths_this_week`, `time_of_day` → Task 2; enriched name-free `prior_death` → Task 3. ✓
- Birth prompt sends new facts → Task 4. ✓
- `announcements` table (births + eulogies, `was_fallback`, `model`) → Tasks 1 + 5. ✓
- Generator `fallback` flag → Task 4. ✓
- Store raw pre-substitution template → Task 5 `record()`. ✓
- Non-goals (no backfill, eulogy prompt unchanged) → respected; eulogy prompt is never edited. ✓

**Placeholder scan:** Task 5's test references existing-file helpers with an explicit instruction to copy the real arrangement if they don't exist — no invented names ship in code. All code steps contain full code.

**Type consistency:** `prior_death` array shape `{cause, weapon, distance_m, playtime_human}` is identical in Task 3 (producer) and Task 4 (consumer). `generate()` return `{headline, body, fallback}` is consistent across Tasks 4 and 5. `record(Life, string, array)` and `Announcement` columns match Task 1's schema.
