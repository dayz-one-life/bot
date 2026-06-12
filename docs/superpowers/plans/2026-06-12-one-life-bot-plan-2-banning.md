# One Life Bot — Plan 2: Banning Layer

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a player dies (after go-live), ban their gamertag on the Nitrado server for 12 hours and automatically lift it when it expires — building on Plan 1's verified life/death tracking.

**Architecture:** Death→ban is an **idempotent reconciliation**, not an inline side-effect of the state machine: after each ingestion tick, a `DeathBanService` finds `lives` that ended after `go_live_at` and aren't yet banned (`ban_issued = false`), bans the player via `BanService`, and marks the life. `BanService` writes the `bans` table, applies the ban to Nitrado's `settings.general.bans`, and notifies Discord. A per-minute `BanExpiryService` lifts expired bans and reconciles the Nitrado ban list against active DB bans. A dry-run flag makes the live cutover safe.

**Tech Stack:** PHP 8.2+, Laracord v2.3.0 (Laravel Zero), Eloquent, Laravel Http, SQLite, Pest.

**Spec:** `docs/superpowers/specs/2026-06-11-one-life-bot-design.md` — Section "Ban lifecycle" (Section 4) + "Death → ban" + `go_live_at` gating. Builds on Plan 1 (`docs/superpowers/plans/2026-06-11-one-life-bot-plan-1-foundation.md`, branch merged to `main`, tag `plan1-verified`).

**Critical safety note:** This plan makes the bot ban real players on a live server. The `go_live_at` cutoff (set by Plan 1 when backfill caught up) ensures only deaths AFTER go-live are banned — historical deaths are never retro-banned. The dry-run flag (`BAN_DRY_RUN=true`) lets us observe what *would* be banned before enabling real bans.

