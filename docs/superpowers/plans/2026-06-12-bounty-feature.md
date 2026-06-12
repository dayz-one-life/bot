# Bounty Feature Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Place a bounty on the longest-lived current player; reward whoever kills them with an unban token, unless the killer is a detected teammate ("associate").

**Architecture:** Two subsystems. (1) `AssociateDetector` — a pure service scoring how likely two players are teammates from proximity, co-presence/login-synchrony, and kill-graph signals over a rolling window, with an admin override list. (2) The bounty engine — `BountyService` ranks recently-active open lives by live-playtime, and on each post-ingest tick resolves a claim, then places/moves the single active bounty. Position samples are harvested during ADM ingest. Slash commands and the periodic `Service` are thin wrappers, per the repo convention.

**Tech Stack:** Laracord (Laravel Zero + DiscordPHP), SQLite, Pest, Carbon. PHP 8.2+.

**Deviation from spec (intentional):** the `player_associations` cache table is omitted. Associate-checking only happens at a claim (one pair, rare), so we compute live. Documented here so the spec/plan mismatch is deliberate, not an oversight.

---

## File Structure

**Create:**
- `database/migrations/2026_06_12_100000_create_bounty_tables.php` — `player_positions`, `associate_overrides`, `bounties`.
- `app/Models/PlayerPosition.php`, `app/Models/AssociateOverride.php`, `app/Models/Bounty.php`.
- `config/bounty.php` — all tunables.
- `app/Services/Adm/PositionRecorder.php` — writes a position sample for a known gamertag.
- `app/Services/Bounty/AssociateDetector.php` — the algorithm.
- `app/Services/Bounty/BountyService.php` — ranking + reconciliation + read status.
- `app/Services/Bounty/OverrideService.php` — admin override writes.
- `app/Services/Bounty/BountyNotifier.php` (interface), `NullBountyNotifier.php`, `DiscordBountyNotifier.php`.
- `app/Services/BountyTickService.php` — periodic Service shim (note: lives in `app/Services/`, NOT a subdir, so Laracord discovers it).
- `app/SlashCommands/BountyCommand.php`, `app/SlashCommands/TeamCommand.php`.
- Tests under `tests/Unit/` and `tests/Feature/`.

**Modify:**
- `app/Services/Adm/AdmParser.php` — add `parsePosition()`.
- `app/Services/Adm/AdmIngestor.php` — harvest positions per line; inject `PositionRecorder`.

---

## Task 1: Parser — `parsePosition()`

**FIRST, confirm the real ADM format.** Position data placement varies by server config. Before writing the regex, dump real lines:

```bash
php laracord tinker --execute='$c=new App\Services\Nitrado\NitradoClient(env("NITRADO_TOKEN"),(int)env("NITRADO_SERVICE_ID"));$f=$c->listAdmFiles();$txt=$c->downloadFile($f[count($f)-1]["path"]);foreach(preg_split("/\r\n|\r|\n/",$txt) as $l){ if(str_contains($l,"pos=<")) { echo $l."\n"; } }' 2>/dev/null | head -20
```

