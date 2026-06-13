# Leaderboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Post a single auto-updating Discord embed to a leaderboard channel, edited in place every 15 minutes, ranking players across five boards: longest life (alive), longest life (all-time), most kills, longest kill streak, and longest-distance kills.

**Architecture:** Business logic lives in plain, testable services (`LeaderboardStatsService` for the five queries, `LivePlaytime` helper for open-life playtime, `LeaderboardComposer` for the Discord-agnostic embed payload). A thin periodic `LeaderboardService` (`Laracord\Services\Service`) wires them to a thin `DiscordLeaderboardNotifier` that does post-or-edit via `bot_state`. No schema changes — all five boards are computed from existing `lives` / `game_sessions` / `players` tables.

**Tech Stack:** Laracord v2.3.0 (Laravel Zero + DiscordPHP), PHP 8.2+, SQLite, Pest. Carbon\CarbonImmutable for time.

---

## Design reference

Spec: `docs/superpowers/specs/2026-06-13-leaderboard-design.md`.

**Key facts that drive the code (verified against the codebase):**

- `lives.playtime_seconds` is incremented **only when a session closes** (`LifeTracker::closeSession` at `app/Services/Life/LifeTracker.php:89`). So an **open** life with a currently-open session does **not** include that open session's elapsed time. "Live playtime" = `playtime_seconds` + elapsed of the open session (if any). This is the `LivePlaytime` helper (Task 2).
- A "kill" = a **victim** `lives` row with `death_cause = 'pvp'` and non-null `death_by_gamertag` (the killer's gamertag, a raw string — not a foreign key). There is no kills table; count by grouping on `death_by_gamertag`.
- Exclude self/suicide/environment from kill boards: require `death_cause = 'pvp'`, non-null `death_by_gamertag`, and `death_by_gamertag != players.gamertag` (the victim's own gamertag).
- Columns (existing): `lives(id, player_id, started_at, ended_at, death_cause, death_by_gamertag, death_weapon, death_distance, playtime_seconds)`; `game_sessions(id, player_id, life_id, connected_at, disconnected_at, duration_seconds)`; `players(id, gamertag, discord_user_id)`.
- `death_distance` is cast to `float` on the `Life` model; `playtime_seconds` to `integer`.

**Row shapes returned by `LeaderboardStatsService` (the contract the composer consumes):**

- `aliveLongestLives` / `allTimeLongestLives` → `['gamertag' => string, 'seconds' => int]`
- `mostKills` → `['gamertag' => string, 'kills' => int]`
- `longestKillStreaks` → `['gamertag' => string, 'streak' => int]`
- `longestKills` → `['killer' => string, 'victim' => string, 'weapon' => ?string, 'distance' => float]`

**Composer payload shape (what the notifier renders):**

```php
[
  'title' => '🏆 One Life Leaderboards',
  'description' => '<rotating intro line>',
  'fields' => [ ['name' => string, 'value' => string], ... 5 entries ],
]
```

---

## Task 1: Leaderboard config + pinned test env

**Files:**
- Create: `config/leaderboard.php`
- Modify: `phpunit.xml` (add `<env>` pins)
- Test: `tests/Feature/LeaderboardConfigTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeaderboardConfigTest.php`:

```php
<?php

it('exposes leaderboard defaults', function () {
    expect(config('leaderboard.enabled'))->toBeTrue();
    expect(config('leaderboard.refresh_minutes'))->toBe(15);
    expect(config('leaderboard.top_count'))->toBe(5);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LeaderboardConfigTest.php`
Expected: FAIL — `config('leaderboard.refresh_minutes')` is null (file doesn't exist).

- [ ] **Step 3: Create the config file**

Create `config/leaderboard.php`:

```php
<?php

return [
    'enabled' => filter_var(env('LEADERBOARD_ENABLED', true), FILTER_VALIDATE_BOOL),
    'channel_id' => env('LEADERBOARD_CHANNEL_ID') ?: env('BANS_CHANNEL_ID'),
    'refresh_minutes' => (int) env('LEADERBOARD_REFRESH_MINUTES', 15),
    'top_count' => (int) env('LEADERBOARD_TOP_COUNT', 5),
];
```

- [ ] **Step 4: Pin defaults in phpunit.xml**

In `phpunit.xml`, inside the `<php>` block (after the `DEATH_FEED_MAX_AGE_MINUTES` line), add:

```xml
        <env name="LEADERBOARD_ENABLED" value="true"/>
        <env name="LEADERBOARD_REFRESH_MINUTES" value="15"/>
        <env name="LEADERBOARD_TOP_COUNT" value="5"/>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LeaderboardConfigTest.php`
Expected: PASS (1 passed).

- [ ] **Step 6: Commit**

```bash
git add config/leaderboard.php phpunit.xml tests/Feature/LeaderboardConfigTest.php
git commit -m "feat: leaderboard config with pinned test defaults"
```

---

## Task 2: `LivePlaytime` helper (open-life playtime)

**Files:**
- Create: `app/Services/Life/LivePlaytime.php`
- Test: `tests/Feature/LivePlaytimeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LivePlaytimeTest.php`:

```php
<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Life\LivePlaytime;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

it('returns stored playtime for a life with no open session', function () {
    $p = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => now()->subHour(), 'playtime_seconds' => 1800]);

    expect(LivePlaytime::forLife($life))->toBe(1800);
});

it('adds the open session elapsed time to stored playtime', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');
    $p = Player::create(['gamertag' => 'Bob', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-13T14:00:00Z', 'playtime_seconds' => 600]);
    // Open session connected at 15:40 -> 20 minutes elapsed by 16:00 = 1200s
    GameSession::create(['player_id' => $p->id, 'life_id' => $life->id, 'connected_at' => '2026-06-13T15:40:00Z']);

    expect(LivePlaytime::forLife($life))->toBe(600 + 1200);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LivePlaytimeTest.php`
Expected: FAIL — class `App\Services\Life\LivePlaytime` not found.

- [ ] **Step 3: Implement the helper**

Create `app/Services/Life/LivePlaytime.php`:

```php
<?php

namespace App\Services\Life;

use App\Models\Life;
use Carbon\CarbonImmutable;

/**
 * Live playtime for a single life. lives.playtime_seconds only accrues when a
 * session CLOSES (LifeTracker::closeSession), so an open life's currently-open
 * session is not yet counted. This adds that session's elapsed-so-far.
 * Kept separate from PlayerStatsService (which uses the stored value as-is for
 * its current_life_seconds) so existing behaviour is untouched.
 */
class LivePlaytime
{
    public static function forLife(Life $life): int
    {
        $seconds = (int) $life->playtime_seconds;

        $open = $life->sessions()->whereNull('disconnected_at')->first();
        if ($open) {
            $seconds += max(0, CarbonImmutable::now()->getTimestamp() - $open->connected_at->getTimestamp());
        }

        return $seconds;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LivePlaytimeTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Life/LivePlaytime.php tests/Feature/LivePlaytimeTest.php
git commit -m "feat: LivePlaytime helper for open-life elapsed time"
```

---

## Task 3: `LeaderboardStatsService::aliveLongestLives`

**Files:**
- Create: `app/Services/Leaderboard/LeaderboardStatsService.php`
- Test: `tests/Feature/LeaderboardStatsServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeaderboardStatsServiceTest.php`:

```php
<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Leaderboard\LeaderboardStatsService;
use Carbon\CarbonImmutable;

beforeEach(fn () => $this->svc = new LeaderboardStatsService());
afterEach(fn () => CarbonImmutable::setTestNow());

/** Helper: create a player with a single life and optional kills against others. */
function lbPlayer(string $tag, ?string $discord = null): Player
{
    return Player::create(['gamertag' => $tag, 'discord_user_id' => $discord, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('ranks alive players by live playtime, longest first', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');

    $a = lbPlayer('Alice');
    $b = lbPlayer('Bob');
    $c = lbPlayer('Carol');

    // Alice: open life, 600 stored + open session 15:00->16:00 (3600) = 4200
    $al = Life::create(['player_id' => $a->id, 'started_at' => '2026-06-13T10:00:00Z', 'playtime_seconds' => 600]);
    GameSession::create(['player_id' => $a->id, 'life_id' => $al->id, 'connected_at' => '2026-06-13T15:00:00Z']);

    // Bob: open life, 5000 stored, no open session = 5000
    Life::create(['player_id' => $b->id, 'started_at' => '2026-06-13T09:00:00Z', 'playtime_seconds' => 5000]);

    // Carol: ENDED life — must be excluded from the alive board
    Life::create(['player_id' => $c->id, 'started_at' => '2026-06-13T08:00:00Z', 'ended_at' => '2026-06-13T09:00:00Z', 'playtime_seconds' => 9999]);

    $rows = $this->svc->aliveLongestLives(5);

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toMatchArray(['gamertag' => 'Bob', 'seconds' => 5000]);
    expect($rows[1])->toMatchArray(['gamertag' => 'Alice', 'seconds' => 4200]);
});

it('honours the limit on the alive board', function () {
    foreach (['P1' => 100, 'P2' => 200, 'P3' => 300] as $tag => $secs) {
        $p = lbPlayer($tag);
        Life::create(['player_id' => $p->id, 'started_at' => now()->subHour(), 'playtime_seconds' => $secs]);
    }

    expect($this->svc->aliveLongestLives(2))->toHaveCount(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LeaderboardStatsServiceTest.php`
Expected: FAIL — class `App\Services\Leaderboard\LeaderboardStatsService` not found.

- [ ] **Step 3: Implement the service with `aliveLongestLives`**

Create `app/Services/Leaderboard/LeaderboardStatsService.php`:

```php
<?php

namespace App\Services\Leaderboard;

use App\Models\Life;
use App\Services\Life\LivePlaytime;

/**
 * Read-only queries powering the five leaderboard boards. All computed from the
 * existing lives / game_sessions / players tables (no kills table). Heavily
 * Feature-tested; the periodic Service and Discord notifier are thin wrappers.
 */
class LeaderboardStatsService
{
    /**
     * Open lives ranked by live playtime (stored + open-session elapsed), desc.
     * Tie-break: earliest started_at.
     *
     * @return array<int, array{gamertag:string, seconds:int}>
     */
    public function aliveLongestLives(int $limit): array
    {
        $rows = Life::query()
            ->whereNull('ended_at')
            ->with('player:id,gamertag')
            ->get()
            ->map(fn (Life $life) => [
                'gamertag' => $life->player->gamertag,
                'seconds' => LivePlaytime::forLife($life),
                'started_at' => $life->started_at->getTimestamp(),
            ])
            ->all();

        return $this->rankBySeconds($rows, $limit);
    }

    /**
     * Sort by seconds desc, tie-break started_at asc, strip the sort key, take $limit.
     *
     * @param  array<int, array{gamertag:string, seconds:int, started_at:int}>  $rows
     * @return array<int, array{gamertag:string, seconds:int}>
     */
    private function rankBySeconds(array $rows, int $limit): array
    {
        usort($rows, fn ($a, $b) => $b['seconds'] <=> $a['seconds'] ?: $a['started_at'] <=> $b['started_at']);

        return array_map(
            fn ($r) => ['gamertag' => $r['gamertag'], 'seconds' => $r['seconds']],
            array_slice($rows, 0, $limit)
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LeaderboardStatsServiceTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leaderboard/LeaderboardStatsService.php tests/Feature/LeaderboardStatsServiceTest.php
git commit -m "feat: leaderboard alive-longest-life board"
```

---

## Task 4: `allTimeLongestLives` (deduped best life per player)

**Files:**
- Modify: `app/Services/Leaderboard/LeaderboardStatsService.php`
- Test: `tests/Feature/LeaderboardStatsServiceTest.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/LeaderboardStatsServiceTest.php`:

```php
it('ranks all-time longest lives with one entry per player (best life)', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');

    $a = lbPlayer('Alice');
    $b = lbPlayer('Bob');

    // Alice has two lives: 1000 and 4000 -> her best (4000) should be the only Alice entry
    Life::create(['player_id' => $a->id, 'started_at' => '2026-06-10T00:00:00Z', 'ended_at' => '2026-06-10T01:00:00Z', 'playtime_seconds' => 1000]);
    Life::create(['player_id' => $a->id, 'started_at' => '2026-06-11T00:00:00Z', 'ended_at' => '2026-06-11T02:00:00Z', 'playtime_seconds' => 4000]);

    // Bob: one ended life of 3000
    Life::create(['player_id' => $b->id, 'started_at' => '2026-06-12T00:00:00Z', 'ended_at' => '2026-06-12T01:00:00Z', 'playtime_seconds' => 3000]);

    $rows = $this->svc->allTimeLongestLives(5);

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toMatchArray(['gamertag' => 'Alice', 'seconds' => 4000]);
    expect($rows[1])->toMatchArray(['gamertag' => 'Bob', 'seconds' => 3000]);
});

it('includes open lives (live playtime) on the all-time board', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:00:00Z');

    $a = lbPlayer('Alice');
    // Open life: 600 stored + open session 15:00->16:00 (3600) = 4200
    $life = Life::create(['player_id' => $a->id, 'started_at' => '2026-06-13T10:00:00Z', 'playtime_seconds' => 600]);
    GameSession::create(['player_id' => $a->id, 'life_id' => $life->id, 'connected_at' => '2026-06-13T15:00:00Z']);

    expect($this->svc->allTimeLongestLives(5)[0])->toMatchArray(['gamertag' => 'Alice', 'seconds' => 4200]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LeaderboardStatsServiceTest.php`
Expected: FAIL — `Call to undefined method ...::allTimeLongestLives()`.

- [ ] **Step 3: Implement `allTimeLongestLives`**

Add this method to `LeaderboardStatsService` (after `aliveLongestLives`):

```php
    /**
     * All lives ranked by playtime, deduped to the best life per player, desc.
     * Open lives use live playtime; ended lives use the stored value (no extra
     * query). Tie-break: earliest started_at.
     *
     * @return array<int, array{gamertag:string, seconds:int}>
     */
    public function allTimeLongestLives(int $limit): array
    {
        $best = []; // gamertag => ['gamertag','seconds','started_at']

        Life::query()->with('player:id,gamertag')->get()->each(function (Life $life) use (&$best) {
            $tag = $life->player->gamertag;
            $seconds = $life->ended_at === null
                ? LivePlaytime::forLife($life)
                : (int) $life->playtime_seconds;

            if (! isset($best[$tag]) || $seconds > $best[$tag]['seconds']) {
                $best[$tag] = [
                    'gamertag' => $tag,
                    'seconds' => $seconds,
                    'started_at' => $life->started_at->getTimestamp(),
                ];
            }
        });

        return $this->rankBySeconds(array_values($best), $limit);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LeaderboardStatsServiceTest.php`
Expected: PASS (4 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leaderboard/LeaderboardStatsService.php tests/Feature/LeaderboardStatsServiceTest.php
git commit -m "feat: leaderboard all-time-longest-life board (deduped per player)"
```

---

## Task 5: `mostKills`

**Files:**
- Modify: `app/Services/Leaderboard/LeaderboardStatsService.php`
- Test: `tests/Feature/LeaderboardStatsServiceTest.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/LeaderboardStatsServiceTest.php`:

```php
/** Record a PvP kill: a victim life ended, killed by $killer. */
function lbKill(Player $victim, string $killer, ?float $distance = null, ?string $weapon = null): void
{
    Life::create([
        'player_id' => $victim->id,
        'started_at' => now()->subHour(),
        'ended_at' => now(),
        'death_cause' => 'pvp',
        'death_by_gamertag' => $killer,
        'death_weapon' => $weapon,
        'death_distance' => $distance,
    ]);
}

it('counts PvP kills per killer gamertag, most first', function () {
    $alice = lbPlayer('Alice');
    $bob = lbPlayer('Bob');
    $carol = lbPlayer('Carol');

    // Bob kills 3, Alice kills 1
    lbKill($carol, 'Bob');
    lbKill($alice, 'Bob');
    $extra = lbPlayer('Dave');
    lbKill($extra, 'Bob');
    lbKill($bob, 'Alice');

    $rows = $this->svc->mostKills(5);

    expect($rows[0])->toMatchArray(['gamertag' => 'Bob', 'kills' => 3]);
    expect($rows[1])->toMatchArray(['gamertag' => 'Alice', 'kills' => 1]);
});

it('excludes suicides, environment deaths, and self-kills from kill counts', function () {
    $alice = lbPlayer('Alice');

    // Suicide (cause != pvp) — excluded
    Life::create(['player_id' => $alice->id, 'started_at' => now()->subHour(), 'ended_at' => now(), 'death_cause' => 'suicide', 'death_by_gamertag' => null]);
    // Self-attributed pvp (killer == victim) — excluded
    Life::create(['player_id' => $alice->id, 'started_at' => now()->subHour(), 'ended_at' => now(), 'death_cause' => 'pvp', 'death_by_gamertag' => 'Alice']);

    expect($this->svc->mostKills(5))->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LeaderboardStatsServiceTest.php`
Expected: FAIL — `Call to undefined method ...::mostKills()`.

- [ ] **Step 3: Implement `mostKills`**

Add `use Illuminate\Support\Facades\DB;` to the top of `LeaderboardStatsService.php` (under the existing `use` lines), then add this method:

```php
    /**
     * Count of PvP kills credited to each killer gamertag, desc.
     * Excludes suicides/environment (cause != pvp), null killers, and self-kills
     * (killer == victim gamertag). Tie-break: earliest kill (min ended_at).
     *
     * @return array<int, array{gamertag:string, kills:int}>
     */
    public function mostKills(int $limit): array
    {
        return DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->where('lives.death_cause', 'pvp')
            ->whereNotNull('lives.death_by_gamertag')
            ->whereColumn('lives.death_by_gamertag', '!=', 'players.gamertag')
            ->groupBy('lives.death_by_gamertag')
            ->orderByDesc('kills')
            ->orderByRaw('MIN(lives.ended_at) ASC')
            ->limit($limit)
            ->get([
                'lives.death_by_gamertag as gamertag',
                DB::raw('COUNT(*) as kills'),
            ])
            ->map(fn ($r) => ['gamertag' => $r->gamertag, 'kills' => (int) $r->kills])
            ->all();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LeaderboardStatsServiceTest.php`
Expected: PASS (6 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leaderboard/LeaderboardStatsService.php tests/Feature/LeaderboardStatsServiceTest.php
git commit -m "feat: leaderboard most-kills board"
```

---

## Task 6: `longestKills` (longest-distance single kills)

**Files:**
- Modify: `app/Services/Leaderboard/LeaderboardStatsService.php`
- Test: `tests/Feature/LeaderboardStatsServiceTest.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/LeaderboardStatsServiceTest.php`:

```php
it('ranks single kills by distance, longest first, with killer/victim/weapon', function () {
    $bob = lbPlayer('Bob');
    $carol = lbPlayer('Carol');
    $dave = lbPlayer('Dave');

    lbKill($carol, 'Bob', distance: 412.7, weapon: 'M24');
    lbKill($dave, 'Bob', distance: 88.0, weapon: 'AKM');
    // pvp kill with null distance — excluded
    lbKill($bob, 'Carol', distance: null, weapon: 'Knife');

    $rows = $this->svc->longestKills(5);

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toMatchArray(['killer' => 'Bob', 'victim' => 'Carol', 'weapon' => 'M24', 'distance' => 412.7]);
    expect($rows[1])->toMatchArray(['killer' => 'Bob', 'victim' => 'Dave', 'weapon' => 'AKM', 'distance' => 88.0]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LeaderboardStatsServiceTest.php`
Expected: FAIL — `Call to undefined method ...::longestKills()`.

- [ ] **Step 3: Implement `longestKills`**

Add this method to `LeaderboardStatsService`:

```php
    /**
     * Top single PvP kills by death_distance, desc. NOT deduped (a board of
     * individual shots). Tie-break: earliest kill (ended_at asc).
     *
     * @return array<int, array{killer:string, victim:string, weapon:?string, distance:float}>
     */
    public function longestKills(int $limit): array
    {
        return DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->where('lives.death_cause', 'pvp')
            ->whereNotNull('lives.death_by_gamertag')
            ->whereNotNull('lives.death_distance')
            ->whereColumn('lives.death_by_gamertag', '!=', 'players.gamertag')
            ->orderByDesc('lives.death_distance')
            ->orderBy('lives.ended_at')
            ->limit($limit)
            ->get([
                'lives.death_by_gamertag as killer',
                'players.gamertag as victim',
                'lives.death_weapon as weapon',
                'lives.death_distance as distance',
            ])
            ->map(fn ($r) => [
                'killer' => $r->killer,
                'victim' => $r->victim,
                'weapon' => $r->weapon,
                'distance' => (float) $r->distance,
            ])
            ->all();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LeaderboardStatsServiceTest.php`
Expected: PASS (7 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leaderboard/LeaderboardStatsService.php tests/Feature/LeaderboardStatsServiceTest.php
git commit -m "feat: leaderboard longest-distance-kill board"
```

---

## Task 7: `longestKillStreaks` (max kills within a single life, deduped)

**Files:**
- Modify: `app/Services/Leaderboard/LeaderboardStatsService.php`
- Test: `tests/Feature/LeaderboardStatsServiceTest.php`

A kill's timestamp is the victim life's `ended_at`. A killer's life window is `[started_at, ended_at ?? now)`. A streak = kills falling inside one of the killer's life windows; take the player's max across their lives. Deduped to one streak per player.

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/LeaderboardStatsServiceTest.php`:

```php
it('computes the longest kill streak within a single life, per player', function () {
    CarbonImmutable::setTestNow('2026-06-13T20:00:00Z');

    $hunter = lbPlayer('Hunter');

    // Hunter life #1: 10:00 -> 12:00 (2 kills inside)
    Life::create(['player_id' => $hunter->id, 'started_at' => '2026-06-13T10:00:00Z', 'ended_at' => '2026-06-13T12:00:00Z', 'playtime_seconds' => 7200]);
    // Hunter life #2: 14:00 -> open (3 kills inside) -> streak 3
    Life::create(['player_id' => $hunter->id, 'started_at' => '2026-06-13T14:00:00Z', 'playtime_seconds' => 1000]);

    $mkV = function (string $tag, string $endedAt) {
        $v = lbPlayer($tag);
        Life::create(['player_id' => $v->id, 'started_at' => '2026-06-13T09:00:00Z', 'ended_at' => $endedAt, 'death_cause' => 'pvp', 'death_by_gamertag' => 'Hunter']);
    };
    $mkV('V1', '2026-06-13T10:30:00Z');
    $mkV('V2', '2026-06-13T11:45:00Z');
    $mkV('V3', '2026-06-13T15:00:00Z');
    $mkV('V4', '2026-06-13T16:00:00Z');
    $mkV('V5', '2026-06-13T19:00:00Z');

    expect($this->svc->longestKillStreaks(5)[0])->toMatchArray(['gamertag' => 'Hunter', 'streak' => 3]);
});

it('omits players with no kills from the streak board', function () {
    CarbonImmutable::setTestNow('2026-06-13T20:00:00Z');
    $p = lbPlayer('Quiet');
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-13T10:00:00Z', 'playtime_seconds' => 100]);

    expect($this->svc->longestKillStreaks(5))->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LeaderboardStatsServiceTest.php`
Expected: FAIL — `Call to undefined method ...::longestKillStreaks()`.

- [ ] **Step 3: Implement `longestKillStreaks`**

Add `use App\Models\Player;` and `use Carbon\CarbonImmutable;` to the top of `LeaderboardStatsService.php` (under existing `use` lines), then add:

```php
    /**
     * Longest run of kills inside a single life, one entry per killer (their best
     * life). A kill counts toward the killer's life whose window
     * [started_at, ended_at ?? now) contains the victim's ended_at.
     * Tie-break: earliest life start.
     *
     * @return array<int, array{gamertag:string, streak:int}>
     */
    public function longestKillStreaks(int $limit): array
    {
        $now = CarbonImmutable::now()->getTimestamp();

        // All kills as (killer => list of kill unix-timestamps).
        $killsByKiller = [];
        DB::table('lives')
            ->join('players', 'players.id', '=', 'lives.player_id')
            ->where('lives.death_cause', 'pvp')
            ->whereNotNull('lives.death_by_gamertag')
            ->whereNotNull('lives.ended_at')
            ->whereColumn('lives.death_by_gamertag', '!=', 'players.gamertag')
            ->get(['lives.death_by_gamertag as killer', 'lives.ended_at as ts'])
            ->each(function ($row) use (&$killsByKiller) {
                $killsByKiller[$row->killer][] = CarbonImmutable::parse($row->ts)->getTimestamp();
            });

        $rows = [];
        foreach ($killsByKiller as $killer => $timestamps) {
            $player = Player::where('gamertag', $killer)->first();
            if (! $player) {
                continue; // killer never tracked as a player -> no life windows
            }

            $best = 0;
            $bestStart = null;
            foreach ($player->lives as $life) {
                $start = $life->started_at->getTimestamp();
                $end = $life->ended_at?->getTimestamp() ?? $now;

                $count = 0;
                foreach ($timestamps as $ts) {
                    if ($ts >= $start && $ts < $end) {
                        $count++;
                    }
                }

                if ($count > $best || ($count === $best && $bestStart !== null && $start < $bestStart)) {
                    $best = $count;
                    $bestStart = $start;
                }
            }

            if ($best > 0) {
                $rows[] = ['gamertag' => $killer, 'streak' => $best, 'started_at' => $bestStart];
            }
        }

        usort($rows, fn ($a, $b) => $b['streak'] <=> $a['streak'] ?: $a['started_at'] <=> $b['started_at']);

        return array_map(
            fn ($r) => ['gamertag' => $r['gamertag'], 'streak' => $r['streak']],
            array_slice($rows, 0, $limit)
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LeaderboardStatsServiceTest.php`
Expected: PASS (9 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leaderboard/LeaderboardStatsService.php tests/Feature/LeaderboardStatsServiceTest.php
git commit -m "feat: leaderboard longest-kill-streak board"
```

---

## Task 8: `LeaderboardComposer` + `leaderboard.intro` personality pool

**Files:**
- Create: `app/Services/Leaderboard/LeaderboardComposer.php`
- Modify: `config/personality.php` (add `leaderboard.intro` pool)
- Modify: `tests/Feature/PersonalityConfigTest.php` (assert the new pool)
- Test: `tests/Unit/LeaderboardComposerTest.php`

- [ ] **Step 1: Write the failing composer test**

Create `tests/Unit/LeaderboardComposerTest.php`:

```php
<?php

use App\Services\Leaderboard\LeaderboardComposer;
use App\Services\Personality\MessagePicker;

beforeEach(function () {
    MessagePicker::reset();
    // Deterministic picker: always the first line of a pool.
    $this->composer = new LeaderboardComposer(new MessagePicker(fn (array $pool, ?int $avoid) => 0));
});

function lbBoards(): array
{
    return [
        'alive' => [['gamertag' => 'Alice', 'seconds' => 5000], ['gamertag' => 'Bob', 'seconds' => 90]],
        'all_time' => [['gamertag' => 'Carol', 'seconds' => 7200]],
        'kills' => [['gamertag' => 'Bob', 'kills' => 3], ['gamertag' => 'Alice', 'kills' => 1]],
        'streak' => [['gamertag' => 'Bob', 'streak' => 2]],
        'distance' => [['killer' => 'Bob', 'victim' => 'Carol', 'weapon' => 'M24', 'distance' => 412.7]],
    ];
}

it('builds a five-field payload with a title and description', function () {
    $payload = $this->composer->compose(lbBoards());

    expect($payload['title'])->toContain('Leaderboard');
    expect($payload['description'])->toBeString()->not->toBe('');
    expect($payload['fields'])->toHaveCount(5);
});

it('formats durations and never @-mentions (plain backticked gamertags)', function () {
    $fields = $this->composer->compose(lbBoards())['fields'];

    // Field 0 = alive board
    expect($fields[0]['value'])->toContain('1. `Alice` — 1h 23m');
    expect($fields[0]['value'])->toContain('2. `Bob` — <1m');
    expect($fields[0]['value'])->not->toContain('<@');
});

it('formats kill counts with singular/plural and distance rows', function () {
    $fields = $this->composer->compose(lbBoards())['fields'];

    // Field 2 = most kills
    expect($fields[2]['value'])->toContain('1. `Bob` — 3 kills');
    expect($fields[2]['value'])->toContain('2. `Alice` — 1 kill');

    // Field 4 = longest distance kill
    expect($fields[4]['value'])->toContain('`Bob` (M24) — 413m → `Carol`');
});

it('renders an empty board as a placeholder', function () {
    $boards = lbBoards();
    $boards['streak'] = [];

    $fields = $this->composer->compose($boards)['fields'];

    // Field 3 = streak
    expect($fields[3]['value'])->toBe('*No entries yet*');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/LeaderboardComposerTest.php`
Expected: FAIL — class `App\Services\Leaderboard\LeaderboardComposer` not found.

- [ ] **Step 3: Implement the composer**

Create `app/Services/Leaderboard/LeaderboardComposer.php`:

```php
<?php

namespace App\Services\Leaderboard;

use App\Services\Connection\SessionDuration;
use App\Services\Personality\MessagePicker;

/**
 * Turns the five board row-sets into a Discord-agnostic embed payload
 * (title, description, list of {name,value} fields). Pure/testable — the
 * notifier turns this into an actual Discord Embed. Players are rendered as
 * plain backticked gamertags; the leaderboard NEVER @-mentions (high-frequency
 * edited message — an intentional exception to the "public posts mention" rule).
 */
class LeaderboardComposer
{
    private MessagePicker $picker;

    public function __construct(?MessagePicker $picker = null)
    {
        $this->picker = $picker ?? new MessagePicker();
    }

    /**
     * @param  array{alive:array, all_time:array, kills:array, streak:array, distance:array}  $boards
     * @return array{title:string, description:string, fields:array<int, array{name:string, value:string}>}
     */
    public function compose(array $boards): array
    {
        return [
            'title' => '🏆 One Life Leaderboards',
            'description' => $this->picker->pick('leaderboard.intro', [], 'The standings, fresh off the server.'),
            'fields' => [
                ['name' => '🫀 Longest Life · Still Alive', 'value' => $this->durationRows($boards['alive'])],
                ['name' => '⏳ Longest Life · All Time', 'value' => $this->durationRows($boards['all_time'])],
                ['name' => '🔫 Most Kills', 'value' => $this->countRows($boards['kills'], 'kills')],
                ['name' => '🩸 Longest Kill Streak', 'value' => $this->countRows($boards['streak'], 'streak')],
                ['name' => '🎯 Longest Kills', 'value' => $this->distanceRows($boards['distance'])],
            ],
        ];
    }

    /** @param array<int, array{gamertag:string, seconds:int}> $rows */
    private function durationRows(array $rows): string
    {
        if ($rows === []) {
            return '*No entries yet*';
        }

        $lines = [];
        foreach ($rows as $i => $r) {
            $lines[] = ($i + 1).". `{$r['gamertag']}` — ".SessionDuration::human((int) $r['seconds']);
        }

        return implode("\n", $lines);
    }

    /** @param array<int, array{gamertag:string}> $rows */
    private function countRows(array $rows, string $key): string
    {
        if ($rows === []) {
            return '*No entries yet*';
        }

        $lines = [];
        foreach ($rows as $i => $r) {
            $n = (int) $r[$key];
            $noun = $n === 1 ? 'kill' : 'kills';
            $lines[] = ($i + 1).". `{$r['gamertag']}` — {$n} {$noun}";
        }

        return implode("\n", $lines);
    }

    /** @param array<int, array{killer:string, victim:string, weapon:?string, distance:float}> $rows */
    private function distanceRows(array $rows): string
    {
        if ($rows === []) {
            return '*No entries yet*';
        }

        $lines = [];
        foreach ($rows as $i => $r) {
            $dist = round((float) $r['distance']).'m';
            $weapon = ! empty($r['weapon']) ? " ({$r['weapon']})" : '';
            $lines[] = ($i + 1).". `{$r['killer']}`{$weapon} — {$dist} → `{$r['victim']}`";
        }

        return implode("\n", $lines);
    }
}
```

- [ ] **Step 4: Add the `leaderboard.intro` personality pool**

In `config/personality.php`, add a top-level `'leaderboard'` key (place it after the `'connection'` block, before the closing `];`). Use exactly 10 non-empty lines, no `:tokens` required:

```php
    'leaderboard' => [

        'intro' => [
            '🏆 The standings, freshly tallied. Climb or cope.',
            '🏆 Who\'s winning the one life? Scroll down and find out.',
            '🏆 Updated leaderboards — bragging rights are temporary, screenshots are forever.',
            '🏆 The numbers don\'t lie, even when you wish they would.',
            '🏆 Fresh rankings off the server. Somebody\'s mad about their spot right now.',
            '🏆 Current standings. Remember: every name here is one bullet from a reshuffle.',
            '🏆 The board just refreshed. Hope you like where you landed.',
            '🏆 Leaderboards updated. Glory to the top, condolences to everyone else.',
            '🏆 Here\'s who\'s actually good and who just talks a big game.',
            '🏆 The latest tally. Survive longer, shoot straighter, climb higher.',
        ],

    ],
```

- [ ] **Step 5: Assert the new pool in PersonalityConfigTest**

In `tests/Feature/PersonalityConfigTest.php`, add `'leaderboard.intro'` to the `$keys` array in the first test (`it('ships a complete set of non-empty personality pools')`). Add it on the line after `'death.pvp', ...` so the array reads:

```php
        'death.pvp', 'death.pvp_noweapon', 'death.suicide', 'death.environment', 'death.misc',
        'leaderboard.intro',
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/LeaderboardComposerTest.php tests/Feature/PersonalityConfigTest.php`
Expected: PASS (composer 5 passed; personality tests still green with the new pool).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Leaderboard/LeaderboardComposer.php config/personality.php tests/Unit/LeaderboardComposerTest.php tests/Feature/PersonalityConfigTest.php
git commit -m "feat: leaderboard composer + intro personality pool"
```

---

## Task 9: Notifier interface + Null + Discord post-or-edit

**Files:**
- Create: `app/Services/Leaderboard/LeaderboardNotifier.php` (interface)
- Create: `app/Services/Leaderboard/NullLeaderboardNotifier.php`
- Create: `app/Services/Leaderboard/DiscordLeaderboardNotifier.php`
- Test: `tests/Feature/NullLeaderboardNotifierTest.php`

The `DiscordLeaderboardNotifier` is the only piece with no automated test (it needs a live gateway), exactly like the other Discord notifiers — keep it thin and best-effort. `NullLeaderboardNotifier` captures the payload so the service test (Task 10) can assert against it.

- [ ] **Step 1: Write the failing Null-notifier test**

Create `tests/Feature/NullLeaderboardNotifierTest.php`:

```php
<?php

use App\Services\Leaderboard\NullLeaderboardNotifier;

it('captures the published payload and never throws', function () {
    $notifier = new NullLeaderboardNotifier();
    $payload = ['title' => 't', 'description' => 'd', 'fields' => []];

    $notifier->publish($payload);

    expect($notifier->lastPayload)->toBe($payload);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/NullLeaderboardNotifierTest.php`
Expected: FAIL — class `App\Services\Leaderboard\NullLeaderboardNotifier` not found.

- [ ] **Step 3: Create the interface**

Create `app/Services/Leaderboard/LeaderboardNotifier.php`:

```php
<?php

namespace App\Services\Leaderboard;

interface LeaderboardNotifier
{
    /**
     * Publish (post or edit) the leaderboard.
     *
     * @param  array{title:string, description:string, fields:array<int, array{name:string, value:string}>}  $payload
     */
    public function publish(array $payload): void;
}
```

- [ ] **Step 4: Create the Null notifier**

Create `app/Services/Leaderboard/NullLeaderboardNotifier.php`:

```php
<?php

namespace App\Services\Leaderboard;

class NullLeaderboardNotifier implements LeaderboardNotifier
{
    /** @var array{title:string, description:string, fields:array}|null */
    public ?array $lastPayload = null;

    public function publish(array $payload): void
    {
        $this->lastPayload = $payload;
    }
}
```

- [ ] **Step 5: Create the Discord notifier (post-or-edit)**

Create `app/Services/Leaderboard/DiscordLeaderboardNotifier.php`:

```php
<?php

namespace App\Services\Leaderboard;

use App\Services\State\BotState;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Embed\Embed;

/**
 * Posts the leaderboard embed once and edits it in place thereafter. The live
 * message id + channel are persisted in bot_state; if the stored message is
 * gone or the channel changed, a fresh message is posted and re-stored.
 * Entirely best-effort: null client, missing channel, or any failure no-ops.
 */
class DiscordLeaderboardNotifier implements LeaderboardNotifier
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
            $messageId = $this->state->get('leaderboard_message_id');
            $storedChannel = $this->state->get('leaderboard_channel_id');

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
                $this->state->set('leaderboard_message_id', (string) $message->id);
                $this->state->set('leaderboard_channel_id', (string) $this->channelId);
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

        foreach ($payload['fields'] as $field) {
            $embed->addFieldValues($field['name'], $field['value'], false);
        }

        return $embed;
    }
}
```

> **DiscordPHP API note for the implementer:** verify the installed `team-reflex/discord-php` exposes `Discord\Parts\Embed\Embed` with `setTitle`/`setDescription`/`addFieldValues($name,$value,$inline)`, `Discord\Builders\MessageBuilder::new()->addEmbed()`, `$channel->messages->fetch($id)` (returns a promise), and `$message->edit(MessageBuilder)`. Run `php -l app/Services/Leaderboard/DiscordLeaderboardNotifier.php` and grep the vendor dir (`ls vendor/team-reflex/discord-php/src/Discord/Parts/Embed/Embed.php`) to confirm method names; adjust if the version differs. This class is intentionally not unit-tested (no gateway).

- [ ] **Step 6: Lint the Discord notifier**

Run: `php -l app/Services/Leaderboard/DiscordLeaderboardNotifier.php`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Run the Null-notifier test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/NullLeaderboardNotifierTest.php`
Expected: PASS (1 passed).

- [ ] **Step 8: Commit**

```bash
git add app/Services/Leaderboard/LeaderboardNotifier.php app/Services/Leaderboard/NullLeaderboardNotifier.php app/Services/Leaderboard/DiscordLeaderboardNotifier.php tests/Feature/NullLeaderboardNotifierTest.php
git commit -m "feat: leaderboard notifier interface + null + discord post-or-edit"
```

---

## Task 10: `LeaderboardService` (periodic wiring)

**Files:**
- Create: `app/Services/LeaderboardService.php`
- Test: `tests/Feature/LeaderboardServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeaderboardServiceTest.php`:

```php
<?php

use App\Models\Life;
use App\Models\Player;
use App\Services\Leaderboard\NullLeaderboardNotifier;
use App\Services\LeaderboardService;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

it('composes all five boards into the notifier payload', function () {
    $p = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => now()->subHours(2), 'playtime_seconds' => 4000]); // open

    $notifier = new NullLeaderboardNotifier();
    (new LeaderboardService())->compose($notifier);

    expect($notifier->lastPayload['fields'])->toHaveCount(5);
    expect($notifier->lastPayload['title'])->toContain('Leaderboard');
    // Alice's open life shows on the alive board (field 0)
    expect($notifier->lastPayload['fields'][0]['value'])->toContain('Alice');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LeaderboardServiceTest.php`
Expected: FAIL — class `App\Services\LeaderboardService` not found.

- [ ] **Step 3: Implement the service**

Create `app/Services/LeaderboardService.php`:

```php
<?php

namespace App\Services;

use App\Services\Leaderboard\DiscordLeaderboardNotifier;
use App\Services\Leaderboard\LeaderboardComposer;
use App\Services\Leaderboard\LeaderboardNotifier;
use App\Services\Leaderboard\LeaderboardStatsService;
use Laracord\Laracord;
use Laracord\Services\Service;

class LeaderboardService extends Service
{
    /** Refresh cadence in seconds; overridden from config in the constructor. */
    protected int $interval = 900;

    /**
     * Allow no-arg instantiation in tests (parent ctor requires a bot).
     */
    public function __construct(?Laracord $bot = null)
    {
        if ($bot !== null) {
            parent::__construct($bot);
        }

        $this->interval = max(60, (int) config('leaderboard.refresh_minutes', 15) * 60);
    }

    public function handle(): void
    {
        if (! config('leaderboard.enabled', true)) {
            return;
        }

        try {
            $this->compose(new DiscordLeaderboardNotifier($this->discord(), config('leaderboard.channel_id')));
        } catch (\Throwable $e) {
            $this->console()->error('[leaderboard] tick failed: '.$e->getMessage());
        }
    }

    /**
     * Build the payload from the five boards and hand it to the notifier.
     * Split out so tests can inject a NullLeaderboardNotifier.
     */
    public function compose(LeaderboardNotifier $notifier): void
    {
        $top = (int) config('leaderboard.top_count', 5);
        $stats = new LeaderboardStatsService();

        $payload = (new LeaderboardComposer())->compose([
            'alive' => $stats->aliveLongestLives($top),
            'all_time' => $stats->allTimeLongestLives($top),
            'kills' => $stats->mostKills($top),
            'streak' => $stats->longestKillStreaks($top),
            'distance' => $stats->longestKills($top),
        ]);

        $notifier->publish($payload);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LeaderboardServiceTest.php`
Expected: PASS (1 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/LeaderboardService.php tests/Feature/LeaderboardServiceTest.php
git commit -m "feat: periodic LeaderboardService wiring"
```

---

## Task 11: Docs + full suite + final commit

**Files:**
- Modify: `CLAUDE.md` (architecture note + env keys)
- Modify: `.env.example` (if present — add the `LEADERBOARD_*` keys)
- Modify: `README.md` (if it documents env keys / features)

- [ ] **Step 1: Check for an `.env.example` and README env section**

Run: `ls .env.example 2>/dev/null; grep -n "BOUNTY_CHANNEL_ID" README.md .env.example 2>/dev/null`
Expected: shows where channel env keys are documented (so the new keys go in the same place).

- [ ] **Step 2: Document the env keys**

Add to whichever of `.env.example` / `README.md` documents the env block (mirror the bounty keys' style):

```
LEADERBOARD_ENABLED=true
LEADERBOARD_CHANNEL_ID=
LEADERBOARD_REFRESH_MINUTES=15
LEADERBOARD_TOP_COUNT=5
```

- [ ] **Step 3: Add a CLAUDE.md architecture note**

In `CLAUDE.md`, under the Architecture bullet list (after the "Connection announcements" bullet), add:

```markdown
- **Leaderboard** — `app/Services/Leaderboard/`: `LeaderboardStatsService` (five read-only
  boards: longest life alive/all-time, most kills, longest kill streak, longest-distance kills —
  all computed from `lives`/`game_sessions`/`players`, no kills table), `LeaderboardComposer`
  (pure → Discord-agnostic embed payload; plain backticked gamertags, **never @-mentions**),
  `DiscordLeaderboardNotifier` / `NullLeaderboardNotifier` (post-or-edit a single embed, message
  id persisted in `bot_state` as `leaderboard_message_id`/`leaderboard_channel_id`), and the
  `LivePlaytime` helper (`app/Services/Life/`) for open-life elapsed playtime. Periodic
  `LeaderboardService` (default 15m, `config/leaderboard.php`). Not gated by `BAN_DRY_RUN`
  (read-only). The all-time-life and kill-streak boards dedupe to one entry per player.
```

Also add `LEADERBOARD_CHANNEL_ID`, `LEADERBOARD_REFRESH_MINUTES`, `LEADERBOARD_TOP_COUNT`,
`LEADERBOARD_ENABLED` to the `.env` keys list in the "Common commands" section.

- [ ] **Step 4: Run the full suite**

Run: `./vendor/bin/pest`
Expected: all green (the new tests plus the full existing suite). `DEPR` markers are harmless.

- [ ] **Step 5: Verify the service auto-discovers and all new files lint**

Run: `php -l app/Services/LeaderboardService.php && php -l app/Services/Leaderboard/LeaderboardStatsService.php && php -l app/Services/Leaderboard/LeaderboardComposer.php && php laracord list >/dev/null 2>&1; echo "boot ok: $?"`
Expected: `No syntax errors detected` for each; `boot ok: 0` (the app boots — services are auto-discovered from `app/Services/`, no manual registration needed).

- [ ] **Step 6: Commit**

```bash
git add CLAUDE.md README.md .env.example
git commit -m "docs: leaderboard feature + env keys"
```

---

## Self-review notes (for the implementer)

- **Spec coverage:** all five boards (Tasks 3–7), single edited embed (Task 9), 15-min cadence + config (Tasks 1, 10), plain gamertags / no mentions (Task 8 test asserts no `<@`), Top-5 (config + per-board limit args), dedupe on all-time + streak (Tasks 4, 7), personality intro (Task 8), `BAN_DRY_RUN`-independent (read-only — no ban calls anywhere), `bot_state` message-id persistence (Task 9).
- **Type consistency:** stats row keys (`seconds`/`kills`/`streak`/`killer`/`victim`/`weapon`/`distance`) match exactly what the composer reads in Task 8. The composer payload shape (`title`/`description`/`fields[].name`/`fields[].value`) matches what the notifier consumes in Task 9 and what the service builds in Task 10.
- **Open-life playtime:** the alive board and the open-life branch of the all-time board both go through `LivePlaytime::forLife` (Task 2) — the one place that adds open-session elapsed time. Existing `PlayerStatsService` behaviour is deliberately left unchanged.
- **No new migrations:** every board reads existing columns.
```