**Stack facts (from Plan 1, do not re-discover):**
- Periodic work = `Laracord\Services\Service` (`protected int $interval` seconds, `handle()`, `$this->discord()` / `$this->console()`), auto-discovered under `app/Services/`. Plain pipeline classes in `app/Services/**` subdirs are ignored (don't extend `Service`).
- `NitradoClient` (`app/Services/Nitrado/NitradoClient.php`) has a private `get()` that wraps `{status:success,data}` and throws otherwise; `listAdmFiles()`/`downloadFile()` exist. Nitrado bans live at `settings.general.bans` = a `\r\n`-joined gamertag list.
- `BotState` (`get/set`), models `Player`/`Ban`/`Life`/`GameSession`/`AdmFile`, `AdmIngestor` (`processFile`, `tick(NitradoClient,BotState,backfillBudget)`), `LifeTracker`, `IngestAdmService` (60s).
- Tests: Pest; Feature uses `Tests\TestCase` + `RefreshDatabase` + `Http::fake()`; harmless `DEPR` markers, exit 0 = green. Run `./vendor/bin/pest`.

---

## File structure (created/modified by this plan)

```
app/
  Services/
    Nitrado/NitradoClient.php         # MODIFY: add getBans/addBan/removeBan + post()
    Ban/BanService.php                # NEW: ban()/unban() — DB + Nitrado + notifier
    Ban/BanNotifier.php               # NEW: interface
    Ban/NullBanNotifier.php           # NEW: no-op (backfill/tests)
    Ban/DiscordBanNotifier.php        # NEW: posts to bans channel + DMs linked player
    Ban/DeathBanService.php           # NEW: ended-life -> ban reconciliation
    BanExpiryService.php              # NEW: Laracord Service, 60s sweep + reconcile
    IngestAdmService.php              # MODIFY: run DeathBanService after each live tick
database/migrations/
  2026_06_12_000000_add_ban_issued_to_lives.php   # NEW
tests/
  Unit/NitradoClientBansTest.php      # NEW
  Feature/BanServiceTest.php          # NEW
  Feature/DeathBanServiceTest.php     # NEW
  Feature/BanExpiryServiceTest.php    # NEW
```

`.env` additions: `BANS_CHANNEL_ID` (already present from Plan 1), `BAN_DURATION_HOURS=12` (present), `BAN_DRY_RUN=false` (new).

---

## Task 1: Nitrado ban-settings methods

Adds reading/writing `settings.general.bans` to `NitradoClient`. Ported from the reference bot's `nitrado.js`.

**Files:** Modify `app/Services/Nitrado/NitradoClient.php`. Test: `tests/Unit/NitradoClientBansTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Unit/NitradoClientBansTest.php`:

```php
<?php

use App\Services\Nitrado\NitradoClient;
use Illuminate\Support\Facades\Http;

it('reads the ban list from general.bans', function () {
    Http::fake([
        '*/gameservers/settings' => Http::response(['status' => 'success', 'data' => [
            'settings' => ['general' => ['bans' => "Alice\r\nBob"]],
        ]]),
    ]);

    expect((new NitradoClient('t', 1))->getBans())->toBe(['Alice', 'Bob']);
});

it('adds a gamertag to the ban list idempotently', function () {
    Http::fake([
        '*/gameservers/settings' => function ($request) {
            // GET returns existing; POST echoes success
            if ($request->method() === 'POST') {
                return Http::response(['status' => 'success', 'data' => ['settings' => []]]);
            }
            return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => "Alice"]]]]);
        },
    ]);

    (new NitradoClient('t', 1))->addBan('Bob');

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && $r['category'] === 'general' && $r['key'] === 'bans'
        && $r['value'] === "Alice\r\nBob");
});

it('does not re-add a gamertag already banned', function () {
    Http::fake([
        '*/gameservers/settings' => Http::response(['status' => 'success', 'data' => [
            'settings' => ['general' => ['bans' => "Alice"]],
        ]]),
    ]);

    (new NitradoClient('t', 1))->addBan('Alice');
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('removes a gamertag from the ban list', function () {
    Http::fake([
        '*/gameservers/settings' => function ($request) {
            if ($request->method() === 'POST') return Http::response(['status' => 'success', 'data' => []]);
            return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => "Alice\r\nBob"]]]]);
        },
    ]);

    (new NitradoClient('t', 1))->removeBan('Alice');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['value'] === "Bob");
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/NitradoClientBansTest.php`
Expected: FAIL ("Call to undefined method ... getBans").

- [ ] **Step 3: Add the methods to `NitradoClient`**

Add a `post()` helper and the ban methods (place near the existing `get()`):

```php
    private function post(string $path, array $body): array
    {
        $res = Http::withToken($this->token)->acceptJson()->asJson()->timeout(20)
            ->post(self::API_BASE.$path, $body);
        $json = $res->json();
        if (!$res->ok() || ($json['status'] ?? null) !== 'success') {
            throw new \RuntimeException("Nitrado API error {$res->status()}");
        }
        return $json['data'] ?? [];
    }

    /** @return string[] current banned gamertags from settings.general.bans */
    public function getBans(): array
    {
        $data = $this->get("/services/{$this->serviceId}/gameservers/settings");
        $raw = $data['settings']['general']['bans'] ?? '';
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $raw)), fn ($s) => $s !== ''));
    }

    public function addBan(string $gamertag): void
    {
        $bans = $this->getBans();
        if (in_array($gamertag, $bans, true)) return; // idempotent
        $bans[] = $gamertag;
        $this->updateBans($bans);
    }

    public function removeBan(string $gamertag): void
    {
        $bans = array_values(array_filter($this->getBans(), fn ($b) => $b !== $gamertag));
        $this->updateBans($bans);
    }

    private function updateBans(array $bans): void
    {
        $this->post("/services/{$this->serviceId}/gameservers/settings", [
            'category' => 'general',
            'key' => 'bans',
            'value' => implode("\r\n", $bans),
        ]);
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/pest tests/Unit/NitradoClientBansTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: Nitrado ban-list read/add/remove via general.bans setting"
```

---

## Task 2: `lives.ban_issued` migration

Adds the idempotency flag for death→ban reconciliation.

**Files:** Create `database/migrations/2026_06_12_000000_add_ban_issued_to_lives.php`.

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
            $t->boolean('ban_issued')->default(false)->after('death_by_gamertag');
            $t->index(['ended_at', 'ban_issued']);
        });
    }

    public function down(): void
    {
        Schema::table('lives', function (Blueprint $t) {
            $t->dropIndex(['ended_at', 'ban_issued']);
            $t->dropColumn('ban_issued');
        });
    }
};
```

- [ ] **Step 2: Migrate and add the cast**

Run: `php laracord migrate`
Then add `'ban_issued' => 'boolean'` to the `$casts` array in `app/Models/Life.php`.

- [ ] **Step 3: Confirm in tests**

`tests/Feature/MigrationTest.php` — append:

```php
it('adds ban_issued to lives', function () {
    expect(Illuminate\Support\Facades\Schema::hasColumn('lives', 'ban_issued'))->toBeTrue();
});
```

Run: `./vendor/bin/pest tests/Feature/MigrationTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: add lives.ban_issued flag for death-ban idempotency"
```

---

## Task 3: BanNotifier interface + NullBanNotifier

Decouples ban notifications (Discord) from `BanService` so it's testable.

**Files:** Create `app/Services/Ban/BanNotifier.php`, `app/Services/Ban/NullBanNotifier.php`.

- [ ] **Step 1: Create the interface**

`app/Services/Ban/BanNotifier.php`:

```php
<?php

