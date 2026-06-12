# One Life Bot — Plan 1: Foundation & Life-Tracking Verification

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Laracord bot that ingests Nitrado `.ADM` logs and reconstructs each player's lives, sessions, and playtime — with **no banning yet** — ending at a verification milestone run against the real ADM file set.

**Architecture:** A single Laracord (Laravel + DiscordPHP) process backed by SQLite. Pure classes (`AdmParser`) parse log lines and reconstruct UTC timestamps; `NitradoClient` lists/downloads ADM files; `LifeTracker` applies a per-player connect/disconnect/death/reboot state machine to Eloquent models; `AdmIngestor` drives chronological backfill from file cursors; an `IngestAdmTask` runs it on a timer; an `adm:verify` console command runs a full backfill and prints a report.

**Tech Stack:** PHP 8.2+, Laracord 2.x, Laravel components (Eloquent, Migrations, Http), SQLite, Pest for tests.

**Spec:** `docs/superpowers/specs/2026-06-11-one-life-bot-design.md` (Phases 1–4).

**Domain references (read, do NOT copy code):**
- `../koth-bot/src/ingest/parseLine.js`, `../koth-bot/src/ingest/admPoller.js`, `../koth-bot/src/nitrado.js` — working ADM parsing, timestamp reconstruction, Nitrado client.
- `../../dayzkoth/onelife-bot` — previous one-life bot (no life/playtime tracking).

---

## File structure (created by this plan)

```
app/
  Services/
    Adm/AdmParser.php          # pure: parse connect/disconnect/death + timestamp reconstruction
    Adm/AdmIngestor.php        # cursor-driven chronological ingestion, backfill/live modes
    Life/LifeTracker.php       # connect/disconnect/death/reboot -> lives, sessions, playtime
    Nitrado/NitradoClient.php  # list ADM files, download file
    State/BotState.php         # typed key/value accessor over bot_state table
  Tasks/IngestAdmTask.php      # ~60s timer -> AdmIngestor::tick()
  Console/Commands/VerifyIngestionCommand.php  # adm:verify report
  Models/Player.php  Ban.php  Life.php  GameSession.php  AdmFile.php
database/migrations/2026_06_11_000000_create_one_life_tables.php
tests/
  Unit/AdmParserTest.php
  Unit/NitradoClientTest.php
  Feature/LifeTrackerTest.php
  Feature/AdmIngestorTest.php
  Pest.php  TestCase.php
  fixtures/sample.ADM
```

---

## Task 1: Scaffold Laracord into the repo

