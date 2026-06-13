# Current-Life Session Breakdown in `/stats` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the individual play sessions of a player's current (open) life in the `/stats` slash command, for alive players only.

**Architecture:** Add a `current_life_sessions` list (plus a `current_life_session_total` count) to `PlayerStatsService::statsFor()` — the tested service that holds all business rules. The `StatsCommand` slash command stays a thin renderer that appends a "Sessions this life" block when the list is non-empty. Session durations are humanized with the existing `SessionDuration` helper.

**Tech Stack:** Laracord (Laravel Zero + DiscordPHP), PHP 8.2+, SQLite, Pest, `Carbon\CarbonImmutable`.

---

## File Structure

- **Modify:** `app/Services/Stats/PlayerStatsService.php` — add `current_life_sessions` + `current_life_session_total` to the return value; alive-only, oldest-first, 12-most-recent cap, computed open-session duration.
- **Modify:** `app/SlashCommands/StatsCommand.php` — append the sessions block to the reply.
- **Modify (tests):** `tests/Feature/PlayerStatsServiceTest.php` — cover ordering, open-session computed duration, dead-player empty, and overflow cap.
- **Reuse (no change):** `app/Services/Connection/SessionDuration.php` (`SessionDuration::human(int $seconds): string`).

### Data model reference (already exists)

- `Life::create(['player_id' => , 'started_at' => , 'ended_at' => null, 'playtime_seconds' => ])` — open life has `ended_at = null`.
- `GameSession::create(['player_id' => , 'life_id' => , 'connected_at' => , 'disconnected_at' => null, 'duration_seconds' => null])` — open session has both `disconnected_at` and `duration_seconds` null. Casts: `connected_at`/`disconnected_at` → datetime, `duration_seconds` → integer.
- `Life` `hasMany` `GameSession` via `sessions()`.

---

## Task 1: Service returns the current-life session list

**Files:**
- Modify: `app/Services/Stats/PlayerStatsService.php`
- Test: `tests/Feature/PlayerStatsServiceTest.php`

- [ ] **Step 1: Write the failing test — alive player, ordered list with computed open-session duration**

Add to `tests/Feature/PlayerStatsServiceTest.php`:

```php
use App\Models\GameSession;
use Carbon\CarbonImmutable;

it('lists current-life sessions oldest-first with computed open duration', function () {
    CarbonImmutable::setTestNow('2026-06-13T16:58:00Z');
    $p = Player::create(['gamertag' => 'Dana', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-13T14:00:00Z', 'playtime_seconds' => 600]);

    // Closed session: 14:02 -> 15:25 = 4980s
    GameSession::create([
        'player_id' => $p->id, 'life_id' => $life->id,
        'connected_at' => '2026-06-13T14:02:00Z', 'disconnected_at' => '2026-06-13T15:25:00Z',
        'duration_seconds' => 4980, 'close_reason' => 'reboot',
    ]);
    // Open session: 16:40 -> now(16:58) = 1080s, no duration stored
    GameSession::create([
        'player_id' => $p->id, 'life_id' => $life->id,
        'connected_at' => '2026-06-13T16:40:00Z', 'disconnected_at' => null, 'duration_seconds' => null,
    ]);

    $s = (new PlayerStatsService())->statsFor('Dana');

    expect($s['current_life_session_total'])->toBe(2);
    expect($s['current_life_sessions'])->toHaveCount(2);

    // oldest-first
    expect($s['current_life_sessions'][0]['connected_at'])->toStartWith('2026-06-13T14:02:00');
    expect($s['current_life_sessions'][0]['duration_seconds'])->toBe(4980);
    expect($s['current_life_sessions'][0]['is_open'])->toBeFalse();

    expect($s['current_life_sessions'][1]['connected_at'])->toStartWith('2026-06-13T16:40:00');
    expect($s['current_life_sessions'][1]['duration_seconds'])->toBe(1080);
    expect($s['current_life_sessions'][1]['is_open'])->toBeTrue();

    CarbonImmutable::setTestNow();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PlayerStatsServiceTest.php --filter='oldest-first'`
