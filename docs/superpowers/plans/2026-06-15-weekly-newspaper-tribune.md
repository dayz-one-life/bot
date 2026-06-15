# Weekly Newspaper — The One Life Tribune — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a fully-automatic weekly Discord "newspaper" (The One Life Tribune) plus the new non-fatal hit/infected-attack capture that helps power it.

**Architecture:** A sibling of the existing births/eulogies subsystem. New ingestion (`AdmParser::parseHit` → `hit_events` via `HitEventService`, wired into `AdmIngestor`) captures damage events. A `WeeklyFactsBuilder` aggregates a trailing-7-day window into a location-safe facts array; a `NewspaperGenerator` (one OpenRouter call, delimiter-split, per-section canned fallback) writes the prose; a `NewspaperComposer` builds the multi-embed payload (incl. a pure-data "Week in Numbers" box); a `DiscordNewspaperNotifier` posts it; a periodic `NewspaperService` fires weekly with `bot_state`-keyed idempotency.

**Tech Stack:** Laracord v2.3.0 (Laravel Zero), PHP 8.2+, SQLite, Pest. Reuses `App\Services\Llm\OpenRouterClient` + `config/llm.php`.

**Spec:** `docs/superpowers/specs/2026-06-15-weekly-newspaper-tribune-design.md`

**Privacy invariant (load-bearing — every task must respect it):** A location may appear only as an aggregate, server-wide trend (`region => count`, no player names inside). Never a coordinate, never a `(player, place)` pair, never a base/build-event location. Per-player facts carry distances (e.g. "412 m", "14 km") but never a place.

---

## Task 1: `AdmParser::parseHit` — parse non-fatal/fatal hit lines

**Files:**
- Modify: `app/Services/Adm/AdmParser.php`
- Test: `tests/Unit/AdmParserTest.php`

- [ ] **Step 1: Write the failing tests** (append to `tests/Unit/AdmParserTest.php`)

```php
it('parses a player-vs-player hit', function () {
    $line = '10:00:00 | Player "Victim" (id=V= pos=<100.5, 200.0, 1.0>)[HP: 50] hit by Player "Attacker" (id=A= pos=<101.0, 201.0, 1.0>) into Torso';
    $h = $this->parser->parseHit($line);
    expect($h)->toMatchArray([
        'victim' => 'Victim',
        'victim_hp' => 50,
        'attacker_gamertag' => 'Attacker',
        'attacker_type' => 'player',
        'attacker_label' => null,
        'body_part' => 'Torso',
    ]);
    expect($h['victim_x'])->toBe(100.5);
    expect($h['victim_y'])->toBe(200.0);
});

it('parses an infected hit and humanizes the source', function () {
    $line = '10:00:00 | Player "Victim" (id=V= pos=<1.0, 2.0, 3.0>)[HP: 30] hit by ZmbM_JoggerSkinny_Red into Leg';
    $h = $this->parser->parseHit($line);
    expect($h['attacker_type'])->toBe('infected');
    expect($h['attacker_gamertag'])->toBeNull();
    expect($h['attacker_label'])->toBe('an infected jogger');
    expect($h['body_part'])->toBe('Leg');
});

it('parses an animal hit', function () {
    $line = '10:00:00 | Player "Victim" (id=V=)[HP: 10] hit by Animal_UrsusArctos into Torso';
    $h = $this->parser->parseHit($line);
    expect($h['attacker_type'])->toBe('animal');
    expect($h['attacker_label'])->toBe('a bear');
    expect($h['victim_x'])->toBeNull();
});

it('parses an environmental hit', function () {
    $line = '10:00:00 | Player "Victim" (id=V=)[HP: 80] hit by FallDamage';
    $h = $this->parser->parseHit($line);
    expect($h['attacker_type'])->toBe('environment');
    expect($h['attacker_gamertag'])->toBeNull();
    expect($h['body_part'])->toBeNull();
});

it('returns null for a non-hit line', function () {
    expect($this->parser->parseHit('10:00:00 | Player "A" (id=A=) is connected'))->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Unit/AdmParserTest.php --filter="hit"`
Expected: FAIL — `Call to undefined method ... parseHit()`.

- [ ] **Step 3: Implement `parseHit`** (add to `app/Services/Adm/AdmParser.php`; add `use App\Services\Adm\DayzNameHumanizer;` is unneeded — same namespace)