The repo (`bot/`) already contains `.git`, `LICENSE`, `README.md`, `docs/`. `composer create-project` requires an empty target, so scaffold into a temp dir and copy in (excluding the scaffold's `.git`).

**Files:** creates the full Laracord skeleton in the repo root.

- [ ] **Step 1: Scaffold into a temp directory and copy in**

```bash
composer create-project laracord/laracord /tmp/laracord-scaffold --no-interaction
rsync -a --exclude='.git' --exclude='README.md' /tmp/laracord-scaffold/ ./
rm -rf /tmp/laracord-scaffold
```

- [ ] **Step 2: Install dependencies and confirm the binary runs**

Run: `composer install && php laracord --version`
Expected: prints a Laracord/Laravel version banner with no fatal error.

- [ ] **Step 3: Confirm the expected directories exist**

Run: `ls app && ls config && ls database`
Expected: `app/` contains `SlashCommands`, `Models`, `Services`, `Bot.php`; `config/` contains `app.php`, `discord.php`; `database/` contains `migrations`.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: scaffold Laracord project"
```

---

## Task 2: Configure environment and SQLite

**Files:**
- Modify: `.env.example`, `.env`
- Modify: `config/database.php` (only if SQLite is not already the default)

- [ ] **Step 1: Define our environment variables**

Append to `.env.example` (create `.env` as a copy):

```dotenv
# Discord
DISCORD_TOKEN=
DISCORD_GUILD_ID=
BANS_CHANNEL_ID=

# Nitrado
NITRADO_TOKEN=
NITRADO_SERVICE_ID=

# Behavior
BAN_DURATION_HOURS=12
ADM_BACKFILL_BUDGET=15

# Database
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

TZ=UTC
```

- [ ] **Step 2: Create the SQLite file and a local .env**

```bash
cp .env.example .env
touch database/database.sqlite
php laracord key:generate || true
```

- [ ] **Step 3: Confirm DB connection**

Run: `php laracord migrate --pretend`
Expected: runs without a "could not find driver"/connection error (it may report no migrations yet — that's fine).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: configure env and sqlite"
```

---

## Task 3: Add the Pest test harness and a DB smoke test

This de-risks the rest of the plan: confirm Pest boots and migrations run against in-memory SQLite before relying on it.

**Files:**
- Create: `tests/TestCase.php`, `tests/Pest.php`, `phpunit.xml`, `tests/Feature/SmokeTest.php`

- [ ] **Step 1: Install Pest**

```bash
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies --no-interaction
```

- [ ] **Step 2: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
        <testsuite name="Feature"><directory>tests/Feature</directory></testsuite>
    </testsuites>
    <php>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="APP_ENV" value="testing"/>
    </php>
</phpunit>
```

- [ ] **Step 3: Create `tests/TestCase.php`**

Laracord is Laravel-based; the app factory lives in `bootstrap/app.php`. This standard TestCase boots it.

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }
}
```

> If `bootstrap/app.php` does not return an `Illuminate\Foundation\Application` (Laracord may use a lighter bootstrap), instead extend the Eloquent Capsule: in `createApplication`, build `new \Illuminate\Database\Capsule\Manager`, add an in-memory sqlite connection, `setAsGlobal()`, `bootEloquent()`, and return a minimal container. Confirm via the smoke test below before continuing.

- [ ] **Step 4: Create `tests/Pest.php`**

```php
<?php

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
```

- [ ] **Step 5: Write the smoke test**

`tests/Feature/SmokeTest.php`:

```php
<?php

use Illuminate\Support\Facades\Schema;

it('boots the framework and can use the schema builder', function () {
    Schema::create('smoke', function ($t) { $t->id(); $t->string('name'); });
    expect(Schema::hasTable('smoke'))->toBeTrue();
});
```

- [ ] **Step 6: Run the smoke test**

Run: `./vendor/bin/pest tests/Feature/SmokeTest.php`
Expected: PASS. If it fails on bootstrap, apply the Capsule fallback from Step 3, then re-run until green.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "test: add Pest harness with in-memory sqlite"
```

---

## Task 4: Database migrations

**Files:**
- Create: `database/migrations/2026_06_11_000000_create_one_life_tables.php`

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
        Schema::create('players', function (Blueprint $t) {
            $t->id();
            $t->string('gamertag')->unique();
            $t->string('discord_user_id')->nullable()->unique();
            $t->foreignId('referrer_id')->nullable()->constrained('players')->nullOnDelete();
            $t->unsignedInteger('unban_tokens')->default(0);
            $t->unsignedInteger('used_tokens')->default(0);
            $t->boolean('link_rewarded')->default(false);
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->index('last_seen_at');
        });

        Schema::create('adm_files', function (Blueprint $t) {
            $t->id();
            $t->string('path')->unique();
            $t->string('name');
            $t->timestamp('log_date')->nullable();
            $t->boolean('is_complete')->default(false);
            $t->unsignedInteger('last_processed_line')->default(0);
            $t->unsignedBigInteger('last_known_size')->default(0);
            $t->timestamps();
        });

        Schema::create('bans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            $t->timestamp('banned_at');
            $t->timestamp('expires_at')->nullable();
            $t->boolean('expired')->default(false);
            $t->string('reason');
            $t->string('source')->default('manual'); // auto_death | manual | token
            $t->timestamps();
            $t->index('expired');
            $t->index('expires_at');
        });

        Schema::create('lives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();
            $t->string('death_cause')->nullable();
            $t->string('death_by_gamertag')->nullable();
            $t->unsignedBigInteger('playtime_seconds')->default(0);
            $t->timestamps();
            $t->index(['player_id', 'ended_at']);
        });

        Schema::create('game_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            $t->foreignId('life_id')->constrained('lives')->cascadeOnDelete();
            $t->timestamp('connected_at');
            $t->timestamp('disconnected_at')->nullable();
            $t->unsignedBigInteger('duration_seconds')->nullable();
            $t->string('close_reason')->nullable(); // clean | reboot | superseded
            $t->timestamps();
            $t->index(['player_id', 'disconnected_at']);
        });

        Schema::create('bot_state', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['game_sessions','lives','bans','adm_files','bot_state','players'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
```

- [ ] **Step 2: Run migrations against the real DB**

Run: `php laracord migrate`
Expected: all six tables created, no errors.

- [ ] **Step 3: Confirm migrations run clean in tests**

`tests/Feature/MigrationTest.php`:

```php
<?php

use Illuminate\Support\Facades\Schema;

it('creates all one-life tables', function () {
    foreach (['players','adm_files','bans','lives','game_sessions','bot_state'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
```

Run: `./vendor/bin/pest tests/Feature/MigrationTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: add one-life database schema"
```

---

## Task 5: Eloquent models

**Files:**
- Create: `app/Models/Player.php`, `Ban.php`, `Life.php`, `GameSession.php`, `AdmFile.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/ModelsTest.php`:

```php
<?php

use App\Models\Player;
use App\Models\Life;
use App\Models\GameSession;

it('exposes open life and open session helpers', function () {
    $player = Player::create(['gamertag' => 'Tag1', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    expect($player->openLife())->toBeNull();
    expect($player->openSession())->toBeNull();

    $life = Life::create(['player_id' => $player->id, 'started_at' => now()]);
    $session = GameSession::create(['player_id' => $player->id, 'life_id' => $life->id, 'connected_at' => now()]);

    expect($player->fresh()->openLife()->id)->toBe($life->id);
    expect($player->fresh()->openSession()->id)->toBe($session->id);

    $life->update(['ended_at' => now()]);
    expect($player->fresh()->openLife())->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/ModelsTest.php`
Expected: FAIL ("Class App\\Models\\Player not found" or method missing).

- [ ] **Step 3: Implement the models**

`app/Models/Player.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $guarded = [];
    protected $casts = [
        'link_rewarded' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function lives() { return $this->hasMany(Life::class); }
    public function sessions() { return $this->hasMany(GameSession::class); }
    public function bans() { return $this->hasMany(Ban::class); }
    public function referrer() { return $this->belongsTo(Player::class, 'referrer_id'); }
    public function referrals() { return $this->hasMany(Player::class, 'referrer_id'); }

    public function openLife(): ?Life
    {
        return $this->lives()->whereNull('ended_at')->latest('started_at')->first();
    }

    public function openSession(): ?GameSession
    {
        return $this->sessions()->whereNull('disconnected_at')->latest('connected_at')->first();
    }
}
```

`app/Models/Life.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Life extends Model
{
    protected $guarded = [];
    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'playtime_seconds' => 'integer',
    ];

    public function player() { return $this->belongsTo(Player::class); }
    public function sessions() { return $this->hasMany(GameSession::class); }
}
```

`app/Models/GameSession.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameSession extends Model
{
    protected $guarded = [];
    protected $casts = [
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'duration_seconds' => 'integer',
    ];

    public function player() { return $this->belongsTo(Player::class); }
    public function life() { return $this->belongsTo(Life::class); }
}
```

`app/Models/Ban.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ban extends Model
{
    protected $guarded = [];
    protected $casts = [
        'banned_at' => 'datetime',
        'expires_at' => 'datetime',
        'expired' => 'boolean',
    ];

    public function player() { return $this->belongsTo(Player::class); }
}
```

`app/Models/AdmFile.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdmFile extends Model
{
    protected $guarded = [];
    protected $casts = [
        'log_date' => 'datetime',
        'is_complete' => 'boolean',
        'last_processed_line' => 'integer',
        'last_known_size' => 'integer',
    ];
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Feature/ModelsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add Eloquent models with open-life/session helpers"
```

---

## Task 6: AdmParser — line parsing (connect / disconnect / death)

Ports koth-bot regexes; extends them to detect **all** deaths (not just PvP), per spec.

**Files:**
- Create: `app/Services/Adm/AdmParser.php`
- Test: `tests/Unit/AdmParserTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Adm\AdmParser;

beforeEach(fn () => $this->parser = new AdmParser());

it('parses a connect line', function () {
    $r = $this->parser->parseConnect('01:02:03 | Player "Alice" (id=ABC123=) is connected');
    expect($r)->toBe(['gamertag' => 'Alice', 'id' => 'ABC123=']);
});

it('parses a disconnect line', function () {
    $r = $this->parser->parseDisconnect('01:02:03 | Player "Bob" (id=XYZ=) has been disconnected');
    expect($r)->toBe(['gamertag' => 'Bob', 'id' => 'XYZ=']);
});

it('parses a PvP death with weapon and distance', function () {
    $line = '10:00:00 | Player "Victim" (DEAD) (id=V=) killed by Player "Killer" (id=K=) with M4A1 from 153.4 meters';
    $r = $this->parser->parseDeath($line);
    expect($r['victim'])->toBe('Victim');
    expect($r['cause'])->toBe('pvp');
    expect($r['killer'])->toBe('Killer');
    expect($r['weapon'])->toBe('M4A1');
    expect($r['distance'])->toBe(153.4);
});

it('parses environmental and self deaths', function () {
    expect($this->parser->parseDeath('10:00:00 | Player "A" (DEAD) (id=A=) bled out')['cause'])->toBe('bled_out');
    expect($this->parser->parseDeath('10:00:00 | Player "A" (DEAD) (id=A=) drowned')['cause'])->toBe('drowned');
    expect($this->parser->parseDeath('10:00:00 | Player "A" (DEAD) (id=A=) committed suicide')['cause'])->toBe('suicide');
    expect($this->parser->parseDeath('10:00:00 | Player "A" (DEAD) (id=A=) died.')['cause'])->toBe('died');
    expect($this->parser->parseDeath('10:00:00 | Player "A" (DEAD) (id=A=) killed by FallDamage')['cause'])->toBe('environment');
});

it('ignores non-fatal hit lines', function () {
    $line = '10:00:00 | Player "A" (id=A=)[HP: 50] hit by Player "B" (id=B=) into Torso';
    expect($this->parser->parseDeath($line))->toBeNull();
    expect($this->parser->parseConnect($line))->toBeNull();
});

it('ignores a fatal hit line so the death is only counted once', function () {
    $line = '10:00:00 | Player "A" (DEAD) (id=A=)[HP: 0] hit by Player "B" (id=B=) into Head';
    expect($this->parser->parseDeath($line))->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Unit/AdmParserTest.php`
Expected: FAIL ("Class App\\Services\\Adm\\AdmParser not found").

- [ ] **Step 3: Implement the parser methods**

`app/Services/Adm/AdmParser.php`:

```php
<?php

namespace App\Services\Adm;

class AdmParser
{
    private const CONNECT_RE = '/Player "([^"]+)"\s*\(id=([^\s)]+)[^)]*\) is connected/u';
    private const DISCONNECT_RE = '/Player "([^"]+)"\s*\(id=([^\s)]+)[^)]*\) has been disconnected/u';
    private const KILL_RE = '/Player "([^"]+)" \(DEAD\) \(id=([^\s)]+)[^)]*\) killed by Player "([^"]+)" \(id=([^\s)]+)[^)]*\)(.*)$/u';
    private const WEAPON_RE = '/with (.+?)(?: from ([\d.]+) meters)?\s*$/u';
    private const DEATH_RE = '/Player "([^"]+)" \(DEAD\) \(id=([^\s)]+)[^)]*\)(.*)$/u';

    public function parseConnect(string $raw): ?array
    {
        if (!preg_match(self::CONNECT_RE, $raw, $m)) return null;
        return ['gamertag' => $m[1], 'id' => $m[2]];
    }

    public function parseDisconnect(string $raw): ?array
    {
        if (!preg_match(self::DISCONNECT_RE, $raw, $m)) return null;
        return ['gamertag' => $m[1], 'id' => $m[2]];
    }

    /**
     * Detect any death. Returns victim/id/cause and (for PvP) killer/weapon/distance.
     * "hit by" lines are damage events, never the death record, so they are ignored.
     */
    public function parseDeath(string $raw): ?array
    {
        if (str_contains($raw, 'hit by')) return null;
        if (!preg_match(self::DEATH_RE, $raw, $m)) return null;

        $victim = $m[1];
        $id = $m[2];
        $tail = $m[3];

        if (preg_match(self::KILL_RE, $raw, $k)) {
            $weapon = null;
            $distance = null;
            if (preg_match(self::WEAPON_RE, $k[5], $w)) {
                $weapon = trim($w[1]);
                $distance = (isset($w[2]) && $w[2] !== '') ? (float) $w[2] : null;
            }
            return [
                'victim' => $k[1], 'id' => $k[2], 'cause' => 'pvp',
                'killer' => $k[3], 'weapon' => $weapon, 'distance' => $distance,
            ];
        }

        $t = strtolower($tail);
        $cause = match (true) {
            str_contains($t, 'bled out') => 'bled_out',
            str_contains($t, 'drowned') => 'drowned',
            str_contains($t, 'committed suicide') => 'suicide',
            str_contains($t, 'killed by') => 'environment',
            str_contains($t, 'died') => 'died',
            default => 'unknown',
        };

        return ['victim' => $victim, 'id' => $id, 'cause' => $cause, 'killer' => null, 'weapon' => null, 'distance' => null];
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Unit/AdmParserTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: parse ADM connect/disconnect/death lines"
```

---

## Task 7: AdmParser — UTC timestamp reconstruction

Ports koth-bot's `assignTimestamps` and `deriveClockOffsetMs`. Event lines carry only `HH:MM:SS`; the date comes from the `AdminLog started on` header, with a day bump on midnight crossings.

**Files:**
- Modify: `app/Services/Adm/AdmParser.php`
- Test: `tests/Unit/AdmParserTest.php` (append)

- [ ] **Step 1: Write the failing test (append to AdmParserTest.php)**

```php
it('detects a server boot header timestamp', function () {
    $r = $this->parser->parseBoot('AdminLog started on 2026-06-11 at 14:30:00');
    expect($r)->toBe('2026-06-11 14:30:00');
});

it('assigns timestamps from the header and bumps a day at midnight', function () {
    $lines = [
        'AdminLog started on 2026-06-11 at 23:59:00',
        '23:59:30 | Player "A" (id=A=) is connected',
        '00:00:30 | Player "A" (id=A=) has been disconnected',
    ];
    $fallback = new DateTimeImmutable('2026-06-11T00:00:00Z');
    $ts = $this->parser->assignTimestamps($lines, $fallback);

    expect($ts[0])->toBeNull();                       // header line is not an event
    expect($ts[1])->toBe(strtotime('2026-06-11T23:59:30Z') * 1000);
    expect($ts[2])->toBe(strtotime('2026-06-12T00:00:30Z') * 1000); // bumped a day
});

it('derives the clock offset as the minimum modified_at minus filename time, snapped to 15 min', function () {
    $files = [
        ['timestamp' => new DateTimeImmutable('2026-06-11T10:00:00Z'), 'modifiedAt' => strtotime('2026-06-11T15:00:05Z')],
        ['timestamp' => new DateTimeImmutable('2026-06-11T11:00:00Z'), 'modifiedAt' => strtotime('2026-06-11T16:20:00Z')],
    ];
    // min candidate ~5h -> snaps to 5h = 18000000 ms
    expect($this->parser->deriveClockOffsetMs($files))->toBe(18000000);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Unit/AdmParserTest.php`
Expected: FAIL ("Call to undefined method ... parseBoot").

- [ ] **Step 3: Implement the timestamp methods (add to AdmParser)**

```php
    private const HEADER_RE = '/AdminLog started on (\d{4})-(\d{2})-(\d{2}) at (\d{2}):(\d{2}):(\d{2})/';
    private const TIME_RE = '/^(\d{2}):(\d{2}):(\d{2})/';

    private const DAY_MS = 86400000;
    private const ROLLOVER_THRESHOLD_SEC = 43200; // 12h
    private const FIFTEEN_MIN_MS = 900000;

    /** Returns 'Y-m-d H:i:s' for a boot header line, else null. */
    public function parseBoot(string $raw): ?string
    {
        if (!preg_match(self::HEADER_RE, $raw, $m)) return null;
        return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
    }

    /**
     * Per-line epoch-ms timestamps. Null for header/blank/non-event lines.
     * @param string[] $lines
     */
    public function assignTimestamps(array $lines, \DateTimeImmutable $fallbackDate): array
    {
        $out = array_fill(0, count($lines), null);
        $dayStart = null; // epoch ms at UTC midnight of the current log date
        $lastSec = -1;

        foreach ($lines as $i => $raw) {
            if ($raw === '' || $raw === null) continue;

            if (preg_match(self::HEADER_RE, $raw, $h)) {
                $dayStart = gmmktime(0, 0, 0, (int) $h[2], (int) $h[3], (int) $h[1]) * 1000;
                $lastSec = (int) $h[4] * 3600 + (int) $h[5] * 60 + (int) $h[6];
                continue;
            }

            if (!preg_match(self::TIME_RE, $raw, $t)) continue;
            $sec = (int) $t[1] * 3600 + (int) $t[2] * 60 + (int) $t[3];

            if ($dayStart === null) {
                $dayStart = gmmktime(0, 0, 0,
                    (int) $fallbackDate->format('n'),
                    (int) $fallbackDate->format('j'),
                    (int) $fallbackDate->format('Y')) * 1000;
            } elseif ($lastSec - $sec > self::ROLLOVER_THRESHOLD_SEC) {
                $dayStart += self::DAY_MS;
            }
            $lastSec = $sec;
            $out[$i] = $dayStart + $sec * 1000;
        }

        return $out;
    }

    /**
     * Server-local log clock -> UTC offset in ms (add to a log ts to get UTC).
     * @param array<int,array{timestamp:\DateTimeImmutable,modifiedAt:?int}> $files
     */
    public function deriveClockOffsetMs(array $files): int
    {
        $best = null;
        foreach ($files as $f) {
            if (!($f['timestamp'] instanceof \DateTimeImmutable) || !is_int($f['modifiedAt'] ?? null)) continue;
            $candidate = $f['modifiedAt'] * 1000 - (int) ($f['timestamp']->getTimestamp() * 1000);
            if ($best === null || $candidate < $best) $best = $candidate;
        }
        if ($best === null) return 0;
        return (int) (round($best / self::FIFTEEN_MIN_MS) * self::FIFTEEN_MIN_MS);
    }
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Unit/AdmParserTest.php`
Expected: PASS (all AdmParser tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: reconstruct UTC timestamps from ADM headers and clock offset"
```

---

## Task 8: NitradoClient — list and download ADM files

Ports koth-bot's `nitrado.js` list/download logic using Laravel's Http client. Tested with `Http::fake()` — no live calls.

**Files:**
- Create: `app/Services/Nitrado/NitradoClient.php`
- Test: `tests/Unit/NitradoClientTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Nitrado\NitradoClient;
use Illuminate\Support\Facades\Http;

it('lists ADM files sorted oldest-first with parsed timestamps', function () {
    Http::fake([
        '*/gameservers' => Http::response(['status' => 'success', 'data' => ['gameserver' => [
            'game_specific' => ['path' => '/games/abc/ftproot/dayzxb/', 'log_files' => []],
        ]]]),
        '*/file_server/list*' => Http::response(['status' => 'success', 'data' => ['entries' => [
            ['name' => 'DayZServer_X1_x64_2026-06-10_01-00-00.ADM', 'path' => '/p/b.ADM', 'modified_at' => 1000],
            ['name' => 'DayZServer_X1_x64_2026-06-09_01-00-00.ADM', 'path' => '/p/a.ADM', 'modified_at' => 900],
            ['name' => 'ignore.txt', 'path' => '/p/ignore.txt'],
        ]]]),
    ]);

    $client = new NitradoClient('token', 123);
    $files = $client->listAdmFiles();

    expect($files)->toHaveCount(2);
    expect($files[0]['name'])->toBe('DayZServer_X1_x64_2026-06-09_01-00-00.ADM'); // oldest first
    expect($files[0]['timestamp'])->toBeInstanceOf(DateTimeImmutable::class);
    expect($files[0]['modifiedAt'])->toBe(900);
});

it('downloads a file by following the token url', function () {
    Http::fake([
        '*/file_server/download*' => Http::response(['status' => 'success', 'data' => ['token' => ['url' => 'https://dl.example/file']]]),
        'https://dl.example/file' => Http::response("line1\nline2"),
    ]);

    $client = new NitradoClient('token', 123);
    expect($client->downloadFile('/p/a.ADM'))->toBe("line1\nline2");
});
```

Place this test in `tests/Unit/` but note it needs the Http facade; add `uses(Tests\TestCase::class)->in('Unit');` to `tests/Pest.php` so Unit tests can boot the facade. (Append that line in this task.)

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Unit/NitradoClientTest.php`
Expected: FAIL ("Class App\\Services\\Nitrado\\NitradoClient not found").

- [ ] **Step 3: Implement the client**

`app/Services/Nitrado/NitradoClient.php`:

```php
<?php

namespace App\Services\Nitrado;

use Illuminate\Support\Facades\Http;

class NitradoClient
{
    private const API_BASE = 'https://api.nitrado.net';
    private const FILENAME_RE = '/DayZServer_X1_x64_(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})\.ADM$/';

    public function __construct(private string $token, private int $serviceId) {}

    private function get(string $path): array
    {
        $res = Http::withToken($this->token)->acceptJson()->timeout(20)
            ->get(self::API_BASE.$path);
        $json = $res->json();
        if (!$res->ok() || ($json['status'] ?? null) !== 'success') {
            throw new \RuntimeException("Nitrado API error {$res->status()}");
        }
        return $json['data'] ?? [];
    }

    private function parseFilenameTs(string $name): ?\DateTimeImmutable
    {
        if (!preg_match(self::FILENAME_RE, $name, $m)) return null;
        return new \DateTimeImmutable(str_replace(' ', 'T', "{$m[1]} ".str_replace('-', ':', $m[2]).'Z'));
    }

    /** @return array<int,array{name:string,path:string,timestamp:?\DateTimeImmutable,modifiedAt:?int}> */
    public function listAdmFiles(): array
    {
        $gs = $this->get("/services/{$this->serviceId}/gameservers")['gameserver'] ?? null;
        $base = $gs['game_specific']['path'] ?? null;
        if (!$base) return [];

        $data = $this->get("/services/{$this->serviceId}/gameservers/file_server/list?dir=".urlencode($base.'config'));
        $entries = $data['entries'] ?? [];

        $files = [];
        foreach ($entries as $e) {
            $name = $e['name'] ?? '';
            $path = $e['path'] ?? '';
            if (!str_ends_with($name, '.ADM') || $path === '') continue;
            $ts = $this->parseFilenameTs($name);
            if (!$ts) continue;
            $files[$path] = [
                'name' => $name,
                'path' => $path,
                'timestamp' => $ts,
                'modifiedAt' => isset($e['modified_at']) && is_int($e['modified_at']) ? $e['modified_at'] : null,
            ];
        }

        $files = array_values($files);
        usort($files, fn ($a, $b) => $a['timestamp'] <=> $b['timestamp']); // oldest first
        return $files;
    }

    public function downloadFile(string $filePath): string
    {
        $data = $this->get("/services/{$this->serviceId}/gameservers/file_server/download?file=".urlencode($filePath));
        $url = $data['token']['url'] ?? null;
        if (!$url) throw new \RuntimeException("Missing download url for {$filePath}");
        $res = Http::timeout(30)->get($url);
        if (!$res->ok()) throw new \RuntimeException("Download failed {$res->status()} for {$filePath}");
        return $res->body();
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Unit/NitradoClientTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add Nitrado client for listing and downloading ADM files"
```

---

## Task 9: LifeTracker — connect and disconnect

The per-player state machine over Eloquent models. Connect opens a life (if none) and a session; disconnect closes the session and accrues playtime. Timestamps are passed as `DateTimeImmutable` (UTC).

**Files:**
- Create: `app/Services/Life/LifeTracker.php`
- Test: `tests/Feature/LifeTrackerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Player;
use App\Services\Life\LifeTracker;

function at(string $iso): DateTimeImmutable { return new DateTimeImmutable($iso); }

beforeEach(fn () => $this->tracker = new LifeTracker());

it('opens a life and session on first connect', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));

    $player = Player::where('gamertag', 'Alice')->first();
    expect($player)->not->toBeNull();
    expect($player->first_seen_at->toIso8601String())->toBe(at('2026-06-11T10:00:00Z')->format('c'));
    expect($player->openLife())->not->toBeNull();
    expect($player->openSession())->not->toBeNull();
});

it('closes the session and accrues playtime on disconnect, keeping the life open', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->disconnect('Alice', at('2026-06-11T10:30:00Z'));

    $player = Player::where('gamertag', 'Alice')->first();
    expect($player->openSession())->toBeNull();
    expect($player->openLife())->not->toBeNull();        // disconnect does NOT end the life
    expect($player->openLife()->playtime_seconds)->toBe(1800);
});

it('accumulates playtime across multiple sessions in one life', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->disconnect('Alice', at('2026-06-11T10:30:00Z'));
    $this->tracker->connect('Alice', at('2026-06-11T11:00:00Z'));   // reuses the open life
    $this->tracker->disconnect('Alice', at('2026-06-11T11:15:00Z'));

    $player = Player::where('gamertag', 'Alice')->first();
    expect($player->lives()->count())->toBe(1);
    expect($player->openLife()->playtime_seconds)->toBe(1800 + 900);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LifeTrackerTest.php`
Expected: FAIL ("Class App\\Services\\Life\\LifeTracker not found").

- [ ] **Step 3: Implement connect/disconnect**

`app/Services/Life/LifeTracker.php`:

```php
<?php

namespace App\Services\Life;

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;

class LifeTracker
{
    public function connect(string $gamertag, \DateTimeImmutable $ts): void
    {
        $player = Player::firstOrCreate(
            ['gamertag' => $gamertag],
            ['first_seen_at' => $ts, 'last_seen_at' => $ts]
        );
        $this->touch($player, $ts);

        if ($open = $player->openSession()) {
            $this->closeSession($open, $ts, 'superseded');
        }

        $life = $player->openLife() ?? Life::create([
            'player_id' => $player->id,
            'started_at' => $ts,
        ]);

        GameSession::create([
            'player_id' => $player->id,
            'life_id' => $life->id,
            'connected_at' => $ts,
        ]);
    }

    public function disconnect(string $gamertag, \DateTimeImmutable $ts): void
    {
        $player = Player::where('gamertag', $gamertag)->first();
        if (!$player) return;
        $this->touch($player, $ts);

        if ($open = $player->openSession()) {
            $this->closeSession($open, $ts, 'clean');
        }
    }

    protected function closeSession(GameSession $session, \DateTimeImmutable $ts, string $reason): void
    {
        $duration = max(0, $ts->getTimestamp() - $session->connected_at->getTimestamp());
        $session->update([
            'disconnected_at' => $ts,
            'duration_seconds' => $duration,
            'close_reason' => $reason,
        ]);
        Life::where('id', $session->life_id)->increment('playtime_seconds', $duration);
    }

    protected function touch(Player $player, \DateTimeImmutable $ts): void
    {
        $data = ['last_seen_at' => $ts];
        if ($player->first_seen_at === null) $data['first_seen_at'] = $ts;
        $player->forceFill($data)->save();
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LifeTrackerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: life tracker connect/disconnect with playtime accrual"
```

---

## Task 10: LifeTracker — death ends a life

A death closes the open session at the death time and ends the life with cause/killer. (No banning in Plan 1.) A death with no open life still records a zero-duration closed life so the death is captured.

**Files:**
- Modify: `app/Services/Life/LifeTracker.php`
- Test: `tests/Feature/LifeTrackerTest.php` (append)

- [ ] **Step 1: Write the failing test (append)**

```php
it('ends the life on death and records cause and killer', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->death([
        'victim' => 'Alice', 'cause' => 'pvp', 'killer' => 'Bob',
    ], at('2026-06-11T10:20:00Z'));

    $player = App\Models\Player::where('gamertag', 'Alice')->first();
    expect($player->openLife())->toBeNull();             // life ended
    expect($player->openSession())->toBeNull();          // session closed
    $life = $player->lives()->latest('started_at')->first();
    expect($life->death_cause)->toBe('pvp');
    expect($life->death_by_gamertag)->toBe('Bob');
    expect($life->playtime_seconds)->toBe(1200);
    expect($life->ended_at->getTimestamp())->toBe(at('2026-06-11T10:20:00Z')->getTimestamp());
});

it('opens a new life on the next connect after a death', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->death(['victim' => 'Alice', 'cause' => 'died', 'killer' => null], at('2026-06-11T10:20:00Z'));
    $this->tracker->connect('Alice', at('2026-06-12T09:00:00Z'));

    $player = App\Models\Player::where('gamertag', 'Alice')->first();
    expect($player->lives()->count())->toBe(2);
    expect($player->openLife())->not->toBeNull();
});

it('records a death with no open life as a closed zero-duration life', function () {
    $this->tracker->death(['victim' => 'Ghost', 'cause' => 'drowned', 'killer' => null], at('2026-06-11T10:00:00Z'));
    $player = App\Models\Player::where('gamertag', 'Ghost')->first();
    expect($player->lives()->count())->toBe(1);
    expect($player->openLife())->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LifeTrackerTest.php`
Expected: FAIL ("Call to undefined method ... death").

- [ ] **Step 3: Implement death (add to LifeTracker)**

```php
    /**
     * @param array{victim:string,cause:string,killer:?string} $death
     */
    public function death(array $death, \DateTimeImmutable $ts): void
    {
        $player = Player::firstOrCreate(
            ['gamertag' => $death['victim']],
            ['first_seen_at' => $ts, 'last_seen_at' => $ts]
        );
        $this->touch($player, $ts);

        if ($open = $player->openSession()) {
            $this->closeSession($open, $ts, 'clean');
        }

        $life = $player->openLife() ?? Life::create([
            'player_id' => $player->id,
            'started_at' => $ts,
        ]);

        $life->update([
            'ended_at' => $ts,
            'death_cause' => $death['cause'],
            'death_by_gamertag' => $death['killer'],
        ]);
    }
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LifeTrackerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: life tracker death ends life with cause and killer"
```

---

## Task 11: LifeTracker — server reboot closes sessions, keeps lives open

A reboot drops everyone without a disconnect log. Close all open sessions at the boot time (`reboot`), but lives stay open — a restart doesn't kill anyone.

**Files:**
- Modify: `app/Services/Life/LifeTracker.php`
- Test: `tests/Feature/LifeTrackerTest.php` (append)

- [ ] **Step 1: Write the failing test (append)**

```php
it('closes all open sessions on reboot but keeps lives open', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->connect('Bob', at('2026-06-11T10:05:00Z'));
    $this->tracker->reboot(at('2026-06-11T10:30:00Z'));

    $alice = App\Models\Player::where('gamertag', 'Alice')->first();
    $bob = App\Models\Player::where('gamertag', 'Bob')->first();

    expect($alice->openSession())->toBeNull();
    expect($bob->openSession())->toBeNull();
    expect($alice->openLife())->not->toBeNull();         // still alive
    expect($alice->openLife()->playtime_seconds)->toBe(1800);
    expect($bob->openLife()->playtime_seconds)->toBe(1500);
});

it('continues the same life when a player reconnects after a reboot', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->reboot(at('2026-06-11T10:30:00Z'));
    $this->tracker->connect('Alice', at('2026-06-11T10:45:00Z'));

    $alice = App\Models\Player::where('gamertag', 'Alice')->first();
    expect($alice->lives()->count())->toBe(1);           // same life
    expect($alice->openSession())->not->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LifeTrackerTest.php`
Expected: FAIL ("Call to undefined method ... reboot").

- [ ] **Step 3: Implement reboot (add to LifeTracker)**

```php
    public function reboot(\DateTimeImmutable $ts): void
    {
        GameSession::whereNull('disconnected_at')->get()->each(
            fn (GameSession $s) => $this->closeSession($s, $ts, 'reboot')
        );
    }
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LifeTrackerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: life tracker reboot closes sessions, preserves lives"
```

---

## Task 12: BotState — typed key/value accessor

Backs ingestion mode, `go_live_at`, and the high-water mark.

**Files:**
- Create: `app/Services/State/BotState.php`
- Test: `tests/Feature/BotStateTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\State\BotState;

it('reads a default and round-trips a value', function () {
    $state = new BotState();
    expect($state->get('mode', 'backfill'))->toBe('backfill');

    $state->set('mode', 'live');
    expect($state->get('mode'))->toBe('live');
});

it('stores and reads integers', function () {
    $state = new BotState();
    $state->setInt('high_water', 1717999999000);
    expect($state->getInt('high_water'))->toBe(1717999999000);
    expect($state->getInt('missing', 7))->toBe(7);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BotStateTest.php`
Expected: FAIL ("Class App\\Services\\State\\BotState not found").

- [ ] **Step 3: Implement BotState**

`app/Services/State/BotState.php`:

```php
<?php

namespace App\Services\State;

use Illuminate\Support\Facades\DB;

class BotState
{
    public function get(string $key, ?string $default = null): ?string
    {
        $row = DB::table('bot_state')->where('key', $key)->first();
        return $row->value ?? $default;
    }

    public function set(string $key, string $value): void
    {
        DB::table('bot_state')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function getInt(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        return $v === null ? $default : (int) $v;
    }

    public function setInt(string $key, int $value): void
    {
        $this->set($key, (string) $value);
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Feature/BotStateTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add BotState key/value accessor"
```

---

## Task 13: AdmIngestor — process one file's lines through the state machine

Given a file's content and cursor, parse each new line in order and dispatch to `LifeTracker`. Returns the new cursor (total line count). Header lines trigger `reboot`. This task wires parser → tracker for a single file; backfill orchestration comes next.

**Files:**
- Create: `app/Services/Adm/AdmIngestor.php`
- Test: `tests/Feature/AdmIngestorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Player;
use App\Services\Adm\AdmIngestor;
use App\Services\Adm\AdmParser;
use App\Services\Life\LifeTracker;

it('applies events from a file in chronological order from the cursor', function () {
    $content = implode("\n", [
        'AdminLog started on 2026-06-11 at 09:00:00',
        '10:00:00 | Player "Alice" (id=A=) is connected',
        '10:20:00 | Player "Alice" (DEAD) (id=A=) killed by Player "Bob" (id=B=) with Knife',
        '10:25:00 | Player "Alice" (id=A=) has been disconnected',
    ]);

    $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
    $fallback = new DateTimeImmutable('2026-06-11T00:00:00Z');

    // offsetMs = 0; cursor starts at 0; process all lines
    $newCursor = $ingestor->processFile($content, 0, $fallback, 0);
    expect($newCursor)->toBe(4);

    $alice = Player::where('gamertag', 'Alice')->first();
    $life = $alice->lives()->latest('started_at')->first();
    expect($life->death_cause)->toBe('pvp');
    expect($life->playtime_seconds)->toBe(1200); // 10:00 -> 10:20 death
});

it('does not reprocess lines before the cursor', function () {
    $content = implode("\n", [
        '10:00:00 | Player "Alice" (id=A=) is connected',
        '10:20:00 | Player "Alice" (id=A=) has been disconnected',
    ]);
    $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
    $fallback = new DateTimeImmutable('2026-06-11T00:00:00Z');

    // cursor=1 -> skip the connect, only the disconnect line is "new"
    $ingestor->processFile($content, 1, $fallback, 0);
    $alice = Player::where('gamertag', 'Alice')->first();
    // no connect applied -> no open session existed -> disconnect is a no-op; no life created
    expect($alice?->lives()->count() ?? 0)->toBe(0);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/AdmIngestorTest.php`
Expected: FAIL ("Class App\\Services\\Adm\\AdmIngestor not found").

- [ ] **Step 3: Implement processFile**

`app/Services/Adm/AdmIngestor.php`:

```php
<?php

namespace App\Services\Adm;

use App\Services\Life\LifeTracker;

class AdmIngestor
{
    public function __construct(
        private AdmParser $parser,
        private LifeTracker $tracker,
    ) {}

    /**
     * Apply events from a file's content, starting at $cursor (0-based line index).
     * $offsetMs converts server-local log time to UTC. Returns the new cursor (line count).
     */
    public function processFile(string $content, int $cursor, \DateTimeImmutable $fallbackDate, int $offsetMs): int
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $total = count($lines);
        if ($cursor < 0 || $cursor > $total) $cursor = 0;

        // Rebuild timestamp context from the whole file (cheap; files are KB).
        $tsByLine = $this->parser->assignTimestamps($lines, $fallbackDate);

        for ($i = 0; $i < $total; $i++) {
            if ($i < $cursor) continue;
            $raw = $lines[$i];
            if ($raw === '' || $raw === null) continue;

            // Boot header: a reboot. Use the header's own time (offset-adjusted).
            if (($boot = $this->parser->parseBoot($raw)) !== null) {
                $this->tracker->reboot($this->utc($boot, $offsetMs));
                continue;
            }

            $localTs = $tsByLine[$i];
            if ($localTs === null) continue;
            $ts = $this->fromMs($localTs + $offsetMs);

            if ($c = $this->parser->parseConnect($raw)) { $this->tracker->connect($c['gamertag'], $ts); continue; }
            if ($d = $this->parser->parseDisconnect($raw)) { $this->tracker->disconnect($d['gamertag'], $ts); continue; }
            if ($k = $this->parser->parseDeath($raw)) { $this->tracker->death($k, $ts); continue; }
        }

        return $total;
    }

    private function fromMs(int $ms): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.intdiv($ms, 1000)))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function utc(string $localDateTime, int $offsetMs): \DateTimeImmutable
    {
        $base = new \DateTimeImmutable($localDateTime, new \DateTimeZone('UTC'));
        return $this->fromMs($base->getTimestamp() * 1000 + $offsetMs);
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Feature/AdmIngestorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: ingest a single ADM file through the life-tracking state machine"
```

---

## Task 14: AdmIngestor — backfill orchestration across files with cursors

Drive ingestion across all files oldest→newest, persist per-file cursors in `adm_files`, derive the clock offset once per tick, and bound how many old files are downloaded per tick. Set/advance the high-water mark and flip BACKFILL→LIVE when caught up. (No banning — Plan 2 adds it.)

**Files:**
- Modify: `app/Services/Adm/AdmIngestor.php`
- Test: `tests/Feature/AdmIngestorTest.php` (append)

- [ ] **Step 1: Write the failing test (append)**

```php
use App\Models\AdmFile;
use App\Services\Nitrado\NitradoClient;
use App\Services\State\BotState;
use Illuminate\Support\Facades\Http;

it('backfills all files oldest-first and flips to live when caught up', function () {
    Http::fake([
        '*/gameservers' => Http::response(['status' => 'success', 'data' => ['gameserver' => [
            'game_specific' => ['path' => '/base/', 'log_files' => []],
        ]]]),
        '*/file_server/list*' => Http::response(['status' => 'success', 'data' => ['entries' => [
            ['name' => 'DayZServer_X1_x64_2026-06-10_00-00-00.ADM', 'path' => '/base/new.ADM', 'modified_at' => 1749513600],
            ['name' => 'DayZServer_X1_x64_2026-06-09_00-00-00.ADM', 'path' => '/base/old.ADM', 'modified_at' => 1749427200],
        ]]]),
        '*file=*old.ADM*' => Http::response(['status' => 'success', 'data' => ['token' => ['url' => 'https://dl/old']]]),
        '*file=*new.ADM*' => Http::response(['status' => 'success', 'data' => ['token' => ['url' => 'https://dl/new']]]),
        'https://dl/old' => Http::response("00:00:00 | Player \"Alice\" (id=A=) is connected\n00:30:00 | Player \"Alice\" (id=A=) has been disconnected"),
        'https://dl/new' => Http::response("01:00:00 | Player \"Alice\" (id=A=) is connected\n01:10:00 | Player \"Alice\" (id=A=) has been disconnected"),
    ]);

    $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
    $client = new NitradoClient('t', 1);
    $state = new BotState();

    // budget large enough to drain both files in one tick
    $ingestor->tick($client, $state, backfillBudget: 10);

    expect(AdmFile::count())->toBe(2);
    expect(AdmFile::where('path', '/base/old.ADM')->first()->is_complete)->toBeTrue();
    expect($state->get('mode'))->toBe('live');     // caught up
    expect($state->get('go_live_at'))->not->toBeNull();

    $alice = Player::where('gamertag', 'Alice')->first();
    expect($alice->lives()->first()->playtime_seconds)->toBe(1800 + 600);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/AdmIngestorTest.php`
Expected: FAIL ("Call to undefined method ... tick").

- [ ] **Step 3: Implement tick (add to AdmIngestor)**

Add the imports and method:

```php
use App\Models\AdmFile;
use App\Services\Nitrado\NitradoClient;
use App\Services\State\BotState;
```

```php
    /**
     * One ingestion tick. Processes the newest file every tick; drains up to
     * $backfillBudget older incomplete files (oldest-first). Flips BACKFILL->LIVE
     * once every file is complete or cursor-current.
     */
    public function tick(NitradoClient $client, BotState $state, int $backfillBudget = 15): void
    {
        $files = $client->listAdmFiles(); // oldest-first
        if (empty($files)) return;

        $offsetMs = $this->parser->deriveClockOffsetMs($files);
        $newestPath = $files[count($files) - 1]['path'];
        $budget = $backfillBudget;
        $allCaughtUp = true;

        foreach ($files as $file) {
            $row = AdmFile::where('path', $file['path'])->first();
            $isNewest = $file['path'] === $newestPath;

            if ($row?->is_complete && !$isNewest) continue;
            if (!$isNewest) {
                if ($budget <= 0) { $allCaughtUp = false; continue; }
                $budget--;
            }

            try {
                $content = $client->downloadFile($file['path']);
            } catch (\Throwable $e) {
                $allCaughtUp = false;
                continue;
            }

            $cursor = $row?->last_processed_line ?? 0;
            $fallback = $file['timestamp'] ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $newCursor = $this->processFile($content, $cursor, $fallback, $offsetMs);

            AdmFile::updateOrCreate(
                ['path' => $file['path']],
                [
                    'name' => $file['name'],
                    'log_date' => $file['timestamp'],
                    'last_processed_line' => $newCursor,
                    'is_complete' => !$isNewest,
                ]
            );
        }

        if ($allCaughtUp && $state->get('mode', 'backfill') !== 'live') {
            $state->set('mode', 'live');
            $state->set('go_live_at', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'));
        }
    }
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Feature/AdmIngestorTest.php`
Expected: PASS.

- [ ] **Step 5: Run the whole suite**

Run: `./vendor/bin/pest`
Expected: all tests PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: backfill orchestration across ADM files with cursors and mode flip"
```

---

## Task 15: IngestAdmTask — run ingestion on a timer

Wires the ingestor into the Laracord ReactPHP loop. Reads config from `.env`.

**Files:**
- Create: `app/Tasks/IngestAdmTask.php`

- [ ] **Step 1: Generate the task skeleton**

Run: `php laracord make:task IngestAdmTask` (if the generator exists; otherwise create the file manually using the structure below).

- [ ] **Step 2: Implement the task**

`app/Tasks/IngestAdmTask.php`:

```php
<?php

namespace App\Tasks;

use App\Services\Adm\AdmIngestor;
use App\Services\Adm\AdmParser;
use App\Services\Life\LifeTracker;
use App\Services\Nitrado\NitradoClient;
use App\Services\State\BotState;
use Laracord\Tasks\Task;

class IngestAdmTask extends Task
{
    protected int $interval = 60;
    protected bool $eager = true;

    public function handle(): void
    {
        $token = env('NITRADO_TOKEN');
        $serviceId = (int) env('NITRADO_SERVICE_ID');
        if (!$token || !$serviceId) {
            $this->console()->error('[ingest] NITRADO_TOKEN / NITRADO_SERVICE_ID not configured.');
            return;
        }

        try {
            $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
            $client = new NitradoClient($token, $serviceId);
            $ingestor->tick($client, new BotState(), (int) env('ADM_BACKFILL_BUDGET', 15));
        } catch (\Throwable $e) {
            $this->console()->error('[ingest] tick failed: '.$e->getMessage());
        }
    }
}
```

- [ ] **Step 3: Confirm the task is registered and the bot boots**

Run: `php laracord` (let it boot, then Ctrl-C)
Expected: bot connects; logs show the ingest task firing (or a clear config-missing message if `.env` is empty). No fatal errors.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: schedule ADM ingestion task on a 60s timer"
```

---

## Task 16: VerifyIngestionCommand — the verification milestone report

A one-shot console command that runs ingestion ticks until backfill completes (bounded), then prints a human-readable report so we can eyeball life/playtime/death correctness against the real ADM set.

**Files:**
- Create: `app/Console/Commands/VerifyIngestionCommand.php`

- [ ] **Step 1: Implement the command**

`app/Console/Commands/VerifyIngestionCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Adm\AdmIngestor;
use App\Services\Adm\AdmParser;
use App\Services\Life\LifeTracker;
use App\Services\Nitrado\NitradoClient;
use App\Services\State\BotState;
use Illuminate\Console\Command;

class VerifyIngestionCommand extends Command
{
    protected $signature = 'adm:verify {--ticks=200 : Max ingestion ticks to run} {--budget=50 : Files to drain per tick}';
    protected $description = 'Run a full ADM backfill (no banning) and print a life/playtime/death report.';

    public function handle(): int
    {
        $token = env('NITRADO_TOKEN');
        $serviceId = (int) env('NITRADO_SERVICE_ID');
        if (!$token || !$serviceId) {
            $this->error('Set NITRADO_TOKEN and NITRADO_SERVICE_ID in .env first.');
            return self::FAILURE;
        }

        $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
        $client = new NitradoClient($token, $serviceId);
        $state = new BotState();

        $maxTicks = (int) $this->option('ticks');
        $budget = (int) $this->option('budget');

        $this->info('Backfilling...');
        for ($i = 0; $i < $maxTicks; $i++) {
            $ingestor->tick($client, $state, $budget);
            if ($state->get('mode') === 'live') {
                $this->info("Caught up after ".($i + 1)." tick(s).");
                break;
            }
        }

        $this->report();
        return self::SUCCESS;
    }

    private function report(): void
    {
        $players = Player::count();
        $lives = Life::count();
        $openLives = Life::whereNull('ended_at')->count();
        $sessions = GameSession::count();
        $playHours = round((int) Life::sum('playtime_seconds') / 3600, 1);

        $this->line('');
        $this->line("Players:        {$players}");
        $this->line("Lives:          {$lives} ({$openLives} still alive)");
        $this->line("Sessions:       {$sessions}");
        $this->line("Total playtime: {$playHours} h");
        $this->line('');

        $this->line('Deaths by cause:');
        Life::whereNotNull('death_cause')
            ->selectRaw('death_cause, count(*) as c')
            ->groupBy('death_cause')->orderByDesc('c')->get()
            ->each(fn ($r) => $this->line("  {$r->death_cause}: {$r->c}"));

        $this->line('');
        $this->line('Top 5 by total playtime:');
        Player::query()
            ->select('players.gamertag')
            ->selectRaw('(select sum(playtime_seconds) from lives where lives.player_id = players.id) as secs')
            ->orderByDesc('secs')->limit(5)->get()
            ->each(fn ($p) => $this->line("  {$p->gamertag}: ".round(((int) $p->secs) / 3600, 1)."h"));
    }
}
```

- [ ] **Step 2: Confirm the command is registered**

Run: `php laracord list | grep adm:verify`
Expected: `adm:verify` appears in the command list. (If Laracord auto-discovers `app/Console/Commands`, nothing else is needed; otherwise register it where the project registers console commands.)

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "feat: add adm:verify backfill report command"
```

---

## Task 17: Run the verification milestone against real data

**This is the gate.** Requires the user's real `NITRADO_TOKEN` and `NITRADO_SERVICE_ID`.

- [ ] **Step 1: Configure real credentials**

Set `NITRADO_TOKEN` and `NITRADO_SERVICE_ID` in `.env` (values provided by the user — never commit them).

- [ ] **Step 2: Fresh-migrate the database**

Run: `php laracord migrate:fresh`
Expected: clean schema.

- [ ] **Step 3: Run the verification report**

Run: `php laracord adm:verify --ticks=500 --budget=50`
Expected: it backfills the full ADM history and prints player/life/session/playtime/death counts plus the top-5 playtime list.

- [ ] **Step 4: Review with the user**

Present the report. Sanity-check together:
- Player count roughly matches the known community size.
- Deaths-by-cause distribution looks plausible (PvP vs environmental).
- Top players' playtime is believable (no absurd values from un-closed sessions).
- Spot-check a few known players' lives (`php laracord tinker` → `App\Models\Player::where('gamertag','<tag>')->first()->lives`).

If anomalies appear (e.g., over-counted playtime, mis-parsed lines, missed death formats), capture them as fixes — likely small adjustments to `AdmParser` death/format handling or `LifeTracker` edge cases — then re-run `migrate:fresh` + `adm:verify`.

- [ ] **Step 5: Tag the verified baseline**

Once the user confirms the numbers look right:

```bash
git tag plan1-verified
git commit --allow-empty -m "chore: life-tracking verified against real ADM data"
```

---

## Task 18: Wrap up Plan 1

- [ ] **Step 1: Update the README**

Replace `README.md` with run instructions: required `.env` vars, `php laracord migrate`, `php laracord adm:verify`, `php laracord` to run the bot. Note that banning is not yet implemented (Plan 2).

- [ ] **Step 2: Run the full suite once more**

Run: `./vendor/bin/pest`
Expected: all green.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "docs: Plan 1 run instructions"
```

---

## Self-review notes (coverage against spec Phases 1–4)

- **Scaffold + config + SQLite** → Tasks 1–3.
- **Data model** (players, adm_files, bans, lives, game_sessions, bot_state) → Tasks 4–5. (`bans` table is created now but unused until Plan 2.)
- **ADM parsing + UTC timestamp reconstruction** (ported from koth-bot, extended to all-death detection) → Tasks 6–7.
- **Nitrado list/download** → Task 8. (Ban-settings methods deferred to Plan 2.)
- **Life/playtime state machine** (connect/disconnect/death/reboot; reboot keeps life open; backfill-no-ban) → Tasks 9–11.
- **Backfill mode + go_live cutoff + high-water** → Tasks 12–14. (`go_live_at` is recorded; it gates banning, which arrives in Plan 2.)
- **In-process timer** → Task 15.
- **Verification milestone against real data** → Tasks 16–17.

**Out of scope here (Plan 2+):** death→ban, ban expiry + reconcile, Nitrado `general.bans` writes, linking, token economy, all slash commands. These build on the verified data model.

---

## Implementation notes (discovered during Plan 1 execution)

Facts about the installed **Laracord v2.3.0 (Laravel Zero)** stack, for Plans 2–3:

- **Periodic tasks are `Laracord\Services\Service`**, not `Laracord\Tasks\Task`. A service lives anywhere under `app/Services/` (auto-discovered by subclassing `Service`), declares `protected int $interval` (seconds) and `protected $enabled`, implements `handle()`, and has `$this->console()` / `$this->discord()`. The ingestion task shipped as `app/Services/IngestAdmService.php` (not `IngestAdmTask`). Our plain pipeline classes (`AdmParser`, `LifeTracker`, etc.) live in `app/Services/**` subdirectories and are ignored by discovery because they don't extend `Service`.
- **Console (artisan-style) commands** live in `app/Console/Commands/` (Laracord's vendored `commands.php` config points there), extend `Laracord\Console\Commands\Command`, and are auto-registered. This is separate from `app/Commands/` which holds **Discord** message commands (`Laracord\Commands\Command`). Plan 2/3 slash commands will use `app/SlashCommands/` extending `Laracord\Commands\SlashCommand`.
- **PHP 8.5 deprecation crash:** a vendored MySQL config triggers a `PDO::MYSQL_ATTR_SSL_CA` deprecation that crashes `migrate` via the deprecation logger. Fixed by adding `config/logging.php` routing the `deprecations` channel to `null`. Test output still shows harmless `DEPR` markers.
- **Test harness:** full-framework `Tests\TestCase` (boots `bootstrap/app.php`) + `RefreshDatabase` works; `pest-plugin-laravel` pulled in `laravel/framework` alongside `laravel-zero/foundation` (dual-framework classmap) — benign so far, first suspect if container/kernel issues appear under tests.
- **Backfill ordering fix:** `AdmIngestor::tick()` must NOT process the newest (live) file during backfill while older files are still pending — doing so feeds the stateful `LifeTracker` out of chronological order. The newest file is processed only once backfill is caught up (or in live mode). Covered by a regression test.
```
