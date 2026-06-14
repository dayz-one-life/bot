# Bunker Visit Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track each player's bunker visits (detected from the explicit `RestrictedAreaBunkerEntrance` teleport line in the ADM logs) and add two new leaderboard boards: Most Bunker Visits and Quickest New-Life → Bunker.

**Architecture:** Detection keys off a deterministic, self-labeling ADM teleport line — no proximity/radius. A pure parser method recognizes the entrance line; a plain `BunkerVisitService` records visits into a new `bunker_visits` table, de-duping with a 60-minute per-player cooldown and associating the life via a timestamp-window query (correct for both live ingest and history backfill). The ingestor calls the service inline alongside the existing position recorder. Two read-only queries feed the existing leaderboard composer/notifier. A backfill console command replays ADM history.

**Tech Stack:** PHP 8.2+, Laracord (Laravel Zero + DiscordPHP), SQLite, Pest. Follows the repo convention: business logic in testable plain services; ingest hook, console command, and periodic Service are thin wrappers.

---

## File Structure

- **Create** `database/migrations/2026_06_14_000000_create_bunker_visits_table.php` — `bunker_visits` table.
- **Create** `app/Models/BunkerVisit.php` — Eloquent model.
- **Create** `config/bunker.php` — `enabled` + `cooldown_minutes` (env-overridable).
- **Create** `app/Services/Bunker/BunkerVisitService.php` — record + cooldown de-dup + life-window association.
- **Create** `app/Services/Adm/BunkerVisitBackfillService.php` — replay ADM history through the service.
- **Create** `app/Console/Commands/BackfillBunkerVisitsCommand.php` — `adm:backfill-bunker-visits`.
- **Modify** `app/Services/Adm/AdmParser.php` — add `parseBunkerEntrance()`.
- **Modify** `app/Services/Adm/AdmIngestor.php` — inject the service, call it in `processFile`.
- **Modify** `app/Services/Leaderboard/LeaderboardStatsService.php` — `mostBunkerVisits()` + `quickestNewLifeToBunker()`.
- **Modify** `app/Services/Leaderboard/LeaderboardComposer.php` — generalize `countRows` noun, add 2 fields.
- **Modify** `app/Services/LeaderboardService.php` — pass the 2 new board keys.
- **Modify** `phpunit.xml` — pin the 2 new config defaults.
- **Modify** `tests/Unit/LeaderboardComposerTest.php` — add the 2 new keys to the `lbBoards()` helper + field-count.
- **Modify** docs: `CLAUDE.md`, `.env` (operator), README — document the feature.

---

### Task 1: Migration + Model

**Files:**
- Create: `database/migrations/2026_06_14_000000_create_bunker_visits_table.php`
- Create: `app/Models/BunkerVisit.php`
- Test: `tests/Feature/BunkerVisitModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a bunker visit with player, life and visited_at', function () {
    $player = Player::create(['gamertag' => 'Alice']);
    $life = Life::create(['player_id' => $player->id, 'started_at' => CarbonImmutable::parse('2026-06-14 01:00:00')]);

    $visit = BunkerVisit::create([
        'player_id' => $player->id,
        'life_id' => $life->id,
        'visited_at' => CarbonImmutable::parse('2026-06-14 02:30:35'),
    ]);

    expect($visit->fresh()->visited_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($visit->player->gamertag)->toBe('Alice')
        ->and($visit->life->id)->toBe($life->id);
});

it('allows a null life_id', function () {
    $player = Player::create(['gamertag' => 'Bob']);
    $visit = BunkerVisit::create([
        'player_id' => $player->id,
        'life_id' => null,
        'visited_at' => CarbonImmutable::parse('2026-06-14 02:30:35'),
    ]);
    expect($visit->fresh()->life_id)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BunkerVisitModelTest.php`
Expected: FAIL — `Class "App\Models\BunkerVisit" not found` / no such table `bunker_visits`.

- [ ] **Step 3a: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bunker_visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            $t->foreignId('life_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('visited_at');
            $t->timestamps();
            $t->index(['player_id', 'visited_at']);
            $t->index('visited_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bunker_visits');
    }
};
```

- [ ] **Step 3b: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BunkerVisit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'visited_at' => 'immutable_datetime',
    ];

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function life()
    {
        return $this->belongsTo(Life::class);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BunkerVisitModelTest.php`