```php
    /**
     * Parse an ADM "hit by" damage line into a structured hit event. Player attackers yield
     * attacker_type='player' + attacker_gamertag; infected/animal/other sources yield a humanized
     * attacker_label and a classified attacker_type, with attacker_gamertag null. Coordinates are
     * the VICTIM's (used only to derive an aggregate region downstream — never exposed raw).
     *
     * @return array{victim:string,victim_hp:?int,victim_x:?float,victim_y:?float,body_part:?string,attacker_gamertag:?string,attacker_type:string,attacker_label:?string}|null
     */
    public function parseHit(string $raw): ?array
    {
        $pos = strpos($raw, 'hit by');
        if ($pos === false) return null;
        if (!preg_match(self::PLAYER_NAME_RE, $raw, $vm)) return null;

        $before = substr($raw, 0, $pos);
        $after = substr($raw, $pos + strlen('hit by'));

        $hp = null;
        if (preg_match('/\[HP:\s*(-?\d+)\]/u', $raw, $hm)) $hp = (int) $hm[1];

        $vx = $vy = null;
        if (preg_match(self::POSITION_RE, $before, $pm)) {
            $vx = (float) $pm[1];
            $vy = (float) $pm[2];
        }

        $bodyPart = null;
        if (preg_match('/into ([A-Za-z]+)\s*$/u', trim($after), $bm)) $bodyPart = $bm[1];

        // Player attacker?
        if (preg_match('/^\s*Player "([^"]+)"/u', $after, $am)) {
            return [
                'victim' => $vm[1], 'victim_hp' => $hp, 'victim_x' => $vx, 'victim_y' => $vy,
                'body_part' => $bodyPart,
                'attacker_gamertag' => $am[1], 'attacker_type' => 'player', 'attacker_label' => null,
            ];
        }

        // Non-player source: strip the trailing "into <BodyPart>" and any "(...)" detail.
        $src = trim($after);
        $src = preg_replace('/\s+into\s+[A-Za-z]+\s*$/u', '', $src);
        $src = trim(preg_replace('/\(.*$/', '', $src));

        $type = match (true) {
            str_contains($src, 'Zmb') => 'infected',
            str_starts_with($src, 'Animal_') => 'animal',
            default => 'environment',
        };
        $label = $type === 'environment'
            ? $this->humanizeEnvironment($src)
            : DayzNameHumanizer::text($src);

        return [
            'victim' => $vm[1], 'victim_hp' => $hp, 'victim_x' => $vx, 'victim_y' => $vy,
            'body_part' => $bodyPart,
            'attacker_gamertag' => null, 'attacker_type' => $type, 'attacker_label' => $label,
        ];
    }

    private function humanizeEnvironment(string $src): string
    {
        return match (true) {
            stripos($src, 'Fall') !== false => 'a fall',
            stripos($src, 'Explosion') !== false => 'an explosion',
            $src === '' => 'the environment',
            default => $src,
        };
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Unit/AdmParserTest.php`
Expected: PASS (all hit tests + existing ones green).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Adm/AdmParser.php tests/Unit/AdmParserTest.php
git commit -m "feat: AdmParser::parseHit for non-fatal/fatal hit lines"
```

---

## Task 2: `hit_events` table + `HitEvent` model

**Files:**
- Create: `database/migrations/2026_06_15_010000_create_hit_events.php`
- Create: `app/Models/HitEvent.php`
- Test: `tests/Feature/HitEventModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\HitEvent;
use App\Models\Player;
use Carbon\CarbonImmutable;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('persists a hit event linked to a player', function () {
    $p = Player::create(['gamertag' => 'Victim']);
    $hit = HitEvent::create([
        'victim_player_id' => $p->id,
        'victim_gamertag' => 'Victim',
        'attacker_gamertag' => 'Attacker',
        'attacker_type' => 'player',
        'attacker_label' => null,
        'body_part' => 'Torso',
        'victim_hp' => 50,
        'victim_x' => 100.5,
        'victim_y' => 200.0,
        'occurred_at' => CarbonImmutable::parse('2026-06-10 10:00:00'),
    ]);
    expect($hit->fresh()->attacker_type)->toBe('player');
    expect($hit->fresh()->victim_x)->toBe(100.5);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/HitEventModelTest.php`
Expected: FAIL — `Class "App\Models\HitEvent" not found`.

- [ ] **Step 3a: Create the migration** (`database/migrations/2026_06_15_010000_create_hit_events.php`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('victim_player_id')->nullable()->constrained('players')->nullOnDelete();
            $t->string('victim_gamertag');
            $t->string('attacker_gamertag')->nullable();
            $t->string('attacker_type'); // player | infected | animal | environment
            $t->string('attacker_label')->nullable();
            $t->string('body_part')->nullable();
            $t->integer('victim_hp')->nullable();
            $t->double('victim_x')->nullable();
            $t->double('victim_y')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index('occurred_at');
            $t->index('victim_player_id');
            $t->index('attacker_gamertag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hit_events');
    }
};
```

- [ ] **Step 3b: Create the model** (`app/Models/HitEvent.php`)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HitEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'victim_hp' => 'integer',
        'victim_x' => 'float',
        'victim_y' => 'float',
    ];

    public function victim()
    {
        return $this->belongsTo(Player::class, 'victim_player_id');
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/HitEventModelTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_15_010000_create_hit_events.php app/Models/HitEvent.php tests/Feature/HitEventModelTest.php
git commit -m "feat: hit_events table + HitEvent model"
```

---

## Task 3: `HitEventService::record`

**Files:**
- Create: `app/Services/Hit/HitEventService.php`
- Create: `config/hits.php`
- Test: `tests/Feature/HitEventServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\HitEvent;
use App\Models\Player;
use App\Services\Hit\HitEventService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => config()->set('hits.enabled', true));

it('records a player hit and links the victim by gamertag', function () {
    Player::create(['gamertag' => 'Victim']);
    $svc = new HitEventService();
    $hit = $svc->record([
        'victim' => 'Victim', 'victim_hp' => 50, 'victim_x' => 1.0, 'victim_y' => 2.0,
        'body_part' => 'Torso', 'attacker_gamertag' => 'Attacker',
        'attacker_type' => 'player', 'attacker_label' => null,
    ], new DateTimeImmutable('2026-06-10 10:00:00'));

    expect($hit)->not->toBeNull();
    expect($hit->victim_player_id)->toBe(Player::where('gamertag', 'Victim')->first()->id);
    expect(HitEvent::count())->toBe(1);
});

it('records an infected hit even when the victim player is unknown', function () {
    $svc = new HitEventService();
    $hit = $svc->record([
        'victim' => 'Stranger', 'victim_hp' => 30, 'victim_x' => null, 'victim_y' => null,
        'body_part' => 'Leg', 'attacker_gamertag' => null,
        'attacker_type' => 'infected', 'attacker_label' => 'an infected jogger',
    ], new DateTimeImmutable('2026-06-10 10:05:00'));

    expect($hit->victim_player_id)->toBeNull();
    expect($hit->victim_gamertag)->toBe('Stranger');
});

it('no-ops when hit tracking is disabled', function () {
    config()->set('hits.enabled', false);
    $svc = new HitEventService();
    $hit = $svc->record([
        'victim' => 'Victim', 'victim_hp' => 50, 'victim_x' => null, 'victim_y' => null,
        'body_part' => null, 'attacker_gamertag' => null,
        'attacker_type' => 'environment', 'attacker_label' => 'a fall',
    ], new DateTimeImmutable('2026-06-10 10:00:00'));
    expect($hit)->toBeNull();
    expect(HitEvent::count())->toBe(0);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/HitEventServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3a: Create `config/hits.php`**

```php
<?php

return [
    'enabled' => filter_var(env('HIT_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOL),
];
```

- [ ] **Step 3b: Create `app/Services/Hit/HitEventService.php`**

```php
<?php

namespace App\Services\Hit;

use App\Models\HitEvent;
use App\Models\Player;
use Carbon\CarbonImmutable;

/**
 * Records non-fatal/fatal hit events parsed from ADM "hit by" lines. DB-only; not gated by
 * BAN_DRY_RUN. Victim is linked to a known player when the gamertag is already tracked, otherwise
 * stored denormalized (we do NOT create player rows from hits — a hit alone is weak evidence and
 * connect/death events own player creation). No-ops when hit tracking is disabled.
 *
 * @phpstan-type ParsedHit array{victim:string,victim_hp:?int,victim_x:?float,victim_y:?float,body_part:?string,attacker_gamertag:?string,attacker_type:string,attacker_label:?string}
 */
class HitEventService
{
    /** @param ParsedHit $hit */
    public function record(array $hit, \DateTimeImmutable $ts): ?HitEvent
    {
        if (! config('hits.enabled', true)) {
            return null;
        }

        $victim = Player::where('gamertag', $hit['victim'])->first();

        return HitEvent::create([
            'victim_player_id' => $victim?->id,
            'victim_gamertag' => $hit['victim'],
            'attacker_gamertag' => $hit['attacker_gamertag'],
            'attacker_type' => $hit['attacker_type'],
            'attacker_label' => $hit['attacker_label'],
            'body_part' => $hit['body_part'],
            'victim_hp' => $hit['victim_hp'],
            'victim_x' => $hit['victim_x'],
            'victim_y' => $hit['victim_y'],
            'occurred_at' => CarbonImmutable::instance($ts),
        ]);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/HitEventServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Hit/HitEventService.php config/hits.php tests/Feature/HitEventServiceTest.php
git commit -m "feat: HitEventService records ADM hit events"
```

---

## Task 4: Wire `HitEventService` into `AdmIngestor`

**Files:**
- Modify: `app/Services/Adm/AdmIngestor.php` (ctor ~17-25, `processFile` ~130-136)
- Test: `tests/Feature/AdmIngestorTest.php`

- [ ] **Step 1: Write the failing test** (append to `tests/Feature/AdmIngestorTest.php`)

```php
it('records hit events during ingest', function () {
    $content = "AdminLog started on 2026-06-10 at 10:00:00\n"
        ."10:00:01 | Player \"Hero\" (id=H=) is connected\n"
        ."10:01:00 | Player \"Hero\" (id=H= pos=<6700.0, 2500.0, 1.0>)[HP: 60] hit by Player \"Villain\" (id=Vv=) into Torso\n"
        ."10:02:00 | Player \"Hero\" (id=H= pos=<6700.0, 2500.0, 1.0>)[HP: 30] hit by ZmbM_JoggerSkinny_Red into Leg\n";

    $ingestor = new App\Services\Adm\AdmIngestor(/* same wiring the existing tests use */);
    $ingestor->processFile($content, 0, new DateTimeImmutable('2026-06-10'), 0);

    expect(App\Models\HitEvent::count())->toBe(2);
    expect(App\Models\HitEvent::where('attacker_type', 'player')->first()->attacker_gamertag)->toBe('Villain');
    expect(App\Models\HitEvent::where('attacker_type', 'infected')->first()->attacker_label)->toBe('an infected jogger');
});
```

> Note: construct `AdmIngestor` exactly as the surrounding tests in this file do (match their existing `new AdmIngestor(...)` call — the ctor takes optional collaborators and defaults the rest). Read the top of `tests/Feature/AdmIngestorTest.php` first.

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/AdmIngestorTest.php --filter="hit events"`
Expected: FAIL — `HitEvent::count()` is 0 (hits not yet wired).

- [ ] **Step 3a: Add the collaborator to the ctor** (`app/Services/Adm/AdmIngestor.php`)

Add `use App\Services\Hit\HitEventService;` at the top. Add a property and ctor param mirroring `$bunkerVisits`:

```php
    private HitEventService $hits;
```
In the constructor signature add `?HitEventService $hits = null,` and in the body:
```php
        $this->hits = $hits ?? new HitEventService();
```

- [ ] **Step 3b: Dispatch hits in `processFile`** — directly after the bunker-entrance block (~line 136):

```php
            if (($hit = $this->parser->parseHit($raw)) !== null) {
                $this->hits->record($hit, $ts);
            }
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/AdmIngestorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Adm/AdmIngestor.php tests/Feature/AdmIngestorTest.php
git commit -m "feat: ingest records hit events from ADM lines"
```

---

## Task 5: `adm:backfill-hits` console command

**Files:**
- Create: `app/Services/Adm/HitBackfillService.php`
- Create: `app/Console/Commands/BackfillHitsCommand.php`
- Test: `tests/Feature/HitBackfillServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\HitEvent;
use App\Services\Adm\AdmParser;
use App\Services\Adm\HitBackfillService;
use App\Services\Hit\HitEventService;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => config()->set('hits.enabled', true));

it('backfills hits from ADM files via the client', function () {
    // Mirror NitradoClientTest's Http::fake() shape: a file list + one file body containing a hit line.
    // (Copy the exact fake URLs/JSON from tests/Unit/NitradoClientTest.php.)
    $client = /* construct a NitradoClient against Http::fake() exactly as NitradoClientTest does */;

    $svc = new HitBackfillService(new AdmParser());
    $result = $svc->backfillAll($client, new HitEventService(), null, fn () => null);

    expect($result['hits'])->toBeGreaterThan(0);
    expect(HitEvent::count())->toBe($result['hits']);
});
```

> Note: this mirrors `BunkerVisitBackfillService` + `tests/Feature/BunkerVisitBackfillServiceTest.php`. Read those two files and copy their `NitradoClient`/`Http::fake()` construction verbatim, swapping the bunker teleport line for a `hit by` line.

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/HitBackfillServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3a: Create `app/Services/Adm/HitBackfillService.php`** (model it on `BunkerVisitBackfillService` — read that file and copy its file-iteration/timestamp logic, replacing the parse+record step):

```php
<?php

namespace App\Services\Adm;

use App\Services\Hit\HitEventService;
use App\Services\Nitrado\NitradoClient;
use Closure;

/**
 * Reconstructs hit events from ADM history. Idempotent in practice: re-running re-inserts, so it is
 * intended as a one-shot seed (run once). Mirrors BunkerVisitBackfillService's file iteration.
 */
class HitBackfillService
{
    public function __construct(private AdmParser $parser) {}

    /**
     * @param  Closure(string,int):void  $progress
     * @return array{files:int,hits:int}
     */
    public function backfillAll(NitradoClient $client, HitEventService $hits, ?int $sinceDays, Closure $progress): int|array
    {
        $files = $client->listAdmFiles(); // use the SAME listing/sorting/sinceDays filtering as BunkerVisitBackfillService
        $fileCount = 0;
        $hitCount = 0;

        foreach ($files as $file) {
            // (Copy the sinceDays filter + download + fallbackDate + clock-offset derivation
            //  from BunkerVisitBackfillService exactly.)
            $content = $client->downloadAdm($file['name']);
            $lines = preg_split('/\r\n|\r|\n/', $content);
            $tsByLine = $this->parser->assignTimestamps($lines, /* fallbackDate */ new \DateTimeImmutable());
            $offsetMs = 0; // derive identically to BunkerVisitBackfillService

            $n = 0;
            foreach ($lines as $i => $raw) {
                if ($raw === '' || $raw === null) continue;
                $local = $tsByLine[$i] ?? null;
                if ($local === null) continue;
                if (($hit = $this->parser->parseHit($raw)) === null) continue;
                $ts = (new \DateTimeImmutable())->setTimestamp(intdiv($local + $offsetMs, 1000));
                if ($hits->record($hit, $ts)) $n++;
            }

            $hitCount += $n;
            $fileCount++;
            $progress($file['name'], $n);
        }

        return ['files' => $fileCount, 'hits' => $hitCount];
    }
}
```

> The exact `NitradoClient` method names (`listAdmFiles`/`downloadAdm`), the `--since-days` filter, and the clock-offset derivation MUST be copied from `BunkerVisitBackfillService.php` so behavior matches. Do not invent method names — read that file.

- [ ] **Step 3b: Create `app/Console/Commands/BackfillHitsCommand.php`** (copy `BackfillBunkerVisitsCommand` structure):

```php
<?php

namespace App\Console\Commands;

use App\Services\Adm\AdmParser;
use App\Services\Adm\HitBackfillService;
use App\Services\Hit\HitEventService;
use App\Services\Nitrado\NitradoClient;
use Laracord\Console\Commands\Command;

class BackfillHitsCommand extends Command
{
    protected $signature = 'adm:backfill-hits {--since-days= : Only scan ADM files newer than N days (default: all)}';
    protected $description = 'Backfill hit events from ADM history (no banning, no life changes).';

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
        $svc = new HitBackfillService(new AdmParser());

        $scope = $sinceDays !== null ? "last {$sinceDays} day(s)" : 'all history';
        $this->info("Backfilling hits — {$scope}...");

        $result = $svc->backfillAll($client, new HitEventService(), $sinceDays, function (string $name, int $n) {
            if ($n > 0) $this->line("  {$name}: {$n} hit(s)");
        });

        $this->info("Done. {$result['files']} file(s) scanned, {$result['hits']} hit(s) recorded.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/HitBackfillServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Adm/HitBackfillService.php app/Console/Commands/BackfillHitsCommand.php tests/Feature/HitBackfillServiceTest.php
git commit -m "feat: adm:backfill-hits command + service"
```

---

## Task 6: `ChernarusRegions::regionFor` — coordinate → aggregate region label

**Files:**
- Create: `app/Services/Geo/ChernarusRegions.php`
- Test: `tests/Unit/ChernarusRegionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Geo\ChernarusRegions;

it('maps a coordinate near a town to that town', function () {
    expect(ChernarusRegions::regionFor(6700.0, 2500.0))->toBe('Chernogorsk');
    expect(ChernarusRegions::regionFor(10400.0, 2300.0))->toBe('Elektrozavodsk');
});

it('returns null for deep wilderness', function () {
    expect(ChernarusRegions::regionFor(0.0, 0.0))->toBeNull();
});

it('tolerates null coordinates', function () {
    expect(ChernarusRegions::regionFor(null, null))->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Unit/ChernarusRegionsTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `app/Services/Geo/ChernarusRegions.php`**

```php
<?php

namespace App\Services\Geo;

/**
 * PURE. Maps a Chernarus coordinate to the nearest named town/POI within a radius, returning ONLY
 * the label — never the coordinate. Used exclusively to build aggregate `region => count` trends for
 * the newspaper; a coordinate must never leave this layer attached to a player. Returns null for the
 * deep wilderness (no nearby POI), which keeps "middle of nowhere" deaths out of town trend counts.
 */
class ChernarusRegions
{
    private const RADIUS_M = 1500.0;

    /** label => [x, y] approximate town centers on the 15360x15360 Chernarus map. */
    private const POIS = [
        'Chernogorsk' => [6700, 2500],
        'Elektrozavodsk' => [10400, 2300],
        'Berezino' => [12900, 9500],
        'Severograd' => [7900, 12500],
        'Northwest Airfield' => [4500, 10200],
        'Zelenogorsk' => [2700, 5300],
        'Novodmitrovsk' => [11900, 12700],
        'Gorka' => [9500, 8800],
        'Stary Sobor' => [6100, 7700],
        'Vybor' => [3800, 8900],
    ];

    public static function regionFor(?float $x, ?float $y): ?string
    {
        if ($x === null || $y === null) {
            return null;
        }

        $best = null;
        $bestDist = self::RADIUS_M;
        foreach (self::POIS as $label => [$px, $py]) {
            $d = sqrt(($x - $px) ** 2 + ($y - $py) ** 2);
            if ($d <= $bestDist) {
                $bestDist = $d;
                $best = $label;
            }
        }

        return $best;
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Unit/ChernarusRegionsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Geo/ChernarusRegions.php tests/Unit/ChernarusRegionsTest.php
git commit -m "feat: ChernarusRegions coordinate->aggregate region mapping"
```

---

## Task 7: `WeeklyFactsBuilder` — location-safe weekly aggregate

**Files:**
- Create: `app/Services/Newspaper/WeeklyFactsBuilder.php`
- Test: `tests/Feature/WeeklyFactsBuilderTest.php`

This is the core aggregation. It reuses `LeaderboardStatsService` query style. The returned array MUST satisfy the privacy invariant.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\HitEvent;
use App\Models\Life;
use App\Models\Player;
use App\Services\Newspaper\WeeklyFactsBuilder;
use Carbon\CarbonImmutable;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function seedWeek(): void
{
    $p = Player::create(['gamertag' => 'DustOffMike']);
    $k = Player::create(['gamertag' => 'SaltShaker77']);
    // A death this week (Cherno coords in death_log), killed by SaltShaker77.
    Life::create([
        'player_id' => $p->id,
        'started_at' => CarbonImmutable::parse('2026-06-08 10:00:00'),
        'ended_at' => CarbonImmutable::parse('2026-06-10 10:00:00'),
        'playtime_seconds' => 7200,
        'death_cause' => 'pvp',
        'death_by_gamertag' => 'SaltShaker77',
        'death_weapon' => 'M4',
        'death_distance' => 412.0,
        'death_log' => '10:00:00 | Player "DustOffMike" (DEAD) (id=D= pos=<6700.0, 2500.0, 1.0>) killed by Player "SaltShaker77"',
    ]);
    // Infected hits near Cherno this week.
    foreach (range(1, 3) as $i) {
        HitEvent::create([
            'victim_player_id' => $p->id, 'victim_gamertag' => 'DustOffMike',
            'attacker_gamertag' => null, 'attacker_type' => 'infected', 'attacker_label' => 'an infected jogger',
            'body_part' => 'Torso', 'victim_hp' => 50, 'victim_x' => 6700.0, 'victim_y' => 2500.0,
            'occurred_at' => CarbonImmutable::parse('2026-06-09 12:00:00'),
        ]);
    }
}

it('aggregates the trailing week with deltas', function () {
    CarbonImmutable::setTestNow('2026-06-13 22:00:00');
    seedWeek();
    $facts = (new WeeklyFactsBuilder())->build(CarbonImmutable::now());

    expect($facts['counts']['lives_lost'])->toBe(1);
    expect($facts['counts']['infected_attacks'])->toBe(3);
    expect($facts['superlatives']['deadliest_player'])->toMatchArray(['gamertag' => 'SaltShaker77', 'kills' => 1]);
    expect($facts['superlatives']['furthest_kill']['distance'])->toBe(412.0);
    CarbonImmutable::setTestNow();
});

it('exposes location only as anonymized region trends (no player names, no coordinates)', function () {
    CarbonImmutable::setTestNow('2026-06-13 22:00:00');
    seedWeek();
    $facts = (new WeeklyFactsBuilder())->build(CarbonImmutable::now());

    // Region trend is a flat region=>count map.
    expect($facts['location_trends']['infected_by_region'])->toHaveKey('Chernogorsk');
    expect($facts['location_trends']['infected_by_region']['Chernogorsk'])->toBe(3);

    // Privacy: serialize the whole facts blob and assert it contains no coordinate and no place
    // glued to a player name.
    $json = json_encode($facts);
    expect($json)->not->toContain('pos=<');
    expect($json)->not->toContain('6700');           // no raw coordinate anywhere
    expect($json)->not->toContain('DustOffMike","region'); // crude guard: no player+region pairing
    CarbonImmutable::setTestNow();
});

it('returns a quiet-week shape with zero counts when nothing happened', function () {
    CarbonImmutable::setTestNow('2026-06-13 22:00:00');
    $facts = (new WeeklyFactsBuilder())->build(CarbonImmutable::now());
    expect($facts['counts']['lives_lost'])->toBe(0);
    expect($facts['superlatives']['deadliest_player'])->toBeNull();
    CarbonImmutable::setTestNow();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/WeeklyFactsBuilderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `app/Services/Newspaper/WeeklyFactsBuilder.php`**

```php
<?php

namespace App\Services\Newspaper;

use App\Models\HitEvent;
use App\Models\Life;
use App\Models\Player;
use App\Services\Connection\SessionDuration;
use App\Services\Geo\ChernarusRegions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates a trailing 7-day window into the structured facts the newspaper LLM (and the canned
 * fallback) consume. PRIVACY: the returned array NEVER contains a coordinate, a grid, or a
 * (player, place) pair. Locations appear only inside `location_trends` as anonymized region=>count
 * maps with no player names. Per-player facts carry distances, never places.
 */
class WeeklyFactsBuilder
{
    public function build(CarbonImmutable $now): array
    {
        $end = $now;
        $start = $now->subDays(7);
        $prevStart = $start->subDays(7);

        return [
            'period' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
            'counts' => $this->counts($start, $end, $prevStart),
            'superlatives' => $this->superlatives($start, $end),
            'location_trends' => $this->locationTrends($start, $end),
            'notable_events' => $this->notableEvents($start, $end),
            'witnesses' => $this->witnesses($end),
        ];
    }

    private function counts(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $prevStart): array
    {
        $lostThis = Life::whereNotNull('ended_at')->whereBetween('ended_at', [$start, $end])->count();
        $lostPrev = Life::whereNotNull('ended_at')->whereBetween('ended_at', [$prevStart, $start])->count();

        $playtime = (int) Life::whereBetween('started_at', [$start, $end])->sum('playtime_seconds');

        $infected = HitEvent::where('attacker_type', 'infected')->whereBetween('occurred_at', [$start, $end])->count();
        $infectedPrev = HitEvent::where('attacker_type', 'infected')->whereBetween('occurred_at', [$prevStart, $start])->count();

        $pvpHits = HitEvent::where('attacker_type', 'player')->whereBetween('occurred_at', [$start, $end])->count();

        $bunker = DB::table('bunker_visits')->whereBetween('visited_at', [$start, $end])->count();
        $alive = Life::whereNull('ended_at')->count();

        return [
            'lives_lost' => $lostThis, 'lives_lost_prev' => $lostPrev,
            'playtime_human' => SessionDuration::human($playtime),
            'infected_attacks' => $infected, 'infected_attacks_prev' => $infectedPrev,
            'pvp_hits' => $pvpHits,
            'bunker_descents' => $bunker,
            'souls_alive' => $alive,
        ];
    }

    private function superlatives(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $deadliest = DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->where('lives.death_cause', 'pvp')
            ->whereNotNull('lives.death_by_gamertag')
            ->whereColumn('lives.death_by_gamertag', '!=', 'players.gamertag')
            ->whereBetween('lives.ended_at', [$start, $end])
            ->groupBy('lives.death_by_gamertag')
            ->orderByDesc('kills')
            ->limit(1)
            ->get(['lives.death_by_gamertag as gamertag', DB::raw('COUNT(*) as kills')])
            ->first();

        $furthest = DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->where('lives.death_cause', 'pvp')
            ->whereNotNull('lives.death_distance')
            ->whereColumn('lives.death_by_gamertag', '!=', 'players.gamertag')
            ->whereBetween('lives.ended_at', [$start, $end])
            ->orderByDesc('lives.death_distance')
            ->limit(1)
            ->get(['lives.death_by_gamertag as killer', 'players.gamertag as victim', 'lives.death_weapon as weapon', 'lives.death_distance as distance'])
            ->first();

        $longest = DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->whereNotNull('lives.ended_at')
            ->whereBetween('lives.ended_at', [$start, $end])
            ->orderByDesc('lives.playtime_seconds')
            ->limit(1)
            ->get(['players.gamertag as gamertag', 'lives.playtime_seconds as seconds'])
            ->first();

        return [
            'deadliest_player' => $deadliest ? ['gamertag' => $deadliest->gamertag, 'kills' => (int) $deadliest->kills] : null,
            'furthest_kill' => $furthest ? [
                'killer' => $furthest->killer, 'victim' => $furthest->victim,
                'weapon' => $furthest->weapon, 'distance' => (float) $furthest->distance,
            ] : null,
            'longest_life_ended' => $longest ? [
                'gamertag' => $longest->gamertag, 'duration_human' => SessionDuration::human((int) $longest->seconds),
            ] : null,
            'most_travelled' => $this->mostTravelled($start, $end),
        ];
    }

    /**
     * Most distance covered this week, summed from consecutive position samples per player. Returns
     * a TOTAL DISTANCE only (km) — names no place. Null when no movement recorded.
     */
    private function mostTravelled(CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        $rows = DB::table('player_positions')
            ->join('players', 'players.id', '=', 'player_positions.player_id')
            ->whereBetween('recorded_at', [$start, $end])
            ->orderBy('player_positions.player_id')
            ->orderBy('recorded_at')
            ->get(['players.gamertag as gamertag', 'player_positions.player_id as pid', 'x', 'y']);

        $dist = []; // pid => ['gamertag'=>, 'm'=>float]
        $prev = []; // pid => [x,y]
        foreach ($rows as $r) {
            if (isset($prev[$r->pid])) {
                [$px, $py] = $prev[$r->pid];
                $dist[$r->pid]['m'] = ($dist[$r->pid]['m'] ?? 0) + sqrt(($r->x - $px) ** 2 + ($r->y - $py) ** 2);
                $dist[$r->pid]['gamertag'] = $r->gamertag;
            }
            $prev[$r->pid] = [$r->x, $r->y];
        }

        if ($dist === []) {
            return null;
        }

        usort($dist, fn ($a, $b) => ($b['m'] ?? 0) <=> ($a['m'] ?? 0));
        $top = $dist[0];

        return ['gamertag' => $top['gamertag'], 'km' => round(($top['m'] ?? 0) / 1000, 1)];
    }

    /**
     * Aggregate region trends — region=>count maps with NO player names. The ONLY place a location
     * surfaces. Derived from coordinates that never leave this method.
     */
    private function locationTrends(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $infectedByRegion = [];
        HitEvent::where('attacker_type', 'infected')
            ->whereBetween('occurred_at', [$start, $end])
            ->get(['victim_x', 'victim_y'])
            ->each(function ($h) use (&$infectedByRegion) {
                $region = ChernarusRegions::regionFor($h->victim_x, $h->victim_y);
                if ($region !== null) {
                    $infectedByRegion[$region] = ($infectedByRegion[$region] ?? 0) + 1;
                }
            });

        $deathsByRegion = [];
        Life::whereNotNull('ended_at')
            ->whereBetween('ended_at', [$start, $end])
            ->whereNotNull('death_log')
            ->get(['death_log'])
            ->each(function ($l) use (&$deathsByRegion) {
                [$x, $y] = $this->coordFromLog($l->death_log);
                $region = ChernarusRegions::regionFor($x, $y);
                if ($region !== null) {
                    $deathsByRegion[$region] = ($deathsByRegion[$region] ?? 0) + 1;
                }
            });

        arsort($infectedByRegion);
        arsort($deathsByRegion);

        return [
            'infected_by_region' => $infectedByRegion,
            'deaths_by_region' => $deathsByRegion,
            'infected_hotspot' => array_key_first($infectedByRegion),
            'deadliest_region' => array_key_first($deathsByRegion),
        ];
    }

    /** @return array{0:?float,1:?float} */
    private function coordFromLog(?string $log): array
    {
        if ($log !== null && preg_match('/pos=<\s*(-?[\d.]+),\s*(-?[\d.]+)/u', $log, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }

        return [null, null];
    }

    /**
     * Notable events for the recap — names + facts, NEVER a location. Most recent first, capped.
     */
    private function notableEvents(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return Life::whereNotNull('ended_at')
            ->whereBetween('ended_at', [$start, $end])
            ->with('player:id,gamertag')
            ->orderByDesc('ended_at')
            ->limit(15)
            ->get()
            ->map(fn (Life $l) => [
                'victim' => $l->player?->gamertag,
                'cause' => $l->death_cause,
                'killer' => $l->death_by_gamertag,
                'weapon' => $l->death_weapon,
                'distance' => $l->death_distance !== null ? (float) $l->death_distance : null,
                'lived_human' => SessionDuration::human((int) $l->playtime_seconds),
            ])
            ->all();
    }

    /** @return string[] Recently-active gamertags the LLM may quote (plain, not pinged). */
    private function witnesses(CarbonImmutable $end): array
    {
        return Player::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $end->subDays(14))
            ->orderByDesc('last_seen_at')
            ->limit(8)
            ->pluck('gamertag')
            ->all();
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/WeeklyFactsBuilderTest.php`
Expected: PASS. (If the `players` table lacks `last_seen_at` in this codebase, match the actual column used by `LifeFactsBuilder::witnesses` — read it and mirror exactly.)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Newspaper/WeeklyFactsBuilder.php tests/Feature/WeeklyFactsBuilderTest.php
git commit -m "feat: WeeklyFactsBuilder location-safe weekly aggregate"
```

---

## Task 8: `NewspaperGenerator` + canned fallback pools

**Files:**
- Create: `app/Services/Newspaper/NewspaperGenerator.php`
- Modify: `config/personality.php` (add `newspaper` pools)
- Test: `tests/Feature/NewspaperGeneratorTest.php`

- [ ] **Step 1: Add fallback pools to `config/personality.php`** (add a top-level `'newspaper' => [...]` key before the closing `];`)

```php
    'newspaper' => [
        'editorial' => [
            "**Another week on the coast.** :lives_lost survivors met their end, and the rest of you kept your heads down. Stay paranoid out there.",
            "**The wasteland grinds on.** :lives_lost lives ended this week. Some heroic, most embarrassing. You know who you are.",
            "**Editor's note:** :lives_lost dead, :playtime logged, zero lessons learned. See you next week.",
        ],
        'recap' => [
            "It was a typical week: people died, people looted, people made questionable choices. The standouts have already been forgotten.",
            "Nothing the history books will remember, but the bodies were real. :lives_lost of them.",
            "A week of small tragedies and smaller victories. The coast remains undefeated.",
        ],
        'classifieds' => [
            "**FOR SALE:** Assorted gear, slightly bloodstained. **WANTED:** A squadmate who can aim. **PERSONAL:** To whoever shot me — I'm not mad, I'm just disappointed.",
            "**LOST:** My will to push the airfield. **FOUND:** A bush, very comfortable. **WANTED:** Directions out of the wilderness.",
            "**FOR SALE:** One life, barely used. **PERSONAL:** Missed connection at the coast — you had a gun, I had hope.",
        ],
    ],
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Services\Llm\OpenRouterClient;
use App\Services\Newspaper\NewspaperGenerator;
use Illuminate\Support\Facades\Http;

it('parses the three sections from a well-formed LLM response', function () {
    Http::fake([
        '*' => Http::response(['choices' => [['message' => ['content' =>
            "## EDITORIAL\nIs Cherno safe? No.\n\n## RECAP\nMike died on Wednesday.\n\n## CLASSIFIEDS\nFOR SALE: one M4."
        ]]]], 200),
    ]);

    $gen = new NewspaperGenerator(new OpenRouterClient('key', 'm', 'https://x/api/v1'));
    $out = $gen->generate(['counts' => ['lives_lost' => 5, 'playtime_human' => '300h']]);

    expect($out['editorial'])->toContain('Is Cherno safe');
    expect($out['recap'])->toContain('Mike died');
    expect($out['classifieds'])->toContain('FOR SALE');
});

it('falls back to canned pools when the API fails', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $gen = new NewspaperGenerator(new OpenRouterClient('key', 'm', 'https://x/api/v1'));
    $out = $gen->generate(['counts' => ['lives_lost' => 5, 'playtime_human' => '300h']]);

    // Fallback editorial interpolates :lives_lost.
    expect($out['editorial'])->toContain('5');
    expect($out['recap'])->not->toBe('');
    expect($out['classifieds'])->not->toBe('');
});

it('falls back per-section when a delimiter is missing', function () {
    Http::fake([
        '*' => Http::response(['choices' => [['message' => ['content' =>
            "## EDITORIAL\nOnly an editorial here."
        ]]]], 200),
    ]);

    $gen = new NewspaperGenerator(new OpenRouterClient('key', 'm', 'https://x/api/v1'));
    $out = $gen->generate(['counts' => ['lives_lost' => 2, 'playtime_human' => '10h']]);

    expect($out['editorial'])->toContain('Only an editorial');
    expect($out['recap'])->not->toBe('');       // fell back
    expect($out['classifieds'])->not->toBe('');  // fell back
});
```

- [ ] **Step 3: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/NewspaperGeneratorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Create `app/Services/Newspaper/NewspaperGenerator.php`**

```php
<?php

namespace App\Services\Newspaper;

use App\Services\Llm\OpenRouterClient;
use App\Services\Personality\MessagePicker;

/**
 * Generates the three prose sections (editorial, recap, classifieds) of a Tribune issue in ONE
 * OpenRouter call, split on explicit "## EDITORIAL / ## RECAP / ## CLASSIFIEDS" delimiters. Any
 * failure — no key, timeout, non-2xx, empty, or a missing section — falls back per-section to the
 * canned `personality.newspaper.*` pools (interpolated with the week's counts). Same hard rules as
 * the eulogy generator PLUS the Tribune location policy.
 */
class NewspaperGenerator
{
    private const SECTIONS = ['editorial', 'recap', 'classifieds'];

    private const SYSTEM = <<<'TXT'
You are the entire editorial staff of "The One Life Tribune", a savage, witty post-apocalyptic
newspaper covering a hardcore DayZ "one life" server. Players get ONE life; when they die they are
banned for a while, so every death is a real funeral.

Write a full weekly issue with THREE sections. Be funny, a little roasty, creative. Use Discord
markdown (**bold**, *italics*, `> blockquotes`, fitting emojis 📰💀🐻🎯🪦).

Output EXACTLY these three delimited sections, in this order, with nothing before the first:
## EDITORIAL
<a 120-250 word op-ed themed on the week's biggest story or trend>
## RECAP
<a 120-250 word narrative of the week's real events>
## CLASSIFIEDS
<4-6 short, funny fake classified ads seeded from the real events>

HARD RULES:
- Use ONLY the facts you are given. NEVER invent names, weapons, distances, kills, or events.
- Refer to players by their exact gamertag from the data. Any witness/reaction quote MUST be
  attributed to one of the gamertags in 'witnesses' (never an invented anonymous bystander). If
  'witnesses' is empty, omit quotes.
- LOCATION POLICY (critical): You MAY mention a town/region name ONLY when it appears in the
  'location_trends' data, and ONLY as an aggregate trend ("infected attacks around Cherno are up").
  NEVER state or imply WHERE a specific named player was, died, fought, or lives. NEVER output
  coordinates or grid references. NEVER mention player bases or build events. When in doubt, omit
  the location.
- If a section has little data (a quiet week), say so wittily rather than inventing detail.
TXT;

    public function __construct(
        private OpenRouterClient $client,
        private ?MessagePicker $picker = null,
    ) {}

    /**
     * @param array<string,mixed> $facts
     * @return array{editorial:string,recap:string,classifieds:string}
     */
    public function generate(array $facts): array
    {
        try {
            $raw = $this->client->complete(self::SYSTEM, $this->userPrompt($facts));
            $parsed = $this->split($raw);
        } catch (\Throwable) {
            $parsed = ['editorial' => '', 'recap' => '', 'classifieds' => ''];
        }

        // Per-section fallback for anything empty/missing.
        foreach (self::SECTIONS as $section) {
            if (($parsed[$section] ?? '') === '') {
                $parsed[$section] = $this->fallback($section, $facts);
            }
        }

        return $parsed;
    }

    private function userPrompt(array $facts): string
    {
        return "Write this week's issue from these facts (JSON):\n".
            json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{editorial:string,recap:string,classifieds:string} */
    private function split(string $raw): array
    {
        $out = ['editorial' => '', 'recap' => '', 'classifieds' => ''];
        // Split into "HEADER\nbody" chunks on the ## delimiters.
        $parts = preg_split('/^##\s*(EDITORIAL|RECAP|CLASSIFIEDS)\s*$/mi', trim($raw), -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 1; $i < count($parts); $i += 2) {
            $key = strtolower(trim($parts[$i]));
            $body = trim($parts[$i + 1] ?? '');
            if (array_key_exists($key, $out)) {
                $out[$key] = $body;
            }
        }

        return $out;
    }

    private function fallback(string $section, array $facts): string
    {
        $picker = $this->picker ?? new MessagePicker();
        $pool = config("personality.newspaper.{$section}", []);
        if (! is_array($pool) || $pool === []) {
            return 'Slow news week on the coast.';
        }
        $pool = array_values($pool);
        $entry = $pool[$picker->indexFor("newspaper.{$section}", count($pool))];

        return strtr($entry, [
            ':lives_lost' => (string) ($facts['counts']['lives_lost'] ?? 0),
            ':playtime' => (string) ($facts['counts']['playtime_human'] ?? '0m'),
        ]);
    }
}
```

- [ ] **Step 5: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/NewspaperGeneratorTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Newspaper/NewspaperGenerator.php config/personality.php tests/Feature/NewspaperGeneratorTest.php
git commit -m "feat: NewspaperGenerator (one LLM call, delimiter split, canned fallback)"
```

---

## Task 9: `NewspaperComposer` — facts + prose → embed payloads

**Files:**
- Create: `app/Services/Newspaper/NewspaperComposer.php`
- Test: `tests/Unit/NewspaperComposerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Newspaper\NewspaperComposer;

function sampleFacts(): array
{
    return [
        'period' => ['start' => '2026-06-06T22:00:00+00:00', 'end' => '2026-06-13T22:00:00+00:00'],
        'counts' => [
            'lives_lost' => 41, 'lives_lost_prev' => 32, 'playtime_human' => '318h',
            'infected_attacks' => 162, 'infected_attacks_prev' => 108, 'pvp_hits' => 90,
            'bunker_descents' => 23, 'souls_alive' => 6,
        ],
        'superlatives' => [
            'deadliest_player' => ['gamertag' => 'SaltShaker77', 'kills' => 7],
            'furthest_kill' => ['killer' => 'RailgunRandy', 'victim' => 'carl', 'weapon' => 'Mosin', 'distance' => 412.0],
            'longest_life_ended' => ['gamertag' => 'DustOffMike', 'duration_human' => '3d 4h'],
            'most_travelled' => ['gamertag' => 'RoamerRick', 'km' => 14.0],
        ],
    ];
}

it('composes a masthead + four section embeds', function () {
    $prose = ['editorial' => 'Ed body', 'recap' => 'Recap body', 'classifieds' => 'Ads body'];
    $embeds = (new NewspaperComposer())->compose(sampleFacts(), $prose, 12);

    expect($embeds)->toHaveCount(5);
    expect($embeds[0]['title'])->toContain('THE ONE LIFE TRIBUNE');
    expect($embeds[0]['title'])->toContain('No.12');
    expect($embeds[2]['title'])->toContain('NUMBERS');
    // Numbers box shows the delta vs last week.
    expect($embeds[2]['description'])->toContain('41');
    expect($embeds[2]['description'])->toContain('SaltShaker77');
    // Editorial/recap/classifieds carry the prose.
    expect($embeds[1]['description'])->toContain('Ed body');
    expect($embeds[3]['description'])->toContain('Recap body');
    expect($embeds[4]['description'])->toContain('Ads body');
});

it('never @-mentions', function () {
    $prose = ['editorial' => 'a', 'recap' => 'b', 'classifieds' => 'c'];
    $embeds = (new NewspaperComposer())->compose(sampleFacts(), $prose, 1);
    foreach ($embeds as $e) {
        expect($e['description'] ?? '')->not->toContain('<@');
        expect($e['title'] ?? '')->not->toContain('<@');
    }
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Unit/NewspaperComposerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `app/Services/Newspaper/NewspaperComposer.php`**

```php
<?php

namespace App\Services\Newspaper;

use Carbon\CarbonImmutable;

/**
 * PURE: weekly facts + generated prose -> an ordered list of Discord-agnostic embed payloads
 * (masthead + Editorial + Week in Numbers + Recap + Classifieds). The "Week in Numbers" box is
 * built here from pure data. Plain backticked gamertags only — NEVER <@id> mentions.
 *
 * @phpstan-type Embed array{title:string,description:string,color:int,footer?:string}
 */
class NewspaperComposer
{
    private const COLOR = 0xC9B037; // newsprint gold

    /**
     * @param array<string,mixed> $facts
     * @param array{editorial:string,recap:string,classifieds:string} $prose
     * @return array<int,array<string,mixed>>
     */
    public function compose(array $facts, array $prose, int $issueNumber): array
    {
        return [
            $this->masthead($facts, $issueNumber),
            $this->section('✒️ EDITORIAL', $prose['editorial']),
            $this->numbers($facts),
            $this->section('🗞️ THE RECAP', $prose['recap']),
            $this->section('📋 CLASSIFIEDS', $prose['classifieds']),
        ];
    }

    private function masthead(array $facts, int $issueNumber): array
    {
        $start = CarbonImmutable::parse($facts['period']['start'])->format('M j');
        $end = CarbonImmutable::parse($facts['period']['end'])->format('M j');

        return [
            'title' => "📰 THE ONE LIFE TRIBUNE — No.{$issueNumber}",
            'description' => "*Week of {$start}–{$end}* · \"All the death that's fit to print\"",
            'color' => self::COLOR,
        ];
    }

    private function section(string $title, string $body): array
    {
        return [
            'title' => $title,
            'description' => $body === '' ? '*Slow news week.*' : $body,
            'color' => self::COLOR,
        ];
    }

    private function numbers(array $facts): array
    {
        $c = $facts['counts'];
        $s = $facts['superlatives'];

        $deltaLives = $this->delta((int) $c['lives_lost'], (int) ($c['lives_lost_prev'] ?? 0));
        $deltaInf = $this->delta((int) $c['infected_attacks'], (int) ($c['infected_attacks_prev'] ?? 0));

        $deadliest = $s['deadliest_player'] ? "`{$s['deadliest_player']['gamertag']}` ({$s['deadliest_player']['kills']})" : '—';
        $furthest = $s['furthest_kill'] ? round($s['furthest_kill']['distance']).'m' : '—';
        $longest = $s['longest_life_ended'] ? "`{$s['longest_life_ended']['gamertag']}` {$s['longest_life_ended']['duration_human']}" : '—';
        $travelled = $s['most_travelled'] ? "`{$s['most_travelled']['gamertag']}` {$s['most_travelled']['km']}km" : '—';

        $lines = [
            "Lives lost ......... **{$c['lives_lost']}** {$deltaLives}",
            "Total playtime ..... {$c['playtime_human']}",
            "Infected attacks ... **{$c['infected_attacks']}** {$deltaInf}",
            "Bunker descents .... {$c['bunker_descents']}",
            "Souls still alive .. {$c['souls_alive']}",
            "Deadliest player ... {$deadliest}",
            "Furthest kill ...... {$furthest}",
            "Longest life ended . {$longest}",
            "Most travelled ..... {$travelled}",
        ];

        return [
            'title' => '📊 THE WEEK IN NUMBERS',
            'description' => "```\n".implode("\n", $lines)."\n```",
            'color' => self::COLOR,
        ];
    }

    private function delta(int $now, int $prev): string
    {
        $d = $now - $prev;
        if ($d === 0) return '';
        return $d > 0 ? "(▲{$d})" : '(▼'.abs($d).')';
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Unit/NewspaperComposerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Newspaper/NewspaperComposer.php tests/Unit/NewspaperComposerTest.php
git commit -m "feat: NewspaperComposer builds masthead + section embeds"
```

---

## Task 10: `NewspaperNotifier` interface + Null + Discord

**Files:**
- Create: `app/Services/Newspaper/NewspaperNotifier.php`
- Create: `app/Services/Newspaper/NullNewspaperNotifier.php`
- Create: `app/Services/Newspaper/DiscordNewspaperNotifier.php`

No new test (gateway-bound; mirrors `DiscordLifecycleNotifier`, which is also untested). Verify with `php -l`.

- [ ] **Step 1: Create the interface** (`app/Services/Newspaper/NewspaperNotifier.php`)

```php
<?php

namespace App\Services\Newspaper;

interface NewspaperNotifier
{
    /** @param array<int,array<string,mixed>> $embeds ordered embed payloads (masthead first) */
    public function publish(array $embeds): void;
}
```

- [ ] **Step 2: Create the Null impl** (`app/Services/Newspaper/NullNewspaperNotifier.php`)

```php
<?php

namespace App\Services\Newspaper;

class NullNewspaperNotifier implements NewspaperNotifier
{
    public function publish(array $embeds): void {}
}
```

- [ ] **Step 3: Create the Discord impl** (`app/Services/Newspaper/DiscordNewspaperNotifier.php`) — mirrors `DiscordLifecycleNotifier`, posting ALL embeds in one message:

```php
<?php

namespace App\Services\Newspaper;

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Embed\Embed;

/**
 * Posts a Tribune issue as ONE message carrying all section embeds (masthead first). One-shot post
 * (immutable back issues — no edit-in-place). No content line => never @-mentions. Best-effort:
 * null client / missing channel / send failure all no-op.
 */
class DiscordNewspaperNotifier implements NewspaperNotifier
{
    public function __construct(
        private ?Discord $discord,
        private ?string $channelId,
    ) {}

    public function publish(array $embeds): void
    {
        if (! $this->discord || ! $this->channelId || $embeds === []) {
            return;
        }

        try {
            $channel = $this->discord->getChannel($this->channelId);
            if (! $channel) {
                return;
            }

            $builder = MessageBuilder::new();
            foreach ($embeds as $payload) {
                $embed = new Embed($this->discord);
                $embed->setTitle($this->trim($payload['title'], 256));
                $embed->setDescription($this->trim($payload['description'], 4096));
                $embed->setColor($payload['color'] ?? 0xC9B037);
                if (! empty($payload['footer'])) {
                    $embed->setFooter($payload['footer']);
                }
                $builder->addEmbed($embed);
            }

            $channel->sendMessage($builder)->otherwise(fn () => null);
        } catch (\Throwable) {
            // best-effort: never propagate
        }
    }

    private function trim(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
```

- [ ] **Step 4: Lint**

Run: `php -l app/Services/Newspaper/DiscordNewspaperNotifier.php && php -l app/Services/Newspaper/NullNewspaperNotifier.php && php -l app/Services/Newspaper/NewspaperNotifier.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Newspaper/NewspaperNotifier.php app/Services/Newspaper/NullNewspaperNotifier.php app/Services/Newspaper/DiscordNewspaperNotifier.php
git commit -m "feat: NewspaperNotifier interface + Null/Discord impls"
```

---

## Task 11: `config/newspaper.php` + phpunit pins + .env example

**Files:**
- Create: `config/newspaper.php`
- Modify: `phpunit.xml` (add `<env>` pins)
- Modify: `.env.example` if present (else skip)

- [ ] **Step 1: Create `config/newspaper.php`**

```php
<?php

return [
    'enabled' => filter_var(env('NEWSPAPER_ENABLED', true), FILTER_VALIDATE_BOOL),
    'channel_id' => env('NEWSPAPER_CHANNEL_ID') ?: null,
    // ISO day-of-week (1=Mon..7=Sun) and UTC hour of the weekly publish moment. Default Fri 22:00 UTC = 6pm UTC-4.
    'publish_dow' => (int) env('NEWSPAPER_PUBLISH_DOW', 5),
    'publish_hour_utc' => (int) env('NEWSPAPER_PUBLISH_HOUR_UTC', 22),
];
```

- [ ] **Step 2: Pin defaults in `phpunit.xml`** (add inside the existing `<php>` block, next to the `BOUNTY_*`/`BUNKER_*` pins):

```xml
        <env name="NEWSPAPER_ENABLED" value="true"/>
        <env name="NEWSPAPER_PUBLISH_DOW" value="5"/>
        <env name="NEWSPAPER_PUBLISH_HOUR_UTC" value="22"/>
        <env name="HIT_TRACKING_ENABLED" value="true"/>
```

- [ ] **Step 3: Verify config loads**

Run: `php laracord tinker --execute="echo config('newspaper.publish_dow'), config('hits.enabled');"`
Expected: prints `5` then a truthy value (no error). If `tinker --execute` isn't supported in this version, instead run `./vendor/bin/pest tests/Feature/HitEventServiceTest.php` which reads `config('hits.enabled')`.

- [ ] **Step 4: Commit**

```bash
git add config/newspaper.php phpunit.xml
git commit -m "feat: newspaper + hits config with pinned test defaults"
```

---

## Task 12: `NewspaperService` — periodic weekly publish with idempotency

**Files:**
- Create: `app/Services/NewspaperService.php`
- Test: `tests/Feature/NewspaperServiceTest.php`

The publish decision + idempotency is the testable core; extract it into a pure method `due(CarbonImmutable $now): bool` and a `publish()` that the tests drive with an injected notifier.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\HitEvent;
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
    Http::fake(['*' => Http::response('nope', 500)]); // force canned fallback (deterministic)
    $gen = new NewspaperGenerator(new App\Services\Llm\OpenRouterClient('k', 'm', 'https://x/api/v1'));
    return new NewspaperService(null, $state, new WeeklyFactsBuilder(), $gen, $notifier);
}

beforeEach(function () {
    $state = new BotState();
    $state->set('go_live_at', '2026-01-01T00:00:00+00:00');
});

it('does not publish before the weekly publish moment', function () {
    CarbonImmutable::setTestNow('2026-06-12 22:00:00'); // Friday is dow 5; 2026-06-12 is a Friday? assert via due()
    $notifier = capturingNotifier();
    $svc = makeService(new BotState(), $notifier);
    $svc->run(CarbonImmutable::now());
    // If 2026-06-12 is before the configured Friday/22:00 it should not have published.
    // (Pick a concrete pre-moment in the implementation step; see note.)
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

    // Second run same week: no re-publish.
    $svc->run(CarbonImmutable::now());
    expect($notifier->calls)->toBe(1);

    // Next week's Friday: publishes again.
    CarbonImmutable::setTestNow('2026-06-19 22:00:00');
    $svc->run(CarbonImmutable::now());
    expect($notifier->calls)->toBe(2);
    CarbonImmutable::setTestNow();
});

it('never publishes before go_live', function () {
    CarbonImmutable::setTestNow('2026-06-12 22:00:00');
    $state = new BotState();
    $state->delete('go_live_at');
    $notifier = capturingNotifier();
    $svc = makeService($state, $notifier);
    $svc->run(CarbonImmutable::now());
    expect($notifier->calls)->toBe(0);
    CarbonImmutable::setTestNow();
});
```

> Note: verify the weekday of the dates you assert with (`CarbonImmutable::parse('2026-06-12')->dayOfWeekIso`) and adjust the literals so "before" and "at" line up with `publish_dow=5`, `publish_hour_utc=22`. 2026-06-12 and 2026-06-19 must both be Fridays.

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/NewspaperServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `app/Services/NewspaperService.php`**

```php
<?php

namespace App\Services;

use App\Services\Newspaper\DiscordNewspaperNotifier;
use App\Services\Newspaper\NewspaperComposer;
use App\Services\Newspaper\NewspaperGenerator;
use App\Services\Newspaper\NewspaperNotifier;
use App\Services\Newspaper\NullNewspaperNotifier;
use App\Services\Newspaper\WeeklyFactsBuilder;
use App\Services\Llm\OpenRouterClient;
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

        $weekKey = $now->isoFormat('GGGG-[W]WW');
        if ($this->state->get('last_newspaper_week') === $weekKey) {
            return; // already published this week
        }

        $facts = $this->facts->build($now);
        $prose = $this->generator->generate($facts);
        $issueNumber = $this->state->getInt('newspaper_issue_count', 0) + 1;
        $embeds = (new NewspaperComposer())->compose($facts, $prose, $issueNumber);

        $this->resolveNotifier()->publish($embeds);

        $this->state->set('last_newspaper_week', $weekKey);
        $this->state->setInt('newspaper_issue_count', $issueNumber);
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
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/NewspaperServiceTest.php`
Expected: PASS. (Adjust the `due()` math / test date literals together until the before/at/idempotent/next-week cases all hold for a Friday-22:00 schedule.)

- [ ] **Step 5: Commit**

```bash
git add app/Services/NewspaperService.php tests/Feature/NewspaperServiceTest.php
git commit -m "feat: NewspaperService weekly publish with idempotency + go_live gate"
```

---

## Task 13: `news:publish` console command (preview / catch-up)

**Files:**
- Create: `app/Console/Commands/NewsPublishCommand.php`
- Test: `tests/Feature/NewsPublishCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('dry-run prints an issue without publishing or stamping state', function () {
    Http::fake(['*' => Http::response('nope', 500)]); // canned fallback
    (new BotState())->set('go_live_at', '2026-01-01T00:00:00+00:00');

    $this->artisan('news:publish --dry-run --force')
        ->assertExitCode(0);

    expect((new BotState())->get('last_newspaper_week'))->toBeNull();
});
```

> Note: `$this->artisan(...)` is Laravel's console test helper; confirm the surrounding suite uses it (e.g. in `VerifyIngestionCommand` tests). If not present, instead assert by calling the command's `handle()` directly.

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/NewsPublishCommandTest.php`
Expected: FAIL — command not registered.

- [ ] **Step 3: Create `app/Console/Commands/NewsPublishCommand.php`**

```php
<?php

namespace App\Console\Commands;

use App\Services\Newspaper\NewspaperComposer;
use App\Services\Newspaper\NewspaperGenerator;
use App\Services\Llm\OpenRouterClient;
use App\Services\Newspaper\WeeklyFactsBuilder;
use App\Services\NewspaperService;
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
            $facts = (new WeeklyFactsBuilder())->build($now);
            $prose = (new NewspaperGenerator(OpenRouterClient::fromConfig()))->generate($facts);
            $issue = $state->getInt('newspaper_issue_count', 0) + 1;
            foreach ((new NewspaperComposer())->compose($facts, $prose, $issue) as $embed) {
                $this->line("\n=== {$embed['title']} ===");
                $this->line($embed['description']);
            }
            return self::SUCCESS;
        }

        // Live publish path: reuse the service. With --force, clear the week stamp so run() proceeds.
        if ($this->option('force')) {
            $state->delete('last_newspaper_week');
            if (! $state->get('go_live_at')) {
                $state->set('go_live_at', $now->subYear()->toIso8601String());
            }
        }

        // Bypass the due() schedule for manual publish by stamping a far-past dow check is not needed:
        // call run() which checks due(); with --force we additionally short-circuit due() below.
        $svc = new NewspaperService($this->laracord(), $state);
        if ($this->option('force')) {
            // Manual force: publish regardless of weekday/time.
            $facts = (new WeeklyFactsBuilder())->build($now);
            $prose = (new NewspaperGenerator(OpenRouterClient::fromConfig()))->generate($facts);
            $issue = $state->getInt('newspaper_issue_count', 0) + 1;
            $embeds = (new NewspaperComposer())->compose($facts, $prose, $issue);
            // Resolve notifier the same way the service does (channel set => Discord, else Null).
            $channel = config('newspaper.channel_id');
            $notifier = $channel
                ? new \App\Services\Newspaper\DiscordNewspaperNotifier($this->laracord()->discord(), $channel)
                : new \App\Services\Newspaper\NullNewspaperNotifier();
            $notifier->publish($embeds);
            $state->set('last_newspaper_week', $now->isoFormat('GGGG-[W]WW'));
            $state->setInt('newspaper_issue_count', $issue);
            $this->info('Published issue '.$issue.'.');
            return self::SUCCESS;
        }

        $svc->run($now);
        $this->info('Publish check complete.');
        return self::SUCCESS;
    }
}
```

> Note: `$this->laracord()` is how `Laracord\Console\Commands\Command` exposes the bot (confirm against `VerifyIngestionCommand`; if the accessor differs, match it). The `--dry-run` path never touches the bot, so it works headless in tests.

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/NewsPublishCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/NewsPublishCommand.php tests/Feature/NewsPublishCommandTest.php
git commit -m "feat: news:publish command (dry-run preview + force)"
```

---

## Task 14: Full-suite green + docs

**Files:**
- Modify: `CLAUDE.md` (architecture bullet + env keys), `README.md` if it documents subsystems

- [ ] **Step 1: Run the whole suite**

Run: `./vendor/bin/pest`
Expected: all green (PHP 8.5 `DEPR` markers are harmless; exit 0 = pass).

- [ ] **Step 2: Add a `CLAUDE.md` architecture bullet** under the Architecture list:

```markdown
- **Weekly newspaper (The One Life Tribune)** — `app/Services/Newspaper/`: `WeeklyFactsBuilder`
  (location-SAFE 7-day aggregate — never a coordinate or (player, place) pair; locations only as
  anonymized `region => count` trends via `app/Services/Geo/ChernarusRegions`), `NewspaperGenerator`
  (one OpenRouter call → editorial/recap/classifieds split on `##` delimiters, per-section
  `personality.newspaper.*` canned fallback), `NewspaperComposer` (masthead + 4 section embeds, pure
  data "Week in Numbers" box, NEVER @-mentions), `NewspaperNotifier` + `Discord`/`Null` (one
  multi-embed message, immutable back issues). Periodic `NewspaperService` (hourly tick; publishes
  Fri 22:00 UTC, idempotent via `bot_state.last_newspaper_week` + `newspaper_issue_count`, gated by
  `go_live_at`). Preview/catch-up with `php laracord news:publish --dry-run|--force`. Not gated by
  `BAN_DRY_RUN`. **Hit capture** feeds it: `AdmParser::parseHit` → `app/Services/Hit/HitEventService`
  → `hit_events` (wired into `AdmIngestor`; backfill via `adm:backfill-hits`), `config/hits.php`
  (`HIT_TRACKING_ENABLED`).
```

- [ ] **Step 3: Add env keys to the `CLAUDE.md` `.env` list:** `NEWSPAPER_ENABLED`, `NEWSPAPER_CHANNEL_ID`, `NEWSPAPER_PUBLISH_DOW=5`, `NEWSPAPER_PUBLISH_HOUR_UTC=22`, `HIT_TRACKING_ENABLED=true` (reuses the existing `OPENROUTER_*` block).

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md README.md
git commit -m "docs: document the weekly newspaper + hit capture subsystems"
```

---

## Self-Review Notes (for the implementer)

- **Spec coverage:** Editorial/Numbers/Recap/Classifieds → Tasks 8/9. Hit capture → Tasks 1–5. Region
  trends → Tasks 6–7. Distance-travelled → Task 7 (`mostTravelled`). Cadence/idempotency/go_live →
  Task 12. No-pings → enforced in Tasks 9 (composer) + 10 (no content line). Privacy invariant →
  Tasks 6, 7 (explicit privacy test), 8 (system-prompt policy). Config/pins → Task 11. Preview →
  Task 13.
- **Read-before-mirror:** Tasks 4, 5, 7, 12, 13 say to read an existing sibling (`AdmIngestorTest`,
  `BunkerVisitBackfillService`, `LifeFactsBuilder::witnesses`, `VerifyIngestionCommand`) and copy its
  exact construction/accessors rather than guessing API. Honor those notes — they cover the few spots
  where method/column names must match the live codebase.
- **Type consistency:** `parseHit` output keys (Task 1) == `HitEventService::record` input (Task 3).
  `WeeklyFactsBuilder::build` output (Task 7) == composer/generator input (Tasks 8, 9). `due()` +
  `run()` (Task 12) are the only schedule logic; `news:publish --force` (Task 13) deliberately
  bypasses `due()`.
```