Expected: FAIL — `current_life_session_total` / `current_life_sessions` keys are undefined.

- [ ] **Step 3: Implement the session list in the service**

In `app/Services/Stats/PlayerStatsService.php`, add `use Carbon\CarbonImmutable;` and the `App\Models\GameSession` import is not needed (we query via the relation). Update the docblock return shape and the returned array. Replace the `return [...]` block so it computes the open life once and appends the two new keys:

```php
use App\Models\Player;
use Carbon\CarbonImmutable;

class PlayerStatsService
{
    /**
     * @return array{found:bool, gamertag?:string, lives?:int, deaths?:int,
     *               playtime_seconds?:int, current_life_seconds?:?int, alive?:bool,
     *               linked?:bool, last_seen_at?:?string,
     *               current_life_sessions?:array<int, array{connected_at:string, duration_seconds:int, is_open:bool}>,
     *               current_life_session_total?:int}
     */
    public function statsFor(string $gamertag): array
    {
        $player = Player::where('gamertag', $gamertag)->withCount([
            'lives',
            'lives as deaths_count' => fn ($q) => $q->whereNotNull('ended_at'),
            'lives as open_lives_count' => fn ($q) => $q->whereNull('ended_at'),
        ])->first();

        if (! $player) {
            return ['found' => false];
        }

        $alive = $player->open_lives_count > 0;
        $openLife = $alive
            ? $player->lives()->whereNull('ended_at')->orderByDesc('started_at')->first()
            : null;

        return [
            'found' => true,
            'gamertag' => $player->gamertag,
            'lives' => (int) $player->lives_count,
            'deaths' => (int) $player->deaths_count,
            'playtime_seconds' => (int) $player->lives()->sum('playtime_seconds'),
            'current_life_seconds' => $openLife?->playtime_seconds !== null
                ? (int) $openLife->playtime_seconds
                : null,
            'alive' => $alive,
            'linked' => $player->discord_user_id !== null,
            'last_seen_at' => $player->last_seen_at?->toIso8601String(),
            'current_life_sessions' => $this->currentLifeSessions($openLife),
            'current_life_session_total' => $openLife
                ? (int) $openLife->sessions()->count()
                : 0,
        ];
    }

    /**
     * The (at most 12 most-recent) sessions of the open life, oldest-first.
     *
     * @return array<int, array{connected_at:string, duration_seconds:int, is_open:bool}>
     */
    private function currentLifeSessions(?\App\Models\Life $openLife): array
    {
        if (! $openLife) {
            return [];
        }

        // Take the 12 most recent by connected_at, then re-sort ascending for display.
        $sessions = $openLife->sessions()
            ->orderByDesc('connected_at')
            ->limit(12)
            ->get()
            ->sortBy('connected_at')
            ->values();

        return $sessions->map(function ($session) {
            $isOpen = $session->disconnected_at === null;

            // For closed sessions use the stored value. For the open session, compute
            // elapsed-so-far via raw unix-timestamp subtraction — the same idiom
            // LifeTracker uses (app/Services/Life/LifeTracker.php:81) to avoid Carbon 3's
            // signed diffInSeconds.
            $duration = $session->duration_seconds !== null
                ? (int) $session->duration_seconds
                : ($isOpen
                    ? max(0, CarbonImmutable::now()->getTimestamp() - $session->connected_at->getTimestamp())
                    : 0);

            return [
                'connected_at' => $session->connected_at->toIso8601String(),
                'duration_seconds' => (int) $duration,
                'is_open' => $isOpen,
            ];
        })->all();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/PlayerStatsServiceTest.php --filter='oldest-first'`
Expected: PASS.

- [ ] **Step 5: Run the full stats test file to confirm no regression**