namespace App\Services\Ban;

use App\Models\Ban;
use App\Models\Player;

interface BanNotifier
{
    public function banned(Ban $ban, Player $player, bool $isExtension): void;

    public function unbanned(Player $player, string $reason, ?string $originalReason): void;
}
```

- [ ] **Step 2: Create the no-op**

`app/Services/Ban/NullBanNotifier.php`:

```php
<?php

namespace App\Services\Ban;

use App\Models\Ban;
use App\Models\Player;

class NullBanNotifier implements BanNotifier
{
    public function banned(Ban $ban, Player $player, bool $isExtension): void {}

    public function unbanned(Player $player, string $reason, ?string $originalReason): void {}
}
```

- [ ] **Step 3: Commit** (no test — pure declarations, exercised by Task 4)

```bash
git add -A
git commit -m "feat: add BanNotifier interface and NullBanNotifier"
```

---

## Task 4: BanService — ban()

Creates/extends a DB ban, applies it to Nitrado, notifies. Honors a dry-run flag (skip the Nitrado write).

**Files:** Create `app/Services/Ban/BanService.php`. Test: `tests/Feature/BanServiceTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/BanServiceTest.php`:

```php
<?php

use App\Models\Ban;
use App\Models\Player;
use App\Services\Ban\BanService;
use App\Services\Ban\NullBanNotifier;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    Http::fake([
        '*/gameservers/settings' => function ($r) {
            if ($r->method() === 'POST') return Http::response(['status' => 'success', 'data' => []]);
            return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => '']]]]);
        },
    ]);
    $this->service = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('creates a 12h ban and applies it to Nitrado', function () {
    $ban = $this->service->ban('Alice', 12, 'One life autoban', 'auto_death');

    expect($ban->expires_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-06-12T00:00:00Z')->addHours(36)->toIso8601String());
    expect(Ban::count())->toBe(1);
    expect(Ban::first()->source)->toBe('auto_death');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r['value'], 'Alice'));
});