Expected: PASS (2 passed). `RefreshDatabase` runs the new migration on the in-memory DB.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_14_000000_create_bunker_visits_table.php app/Models/BunkerVisit.php tests/Feature/BunkerVisitModelTest.php
git commit -m "feat: bunker_visits table + model"
```

---

### Task 2: Parser — `parseBunkerEntrance`

**Files:**
- Modify: `app/Services/Adm/AdmParser.php`
- Test: `tests/Unit/AdmParserTest.php`

- [ ] **Step 1: Write the failing test** (append these cases to `tests/Unit/AdmParserTest.php`)

```php
it('parses a bunker entrance teleport line', function () {
    $parser = new App\Services\Adm\AdmParser();
    $line = '02:30:35 | Player "RonaldRaygun552" (id=89B90470 pos=<5154.0, 1075.1, 56.3>) was teleported from: <4767.4, 339.4, 10376.3> to: <5154.0, 56.3, 1075.1>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerEntrance';
    expect($parser->parseBunkerEntrance($line))->toBe(['gamertag' => 'RonaldRaygun552']);
});

it('ignores a bunker exit teleport line', function () {
    $parser = new App\Services\Adm\AdmParser();
    $line = '03:01:32 | Player "RonaldRaygun552" (id=89B90470 pos=<4828.4, 10291.8, 339.9>) was teleported from: <5005.0, 17.7, 1086.6> to: <4828.4, 339.9, 10291.7>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerExit';
    expect($parser->parseBunkerEntrance($line))->toBeNull();
});