Run: `./vendor/bin/pest tests/Feature/PlayerStatsServiceTest.php`
Expected: PASS (all existing assertions still green — the existing keys are unchanged).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Stats/PlayerStatsService.php tests/Feature/PlayerStatsServiceTest.php
git commit -m "feat: add current-life session list to PlayerStatsService"
```

---

## Task 2: Service — dead player returns empty list

**Files:**
- Test: `tests/Feature/PlayerStatsServiceTest.php`
- (No production change expected — this pins the alive-only contract.)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/PlayerStatsServiceTest.php`:

```php
it('returns no current-life sessions for a dead player', function () {
    $p = Player::create(['gamertag' => 'Eve', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour(), 'death_cause' => 'pvp', 'playtime_seconds' => 1800]);
    GameSession::create([
        'player_id' => $p->id, 'life_id' => $life->id,
        'connected_at' => now()->subDay(), 'disconnected_at' => now()->subDay()->addHour(),
        'duration_seconds' => 3600, 'close_reason' => 'clean',
    ]);

    $s = (new PlayerStatsService())->statsFor('Eve');
    expect($s['alive'])->toBeFalse();
    expect($s['current_life_sessions'])->toBe([]);
    expect($s['current_life_session_total'])->toBe(0);
});
```

- [ ] **Step 2: Run the test**