it('extends an existing active ban instead of stacking', function () {
    $this->service->ban('Alice', 12, 'first', 'auto_death');
    CarbonImmutable::setTestNow('2026-06-12T18:00:00Z');
    $this->service->ban('Alice', 12, 'second', 'auto_death');

    expect(Ban::count())->toBe(1);
    expect(Ban::first()->reason)->toBe('second');
    expect(Ban::first()->expires_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-06-13T06:00:00Z')->toIso8601String());
});

it('creates a permanent ban when hours is 0', function () {
    $ban = $this->service->ban('Alice', 0, 'perma', 'manual');
    expect($ban->expires_at)->toBeNull();
});

it('skips the Nitrado write in dry-run mode', function () {
    $dry = new BanService(new NitradoClient('t', 1), new NullBanNotifier(), dryRun: true);
    $dry->ban('Bob', 12, 'auto', 'auto_death');
    expect(Ban::where('player_id', Player::where('gamertag', 'Bob')->first()->id)->count())->toBe(1);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});
```

> Note on the first assertion: with `setTestNow('2026-06-12T12:00:00Z')`, `now()->addHours(12)` is `2026-06-12T24:00 = 2026-06-13T00:00Z`. Adjust the expected literal to match `CarbonImmutable::now()->addHours(12)` exactly — the intent is "expires 12h after now". Keep the assertion simple: `expect($ban->expires_at->equalTo(CarbonImmutable::now()->addHours(12)))->toBeTrue();`. Use that form rather than the hand-computed literal above.

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BanServiceTest.php`
Expected: FAIL ("Class App\\Services\\Ban\\BanService not found").

- [ ] **Step 3: Implement** — `app/Services/Ban/BanService.php`:

```php
<?php

namespace App\Services\Ban;

use App\Models\Ban;
use App\Models\Player;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;

class BanService
{
    public function __construct(
        private NitradoClient $nitrado,
        private BanNotifier $notifier,
        private bool $dryRun = false,
    ) {}

    public function ban(string $gamertag, int $hours, string $reason, string $source): Ban
    {
        $now = CarbonImmutable::now();
        $player = Player::firstOrCreate(
            ['gamertag' => $gamertag],
            ['first_seen_at' => $now, 'last_seen_at' => $now]
        );
        $expiresAt = $hours > 0 ? $now->addHours($hours) : null;

        $existing = Ban::where('player_id', $player->id)
            ->where('expired', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->latest('banned_at')->first();

        $isExtension = (bool) $existing;
        if ($existing) {
            $existing->update(['banned_at' => $now, 'expires_at' => $expiresAt, 'reason' => $reason, 'source' => $source]);
            $ban = $existing;
        } else {
            $ban = Ban::create([
                'player_id' => $player->id,
                'banned_at' => $now,
                'expires_at' => $expiresAt,
                'expired' => false,
                'reason' => $reason,
                'source' => $source,
            ]);
        }

        if (! $this->dryRun) {
            $this->nitrado->addBan($gamertag);
        }
        $this->notifier->banned($ban, $player, $isExtension);

        return $ban;
    }
}
```

- [ ] **Step 4: Run to verify it passes** — `./vendor/bin/pest tests/Feature/BanServiceTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: BanService::ban creates/extends bans and applies to Nitrado"
```

---

## Task 5: BanService — unban()

Lifts a ban: removes from Nitrado, expires DB bans, notifies.

**Files:** Modify `app/Services/Ban/BanService.php`. Test: append to `tests/Feature/BanServiceTest.php`.

- [ ] **Step 1: Write the failing test (append)**

```php
it('unbans: removes from Nitrado and expires active DB bans', function () {
    $this->service->ban('Alice', 12, 'auto', 'auto_death');
    Http::fake([
        '*/gameservers/settings' => function ($r) {
            if ($r->method() === 'POST') return Http::response(['status' => 'success', 'data' => []]);
            return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => 'Alice']]]]);
        },
    ]);

    $this->service->unban('Alice', 'Ban expired');

    expect(App\Models\Ban::where('expired', false)->count())->toBe(0);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && ! str_contains($r['value'], 'Alice'));
});

it('unban is a no-op for an unknown gamertag but still clears Nitrado', function () {
    $this->service->unban('Ghost', 'cleanup');
    expect(App\Models\Player::where('gamertag', 'Ghost')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run to verify it fails** — `./vendor/bin/pest tests/Feature/BanServiceTest.php` → FAIL ("undefined method unban").

- [ ] **Step 3: Add `unban()` to `BanService`**

```php
    public function unban(string $gamertag, string $reason): void
    {
        if (! $this->dryRun) {
            $this->nitrado->removeBan($gamertag);
        }

        $player = Player::where('gamertag', $gamertag)->first();
        if (! $player) return;

        $active = Ban::where('player_id', $player->id)->where('expired', false)->get();
        $original = $active->first()->reason ?? null;
        if ($active->isNotEmpty()) {
            Ban::whereIn('id', $active->pluck('id'))->update(['expired' => true, 'expires_at' => CarbonImmutable::now()]);
        }

        $this->notifier->unbanned($player, $reason, $original);
    }
```

- [ ] **Step 4: Run to verify it passes** — `./vendor/bin/pest tests/Feature/BanServiceTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: BanService::unban removes from Nitrado and expires DB bans"
```

---

## Task 6: DeathBanService — death→ban reconciliation

Finds lives that ended after `go_live_at` and haven't been banned, bans the player, marks the life. Idempotent.

**Files:** Create `app/Services/Ban/DeathBanService.php`. Test: `tests/Feature/DeathBanServiceTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/DeathBanServiceTest.php`:

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
    $this->deathBans = new DeathBanService($bans, $this->state, 12);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function endedLife(string $tag, string $endedAt, bool $banIssued = false): void {
    $p = Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => $endedAt, 'ended_at' => $endedAt, 'death_cause' => 'pvp', 'ban_issued' => $banIssued]);
}

it('bans players whose lives ended after go_live and marks them', function () {
    endedLife('AfterGoLive', '2026-06-12T11:00:00Z');
    $n = $this->deathBans->run();

    expect($n)->toBe(1);
    expect(Ban::where('source', 'auto_death')->count())->toBe(1);
    expect(Life::where('death_cause', 'pvp')->first()->ban_issued)->toBeTrue();
});

it('does not ban deaths before go_live', function () {
    endedLife('BeforeGoLive', '2026-06-12T09:00:00Z');
    expect($this->deathBans->run())->toBe(0);
    expect(Ban::count())->toBe(0);
});

it('is idempotent — already-issued lives are skipped', function () {
    endedLife('Already', '2026-06-12T11:00:00Z', banIssued: true);
    expect($this->deathBans->run())->toBe(0);
});

it('does nothing before go_live is set', function () {
    $this->state->set('go_live_at', '');  // simulate not-yet-live by clearing
    Ban::query()->delete();
    expect((new DeathBanService(new BanService(new NitradoClient('t', 1), new NullBanNotifier()), new BotState(), 12))->run())->toBe(0);
});
```

> The last test relies on `go_live_at` being empty/absent meaning "not live". Implement `run()` to return 0 when `go_live_at` is null or empty.

- [ ] **Step 2: Run to verify it fails** — `./vendor/bin/pest tests/Feature/DeathBanServiceTest.php` → FAIL ("Class ... DeathBanService not found").

- [ ] **Step 3: Implement** — `app/Services/Ban/DeathBanService.php`:

```php
<?php

namespace App\Services\Ban;

use App\Models\Life;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;

class DeathBanService
{
    public function __construct(
        private BanService $bans,
        private BotState $state,
        private int $banHours = 12,
    ) {}

    /** Ban players whose lives ended after go_live and aren't yet banned. Returns count banned. */
    public function run(): int
    {
        $goLive = $this->state->get('go_live_at');
        if (! $goLive) return 0; // not live yet — never retro-ban history

        $cutoff = CarbonImmutable::parse($goLive);

        $lives = Life::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '>', $cutoff)
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

- [ ] **Step 4: Run to verify it passes** — `./vendor/bin/pest tests/Feature/DeathBanServiceTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: DeathBanService bans newly-ended lives after go_live (idempotent)"
```

---

## Task 7: BanExpiryService — sweep + reconcile

A 60s Laracord `Service`: lift expired bans, and reconcile the Nitrado ban list so every active DB ban is present (heals manual edits / failed writes).

**Files:** Create `app/Services/BanExpiryService.php`. Test: `tests/Feature/BanExpiryServiceTest.php` (test the testable core via an injectable method; the `Service` boot wiring is verified by discovery, not Pest).

Because a `Laracord\Services\Service` is constructed by the framework with the bot instance, put the testable logic in a plain method `sweep(BanService $bans, NitradoClient $nitrado)` that the `handle()` wrapper calls with framework-built collaborators.

- [ ] **Step 1: Write the failing test** — `tests/Feature/BanExpiryServiceTest.php`:

```php
<?php

use App\Models\Ban;
use App\Models\Player;
use App\Services\Ban\BanService;
use App\Services\Ban\NullBanNotifier;
use App\Services\BanExpiryService;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    $this->postedValues = [];
    Http::fake(['*/gameservers/settings' => function ($r) {
        if ($r->method() === 'POST') { $this->postedValues[] = $r['value']; return Http::response(['status' => 'success', 'data' => []]); }
        return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => 'Stale']]]]);
    }]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function makeBan(string $tag, ?string $expiresAt, bool $expired = false): void {
    $p = Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Ban::create(['player_id' => $p->id, 'banned_at' => now()->subHours(13), 'expires_at' => $expiresAt, 'expired' => $expired, 'reason' => 'auto', 'source' => 'auto_death']);
}