it('ignores connect, death and bare position lines for bunker entrance', function () {
    $parser = new App\Services\Adm\AdmParser();
    expect($parser->parseBunkerEntrance('12:34:56 | Player "Bob" (id=XYZ pos=<100.0, 200.0, 5.0>) is connected'))->toBeNull();
    expect($parser->parseBunkerEntrance('12:34:56 | Player "Bob" (DEAD) (id=XYZ pos=<1,2,3>) killed by Player "Eve" (id=ABC)'))->toBeNull();
    expect($parser->parseBunkerEntrance('12:34:56 | Player "Bob" (id=XYZ pos=<100.0, 200.0, 5.0>)'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/AdmParserTest.php`
Expected: FAIL — `Call to undefined method ...::parseBunkerEntrance()`.

- [ ] **Step 3: Implement the parser method**

Add the constant alongside the other `*_RE` constants near the top of the class (after line 15):

```php
    private const BUNKER_ENTRANCE_RE = '/Player "([^"]+)".*was teleported.*RestrictedAreaBunkerEntrance/u';
```

Add the method (e.g. directly after `parseDisconnect()`):

```php
    /**
     * A bunker visit: the player was teleported into the bunker's restricted area on
     * spawn-in. Self-labeling via the reason string — coordinate-independent. Returns
     * the gamertag, or null for the exit line / any other line.
     */
    public function parseBunkerEntrance(string $raw): ?array
    {
        if (!preg_match(self::BUNKER_ENTRANCE_RE, $raw, $m)) return null;
        return ['gamertag' => $m[1]];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/AdmParserTest.php`
Expected: PASS (all parser tests green, including the 3 new ones).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Adm/AdmParser.php tests/Unit/AdmParserTest.php
git commit -m "feat: parse RestrictedAreaBunkerEntrance teleport lines"
```

---

### Task 3: Config + phpunit pins

**Files:**
- Create: `config/bunker.php`
- Modify: `phpunit.xml:25` (inside the `<php>` `<env>` block)
- Test: `tests/Feature/BunkerConfigTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

it('exposes bunker config defaults', function () {
    expect(config('bunker.enabled'))->toBeTrue()
        ->and(config('bunker.cooldown_minutes'))->toBe(60);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BunkerConfigTest.php`
Expected: FAIL — `config('bunker.cooldown_minutes')` is null (no config file yet).

- [ ] **Step 3a: Create `config/bunker.php`**

```php
<?php

return [
    'enabled'          => filter_var(env('BUNKER_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOL),
    'cooldown_minutes' => (int) env('BUNKER_VISIT_COOLDOWN_MINUTES', 60),
];
```

- [ ] **Step 3b: Pin defaults in `phpunit.xml`**

Add these two lines after the `LEADERBOARD_TOP_COUNT` env line (currently line 25), still inside the `<php>` block:

```xml
        <env name="BUNKER_TRACKING_ENABLED" value="true"/>
        <env name="BUNKER_VISIT_COOLDOWN_MINUTES" value="60"/>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BunkerConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/bunker.php phpunit.xml tests/Feature/BunkerConfigTest.php
git commit -m "feat: bunker config (enabled, cooldown_minutes) + phpunit pins"
```

---

### Task 4: `BunkerVisitService`

**Files:**
- Create: `app/Services/Bunker/BunkerVisitService.php`
- Test: `tests/Feature/BunkerVisitServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use App\Services\Bunker\BunkerVisitService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bvLife(string $tag, string $start, ?string $end = null): array
{
    $player = Player::firstOrCreate(['gamertag' => $tag]);
    $life = Life::create([
        'player_id' => $player->id,
        'started_at' => CarbonImmutable::parse($start),
        'ended_at' => $end ? CarbonImmutable::parse($end) : null,
    ]);
    return [$player, $life];
}

it('records a visit and associates the life containing visited_at', function () {
    [$player, $life] = bvLife('Alice', '2026-06-14 01:00:00');

    $visit = (new BunkerVisitService())->record('Alice', new DateTimeImmutable('2026-06-14 02:30:35'));

    expect($visit)->not->toBeNull()
        ->and($visit->player_id)->toBe($player->id)
        ->and($visit->life_id)->toBe($life->id)
        ->and($visit->visited_at->format('Y-m-d H:i:s'))->toBe('2026-06-14 02:30:35');
});

it('skips a second visit inside the cooldown window', function () {
    bvLife('Alice', '2026-06-14 01:00:00');
    $svc = new BunkerVisitService();

    $svc->record('Alice', new DateTimeImmutable('2026-06-14 02:00:00'));
    $second = $svc->record('Alice', new DateTimeImmutable('2026-06-14 02:30:00')); // +30min, cooldown 60

    expect($second)->toBeNull()
        ->and(BunkerVisit::count())->toBe(1);
});

it('records again after the cooldown window', function () {
    bvLife('Alice', '2026-06-14 01:00:00');
    $svc = new BunkerVisitService();

    $svc->record('Alice', new DateTimeImmutable('2026-06-14 02:00:00'));
    $second = $svc->record('Alice', new DateTimeImmutable('2026-06-14 03:01:00')); // +61min

    expect($second)->not->toBeNull()
        ->and(BunkerVisit::count())->toBe(2);
});

it('records with null life when the player has no life containing the timestamp', function () {
    Player::create(['gamertag' => 'Ghost']); // no life rows

    $visit = (new BunkerVisitService())->record('Ghost', new DateTimeImmutable('2026-06-14 02:30:35'));

    expect($visit)->not->toBeNull()
        ->and($visit->life_id)->toBeNull();
});

it('associates the correct historical life when several exist', function () {
    [$player] = bvLife('Alice', '2026-06-10 00:00:00', '2026-06-11 00:00:00'); // old, ended
    [, $current] = bvLife('Alice', '2026-06-14 01:00:00');                      // open

    $visit = (new BunkerVisitService())->record('Alice', new DateTimeImmutable('2026-06-14 02:30:35'));

    expect($visit->life_id)->toBe($current->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BunkerVisitServiceTest.php`
Expected: FAIL — `Class "App\Services\Bunker\BunkerVisitService" not found`.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Services\Bunker;

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use Carbon\CarbonImmutable;

/**
 * Records bunker visits (RestrictedAreaBunkerEntrance teleports). De-dupes rapid
 * relogs inside the bunker via a per-player cooldown. Associates the life whose
 * window [started_at, ended_at) contains the visit — correct for both live ingest
 * (the open life) and history backfill (the matching historical life). DB-only;
 * not gated by BAN_DRY_RUN.
 */
class BunkerVisitService
{
    public function record(string $gamertag, \DateTimeImmutable $ts): ?BunkerVisit
    {
        if (! config('bunker.enabled', true)) {
            return null;
        }

        $player = Player::firstOrCreate(['gamertag' => $gamertag]);
        $tsC = CarbonImmutable::instance($ts);

        $cooldownMinutes = (int) config('bunker.cooldown_minutes', 60);
        $windowStart = $tsC->subMinutes($cooldownMinutes);

        $recent = BunkerVisit::where('player_id', $player->id)
            ->where('visited_at', '>=', $windowStart)
            ->where('visited_at', '<=', $tsC)
            ->exists();
        if ($recent) {
            return null;
        }

        $life = Life::where('player_id', $player->id)
            ->where('started_at', '<=', $tsC)
            ->where(function ($q) use ($tsC) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>=', $tsC);
            })
            ->orderByDesc('started_at')
            ->first();

        return BunkerVisit::create([
            'player_id' => $player->id,
            'life_id' => $life?->id,
            'visited_at' => $tsC,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BunkerVisitServiceTest.php`
Expected: PASS (5 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bunker/BunkerVisitService.php tests/Feature/BunkerVisitServiceTest.php
git commit -m "feat: BunkerVisitService (cooldown de-dup + life-window association)"
```

---

### Task 5: Wire detection into `AdmIngestor`

**Files:**
- Modify: `app/Services/Adm/AdmIngestor.php`
- Test: `tests/Feature/BunkerVisitIngestTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use App\Services\Adm\AdmIngestor;
use App\Services\Adm\AdmParser;
use App\Services\Life\LifeTracker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a bunker visit while ingesting an entrance line', function () {
    // Pre-existing open life (player logged out inside the bunker earlier).
    $player = Player::create(['gamertag' => 'RonaldRaygun552']);
    $life = Life::create(['player_id' => $player->id, 'started_at' => CarbonImmutable::parse('2026-06-14 00:00:00')]);

    $content = implode("\n", [
        'AdminLog started on 2026-06-14 at 02:00:00',
        '02:30:35 | Player "RonaldRaygun552" (id=89B90470 pos=<5154.0, 1075.1, 56.3>) was teleported from: <4767.4, 339.4, 10376.3> to: <5154.0, 56.3, 1075.1>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerEntrance',
        '02:30:35 | Player "RonaldRaygun552" (id=89B90470 pos=<5154.1, 1075.1, 56.4>) is connected',
    ]);

    $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
    $ingestor->processFile($content, 0, new DateTimeImmutable('2026-06-14 00:00:00'), 0);

    expect(BunkerVisit::count())->toBe(1)
        ->and(BunkerVisit::first()->life_id)->toBe($life->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BunkerVisitIngestTest.php`
Expected: FAIL — `BunkerVisit::count()` is 0 (ingestor doesn't detect entrances yet).

- [ ] **Step 3a: Inject the service into the constructor**

Replace the constructor and add the property (top of `AdmIngestor`, currently lines 12-20):

```php
    private PositionRecorder $positions;
    private \App\Services\Bunker\BunkerVisitService $bunkerVisits;

    public function __construct(
        private AdmParser $parser,
        private LifeTracker $tracker,
        ?PositionRecorder $positions = null,
        ?\App\Services\Bunker\BunkerVisitService $bunkerVisits = null,
    ) {
        $this->positions = $positions ?? new PositionRecorder();
        $this->bunkerVisits = $bunkerVisits ?? new \App\Services\Bunker\BunkerVisitService();
    }
```

- [ ] **Step 3b: Add the detection hook in `processFile`**

Immediately after the existing position-recorder block (currently lines 119-121), add:

```php
            if (($b = $this->parser->parseBunkerEntrance($raw)) !== null) {
                $this->bunkerVisits->record($b['gamertag'], $ts);
            }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BunkerVisitIngestTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full Adm/ingest suite to confirm no regressions**

Run: `./vendor/bin/pest tests/Unit/AdmParserTest.php tests/Feature --filter=Ingest`
Expected: PASS (existing ingestor tests still green — the new constructor arg has a default).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Adm/AdmIngestor.php tests/Feature/BunkerVisitIngestTest.php
git commit -m "feat: detect bunker visits during ADM ingest"
```

---

### Task 6: Leaderboard queries

**Files:**
- Modify: `app/Services/Leaderboard/LeaderboardStatsService.php`
- Test: `tests/Feature/BunkerLeaderboardStatsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use App\Services\Leaderboard\LeaderboardStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedVisit(string $tag, string $lifeStart, string $visitedAt): void
{
    $player = Player::firstOrCreate(['gamertag' => $tag]);
    $life = Life::create(['player_id' => $player->id, 'started_at' => CarbonImmutable::parse($lifeStart)]);
    BunkerVisit::create([
        'player_id' => $player->id,
        'life_id' => $life->id,
        'visited_at' => CarbonImmutable::parse($visitedAt),
    ]);
}

it('ranks most bunker visits desc, tie-break earliest first visit', function () {
    seedVisit('Alice', '2026-06-14 00:00:00', '2026-06-14 00:10:00');
    seedVisit('Alice', '2026-06-14 01:00:00', '2026-06-14 01:10:00');
    seedVisit('Bob', '2026-06-13 00:00:00', '2026-06-13 00:10:00'); // 1 visit, earliest

    $rows = (new LeaderboardStatsService())->mostBunkerVisits(5);

    expect($rows)->toBe([
        ['gamertag' => 'Alice', 'bunker_visits' => 2],
        ['gamertag' => 'Bob', 'bunker_visits' => 1],
    ]);
});

it('ranks quickest new-life-to-bunker ascending, one row per player (best life)', function () {
    // Alice: slow life (10min) then fast life (2min) -> best = 120s
    seedVisit('Alice', '2026-06-14 00:00:00', '2026-06-14 00:10:00');
    seedVisit('Alice', '2026-06-14 01:00:00', '2026-06-14 01:02:00');
    // Bob: 5min
    seedVisit('Bob', '2026-06-14 02:00:00', '2026-06-14 02:05:00');

    $rows = (new LeaderboardStatsService())->quickestNewLifeToBunker(5);

    expect($rows)->toBe([
        ['gamertag' => 'Alice', 'seconds' => 120],
        ['gamertag' => 'Bob', 'seconds' => 300],
    ]);
});

it('excludes visits with no life from the quickest board but counts them in totals', function () {
    Player::create(['gamertag' => 'Ghost']);
    BunkerVisit::create([
        'player_id' => Player::where('gamertag', 'Ghost')->value('id'),
        'life_id' => null,
        'visited_at' => CarbonImmutable::parse('2026-06-14 00:10:00'),
    ]);

    expect((new LeaderboardStatsService())->quickestNewLifeToBunker(5))->toBe([])
        ->and((new LeaderboardStatsService())->mostBunkerVisits(5))
        ->toBe([['gamertag' => 'Ghost', 'bunker_visits' => 1]]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BunkerLeaderboardStatsTest.php`
Expected: FAIL — `Call to undefined method ...::mostBunkerVisits()`.

- [ ] **Step 3: Implement the two query methods**

Add to `LeaderboardStatsService` (it already imports `DB` and `CarbonImmutable`):

```php
    /**
     * Total counted bunker visits per player, desc. Tie-break: earliest first visit.
     *
     * @return array<int, array{gamertag:string, bunker_visits:int}>
     */
    public function mostBunkerVisits(int $limit): array
    {
        return DB::table('bunker_visits')
            ->join('players', 'players.id', '=', 'bunker_visits.player_id')
            ->groupBy('bunker_visits.player_id', 'players.gamertag')
            ->orderByDesc('visits')
            ->orderByRaw('MIN(bunker_visits.visited_at) ASC')
            ->limit($limit)
            ->get([
                'players.gamertag as gamertag',
                DB::raw('COUNT(*) as visits'),
            ])
            ->map(fn ($r) => ['gamertag' => $r->gamertag, 'bunker_visits' => (int) $r->visits])
            ->all();
    }

    /**
     * Each player's fastest life-start -> first-bunker-visit time, ascending.
     * One row per player (their best life). Lives without a visit, and visits with a
     * null life_id, are excluded. Tie-break: earliest life start.
     *
     * @return array<int, array{gamertag:string, seconds:int}>
     */
    public function quickestNewLifeToBunker(int $limit): array
    {
        $rows = DB::table('bunker_visits')
            ->join('lives', 'lives.id', '=', 'bunker_visits.life_id')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->whereNotNull('bunker_visits.life_id')
            ->groupBy('bunker_visits.life_id', 'players.gamertag', 'lives.started_at')
            ->get([
                'players.gamertag as gamertag',
                'lives.started_at as started_at',
                DB::raw('MIN(bunker_visits.visited_at) as first_visit'),
            ]);

        $best = []; // gamertag => ['gamertag','seconds','started_at']
        foreach ($rows as $r) {
            $startTs = CarbonImmutable::parse($r->started_at)->getTimestamp();
            $seconds = CarbonImmutable::parse($r->first_visit)->getTimestamp() - $startTs;
            if ($seconds < 0) {
                continue; // defensive: a visit can't precede its own life
            }
            if (! isset($best[$r->gamertag]) || $seconds < $best[$r->gamertag]['seconds']) {
                $best[$r->gamertag] = ['gamertag' => $r->gamertag, 'seconds' => $seconds, 'started_at' => $startTs];
            }
        }

        $out = array_values($best);
        usort($out, fn ($a, $b) => $a['seconds'] <=> $b['seconds'] ?: $a['started_at'] <=> $b['started_at']);

        return array_map(
            fn ($r) => ['gamertag' => $r['gamertag'], 'seconds' => $r['seconds']],
            array_slice($out, 0, $limit)
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BunkerLeaderboardStatsTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leaderboard/LeaderboardStatsService.php tests/Feature/BunkerLeaderboardStatsTest.php
git commit -m "feat: leaderboard queries for bunker visits + quickest-to-bunker"
```

---

### Task 7: Composer fields + service wiring

**Files:**
- Modify: `app/Services/Leaderboard/LeaderboardComposer.php`
- Modify: `app/Services/LeaderboardService.php:51-57`
- Modify: `tests/Unit/LeaderboardComposerTest.php` (existing `lbBoards()` helper + field-count assertions)
- Test: `tests/Unit/LeaderboardComposerTest.php`

- [ ] **Step 1: Update the existing test helper + add new assertions**

In `tests/Unit/LeaderboardComposerTest.php`, add the two new keys to the `lbBoards()` helper (lines 15-20) so `compose()` finds them:

```php
        'distance' => [['killer' => 'Bob', 'victim' => 'Carol', 'weapon' => 'M24', 'distance' => 412.7]],
        'bunker_visits' => [['gamertag' => 'Alice', 'bunker_visits' => 2], ['gamertag' => 'Bob', 'bunker_visits' => 1]],
        'quickest_bunker' => [['gamertag' => 'Bob', 'seconds' => 120]],
```

If any test asserts an exact field count (e.g. `toHaveCount(5)`), update it to `7`. Append a new test:

```php
it('renders the two bunker boards with correct nouns and duration', function () {
    $fields = $this->composer->compose(lbBoards())['fields'];
    $names = array_column($fields, 'name');

    expect($names)->toContain('🚪 Most Bunker Visits')
        ->and($names)->toContain('⏱️ Quickest New Life → Bunker');

    $visitsField = collect($fields)->firstWhere('name', '🚪 Most Bunker Visits');
    expect($visitsField['value'])->toContain('`Alice` — 2 visits')
        ->and($visitsField['value'])->toContain('`Bob` — 1 visit'); // singular

    $quickField = collect($fields)->firstWhere('name', '⏱️ Quickest New Life → Bunker');
    expect($quickField['value'])->toContain('`Bob`'); // duration rendered via SessionDuration
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/LeaderboardComposerTest.php`
Expected: FAIL — new fields absent (and `countRows` would still say "visits"? no — it currently hardcodes "kills", so the assertion `2 visits` fails).

- [ ] **Step 3a: Generalize `countRows` with a noun (keeps existing callers working)**

Replace the `countRows` method (lines 58-73) with:

```php
    /** @param array<int, array{gamertag:string}> $rows */
    private function countRows(array $rows, string $key, string $singular = 'kill', string $plural = 'kills'): string
    {
        if ($rows === []) {
            return '*No entries yet*';
        }

        $lines = [];
        foreach ($rows as $i => $r) {
            $n = (int) $r[$key];
            $noun = $n === 1 ? $singular : $plural;
            $lines[] = ($i + 1).". `{$r['gamertag']}` — {$n} {$noun}";
        }

        return implode("\n", $lines);
    }
```

- [ ] **Step 3b: Add the two fields + update the compose() docblock**

Update the `@param` shape on `compose()` (line 25) to include the new keys:

```php
     * @param  array{alive:array, all_time:array, kills:array, streak:array, distance:array, bunker_visits:array, quickest_bunker:array}  $boards
```

Add the two fields to the `fields` array (after the `Longest Kills` field, line 38):

```php
                ['name' => '🚪 Most Bunker Visits', 'value' => $this->countRows($boards['bunker_visits'], 'bunker_visits', 'visit', 'visits')],
                ['name' => '⏱️ Quickest New Life → Bunker', 'value' => $this->durationRows($boards['quickest_bunker'])],
```

- [ ] **Step 3c: Pass the new boards from `LeaderboardService`**

In `app/Services/LeaderboardService.php`, extend the `compose(...)` array (lines 51-57):

```php
        $payload = (new LeaderboardComposer())->compose([
            'alive' => $stats->aliveLongestLives($top),
            'all_time' => $stats->allTimeLongestLives($top),
            'kills' => $stats->mostKills($top),
            'streak' => $stats->longestKillStreaks($top),
            'distance' => $stats->longestKills($top),
            'bunker_visits' => $stats->mostBunkerVisits($top),
            'quickest_bunker' => $stats->quickestNewLifeToBunker($top),
        ]);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/LeaderboardComposerTest.php tests/Feature/LeaderboardServiceTest.php`
Expected: PASS (composer + service tests green; `LeaderboardServiceTest` runs against an empty DB so the new boards render `*No entries yet*`).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leaderboard/LeaderboardComposer.php app/Services/LeaderboardService.php tests/Unit/LeaderboardComposerTest.php
git commit -m "feat: render bunker boards on the leaderboard embed"
```

---

### Task 8: History backfill (service + command)

**Files:**
- Create: `app/Services/Adm/BunkerVisitBackfillService.php`
- Create: `app/Console/Commands/BackfillBunkerVisitsCommand.php`
- Test: `tests/Feature/BunkerVisitBackfillServiceTest.php`

- [ ] **Step 1: Write the failing test** (tests the pure per-file extraction; no network)

```php
<?php

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use App\Services\Adm\AdmParser;
use App\Services\Adm\BunkerVisitBackfillService;
use App\Services\Bunker\BunkerVisitService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills entrance lines from a file content into visits', function () {
    $player = Player::create(['gamertag' => 'RonaldRaygun552']);
    Life::create(['player_id' => $player->id, 'started_at' => CarbonImmutable::parse('2026-06-14 02:00:00')]);

    $content = implode("\n", [
        'AdminLog started on 2026-06-14 at 02:00:00',
        '02:30:35 | Player "RonaldRaygun552" (id=89B90470 pos=<5154.0, 1075.1, 56.3>) was teleported from: <4767,339,10376> to: <5154,56,1075>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerEntrance',
        '03:01:32 | Player "RonaldRaygun552" (id=89B90470 pos=<4828.4, 10291.8, 339.9>) was teleported from: <5005,17,1086> to: <4828,339,10291>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerExit',
    ]);

    $svc = new BunkerVisitBackfillService(new AdmParser());
    $n = $svc->backfillFile($content, new DateTimeImmutable('2026-06-14 02:00:00'), 0, new BunkerVisitService());

    expect($n)->toBe(1) // only the entrance, not the exit
        ->and(BunkerVisit::count())->toBe(1);
});

it('is idempotent on re-run via the cooldown window', function () {
    $player = Player::create(['gamertag' => 'RonaldRaygun552']);
    Life::create(['player_id' => $player->id, 'started_at' => CarbonImmutable::parse('2026-06-14 02:00:00')]);

    $content = implode("\n", [
        'AdminLog started on 2026-06-14 at 02:00:00',
        '02:30:35 | Player "RonaldRaygun552" (id=89B90470 pos=<5154,1075,56>) was teleported from: <0,0,0> to: <0,0,0>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerEntrance',
    ]);

    $svc = new BunkerVisitBackfillService(new AdmParser());
    $svc->backfillFile($content, new DateTimeImmutable('2026-06-14 02:00:00'), 0, new BunkerVisitService());
    $svc->backfillFile($content, new DateTimeImmutable('2026-06-14 02:00:00'), 0, new BunkerVisitService());

    expect(BunkerVisit::count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BunkerVisitBackfillServiceTest.php`
Expected: FAIL — `Class "App\Services\Adm\BunkerVisitBackfillService" not found`.

- [ ] **Step 3a: Create the backfill service**

```php
<?php

namespace App\Services\Adm;

use App\Services\Bunker\BunkerVisitService;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;

/**
 * Replays ADM history through BunkerVisitService to capture past bunker visits.
 * Reconstructs per-line UTC timestamps exactly as AdmIngestor. Idempotent on re-run
 * (the service's cooldown window swallows re-derived duplicates). Does NOT touch
 * lives/sessions — it relies on lives already reconstructed by normal ingest, and
 * associates each visit to the life whose window contains it.
 */
class BunkerVisitBackfillService
{
    public function __construct(private AdmParser $parser) {}

    /**
     * @param  ?callable  $progress  fn(string $fileName, int $count): void
     * @return array{files:int, visits:int}
     */
    public function backfillAll(NitradoClient $client, BunkerVisitService $visits, ?int $sinceDays = null, ?callable $progress = null): array
    {
        $files = $client->listAdmFiles(); // oldest-first
        if (empty($files)) return ['files' => 0, 'visits' => 0];

        $offsetMs = $this->parser->deriveClockOffsetMs($files);

        if ($sinceDays !== null) {
            $cut = CarbonImmutable::now()->subDays($sinceDays)->getTimestamp();
            $files = array_values(array_filter($files, function ($f) use ($cut) {
                $ts = $f['timestamp'] ?? null;
                return $ts instanceof \DateTimeInterface ? $ts->getTimestamp() >= $cut : true;
            }));
        }

        $total = 0;
        $fileCount = 0;
        foreach ($files as $f) {
            try {
                $content = $client->downloadFile($f['path']);
            } catch (\Throwable) {
                continue;
            }
            $fallback = ($f['timestamp'] ?? null) instanceof \DateTimeImmutable
                ? $f['timestamp']
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $n = $this->backfillFile($content, $fallback, $offsetMs, $visits);
            $total += $n;
            $fileCount++;
            if ($progress) $progress($f['name'], $n);
        }

        return ['files' => $fileCount, 'visits' => $total];
    }

    /** Parse one file's entrance lines and record each visit. Returns the count recorded. */
    public function backfillFile(string $content, \DateTimeImmutable $fallback, int $offsetMs, BunkerVisitService $visits): int
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $tsByLine = $this->parser->assignTimestamps($lines, $fallback);

        $count = 0;
        foreach ($lines as $i => $raw) {
            if ($raw === '' || $raw === null) continue;
            $localMs = $tsByLine[$i] ?? null;
            if ($localMs === null) continue;

            $b = $this->parser->parseBunkerEntrance($raw);
            if ($b === null) continue;

            $utc = (new \DateTimeImmutable('@'.intdiv($localMs + $offsetMs, 1000)))
                ->setTimezone(new \DateTimeZone('UTC'));

            if ($visits->record($b['gamertag'], $utc) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
```

- [ ] **Step 3b: Create the console command**

```php
<?php

namespace App\Console\Commands;

use App\Services\Adm\AdmParser;
use App\Services\Adm\BunkerVisitBackfillService;
use App\Services\Bunker\BunkerVisitService;
use App\Services\Nitrado\NitradoClient;
use Laracord\Console\Commands\Command;

class BackfillBunkerVisitsCommand extends Command
{
    protected $signature = 'adm:backfill-bunker-visits {--since-days= : Only scan ADM files newer than N days (default: all)}';
    protected $description = 'Backfill bunker visits from ADM history (no banning, no life changes). Idempotent.';

    public function handle(): int
    {
        $token = env('NITRADO_TOKEN');
        $serviceId = (int) env('NITRADO_SERVICE_ID');
        if (! $token || ! $serviceId) {
            $this->error('Set NITRADO_TOKEN and NITRADO_SERVICE_ID in .env first.');
            return self::FAILURE;
        }

        $sinceDays = $this->option('since-days') !== null ? (int) $this->option('since-days') : null;

        $client = new NitradoClient($token, $serviceId);
        $svc = new BunkerVisitBackfillService(new AdmParser());
        $visits = new BunkerVisitService();

        $scope = $sinceDays !== null ? "last {$sinceDays} day(s)" : 'all history';
        $this->info("Backfilling bunker visits — {$scope}...");

        $result = $svc->backfillAll($client, $visits, $sinceDays, function (string $name, int $n) {
            if ($n > 0) $this->line("  {$name}: {$n} visit(s)");
        });

        $this->info("Done. {$result['files']} file(s) scanned, {$result['visits']} visit(s) recorded.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BunkerVisitBackfillServiceTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Verify the command registers**

Run: `php laracord list | grep backfill-bunker`
Expected: `adm:backfill-bunker-visits` listed.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Adm/BunkerVisitBackfillService.php app/Console/Commands/BackfillBunkerVisitsCommand.php tests/Feature/BunkerVisitBackfillServiceTest.php
git commit -m "feat: adm:backfill-bunker-visits command + backfill service"
```

---

### Task 9: Full suite, live backfill, docs

**Files:**
- Modify: `CLAUDE.md` (architecture + commands + `.env` keys)
- Modify: operator `.env` (add the two keys; not committed — `.env` is git-ignored)
- Modify: `README.md` if it documents leaderboard boards / env keys

- [ ] **Step 1: Run the full test suite**

Run: `./vendor/bin/pest`
Expected: All green (DEPR markers are harmless per CLAUDE.md). If any pre-existing leaderboard test asserted a 5-field count, it was updated in Task 7.

- [ ] **Step 2: Apply the migration locally**

Run: `php laracord migrate`
Expected: `bunker_visits` table migrated (no errors).

- [ ] **Step 3: Add operator `.env` keys**

Add to `.env` (defaults match config, so optional but explicit):

```
# Bunker visit tracking
BUNKER_TRACKING_ENABLED=true
BUNKER_VISIT_COOLDOWN_MINUTES=60
```

- [ ] **Step 4: Backfill live history (one-off operational run)**

> Uses the live Nitrado token. The command reads `NITRADO_TOKEN`; if the operator uses `LIVE_NITRADO_TOKEN`, run with `NITRADO_TOKEN="$LIVE_NITRADO_TOKEN"` prefixed, or set `NITRADO_TOKEN` for the run.

Run: `php laracord adm:backfill-bunker-visits --since-days=14`
Expected: prints files scanned and visit(s) recorded; at least the operator's own recent visit appears.

Verify: `php laracord tinker --execute="echo App\Models\BunkerVisit::count();"`
Expected: ≥ 1.

- [ ] **Step 5: Update `CLAUDE.md`**

- Add a **Bunker visits** bullet under "Architecture" describing: detection via the `RestrictedAreaBunkerEntrance` teleport line (not proximity), `BunkerVisitService` (60-min cooldown, life-window association), the two leaderboard boards, and that it is DB-only / not gated by `BAN_DRY_RUN`.
- Add `adm:backfill-bunker-visits` to "Common commands".
- Add `BUNKER_TRACKING_ENABLED` / `BUNKER_VISIT_COOLDOWN_MINUTES` to the `.env` keys list.
- Note in the status line that bunker tracking is implemented/deployed.

- [ ] **Step 6: Update `README.md`** (if it lists leaderboard boards or env keys) to mention the two bunker boards and the two env keys.

- [ ] **Step 7: Commit**

```bash
git add CLAUDE.md README.md
git commit -m "docs: document bunker visit tracking"
```

---

## Self-Review Notes

- **Spec coverage:** detection (Task 2/5), cooldown de-dup (Task 4), life-window association incl. null-life edge (Task 4), `bunker_visits` schema (Task 1), config + phpunit pins (Task 3), both leaderboards with tie-breaks + null-life exclusion (Task 6/7), backfill (Task 8), docs (Task 9). All spec sections map to a task.
- **Type consistency:** `mostBunkerVisits` returns `bunker_visits` key (matches composer `countRows($boards['bunker_visits'], 'bunker_visits', ...)`); `quickestNewLifeToBunker` returns `seconds` (matches `durationRows`). Board keys `bunker_visits` / `quickest_bunker` are identical in `LeaderboardService` (Task 7c), `LeaderboardComposer::compose` (Task 7b), and the test helper (Task 7a). `record(string, \DateTimeImmutable): ?BunkerVisit` signature is identical across Tasks 4, 5, 8.
- **No placeholders:** every code step shows complete code; every run step states the expected result.