Run: `./vendor/bin/pest tests/Feature/PlayerStatsServiceTest.php --filter='dead player'`
Expected: PASS immediately (Task 1's implementation already returns `[]`/`0` when no open life). If it FAILS, fix the service so a dead player yields `current_life_sessions === []` and `current_life_session_total === 0`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PlayerStatsServiceTest.php
git commit -m "test: pin alive-only contract for current-life sessions"
```

---

## Task 3: Service — overflow cap at 12 most-recent

**Files:**
- Test: `tests/Feature/PlayerStatsServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/PlayerStatsServiceTest.php`:

```php
it('caps the current-life session list at the 12 most recent, ascending', function () {
    $p = Player::create(['gamertag' => 'Finn', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-13T00:00:00Z', 'playtime_seconds' => 600]);

    // 15 closed sessions, one per hour starting at 00:00.
    for ($h = 0; $h < 15; $h++) {
        $start = CarbonImmutable::parse('2026-06-13T00:00:00Z')->addHours($h);
        GameSession::create([
            'player_id' => $p->id, 'life_id' => $life->id,
            'connected_at' => $start, 'disconnected_at' => $start->addMinutes(30),
            'duration_seconds' => 1800, 'close_reason' => 'reboot',
        ]);
    }

    $s = (new PlayerStatsService())->statsFor('Finn');

    expect($s['current_life_session_total'])->toBe(15);
    expect($s['current_life_sessions'])->toHaveCount(12);
    // The shown window is the 12 most recent (hours 3..14), oldest-first.
    expect($s['current_life_sessions'][0]['connected_at'])->toStartWith('2026-06-13T03:00:00');
    expect($s['current_life_sessions'][11]['connected_at'])->toStartWith('2026-06-13T14:00:00');
});
```

- [ ] **Step 2: Run the test**

Run: `./vendor/bin/pest tests/Feature/PlayerStatsServiceTest.php --filter='caps the current-life'`
Expected: PASS (Task 1 already implements the cap). If FAIL, ensure `currentLifeSessions()` takes the 12 most-recent by `connected_at` desc then re-sorts ascending, and that `current_life_session_total` uses an unbounded `count()`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PlayerStatsServiceTest.php
git commit -m "test: cap current-life session list at 12 most recent"
```

---

## Task 4: Render the sessions block in `/stats`

**Files:**
- Modify: `app/SlashCommands/StatsCommand.php`

(The slash command is not unit-tested — no Discord gateway — per repo convention. Verify by linting and a render-only smoke check.)

- [ ] **Step 1: Add the rendering logic**

In `app/SlashCommands/StatsCommand.php`, add the import at the top with the others:

```php
use App\Services\Connection\SessionDuration;
```

Then, in `handle()`, replace the final `$this->message(...)->reply(...)` block with one that builds the base summary, appends the sessions block, and replies once:

```php
        $hours = round($s['playtime_seconds'] / 3600, 1);
        $currentLife = $s['current_life_seconds'] !== null
            ? round($s['current_life_seconds'] / 3600, 1).'h'
            : '—';
        $status = $s['alive'] ? 'alive' : 'dead';
        $linked = $s['linked'] ? 'yes' : 'no';

        $body = "**{$s['gamertag']}** — {$status}\n"
            ."• Lives: {$s['lives']}  • Deaths: {$s['deaths']}\n"
            ."• Current life: {$currentLife}  • Total playtime: {$hours}h\n"
            ."• Linked: {$linked}";

        $sessions = $s['current_life_sessions'] ?? [];
        if ($sessions !== []) {
            $body .= "\n\n**Sessions this life:**";

            $hidden = ($s['current_life_session_total'] ?? count($sessions)) - count($sessions);
            if ($hidden > 0) {
                $body .= "\n… +{$hidden} earlier sessions";
            }

            foreach ($sessions as $session) {
                $when = \Carbon\CarbonImmutable::parse($session['connected_at'])->format('M j H:i').' UTC';
                $duration = SessionDuration::human($session['duration_seconds']);
                $tag = $session['is_open'] ? ' (current)' : '';
                $body .= "\n• {$when} — {$duration}{$tag}";
            }
        }

        $this->message($body)->reply($interaction, ephemeral: true);
```

- [ ] **Step 2: Lint the file**

Run: `php -l app/SlashCommands/StatsCommand.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Smoke-test the rendering string in isolation**

Run (verifies the block builds the expected lines without a gateway):

```bash
php -r '
require "vendor/autoload.php";
use App\Services\Connection\SessionDuration;
$sessions = [
  ["connected_at" => "2026-06-13T14:02:00+00:00", "duration_seconds" => 4980, "is_open" => false],
  ["connected_at" => "2026-06-13T16:40:00+00:00", "duration_seconds" => 1080, "is_open" => true],
];
$body = "**Sessions this life:**";
foreach ($sessions as $s) {
  $when = Carbon\CarbonImmutable::parse($s["connected_at"])->format("M j H:i")." UTC";
  $tag = $s["is_open"] ? " (current)" : "";
  $body .= "\n• {$when} — ".SessionDuration::human($s["duration_seconds"]).$tag;
}
echo $body, "\n";
'
```

Expected output:

```
**Sessions this life:**
• Jun 13 14:02 UTC — 1h 23m
• Jun 13 16:40 UTC — 18m (current)
```

- [ ] **Step 4: Run the full test suite**

Run: `./vendor/bin/pest`
Expected: PASS (PHP 8.5 `DEPR` markers are harmless per CLAUDE.md; exit 0 = green).

- [ ] **Step 5: Commit**

```bash
git add app/SlashCommands/StatsCommand.php
git commit -m "feat: render current-life session breakdown in /stats"
```

---

## Self-Review notes

- **Spec coverage:** alive-only (Task 2), connected-time + duration per line (Task 4), oldest-first (Task 1/3), open session `(current)` + elapsed-so-far (Task 1, Task 4), 12-cap with `… +N earlier sessions` (Task 3 + Task 4 render), reuse `SessionDuration` (Task 4). UTC `M j H:i` format (Task 4). All covered.
- **Type consistency:** `current_life_sessions` items are `{connected_at:string, duration_seconds:int, is_open:bool}` in the service (Task 1) and consumed identically in the renderer (Task 4); `current_life_session_total:int` defined in Task 1 and read in Task 4.
- **No placeholders:** every code/test step shows full code and exact commands.