If `pos=<x, y, z>` appears, the regex below works as-is. If positions are absent, note it: the proximity sub-score will always be 0 and detection runs on co-presence + kill-graph (graceful degradation — still ship the parser method so it's ready if position logging is later enabled). Replace the SAMPLE lines in the test below with real lines from the dump.

**Files:**
- Modify: `app/Services/Adm/AdmParser.php`
- Test: `tests/Unit/AdmParserTest.php`

- [ ] **Step 1: Write the failing test** (append to `tests/Unit/AdmParserTest.php`)

```php
it('parses a standalone position line', function () {
    $r = $this->parser->parsePosition('12:34:56 | Player "Alice" (id=ABC123=) pos=<7500.5, 3200.1, 300.0>');
    expect($r)->toBe(['gamertag' => 'Alice', 'x' => 7500.5, 'y' => 3200.1]);
});

it('parses a position embedded inside the id parentheses', function () {
    $r = $this->parser->parsePosition('12:34:56 | Player "Bob" (id=XYZ= pos=<100.0, 200.0, 5.0>) is connected');
    expect($r)->toBe(['gamertag' => 'Bob', 'x' => 100.0, 'y' => 200.0]);
});

it('returns null when a line carries no position', function () {
    expect($this->parser->parsePosition('12:34:56 | Player "Bob" (id=XYZ=) is connected'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/AdmParserTest.php`
Expected: FAIL with "Call to undefined method ... parsePosition()".

- [ ] **Step 3: Implement** (add the method to `AdmParser`, alongside the other parse methods)

```php
    /**
     * Harvest a horizontal position sample (x, y) from any line that names a
     * player and carries a pos=<x, y, z> token, wherever it appears on the line.
     * z (altitude) is dropped — proximity is judged on the horizontal plane.
     */
    public function parsePosition(string $raw): ?array
    {
        if (!preg_match('/Player "([^"]+)"/u', $raw, $p)) return null;
        if (!preg_match('/pos=<\s*(-?[\d.]+),\s*(-?[\d.]+),\s*(-?[\d.]+)>/u', $raw, $c)) return null;
        return ['gamertag' => $p[1], 'x' => (float) $c[1], 'y' => (float) $c[2]];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/AdmParserTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Adm/AdmParser.php tests/Unit/AdmParserTest.php
git commit -m "feat(bounty): parse position samples from ADM lines"
```

---

## Task 2: Migration — bounty tables

**Files:**
- Create: `database/migrations/2026_06_12_100000_create_bounty_tables.php`
- Test: `tests/Feature/MigrationTest.php`

- [ ] **Step 1: Write the failing test** (append to `tests/Feature/MigrationTest.php`)

```php
it('creates the bounty tables', function () {
    foreach (['player_positions', 'associate_overrides', 'bounties'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
    expect(Schema::hasColumn('bounties', 'token_awarded'))->toBeTrue();
    expect(Schema::hasColumn('bounties', 'end_reason'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/MigrationTest.php`
Expected: FAIL (tables don't exist).

- [ ] **Step 3: Implement the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_positions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            $t->double('x');
            $t->double('y');
            $t->timestamp('recorded_at');
            $t->timestamps();
            $t->index(['player_id', 'recorded_at']);
            $t->index('recorded_at');
        });

        Schema::create('associate_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_a_id')->constrained('players')->cascadeOnDelete();
            $t->foreignId('player_b_id')->constrained('players')->cascadeOnDelete();
            $t->boolean('force'); // true = always associates, false = never associates
            $t->timestamps();
            $t->unique(['player_a_id', 'player_b_id']);
        });

        Schema::create('bounties', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            $t->foreignId('life_id')->constrained('lives')->cascadeOnDelete();
            $t->timestamp('placed_at');
            $t->timestamp('ended_at')->nullable();
            $t->string('end_reason')->nullable(); // moved | claimed | claimed_by_associate | died | inactive
            $t->foreignId('claimed_by_player_id')->nullable()->constrained('players')->nullOnDelete();
            $t->boolean('token_awarded')->default(false);
            $t->timestamps();
            $t->index('ended_at');
        });
    }

    public function down(): void
    {
        foreach (['bounties', 'associate_overrides', 'player_positions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/MigrationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_12_100000_create_bounty_tables.php tests/Feature/MigrationTest.php
git commit -m "feat(bounty): migration for positions, overrides, bounties"
```

---

## Task 3: Models

**Files:**
- Create: `app/Models/PlayerPosition.php`, `app/Models/AssociateOverride.php`, `app/Models/Bounty.php`
- Test: `tests/Feature/BountyModelsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Bounty;
use App\Models\Life;
use App\Models\Player;
use App\Models\PlayerPosition;

it('creates a position bound to a player', function () {
    $p = Player::create(['gamertag' => 'Tag', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $pos = PlayerPosition::create(['player_id' => $p->id, 'x' => 1.5, 'y' => 2.5, 'recorded_at' => now()]);
    expect($pos->player->id)->toBe($p->id);
    expect((float) $pos->x)->toBe(1.5);
});

it('exposes the single active bounty via active()', function () {
    $p = Player::create(['gamertag' => 'Tag2', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => now()]);
    expect(Bounty::active())->toBeNull();
    $b = Bounty::create(['player_id' => $p->id, 'life_id' => $life->id, 'placed_at' => now()]);
    expect(Bounty::active()->id)->toBe($b->id);
    $b->update(['ended_at' => now(), 'end_reason' => 'died']);
    expect(Bounty::active())->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BountyModelsTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement the three models**

`app/Models/PlayerPosition.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerPosition extends Model
{
    protected $guarded = [];
    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'recorded_at' => 'datetime',
    ];

    public function player() { return $this->belongsTo(Player::class); }
}
```

`app/Models/AssociateOverride.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssociateOverride extends Model
{
    protected $guarded = [];
    protected $casts = ['force' => 'boolean'];
}
```

`app/Models/Bounty.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bounty extends Model
{
    protected $guarded = [];
    protected $casts = [
        'placed_at' => 'datetime',
        'ended_at' => 'datetime',
        'token_awarded' => 'boolean',
    ];

    public function player() { return $this->belongsTo(Player::class); }
    public function life() { return $this->belongsTo(Life::class); }

    /** The single open bounty (ended_at IS NULL), or null. */
    public static function active(): ?self
    {
        return static::whereNull('ended_at')->first();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BountyModelsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/PlayerPosition.php app/Models/AssociateOverride.php app/Models/Bounty.php tests/Feature/BountyModelsTest.php
git commit -m "feat(bounty): PlayerPosition, AssociateOverride, Bounty models"
```

---

## Task 4: `config/bounty.php`

**Files:**
- Create: `config/bounty.php`
- Test: `tests/Feature/BountyConfigTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

it('exposes bounty defaults', function () {
    expect(config('bounty.activity_window_hours'))->toBe(48);
    expect(config('bounty.assoc_threshold'))->toBe(0.45);
    expect(config('bounty.weight_prox') + config('bounty.weight_copres') + config('bounty.weight_killg'))
        ->toEqual(1.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BountyConfigTest.php`
Expected: FAIL (config null).

- [ ] **Step 3: Implement `config/bounty.php`**

```php
<?php

return [
    'activity_window_hours' => (int) env('BOUNTY_ACTIVITY_WINDOW_HOURS', 48),
    'min_playtime_hours'    => (int) env('BOUNTY_MIN_PLAYTIME_HOURS', 2),
    'move_margin_min'       => (int) env('BOUNTY_MOVE_MARGIN_MIN', 5),

    'assoc_window_days'     => (int) env('BOUNTY_ASSOC_WINDOW_DAYS', 14),
    'assoc_radius_m'        => (float) env('BOUNTY_ASSOC_RADIUS_M', 150),
    'assoc_threshold'       => (float) env('BOUNTY_ASSOC_THRESHOLD', 0.45),
    'weight_prox'           => (float) env('BOUNTY_ASSOC_WEIGHT_PROX', 0.55),
    'weight_copres'         => (float) env('BOUNTY_ASSOC_WEIGHT_COPRES', 0.35),
    'weight_killg'          => (float) env('BOUNTY_ASSOC_WEIGHT_KILLG', 0.10),
    'sync_window_min'       => (int) env('BOUNTY_SYNC_WINDOW_MIN', 3),

    'token_reward'          => (int) env('BOUNTY_TOKEN_REWARD', 1),
    'channel_id'            => env('BOUNTY_CHANNEL_ID') ?: env('BANS_CHANNEL_ID'),
];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BountyConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/bounty.php tests/Feature/BountyConfigTest.php
git commit -m "feat(bounty): config defaults"
```

---

## Task 5: `PositionRecorder` + ingest wiring

**Files:**
- Create: `app/Services/Adm/PositionRecorder.php`
- Modify: `app/Services/Adm/AdmIngestor.php`
- Test: `tests/Feature/PositionRecorderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Player;
use App\Models\PlayerPosition;
use App\Services\Adm\PositionRecorder;

it('records a position for a known player', function () {
    $p = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    (new PositionRecorder())->record('Alice', 10.0, 20.0, new DateTimeImmutable('2026-06-12T12:00:00Z'));
    expect(PlayerPosition::where('player_id', $p->id)->count())->toBe(1);
});

it('ignores positions for an unknown player', function () {
    (new PositionRecorder())->record('Ghost', 1.0, 2.0, new DateTimeImmutable('2026-06-12T12:00:00Z'));
    expect(PlayerPosition::count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PositionRecorderTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement `PositionRecorder`**

```php
<?php

namespace App\Services\Adm;

use App\Models\Player;
use App\Models\PlayerPosition;

class PositionRecorder
{
    /** Store a position sample. No-op for a gamertag we've never seen (no player row yet). */
    public function record(string $gamertag, float $x, float $y, \DateTimeImmutable $ts): void
    {
        $player = Player::where('gamertag', $gamertag)->first();
        if (! $player) return;

        PlayerPosition::create([
            'player_id' => $player->id,
            'x' => $x,
            'y' => $y,
            'recorded_at' => $ts,
        ]);
    }
}
```

- [ ] **Step 4: Wire into `AdmIngestor`**

In `app/Services/Adm/AdmIngestor.php`, change the constructor to accept an optional recorder (keeps existing `new AdmIngestor($parser, $tracker)` call sites working):

```php
    private PositionRecorder $positions;

    public function __construct(
        private AdmParser $parser,
        private LifeTracker $tracker,
        ?PositionRecorder $positions = null,
    ) {
        $this->positions = $positions ?? new PositionRecorder();
    }
```

Then in `processFile()`, inside the per-line loop, immediately after `$ts = $this->fromMs($localTs + $offsetMs);` and BEFORE the connect/disconnect/death dispatch, add position harvesting (no `continue` — a connect/death line can also carry a position):

```php
            if (($pos = $this->parser->parsePosition($raw)) !== null) {
                $this->positions->record($pos['gamertag'], $pos['x'], $pos['y'], $ts);
            }
```

- [ ] **Step 5: Add an ingest-level test** (append to `tests/Feature/PositionRecorderTest.php`)

```php
it('harvests a position from a connect line during ingest', function () {
    $ingestor = new App\Services\Adm\AdmIngestor(new App\Services\Adm\AdmParser(), new App\Services\Life\LifeTracker());
    $content = "AdminLog started on 2026-06-12 at 00:00:00\n"
        ."12:00:00 | Player \"Alice\" (id=ABC= pos=<500.0, 600.0, 5.0>) is connected\n";
    $ingestor->processFile($content, 0, new DateTimeImmutable('2026-06-12T00:00:00Z'), 0);
    expect(PlayerPosition::count())->toBe(1);
    expect((float) PlayerPosition::first()->x)->toBe(500.0);
});
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PositionRecorderTest.php tests/Feature/AdmIngestorTest.php`
Expected: PASS (existing ingestor tests still green).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Adm/PositionRecorder.php app/Services/Adm/AdmIngestor.php tests/Feature/PositionRecorderTest.php
git commit -m "feat(bounty): record position samples during ADM ingest"
```

---

## Task 6: `AssociateDetector` — co-presence (overlap + sync)

**Files:**
- Create: `app/Services/Bounty/AssociateDetector.php`
- Test: `tests/Feature/AssociateDetectorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

// All imports for the WHOLE AssociateDetectorTest file are declared here once.
// Later tasks append test bodies + helpers but must NOT re-declare these `use`s
// (a duplicate `use` is a parse error).
use App\Models\AssociateOverride;
use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Models\PlayerPosition;
use App\Services\Bounty\AssociateDetector;
use Carbon\CarbonImmutable;

function makePlayer(string $tag): Player {
    return Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

/**
 * A closed session [from, to] for a player. A real Life is created (and reused per
 * player) so the game_sessions.life_id foreign key is satisfied — SQLite FK
 * enforcement is on by default. Detection never joins through lives, so one shared
 * dummy life per player is fine.
 */
function session(Player $p, string $from, string $to): void {
    $life = Life::firstOrCreate(['player_id' => $p->id], ['started_at' => $from]);
    GameSession::create([
        'player_id' => $p->id, 'life_id' => $life->id,
        'connected_at' => $from, 'disconnected_at' => $to,
    ]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    $this->now = CarbonImmutable::now();
    $this->detector = new AssociateDetector();
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('scores full online overlap as 1.0', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    session($a, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    session($b, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    // copresence = (overlap=1.0 + sync) / 2; identical sessions => both connect & disconnect sync => sync=1.0
    expect($this->detector->copresenceScore($a, $b, $this->now))->toBe(1.0);
});

it('scores disjoint sessions as 0.0', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    session($a, '2026-06-12T00:00:00Z', '2026-06-12T01:00:00Z');
    session($b, '2026-06-12T08:00:00Z', '2026-06-12T09:00:00Z');
    expect($this->detector->copresenceScore($a, $b, $this->now))->toBe(0.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/AssociateDetectorTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement the detector with co-presence only (more methods added in later tasks)**

```php
<?php

namespace App\Services\Bounty;

use App\Models\GameSession;
use App\Models\Player;
use Carbon\CarbonImmutable;

class AssociateDetector
{
    /** Average of online-time overlap (Jaccard) and connect/disconnect synchrony. 0–1. */
    public function copresenceScore(Player $a, Player $b, CarbonImmutable $now): float
    {
        $cutoff = $now->subDays((int) config('bounty.assoc_window_days'));
        $overlap = $this->overlapScore($a->id, $b->id, $cutoff, $now);
        $sync = $this->syncScore($a->id, $b->id, $cutoff);
        return ($overlap + $sync) / 2;
    }

    /** @return array<int,array{0:int,1:int}> online intervals (epoch sec), clipped to window; open sessions end at $now. */
    private function intervals(int $playerId, CarbonImmutable $cutoff, CarbonImmutable $now): array
    {
        $rows = GameSession::where('player_id', $playerId)
            ->where(fn ($q) => $q->whereNull('disconnected_at')->orWhere('disconnected_at', '>=', $cutoff))
            ->get();

        $out = [];
        foreach ($rows as $s) {
            $start = max($s->connected_at->getTimestamp(), $cutoff->getTimestamp());
            $end = $s->disconnected_at?->getTimestamp() ?? $now->getTimestamp();
            if ($end > $start) $out[] = [$start, $end];
        }
        return $out;
    }

    private function overlapScore(int $aId, int $bId, CarbonImmutable $cutoff, CarbonImmutable $now): float
    {
        $ia = $this->intervals($aId, $cutoff, $now);
        $ib = $this->intervals($bId, $cutoff, $now);

        $overlap = 0;
        foreach ($ia as [$s1, $e1]) {
            foreach ($ib as [$s2, $e2]) {
                $o = min($e1, $e2) - max($s1, $s2);
                if ($o > 0) $overlap += $o;
            }
        }
        $sumA = array_sum(array_map(fn ($i) => $i[1] - $i[0], $ia));
        $sumB = array_sum(array_map(fn ($i) => $i[1] - $i[0], $ib));
        $union = $sumA + $sumB - $overlap;
        return $union <= 0 ? 0.0 : $overlap / $union;
    }

    /** @return array<int,int> connect & disconnect epoch-sec events within the window. */
    private function events(int $playerId, CarbonImmutable $cutoff): array
    {
        $rows = GameSession::where('player_id', $playerId)
            ->where(fn ($q) => $q->where('connected_at', '>=', $cutoff)->orWhere('disconnected_at', '>=', $cutoff))
            ->get();

        $ev = [];
        foreach ($rows as $s) {
            if ($s->connected_at && $s->connected_at->getTimestamp() >= $cutoff->getTimestamp()) {
                $ev[] = $s->connected_at->getTimestamp();
            }
            if ($s->disconnected_at && $s->disconnected_at->getTimestamp() >= $cutoff->getTimestamp()) {
                $ev[] = $s->disconnected_at->getTimestamp();
            }
        }
        return $ev;
    }

    /** Fraction of A's events that have a B event within sync_window_min. 0–1. */
    private function syncScore(int $aId, int $bId, CarbonImmutable $cutoff): float
    {
        $ea = $this->events($aId, $cutoff);
        $eb = $this->events($bId, $cutoff);
        if (empty($ea)) return 0.0;

        $window = (int) config('bounty.sync_window_min') * 60;
        $matched = 0;
        foreach ($ea as $t) {
            foreach ($eb as $u) {
                if (abs($t - $u) <= $window) { $matched++; break; }
            }
        }
        return $matched / count($ea);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/AssociateDetectorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bounty/AssociateDetector.php tests/Feature/AssociateDetectorTest.php
git commit -m "feat(bounty): co-presence (overlap + sync) sub-score"
```

---

## Task 7: `AssociateDetector` — proximity

**Files:**
- Modify: `app/Services/Bounty/AssociateDetector.php`
- Test: `tests/Feature/AssociateDetectorTest.php`

- [ ] **Step 1: Write the failing test** (append)

```php
function pos(Player $p, string $at, float $x, float $y): void {
    PlayerPosition::create(['player_id' => $p->id, 'x' => $x, 'y' => $y, 'recorded_at' => $at]);
}

it('scores co-located players near 1.0', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    // three different 5-min bins, always within 150m
    pos($a, '2026-06-12T09:00:00Z', 1000, 1000); pos($b, '2026-06-12T09:00:30Z', 1050, 1000);
    pos($a, '2026-06-12T09:06:00Z', 2000, 2000); pos($b, '2026-06-12T09:06:30Z', 2010, 2000);
    pos($a, '2026-06-12T09:12:00Z', 3000, 3000); pos($b, '2026-06-12T09:12:30Z', 3000, 3030);
    expect($this->detector->proximityScore($a, $b, $this->now))->toBe(1.0);
});

it('scores far-apart players as 0.0', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    pos($a, '2026-06-12T09:00:00Z', 0, 0); pos($b, '2026-06-12T09:00:30Z', 9000, 9000);
    expect($this->detector->proximityScore($a, $b, $this->now))->toBe(0.0);
});

it('scores proximity 0.0 when bins never overlap', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    pos($a, '2026-06-12T09:00:00Z', 0, 0); pos($b, '2026-06-12T10:00:00Z', 0, 0);
    expect($this->detector->proximityScore($a, $b, $this->now))->toBe(0.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/AssociateDetectorTest.php`
Expected: FAIL ("undefined method proximityScore").

- [ ] **Step 3: Implement** (add to `AssociateDetector`)

```php
    /** Fraction of shared 5-min time-bins where the pair were within assoc_radius_m. 0–1. */
    public function proximityScore(Player $a, Player $b, CarbonImmutable $now): float
    {
        $cutoff = $now->subDays((int) config('bounty.assoc_window_days'));
        $radius = (float) config('bounty.assoc_radius_m');
        $binSec = 300;

        $aBins = $this->binnedPositions($a->id, $cutoff, $binSec);
        $bBins = $this->binnedPositions($b->id, $cutoff, $binSec);

        $shared = 0;
        $colocated = 0;
        foreach ($aBins as $bin => $pa) {
            if (! isset($bBins[$bin])) continue;
            $shared++;
            $pb = $bBins[$bin];
            $dist = sqrt(($pa['x'] - $pb['x']) ** 2 + ($pa['y'] - $pb['y']) ** 2);
            if ($dist <= $radius) $colocated++;
        }
        return $shared === 0 ? 0.0 : $colocated / $shared;
    }

    /** @return array<int,array{x:float,y:float}> one representative position per time-bin (last sample wins). */
    private function binnedPositions(int $playerId, CarbonImmutable $cutoff, int $binSec): array
    {
        $rows = \App\Models\PlayerPosition::where('player_id', $playerId)
            ->where('recorded_at', '>=', $cutoff)
            ->orderBy('recorded_at')
            ->get();

        $bins = [];
        foreach ($rows as $r) {
            $bin = intdiv($r->recorded_at->getTimestamp(), $binSec);
            $bins[$bin] = ['x' => (float) $r->x, 'y' => (float) $r->y];
        }
        return $bins;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/AssociateDetectorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bounty/AssociateDetector.php tests/Feature/AssociateDetectorTest.php
git commit -m "feat(bounty): proximity sub-score (binned co-location)"
```

---

## Task 8: `AssociateDetector` — kill-graph modifier

Kill data: `lives.death_by_gamertag` holds the **killer's** gamertag; the victim is the life's `player_id`. "A killed B" = a Life with `player_id = B.id` and `death_by_gamertag = A.gamertag`.

**Files:**
- Modify: `app/Services/Bounty/AssociateDetector.php`
- Test: `tests/Feature/AssociateDetectorTest.php`

- [ ] **Step 1: Write the failing test** (append; `Life` is already imported at the top of the file)

```php
it('returns 0.0 kill-graph when the pair killed each other', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    // A killed B
    Life::create(['player_id' => $b->id, 'started_at' => '2026-06-12T08:00:00Z',
        'ended_at' => '2026-06-12T09:00:00Z', 'death_cause' => 'pvp', 'death_by_gamertag' => 'A']);
    expect($this->detector->killGraphModifier($a, $b, $this->now))->toBe(0.0);
});

it('rewards shared victims in the kill-graph', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    $v = makePlayer('Victim');
    // both A and B have killed Victim (two separate lives)
    Life::create(['player_id' => $v->id, 'started_at' => '2026-06-12T07:00:00Z',
        'ended_at' => '2026-06-12T07:30:00Z', 'death_cause' => 'pvp', 'death_by_gamertag' => 'A']);
    Life::create(['player_id' => $v->id, 'started_at' => '2026-06-12T08:00:00Z',
        'ended_at' => '2026-06-12T08:30:00Z', 'death_cause' => 'pvp', 'death_by_gamertag' => 'B']);
    expect($this->detector->killGraphModifier($a, $b, $this->now))->toBeGreaterThan(0.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/AssociateDetectorTest.php`
Expected: FAIL ("undefined method killGraphModifier").

- [ ] **Step 3: Implement** (add to `AssociateDetector`)

```php
    /**
     * Sparse confidence modifier, 0–1. Any mutual kill zeroes it (they fight =>
     * not teammates). Otherwise rewards shared victims (players both have killed).
     */
    public function killGraphModifier(Player $a, Player $b, CarbonImmutable $now): float
    {
        $cutoff = $now->subDays((int) config('bounty.assoc_window_days'));

        $mutual = Life::whereNotNull('ended_at')->where('ended_at', '>=', $cutoff)
            ->where(function ($q) use ($a, $b) {
                $q->where(fn ($w) => $w->where('player_id', $b->id)->where('death_by_gamertag', $a->gamertag))
                  ->orWhere(fn ($w) => $w->where('player_id', $a->id)->where('death_by_gamertag', $b->gamertag));
            })->count();
        if ($mutual > 0) return 0.0;

        $aVictims = Life::whereNotNull('ended_at')->where('ended_at', '>=', $cutoff)
            ->where('death_by_gamertag', $a->gamertag)->pluck('player_id')->unique();
        $bVictims = Life::whereNotNull('ended_at')->where('ended_at', '>=', $cutoff)
            ->where('death_by_gamertag', $b->gamertag)->pluck('player_id')->unique();

        $shared = $aVictims->intersect($bVictims)->count();
        return $shared > 0 ? min(1.0, $shared / 3.0) : 0.0;
    }
```

Add `use App\Models\Life;` to the top of the file (alongside `use App\Models\Player;`).

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/AssociateDetectorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bounty/AssociateDetector.php tests/Feature/AssociateDetectorTest.php
git commit -m "feat(bounty): kill-graph confidence modifier"
```

---

## Task 9: `AssociateDetector` — blend, threshold, overrides, `associatesOf`

**Files:**
- Modify: `app/Services/Bounty/AssociateDetector.php`
- Test: `tests/Feature/AssociateDetectorTest.php`

- [ ] **Step 1: Write the failing test** (append; `AssociateOverride` is already imported at the top of the file)

```php
it('blends sub-scores with configured weights', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    // full overlap+sync => copresence 1.0; no positions => prox 0; no kills => killg 0
    session($a, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    session($b, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    config(['bounty.weight_prox' => 0.5, 'bounty.weight_copres' => 0.5, 'bounty.weight_killg' => 0.0]);
    expect($this->detector->score($a, $b, $this->now))->toBe(0.5); // 0.5*0 + 0.5*1 + 0
});

it('treats a score above threshold as associates', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    session($a, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    session($b, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    config(['bounty.weight_prox' => 0.0, 'bounty.weight_copres' => 1.0, 'bounty.weight_killg' => 0.0,
            'bounty.assoc_threshold' => 0.5]);
    expect($this->detector->areAssociates($a, $b, $this->now))->toBeTrue();
});

it('force-true override makes a pair associates regardless of score', function () {
    $a = makePlayer('A'); $b = makePlayer('B'); // no shared data => score 0
    [$lo, $hi] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    AssociateOverride::create(['player_a_id' => $lo, 'player_b_id' => $hi, 'force' => true]);
    expect($this->detector->areAssociates($a, $b, $this->now))->toBeTrue();
});

it('force-false override denies a pair even with a high score', function () {
    $a = makePlayer('A'); $b = makePlayer('B');
    session($a, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    session($b, '2026-06-12T08:00:00Z', '2026-06-12T10:00:00Z');
    [$lo, $hi] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    AssociateOverride::create(['player_a_id' => $lo, 'player_b_id' => $hi, 'force' => false]);
    config(['bounty.weight_copres' => 1.0, 'bounty.assoc_threshold' => 0.1]);
    expect($this->detector->areAssociates($a, $b, $this->now))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/AssociateDetectorTest.php`
Expected: FAIL ("undefined method score").

- [ ] **Step 3: Implement** (add to `AssociateDetector`; add `use App\Models\AssociateOverride;` and `use Illuminate\Support\Collection;`)

```php
    /** Weighted blend of the three sub-scores. 0–1. */
    public function score(Player $a, Player $b, ?CarbonImmutable $now = null): float
    {
        $now = $now ?? CarbonImmutable::now();
        return (float) config('bounty.weight_prox') * $this->proximityScore($a, $b, $now)
            + (float) config('bounty.weight_copres') * $this->copresenceScore($a, $b, $now)
            + (float) config('bounty.weight_killg') * $this->killGraphModifier($a, $b, $now);
    }

    /** Override-aware: a force row wins; otherwise score >= threshold. */
    public function areAssociates(Player $a, Player $b, ?CarbonImmutable $now = null): bool
    {
        [$lo, $hi] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
        $override = AssociateOverride::where('player_a_id', $lo)->where('player_b_id', $hi)->first();
        if ($override) return (bool) $override->force;

        return $this->score($a, $b, $now) >= (float) config('bounty.assoc_threshold');
    }

    /** Every other player who clears areAssociates() with $a. */
    public function associatesOf(Player $a, ?CarbonImmutable $now = null): Collection
    {
        return Player::where('id', '!=', $a->id)->get()
            ->filter(fn (Player $p) => $this->areAssociates($a, $p, $now))
            ->values();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/AssociateDetectorTest.php`
Expected: PASS (all detector tests green).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bounty/AssociateDetector.php tests/Feature/AssociateDetectorTest.php
git commit -m "feat(bounty): score blend, threshold, overrides, associatesOf"
```

---

## Task 10: `BountyNotifier` interface + Null + Discord

**Files:**
- Create: `app/Services/Bounty/BountyNotifier.php`, `NullBountyNotifier.php`, `DiscordBountyNotifier.php`
- Test: `tests/Feature/BountyNotifierTest.php` (only the Null + a spy; Discord stays uncovered per convention)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Bounty;
use App\Models\Life;
use App\Models\Player;
use App\Services\Bounty\BountyNotifier;
use App\Services\Bounty\NullBountyNotifier;

it('NullBountyNotifier satisfies the interface and does nothing', function () {
    $n = new NullBountyNotifier();
    expect($n)->toBeInstanceOf(BountyNotifier::class);
    $p = Player::create(['gamertag' => 'T', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => now()]);
    $b = Bounty::create(['player_id' => $p->id, 'life_id' => $life->id, 'placed_at' => now()]);
    $n->placed($b, $p);
    $n->moved($b, $p);
    $n->claimed($b, $p, $p, 1);
    $n->ended($b, $p, 'died');
    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BountyNotifierTest.php`
Expected: FAIL (interface not found).

- [ ] **Step 3: Implement the interface and two implementations**

`app/Services/Bounty/BountyNotifier.php`:
```php
<?php

namespace App\Services\Bounty;

use App\Models\Bounty;
use App\Models\Player;

interface BountyNotifier
{
    /** A bounty was first placed on a player. */
    public function placed(Bounty $bounty, Player $target): void;

    /** The crown moved to a new player (overtake or prior holder dropped). */
    public function moved(Bounty $bounty, Player $target): void;

    /** A non-associate killed the bounty and earned tokens. */
    public function claimed(Bounty $bounty, Player $target, Player $killer, int $tokens): void;

    /** Bounty ended with no reward (died/non-pvp, associate kill, or inactivity). */
    public function ended(Bounty $bounty, Player $target, string $reason): void;
}
```

`app/Services/Bounty/NullBountyNotifier.php`:
```php
<?php

namespace App\Services\Bounty;

use App\Models\Bounty;
use App\Models\Player;

class NullBountyNotifier implements BountyNotifier
{
    public function placed(Bounty $bounty, Player $target): void {}
    public function moved(Bounty $bounty, Player $target): void {}
    public function claimed(Bounty $bounty, Player $target, Player $killer, int $tokens): void {}
    public function ended(Bounty $bounty, Player $target, string $reason): void {}
}
```

`app/Services/Bounty/DiscordBountyNotifier.php` (mirrors `DiscordBanNotifier`'s best-effort `toChannel`/`toUser`):
```php
<?php

namespace App\Services\Bounty;

use App\Models\Bounty;
use App\Models\Player;
use Discord\Discord;

class DiscordBountyNotifier implements BountyNotifier
{
    public function __construct(private ?Discord $discord, private ?string $channelId) {}

    public function placed(Bounty $bounty, Player $target): void
    {
        $this->toChannel("🎯 **Bounty placed** on `{$target->gamertag}` — kill them for an unban token!");
        if ($target->discord_user_id) {
            $this->toUser($target->discord_user_id, '🎯 A bounty has been placed on you. Watch your back.');
        }
    }

    public function moved(Bounty $bounty, Player $target): void
    {
        $this->toChannel("🎯 **Bounty moved** — `{$target->gamertag}` is now the longest-surviving target.");
        if ($target->discord_user_id) {
            $this->toUser($target->discord_user_id, '🎯 The bounty is now on you. Watch your back.');
        }
    }

    public function claimed(Bounty $bounty, Player $target, Player $killer, int $tokens): void
    {
        $who = $killer->discord_user_id ? "<@{$killer->discord_user_id}>" : "`{$killer->gamertag}`";
        $this->toChannel("💀 **Bounty claimed!** {$who} killed `{$target->gamertag}` and earned {$tokens} unban token(s).");
        if ($killer->discord_user_id) {
            $this->toUser($killer->discord_user_id, "💰 You claimed the bounty on `{$target->gamertag}` and earned {$tokens} unban token(s)!");
        }
    }

    public function ended(Bounty $bounty, Player $target, string $reason): void
    {
        // Neutral wording — never reveals whether a reward was paid, so an associate
        // pair cannot confirm a farm worked.
        $this->toChannel("🏳️ **Bounty ended** — the bounty on `{$target->gamertag}` is no longer active.");
    }

    private function toChannel(string $content): void
    {
        if (! $this->discord || ! $this->channelId) return;
        try {
            $channel = $this->discord->getChannel($this->channelId);
            if (! $channel) return;
            $channel->sendMessage($content)->otherwise(fn () => null);
        } catch (\Throwable) {
        }
    }

    private function toUser(string $userId, string $content): void
    {
        if (! $this->discord) return;
        try {
            $this->discord->users->fetch($userId)
                ->then(fn ($user) => $user?->sendMessage($content))
                ->otherwise(fn () => null);
        } catch (\Throwable) {
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BountyNotifierTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bounty/BountyNotifier.php app/Services/Bounty/NullBountyNotifier.php app/Services/Bounty/DiscordBountyNotifier.php tests/Feature/BountyNotifierTest.php
git commit -m "feat(bounty): notifier interface, null + discord implementations"
```

---

## Task 11: `BountyService` — `livePlaytime` + `currentLeader`

**Files:**
- Create: `app/Services/Bounty/BountyService.php`
- Test: `tests/Feature/BountyServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Bounty\AssociateDetector;
use App\Services\Bounty\BountyService;
use App\Services\Bounty\NullBountyNotifier;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    $this->now = CarbonImmutable::now();
    $this->state = new BotState();
    $this->state->set('go_live_at', '2026-06-10T00:00:00+00:00');
    $this->svc = new BountyService(new AssociateDetector(), $this->state, new NullBountyNotifier(), 1);
});
afterEach(fn () => CarbonImmutable::setTestNow());

/** Player active `lastSeenHoursAgo` ago, with an open life of `committed` playtime seconds. */
function activeLife(string $tag, int $committed, int $lastSeenHoursAgo = 0): Life {
    $p = Player::create([
        'gamertag' => $tag, 'first_seen_at' => now()->subDays(5),
        'last_seen_at' => CarbonImmutable::now()->subHours($lastSeenHoursAgo),
    ]);
    return Life::create(['player_id' => $p->id, 'started_at' => now()->subDays(2),
        'ended_at' => null, 'playtime_seconds' => $committed]);
}

it('adds open-session elapsed to committed playtime', function () {
    $life = activeLife('A', 3600);
    GameSession::create(['player_id' => $life->player_id, 'life_id' => $life->id,
        'connected_at' => CarbonImmutable::now()->subMinutes(30), 'disconnected_at' => null]);
    expect($this->svc->livePlaytime($life, $this->now))->toBe(3600 + 1800);
});

it('picks the highest live-playtime recently-active eligible life', function () {
    activeLife('Low', 3 * 3600);
    $high = activeLife('High', 10 * 3600);
    expect($this->svc->currentLeader($this->now)->id)->toBe($high->id);
});

it('excludes players below the playtime floor', function () {
    activeLife('TooNew', 1800); // 30 min < 2h floor
    expect($this->svc->currentLeader($this->now))->toBeNull();
});

it('excludes players who are not recently active', function () {
    activeLife('Stale', 10 * 3600, lastSeenHoursAgo: 72); // > 48h window
    expect($this->svc->currentLeader($this->now))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BountyServiceTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement `BountyService` with these two methods (more added next tasks)**

```php
<?php

namespace App\Services\Bounty;

use App\Models\Bounty;
use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BountyService
{
    public function __construct(
        private AssociateDetector $detector,
        private BotState $state,
        private BountyNotifier $notifier,
        private int $tokenReward = 1,
    ) {}

    /** Committed playtime plus the elapsed time of the current open session (if any). */
    public function livePlaytime(Life $life, CarbonImmutable $now): int
    {
        $pt = (int) $life->playtime_seconds;
        $open = GameSession::where('life_id', $life->id)->whereNull('disconnected_at')
            ->latest('connected_at')->first();
        if ($open) {
            $pt += max(0, $now->getTimestamp() - $open->connected_at->getTimestamp());
        }
        return $pt;
    }

    /** Highest live-playtime open life among recently-active players above the floor; null if none. */
    public function currentLeader(CarbonImmutable $now): ?Life
    {
        $cutoff = $now->subHours((int) config('bounty.activity_window_hours'));
        $floor = (int) config('bounty.min_playtime_hours') * 3600;

        $lives = Life::whereNull('ended_at')
            ->whereHas('player', fn ($q) => $q->where('last_seen_at', '>=', $cutoff))
            ->get();

        $best = null;
        $bestPt = -1;
        foreach ($lives as $life) {
            $pt = $this->livePlaytime($life, $now);
            if ($pt < $floor) continue;
            if ($pt > $bestPt || ($pt === $bestPt && $best && $life->started_at < $best->started_at)) {
                $best = $life;
                $bestPt = $pt;
            }
        }
        return $best;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BountyServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bounty/BountyService.php tests/Feature/BountyServiceTest.php
git commit -m "feat(bounty): livePlaytime + currentLeader ranking"
```

---

## Task 12: `BountyService::run` — place & move

**Files:**
- Modify: `app/Services/Bounty/BountyService.php`
- Test: `tests/Feature/BountyServiceTest.php`

- [ ] **Step 1: Write the failing test** (append)

```php
use App\Models\Bounty;

it('places a bounty on the leader when none is active', function () {
    $life = activeLife('Leader', 10 * 3600);
    $this->svc->run($this->now);
    $b = Bounty::active();
    expect($b)->not->toBeNull();
    expect($b->life_id)->toBe($life->id);
});

it('does nothing before go_live', function () {
    $this->state->delete('go_live_at');
    activeLife('Leader', 10 * 3600);
    $this->svc->run($this->now);
    expect(Bounty::count())->toBe(0);
});

it('moves the bounty when a challenger leads by more than the margin', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    activeLife('Challenger', 12 * 3600); // +2h > 5min margin
    $this->svc->run($this->now);
    expect(Bounty::active()->player->gamertag)->toBe('Challenger');
    expect(Bounty::where('end_reason', 'moved')->count())->toBe(1);
});

it('does not move for a sub-margin lead', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    activeLife('Barely', 10 * 3600 + 60); // +1min < 5min margin
    $this->svc->run($this->now);
    expect(Bounty::active()->life_id)->toBe($held->id);
});

it('drops a stale holder as inactive and moves on', function () {
    $held = activeLife('Holder', 10 * 3600, lastSeenHoursAgo: 72); // now stale
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subDay()]);
    $fresh = activeLife('Fresh', 4 * 3600);
    $this->svc->run($this->now);
    expect(Bounty::where('end_reason', 'inactive')->count())->toBe(1);
    expect(Bounty::active()->life_id)->toBe($fresh->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BountyServiceTest.php`
Expected: FAIL ("undefined method run").

- [ ] **Step 3: Implement `run()` (place/move only — claim resolution added in Task 13) and helpers**

Add to `BountyService`:

```php
    /** One reconciliation tick: resolve an ended bounty, then place/move. No-op before go_live. */
    public function run(?CarbonImmutable $now = null): void
    {
        $now = $now ?? CarbonImmutable::now();
        if (! $this->state->get('go_live_at')) return;

        DB::transaction(function () use ($now) {
            // 1) Resolve a bounty whose life has ended (claim/death). Added in Task 13.
            $this->resolveEnded($now);

            // 2) Place or move.
            $active = Bounty::active();
            $leader = $this->currentLeader($now);

            if (! $active) {
                if ($leader) $this->place($leader, $now);
                return;
            }

            if (! $this->eligible($active->player_id, $now)) {
                $this->close($active, 'inactive', $now);
                if ($leader) $this->move($leader, $now);
                return;
            }

            if ($leader && $leader->id !== $active->life_id) {
                $holderLife = Life::find($active->life_id);
                $margin = (int) config('bounty.move_margin_min') * 60;
                if ($this->livePlaytime($leader, $now) - $this->livePlaytime($holderLife, $now) >= $margin) {
                    $this->close($active, 'moved', $now);
                    $this->move($leader, $now);
                }
            }
        });
    }

    /** Placeholder filled in Task 13. */
    protected function resolveEnded(CarbonImmutable $now): void
    {
        // no-op until Task 13
    }

    private function eligible(int $playerId, CarbonImmutable $now): bool
    {
        $cutoff = $now->subHours((int) config('bounty.activity_window_hours'));
        return Player::where('id', $playerId)->where('last_seen_at', '>=', $cutoff)->exists();
    }

    private function place(Life $leader, CarbonImmutable $now): void
    {
        $b = Bounty::create(['player_id' => $leader->player_id, 'life_id' => $leader->id, 'placed_at' => $now]);
        $this->notifier->placed($b, Player::find($leader->player_id));
    }

    private function move(Life $leader, CarbonImmutable $now): void
    {
        $b = Bounty::create(['player_id' => $leader->player_id, 'life_id' => $leader->id, 'placed_at' => $now]);
        $this->notifier->moved($b, Player::find($leader->player_id));
    }

    private function close(Bounty $b, string $reason, CarbonImmutable $now): void
    {
        $b->update(['ended_at' => $now, 'end_reason' => $reason]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BountyServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bounty/BountyService.php tests/Feature/BountyServiceTest.php
git commit -m "feat(bounty): run() place/move/inactive reconciliation"
```

---

## Task 13: `BountyService` — claim resolution + token award

**Files:**
- Modify: `app/Services/Bounty/BountyService.php`
- Test: `tests/Feature/BountyServiceTest.php`

- [ ] **Step 1: Write the failing test** (append)

```php
/** End the bounty holder's life as a PvP kill by $killerTag. */
function killHolder(Life $life, string $killerTag): void {
    $life->update(['ended_at' => CarbonImmutable::now(), 'death_cause' => 'pvp', 'death_by_gamertag' => $killerTag]);
}

it('awards a token when a non-associate kills the bounty', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    $killer = Player::create(['gamertag' => 'Hunter', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    killHolder($held, 'Hunter');

    $this->svc->run($this->now);

    expect($killer->fresh()->unban_tokens)->toBe(1);
    $b = Bounty::where('end_reason', 'claimed')->first();
    expect($b)->not->toBeNull();
    expect($b->token_awarded)->toBeTrue();
    expect($b->claimed_by_player_id)->toBe($killer->id);
});

it('awards no token when an associate kills the bounty', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    $killer = Player::create(['gamertag' => 'Buddy', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    // force associates
    [$lo, $hi] = $held->player_id < $killer->id ? [$held->player_id, $killer->id] : [$killer->id, $held->player_id];
    App\Models\AssociateOverride::create(['player_a_id' => $lo, 'player_b_id' => $hi, 'force' => true]);
    killHolder($held, 'Buddy');

    $this->svc->run($this->now);

    expect($killer->fresh()->unban_tokens)->toBe(0);
    expect(Bounty::where('end_reason', 'claimed_by_associate')->count())->toBe(1);
});

it('awards no token for a non-pvp bounty death', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    $held->update(['ended_at' => CarbonImmutable::now(), 'death_cause' => 'bled_out', 'death_by_gamertag' => null]);

    $this->svc->run($this->now);

    expect(Bounty::where('end_reason', 'died')->count())->toBe(1);
});

it('is idempotent — a resolved bounty is not paid twice', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()->subHour()]);
    $killer = Player::create(['gamertag' => 'Hunter', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    killHolder($held, 'Hunter');

    $this->svc->run($this->now);
    $this->svc->run($this->now); // second tick

    expect($killer->fresh()->unban_tokens)->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BountyServiceTest.php`
Expected: FAIL (resolveEnded is a no-op; tokens stay 0).

- [ ] **Step 3: Replace the placeholder `resolveEnded()` with the real implementation**

```php
    /** If the active bounty's life has ended, close it — paying a token for a clean PvP claim. */
    protected function resolveEnded(CarbonImmutable $now): void
    {
        $active = Bounty::active();
        if (! $active) return;

        $life = Life::find($active->life_id);
        if (! $life || $life->ended_at === null) return;

        $target = Player::find($active->player_id);

        // Non-PvP death, or unparseable killer => no token.
        if ($life->death_cause !== 'pvp' || ! $life->death_by_gamertag) {
            $this->close($active, 'died', $now);
            $this->notifier->ended($active, $target, 'died');
            return;
        }

        $killer = Player::where('gamertag', $life->death_by_gamertag)->first();
        if (! $killer || $killer->id === $target->id) {
            $this->close($active, 'died', $now);
            $this->notifier->ended($active, $target, 'died');
            return;
        }

        if ($this->detector->areAssociates($target, $killer, $now)) {
            $this->close($active, 'claimed_by_associate', $now);
            $this->notifier->ended($active, $target, 'claimed_by_associate');
            return;
        }

        // Clean claim: award tokens (guarded by token_awarded so a re-tick can't double-pay).
        Player::where('id', $killer->id)->increment('unban_tokens', $this->tokenReward);
        $active->update([
            'ended_at' => $now,
            'end_reason' => 'claimed',
            'claimed_by_player_id' => $killer->id,
            'token_awarded' => true,
        ]);
        $this->notifier->claimed($active, $target, $killer->fresh(), $this->tokenReward);
    }
```

(Idempotency holds because `resolveEnded` only acts on the *active* bounty; once closed, `Bounty::active()` returns null on the next tick, so no second payout is possible.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BountyServiceTest.php`
Expected: PASS (all bounty-service tests green).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bounty/BountyService.php tests/Feature/BountyServiceTest.php
git commit -m "feat(bounty): claim resolution with associate-aware token award"
```

---

## Task 14: `BountyService::status` (read for `/bounty`)

**Files:**
- Modify: `app/Services/Bounty/BountyService.php`
- Test: `tests/Feature/BountyServiceTest.php`

- [ ] **Step 1: Write the failing test** (append)

```php
it('reports no active bounty', function () {
    expect($this->svc->status($this->now))->toBe(['active' => false]);
});

it('reports the active bounty with runner-up gap', function () {
    $held = activeLife('Holder', 10 * 3600);
    Bounty::create(['player_id' => $held->player_id, 'life_id' => $held->id, 'placed_at' => now()]);
    activeLife('Runner', 7 * 3600);
    $s = $this->svc->status($this->now);
    expect($s['active'])->toBeTrue();
    expect($s['gamertag'])->toBe('Holder');
    expect($s['playtime_seconds'])->toBe(10 * 3600);
    expect($s['runner_up_gap_seconds'])->toBe(3 * 3600);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BountyServiceTest.php`
Expected: FAIL ("undefined method status").

- [ ] **Step 3: Implement** (add to `BountyService`)

```php
    /**
     * @return array{active:bool, gamertag?:string, playtime_seconds?:int,
     *               life_started_at?:?string, runner_up_gap_seconds?:?int}
     */
    public function status(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $active = Bounty::active();
        if (! $active) return ['active' => false];

        $life = Life::find($active->life_id);
        $target = Player::find($active->player_id);
        $holderPt = $life ? $this->livePlaytime($life, $now) : 0;

        // Runner-up = highest live-playtime eligible open life that isn't the holder.
        $cutoff = $now->subHours((int) config('bounty.activity_window_hours'));
        $runnerPt = null;
        foreach (Life::whereNull('ended_at')->whereHas('player', fn ($q) => $q->where('last_seen_at', '>=', $cutoff))->get() as $other) {
            if ($other->id === $active->life_id) continue;
            $pt = $this->livePlaytime($other, $now);
            if ($runnerPt === null || $pt > $runnerPt) $runnerPt = $pt;
        }

        return [
            'active' => true,
            'gamertag' => $target?->gamertag,
            'playtime_seconds' => $holderPt,
            'life_started_at' => $life?->started_at?->toIso8601String(),
            'runner_up_gap_seconds' => $runnerPt === null ? null : $holderPt - $runnerPt,
        ];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BountyServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bounty/BountyService.php tests/Feature/BountyServiceTest.php
git commit -m "feat(bounty): status() read for /bounty command"
```

---

## Task 15: `OverrideService` (admin override writes)

**Files:**
- Create: `app/Services/Bounty/OverrideService.php`
- Test: `tests/Feature/OverrideServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\AssociateOverride;
use App\Models\Player;
use App\Services\Bounty\OverrideService;

beforeEach(fn () => $this->svc = new OverrideService());

function mkPlayer(string $tag): Player {
    return Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('sets a normalized override row (a_id < b_id) once', function () {
    $a = mkPlayer('A'); $b = mkPlayer('B');
    expect($this->svc->set('B', 'A', true))->toBe('ok'); // reversed order on input
    $row = AssociateOverride::first();
    expect($row->player_a_id)->toBe(min($a->id, $b->id));
    expect($row->player_b_id)->toBe(max($a->id, $b->id));
    expect($row->force)->toBeTrue();

    // updating the same pair flips force without duplicating
    expect($this->svc->set('A', 'B', false))->toBe('ok');
    expect(AssociateOverride::count())->toBe(1);
    expect(AssociateOverride::first()->force)->toBeFalse();
});

it('reports a missing gamertag', function () {
    mkPlayer('A');
    expect($this->svc->set('A', 'Ghost', true))->toBe('not_found');
});

it('clears an override', function () {
    mkPlayer('A'); mkPlayer('B');
    $this->svc->set('A', 'B', true);
    expect($this->svc->clear('A', 'B'))->toBe('ok');
    expect(AssociateOverride::count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/OverrideServiceTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Bounty;

use App\Models\AssociateOverride;
use App\Models\Player;

class OverrideService
{
    /** @return string 'ok' | 'not_found' */
    public function set(string $tagA, string $tagB, bool $force): string
    {
        [$lo, $hi] = $this->normalize($tagA, $tagB);
        if ($lo === null) return 'not_found';

        AssociateOverride::updateOrCreate(
            ['player_a_id' => $lo, 'player_b_id' => $hi],
            ['force' => $force],
        );
        return 'ok';
    }

    /** @return string 'ok' | 'not_found' */
    public function clear(string $tagA, string $tagB): string
    {
        [$lo, $hi] = $this->normalize($tagA, $tagB);
        if ($lo === null) return 'not_found';

        AssociateOverride::where('player_a_id', $lo)->where('player_b_id', $hi)->delete();
        return 'ok';
    }

    /** @return array{0:?int,1:?int} ordered ids, or [null,null] if either gamertag is unknown. */
    private function normalize(string $tagA, string $tagB): array
    {
        $a = Player::where('gamertag', $tagA)->first();
        $b = Player::where('gamertag', $tagB)->first();
        if (! $a || ! $b) return [null, null];

        return $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/OverrideServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bounty/OverrideService.php tests/Feature/OverrideServiceTest.php
git commit -m "feat(bounty): admin override service"
```

---

## Task 16: `BountyTickService` (periodic) + position pruning

This periodic `Service` must live in `app/Services/` (top level), like `IngestAdmService`, so Laracord discovers it. It is a thin shim — not unit-tested (no gateway), per convention. We verify it loads and subclasses `Service`.

**Files:**
- Create: `app/Services/BountyTickService.php`
- Test: `tests/Feature/BountyTickServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\BountyTickService;
use Laracord\Services\Service;

it('is a discoverable Laracord service constructible without a bot', function () {
    $svc = new BountyTickService();
    expect($svc)->toBeInstanceOf(Service::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BountyTickServiceTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement** (mirrors `IngestAdmService` + the no-arg-construct override from `BanExpiryService`)

```php
<?php

namespace App\Services;

use App\Models\PlayerPosition;
use App\Services\Bounty\AssociateDetector;
use App\Services\Bounty\BountyService;
use App\Services\Bounty\DiscordBountyNotifier;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Laracord\Laracord;
use Laracord\Services\Service;

class BountyTickService extends Service
{
    protected int $interval = 60;

    /** Allow no-arg instantiation in tests (parent ctor requires a bot). */
    public function __construct(?Laracord $bot = null)
    {
        if ($bot) parent::__construct($bot);
    }

    public function handle(): void
    {
        $state = new BotState();
        if (! $state->get('go_live_at')) return;

        try {
            $notifier = new DiscordBountyNotifier($this->discord(), config('bounty.channel_id'));
            $svc = new BountyService(new AssociateDetector(), $state, $notifier, (int) config('bounty.token_reward'));
            $svc->run();

            // Prune position samples older than the detection window.
            $cutoff = CarbonImmutable::now()->subDays((int) config('bounty.assoc_window_days'));
            PlayerPosition::where('recorded_at', '<', $cutoff)->delete();
        } catch (\Throwable $e) {
            $this->console()->error('[bounty] tick failed: '.$e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BountyTickServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/BountyTickService.php tests/Feature/BountyTickServiceTest.php
git commit -m "feat(bounty): periodic tick service + position pruning"
```

---

## Task 17: `/bounty` slash command

Slash commands aren't unit-tested (no gateway). Verify with `php -l` and a class-load check, per convention.

**Files:**
- Create: `app/SlashCommands/BountyCommand.php`

- [ ] **Step 1: Implement** (thin wrapper over `BountyService::status`)

```php
<?php

namespace App\SlashCommands;

use App\Services\Bounty\AssociateDetector;
use App\Services\Bounty\BountyService;
use App\Services\Bounty\NullBountyNotifier;
use App\Services\State\BotState;
use Laracord\Commands\SlashCommand;

class BountyCommand extends SlashCommand
{
    protected $name = 'bounty';
    protected $description = 'Show the current bounty target.';

    public function handle($interaction): void
    {
        $svc = new BountyService(new AssociateDetector(), new BotState(), new NullBountyNotifier(), (int) config('bounty.token_reward'));
        $s = $svc->status();

        if (! ($s['active'] ?? false)) {
            $this->message('🎯 No active bounty right now.')->reply($interaction, ephemeral: true);
            return;
        }

        $hours = round($s['playtime_seconds'] / 3600, 1);
        $gap = $s['runner_up_gap_seconds'] !== null
            ? round($s['runner_up_gap_seconds'] / 3600, 1).'h ahead of the runner-up'
            : 'no runner-up';

        $this->message(
            "🎯 **Bounty:** `{$s['gamertag']}`\n"
            ."• Live playtime: {$hours}h\n"
            ."• {$gap}\n"
            .'Kill them (and don\'t be on their team) to earn an unban token.'
        )->reply($interaction, ephemeral: true);
    }
}
```

- [ ] **Step 2: Verify the command loads**

Run: `php -l app/SlashCommands/BountyCommand.php && php laracord tinker --execute='echo is_subclass_of(App\SlashCommands\BountyCommand::class, Laracord\Commands\SlashCommand::class) ? "OK" : "BAD";'`
Expected: "No syntax errors" and "OK".

- [ ] **Step 3: Run the full suite** (nothing regressed)

Run: `./vendor/bin/pest`
Expected: PASS (green; harmless `DEPR` markers may appear).

- [ ] **Step 4: Commit**

```bash
git add app/SlashCommands/BountyCommand.php
git commit -m "feat(bounty): /bounty slash command"
```

---

## Task 18: `/team` admin slash command

Uses `GuardsAdmin` + a single STRING `action` option with choices (avoids Laracord subcommand-parsing uncertainty). `gamertag` autocompletes; `gamertag2` is required for link/unlink/clear and ignored for show.

**Files:**
- Create: `app/SlashCommands/TeamCommand.php`

- [ ] **Step 1: Implement**

```php
<?php

namespace App\SlashCommands;

use App\Services\Bounty\AssociateDetector;
use App\Services\Bounty\OverrideService;
use App\Services\Lookup\GamertagLookup;
use App\Models\Player;
use App\SlashCommands\Concerns\GuardsAdmin;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class TeamCommand extends SlashCommand
{
    use GuardsAdmin;

    protected $name = 'team';
    protected $description = 'Admin: manage bounty associate overrides.';

    protected $options = [
        ['name' => 'action', 'description' => 'link | unlink | clear | show', 'type' => 3, 'required' => true,
            'choices' => [
                ['name' => 'link (force associates)', 'value' => 'link'],
                ['name' => 'unlink (force NOT associates)', 'value' => 'unlink'],
                ['name' => 'clear (back to algorithm)', 'value' => 'clear'],
                ['name' => 'show', 'value' => 'show'],
            ]],
        ['name' => 'gamertag', 'description' => 'First gamertag', 'type' => 3, 'required' => true, 'autocomplete' => true],
        ['name' => 'gamertag2', 'description' => 'Second gamertag (for link/unlink/clear)', 'type' => 3, 'required' => false, 'autocomplete' => true],
    ];

    public function handle($interaction): void
    {
        if ($this->denyIfNotAdmin($interaction)) return;

        $action = (string) $this->value('action');
        $a = (string) $this->value('gamertag');
        $b = $this->value('gamertag2') !== null ? (string) $this->value('gamertag2') : null;

        if ($action === 'show') {
            $this->message($this->renderShow($a))->reply($interaction, ephemeral: true);
            return;
        }

        if ($b === null) {
            $this->message('⚠️ This action needs a second gamertag.')->reply($interaction, ephemeral: true);
            return;
        }

        $svc = new OverrideService();
        $result = match ($action) {
            'link' => $svc->set($a, $b, true),
            'unlink' => $svc->set($a, $b, false),
            'clear' => $svc->clear($a, $b),
            default => 'not_found',
        };

        $msg = $result === 'ok'
            ? "✅ `{$a}` / `{$b}` override updated ({$action})."
            : '⚠️ One of those gamertags was not found.';
        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    private function renderShow(string $tag): string
    {
        $player = Player::where('gamertag', $tag)->first();
        if (! $player) return '⚠️ No player found with that gamertag.';

        $associates = (new AssociateDetector())->associatesOf($player);
        if ($associates->isEmpty()) {
            return "🔍 `{$tag}` has no detected associates.";
        }

        $lines = $associates->map(function (Player $p) use ($player) {
            $score = round((new AssociateDetector())->score($player, $p), 2);
            return "• `{$p->gamertag}` (score {$score})";
        })->implode("\n");

        return "🔍 Associates of `{$tag}`:\n{$lines}";
    }

    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => (new GamertagLookup())->players($value),
            'gamertag2' => fn (Interaction $i, $value) => (new GamertagLookup())->players($value),
        ];
    }
}
```

- [ ] **Step 2: Verify the command loads**

Run: `php -l app/SlashCommands/TeamCommand.php && php laracord tinker --execute='echo is_subclass_of(App\SlashCommands\TeamCommand::class, Laracord\Commands\SlashCommand::class) ? "OK" : "BAD";'`
Expected: "No syntax errors" and "OK".

- [ ] **Step 3: Run the full suite**

Run: `./vendor/bin/pest`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/SlashCommands/TeamCommand.php
git commit -m "feat(bounty): /team admin override command"
```

---

## Final verification

- [ ] **Run the entire suite**

Run: `./vendor/bin/pest`
Expected: all green (harmless `DEPR` markers allowed; exit 0).

- [ ] **Confirm migrations apply cleanly on a fresh DB**

Run: `php laracord migrate:fresh && ./vendor/bin/pest tests/Feature/MigrationTest.php`
Expected: migrations run; table assertions pass.

- [ ] **Update docs**

Add a "Bounty" subsection to `CLAUDE.md` (Architecture) and `README` noting: the new `app/Services/Bounty/` services, `BountyTickService`, `/bounty` + `/team` commands, the `config/bounty.php` tunables, and that `BAN_DRY_RUN` does NOT gate bounty token awards (they are DB-only writes — no external side effects — so they fire even in dry-run). Commit.

```bash
git add CLAUDE.md README.md
git commit -m "docs: document bounty feature"
```

---

## Notes for the implementer

- **`life_id => 0` in detector tests** is deliberate — co-presence/proximity never join through `lives`, so a dummy id keeps fixtures terse. Bounty-service tests always use real lives.
- **Weights/threshold/radius are guesses.** After a few days of live position data, use `/team show` to eyeball real pairs and tune `BOUNTY_ASSOC_*` in `.env`. No code change needed.
- **If the Task 1 dump shows no `pos=<…>`:** positions won't accumulate and `proximityScore` returns 0 — the feature still works on co-presence + kill-graph. Flag to the user that proximity is dormant until position logging is enabled server-side.
- **Ordering across periodic Services** (ingest vs bounty tick) isn't guaranteed, but bounty reconciliation is idempotent and eventually consistent — a claim observed one tick late is still paid exactly once.