it('lifts bans whose expires_at has passed', function () {
    makeBan('Expired', '2026-06-12T11:00:00Z');   // expired 1h ago
    makeBan('Active', '2026-06-12T20:00:00Z');     // still active

    $svc = new BanExpiryService();
    $svc->sweep(new BanService(new NitradoClient('t', 1), new NullBanNotifier()), new NitradoClient('t', 1));

    expect(Ban::where('expired', false)->pluck('player_id'))->toHaveCount(1);
    expect(Ban::where('expired', true)->count())->toBe(1);
});

it('reconciles: active DB bans missing from Nitrado are re-added', function () {
    makeBan('Active', '2026-06-12T20:00:00Z');  // active; Nitrado fake returns only "Stale"

    $svc = new BanExpiryService();
    $svc->sweep(new BanService(new NitradoClient('t', 1), new NullBanNotifier()), new NitradoClient('t', 1));

    // Active was missing from Nitrado -> a POST should include "Active"
    expect(collect($this->postedValues)->contains(fn ($v) => str_contains($v, 'Active')))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails** — `./vendor/bin/pest tests/Feature/BanExpiryServiceTest.php` → FAIL.

- [ ] **Step 3: Implement** — `app/Services/BanExpiryService.php`:

```php
<?php

namespace App\Services;

use App\Models\Ban;
use App\Services\Ban\BanService;
use App\Services\Ban\DiscordBanNotifier;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;
use Laracord\Services\Service;

class BanExpiryService extends Service
{
    protected int $interval = 60;

    public function handle(): void
    {
        $token = env('NITRADO_TOKEN');
        $serviceId = (int) env('NITRADO_SERVICE_ID');
        if (! $token || ! $serviceId) return;

        $nitrado = new NitradoClient($token, $serviceId);
        $bans = new BanService(
            $nitrado,
            new DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID')),
            dryRun: filter_var(env('BAN_DRY_RUN', false), FILTER_VALIDATE_BOOL),
        );

        try {
            $this->sweep($bans, $nitrado);
        } catch (\Throwable $e) {
            $this->console()->error('[ban-expiry] sweep failed: '.$e->getMessage());
        }
    }

    /** Testable core: expire due bans, then reconcile the Nitrado ban list. */
    public function sweep(BanService $bans, NitradoClient $nitrado): void
    {
        $now = CarbonImmutable::now();

        // 1) Lift expired bans.
        Ban::query()
            ->where('expired', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->with('player')
            ->get()
            ->each(function (Ban $ban) use ($bans) {
                if ($gamertag = $ban->player?->gamertag) {
                    $bans->unban($gamertag, 'Ban expired');
                }
            });

        // 2) Reconcile: every still-active ban must be present in Nitrado.
        $activeTags = Ban::query()
            ->where('expired', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->with('player')
            ->get()
            ->map(fn (Ban $b) => $b->player?->gamertag)
            ->filter()
            ->unique();

        if ($activeTags->isEmpty()) return;

        $present = collect($nitrado->getBans());
        foreach ($activeTags->diff($present) as $missing) {
            $nitrado->addBan($missing);
        }
    }
}
```

- [ ] **Step 4: Run to verify it passes** — `./vendor/bin/pest tests/Feature/BanExpiryServiceTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: BanExpiryService sweeps expired bans and reconciles Nitrado list"
```

---

## Task 8: DiscordBanNotifier

The production notifier — posts to the bans channel and DMs the linked player. Thin; not unit-tested (requires the gateway). Verified at runtime in Task 10.

**Files:** Create `app/Services/Ban/DiscordBanNotifier.php`.

- [ ] **Step 1: Implement** (adapt the message-send API to Laracord — read `vendor/laracord/framework` `Message`/service `message()` helpers; a `Service`/command builds messages via `$this->message(...)`, but here we hold a raw Discord client, so use DiscordPHP directly):

```php
<?php

namespace App\Services\Ban;

use App\Models\Ban;
use App\Models\Player;
use Discord\Discord;

class DiscordBanNotifier implements BanNotifier
{
    public function __construct(private ?Discord $discord, private ?string $bansChannelId) {}

    public function banned(Ban $ban, Player $player, bool $isExtension): void
    {
        $who = $player->discord_user_id ? "<@{$player->discord_user_id}>" : "`{$player->gamertag}`";
        $expires = $ban->expires_at ? "<t:{$ban->expires_at->timestamp}:f>" : 'never (permanent)';
        $action = $isExtension ? 'Ban updated' : 'Player banned';
        $this->toChannel("🔨 **{$action}** — {$who} · {$ban->reason} · expires {$expires}");

        if ($player->discord_user_id) {
            $this->toUser($player->discord_user_id,
                "🔨 You have been **banned** from the server.\n• Reason: {$ban->reason}\n• Expires: {$expires}");
        }
    }

    public function unbanned(Player $player, string $reason, ?string $originalReason): void
    {
        $who = $player->discord_user_id ? "<@{$player->discord_user_id}>" : "`{$player->gamertag}`";
        $this->toChannel("✅ **Player unbanned** — {$who} · {$reason}");
        if ($player->discord_user_id) {
            $this->toUser($player->discord_user_id, "🕊️ Your ban has been removed.\n• Reason: {$reason}");
        }
    }

    private function toChannel(string $content): void
    {
        if (! $this->discord || ! $this->bansChannelId) return;
        $channel = $this->discord->getChannel($this->bansChannelId);
        $channel?->sendMessage($content);
    }

    private function toUser(string $userId, string $content): void
    {
        if (! $this->discord) return;
        $user = $this->discord->users->get('id', $userId);
        $user?->sendMessage($content);
    }
}
```

> Verify the DiscordPHP API names against `vendor/team-reflex/discord-php` (`getChannel`, `Channel::sendMessage`, `users->get`, `User::sendMessage` / `User::sendMessage` may be `->sendMessage()` returning a promise). Adapt to the installed version; keep all sends best-effort (null-safe, never throw into the caller). If a linked-player DM needs `discord_user_id` (added in Plan 3), it's null until then — the null guards handle it.

- [ ] **Step 2: Lint + commit**

Run: `php -l app/Services/Ban/DiscordBanNotifier.php`

```bash
git add -A
git commit -m "feat: DiscordBanNotifier posts ban/unban notices to channel and DM"
```

---

## Task 9: Wire death→ban into the ingestion service

After each tick, in live mode, run `DeathBanService` with the real notifier and dry-run flag.

**Files:** Modify `app/Services/IngestAdmService.php`. Modify `.env.example` (add `BAN_DRY_RUN=false`).

- [ ] **Step 1: Add `BAN_DRY_RUN=false` under the Behavior section of `.env.example` (and your local `.env`).**

- [ ] **Step 2: Update `IngestAdmService::handle()`** so that after `$ingestor->tick(...)` it runs the death-ban pass:

```php
        try {
            $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
            $client = new NitradoClient($token, $serviceId);
            $state = new BotState();
            $ingestor->tick($client, $state, (int) env('ADM_BACKFILL_BUDGET', 15));

            $bans = new \App\Services\Ban\BanService(
                $client,
                new \App\Services\Ban\DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID')),
                dryRun: filter_var(env('BAN_DRY_RUN', false), FILTER_VALIDATE_BOOL),
            );
            $banned = (new \App\Services\Ban\DeathBanService($bans, $state, (int) env('BAN_DURATION_HOURS', 12)))->run();
            if ($banned > 0) {
                $this->console()->log("[ingest] issued {$banned} death ban(s).");
            }
        } catch (\Throwable $e) {
            $this->console()->error('[ingest] tick failed: '.$e->getMessage());
        }
```

(Keep the existing env guard above this block. `DeathBanService::run()` returns 0 until `go_live_at` is set, so backfill ticks never ban.)

- [ ] **Step 3: Confirm the bot boots and discovers both services**

Run: `php laracord list` (no fatal errors); confirm the framework loads `IngestAdmService` and `BanExpiryService` (both under `app/Services/`, extending `Service`). Full gateway boot needs a `DISCORD_TOKEN`; class-loading without error is the bar here.

- [ ] **Step 4: Full suite**

Run: `./vendor/bin/pest`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: run death-ban reconciliation after each live ingestion tick"
```

---

## Task 10: Safe live cutover verification

**Requires the real Nitrado token + a `DISCORD_TOKEN`.** Verify against the live server in DRY-RUN first, then enable real bans.

- [ ] **Step 1: Dry-run cutover.** Set `BAN_DRY_RUN=true`, `BAN_DURATION_HOURS=12` in `.env`. Fresh state is NOT needed — keep the verified DB, or `migrate:fresh` then let it backfill once (mode flips to live, `go_live_at` set). With dry-run on, run the bot (`php laracord`) or invoke a single ingest cycle and observe: when a real player dies after go-live, `DeathBanService` creates a `bans` row and logs the intended ban, but **no Nitrado write** occurs and the player is NOT actually kicked. Confirm `bans` rows look right (correct gamertag, `auto_death`, 12h `expires_at`) without affecting the live server.

- [ ] **Step 2: Inspect.** `sqlite3 database/database.sqlite "select b.banned_at,b.expires_at,b.source,p.gamertag from bans b join players p on p.id=b.player_id order by b.banned_at desc limit 10;"` — confirm bans correspond to real post-go-live deaths only (cross-check against `lives.ended_at > go_live_at`).

- [ ] **Step 3: Review with the user.** Confirm the dry-run bans are correct and nothing historical was banned. THEN set `BAN_DRY_RUN=false` to enable real Nitrado bans, and confirm one real death results in: a `general.bans` entry on Nitrado (`php laracord tinker` → `(new App\Services\Nitrado\NitradoClient(env('NITRADO_TOKEN'),(int)env('NITRADO_SERVICE_ID')))->getBans()`), a bans-channel post, and automatic removal after 12h by `BanExpiryService`.

- [ ] **Step 4: Tag the verified baseline**

```bash
git commit --allow-empty -m "chore: Plan 2 banning verified (dry-run then live) against real server"
git tag plan2-verified
```

---

## Self-review notes (coverage against spec Section 4)

- **Nitrado `general.bans` add/remove** → Task 1.
- **`BanService.ban` create/extend, permanent bans, Nitrado apply, notify** → Tasks 3–4.
- **`BanService.unban` Nitrado remove + DB expire + notify** → Task 5.
- **Death → ban (LIVE, `ts > go_live_at`, idempotent, `auto_death`)** → Tasks 2, 6, 9. Implemented as post-tick reconciliation over `lives.ended_at > go_live_at` + `ban_issued` flag (cleaner than inline state-machine side-effects; same outcome).
- **Expiry sweep (per-minute) + reconcile** → Task 7.
- **Notifications (bans channel + DM if linked)** → Task 8. (DM targets `discord_user_id`, which is null until Plan 3 linking — null-guarded.)
- **Dry-run safety for cutover** → Tasks 4/9/10 (`BAN_DRY_RUN`).
- **Permanent bans not token-removable** → enforced later (Plan 3 redemption); permanent = `expires_at null`, never swept by expiry.

**Out of scope (Plan 3):** linking, unban tokens (link/monthly/referral grants + redemption), and all slash commands (`/link`, `/unban`, `/bans`, admin commands). Ban notifications can DM linked players once linking exists; until then they post to the channel only.
```
