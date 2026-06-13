# Death Feed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Post a single cheeky, detail-rich "death feed" message to the bans channel on every live death — killer/weapon/distance for PvP, manner-of-death for non-PvP, plus the 12h ban return time — decoupled from `BAN_DRY_RUN`.

**Architecture:** A new self-contained `app/Services/DeathFeed/` concern. The pure `DeathFeedComposer` picks a personality pool and renders the message; the `DeathFeedNotifier` trio (interface / Discord / Null) follows the existing Connection/Ban notifier pattern. `DeathBanService` — which already has both the life's death detail and the issued ban — drives the post, freshness-gated and dry-run-independent. `DiscordBanNotifier` stops channel-posting `ban.death` (keeps the DM) so there's no duplicate at cutover. Weapon/distance, currently parsed but discarded, are persisted on `lives`.

**Tech Stack:** Laracord (Laravel Zero) · PHP 8.2+ · SQLite · Pest · `CarbonImmutable` · DiscordPHP.

---

## File Structure

- **Create** `database/migrations/2026_06_13_000000_add_death_detail_to_lives.php` — adds `death_weapon` + `death_distance` to `lives`.
- **Modify** `app/Models/Life.php` — add `death_distance => 'float'` cast.
- **Modify** `app/Services/Life/LifeTracker.php:51-55` — persist `death_weapon`/`death_distance`.
- **Create** `app/Services/DeathFeed/DeathFeedComposer.php` — pure: pool selection + token rendering.
- **Create** `app/Services/DeathFeed/DeathFeedNotifier.php` — interface `died(Life, Ban)`.
- **Create** `app/Services/DeathFeed/DiscordDeathFeedNotifier.php` — sends composed message to the bans channel.
- **Create** `app/Services/DeathFeed/NullDeathFeedNotifier.php` — no-op default.
- **Modify** `config/personality.php` — add `death.pvp`, `death.pvp_noweapon`, `death.suicide`, `death.environment`, `death.misc`.
- **Modify** `app/Services/Ban/DeathBanService.php` — inject notifier + freshness window; post per reconciled fresh life.
- **Modify** `app/Services/Ban/DiscordBanNotifier.php:20-40` — skip channel post for `ban.death`, keep DM.
- **Modify** `app/Services/IngestAdmService.php:47-52` — wire `DiscordDeathFeedNotifier` + window into `DeathBanService`.
- **Modify** `phpunit.xml:20` — pin `DEATH_FEED_MAX_AGE_MINUTES=10`.
- **Tests:** `tests/Feature/LifeTrackerTest.php`, `tests/Unit/DeathFeedComposerTest.php`, `tests/Feature/PersonalityConfigTest.php`, `tests/Feature/DeathBanServiceTest.php`, `tests/Feature/DiscordBanNotifierDeathTest.php`.

---

## Task 1: Persist weapon + distance on lives

**Files:**
- Create: `database/migrations/2026_06_13_000000_add_death_detail_to_lives.php`
- Modify: `app/Models/Life.php`
- Modify: `app/Services/Life/LifeTracker.php`
- Test: `tests/Feature/LifeTrackerTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/LifeTrackerTest.php` (after the existing "records cause and killer" test, ~line 56):

```php
it('records weapon and distance for a pvp death', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->death([
        'victim' => 'Alice', 'cause' => 'pvp', 'killer' => 'Bob',
        'weapon' => 'SVD', 'distance' => 243.5,
    ], at('2026-06-11T10:20:00Z'));

    $life = App\Models\Player::where('gamertag', 'Alice')->first()->lives()->latest('started_at')->first();
    expect($life->death_weapon)->toBe('SVD');
    expect($life->death_distance)->toBe(243.5);
});

it('leaves weapon and distance null for a non-pvp death', function () {
    $this->tracker->connect('Carol', at('2026-06-11T10:00:00Z'));
    $this->tracker->death(['victim' => 'Carol', 'cause' => 'drowned', 'killer' => null], at('2026-06-11T10:20:00Z'));

    $life = App\Models\Player::where('gamertag', 'Carol')->first()->lives()->latest('started_at')->first();
    expect($life->death_weapon)->toBeNull();
    expect($life->death_distance)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LifeTrackerTest.php --filter="weapon and distance"`
Expected: FAIL — `death_weapon`/`death_distance` columns don't exist (SQLity error) or values are null.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_13_000000_add_death_detail_to_lives.php`:

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
            $t->string('death_weapon')->nullable()->after('death_by_gamertag');
            $t->float('death_distance')->nullable()->after('death_weapon');
        });
    }

    public function down(): void
    {
        Schema::table('lives', function (Blueprint $t) {
            $t->dropColumn(['death_weapon', 'death_distance']);
        });
    }
};
```

- [ ] **Step 4: Add the cast on the Life model**

In `app/Models/Life.php`, add to the `$casts` array (after the `ban_issued` line):

```php
        'ban_issued' => 'boolean',
        'death_distance' => 'float',
```

- [ ] **Step 5: Persist the fields in LifeTracker**

In `app/Services/Life/LifeTracker.php`, replace the `$life->update([...])` block (lines 51-55):

```php
        $life->update([
            'ended_at' => $ts,
            'death_cause' => $death['cause'],
            'death_by_gamertag' => $death['killer'],
            'death_weapon' => $death['weapon'] ?? null,
            'death_distance' => $death['distance'] ?? null,
        ]);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/LifeTrackerTest.php`
Expected: PASS (all existing + 2 new). `RefreshDatabase` re-runs migrations on the in-memory DB.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_13_000000_add_death_detail_to_lives.php app/Models/Life.php app/Services/Life/LifeTracker.php tests/Feature/LifeTrackerTest.php
git commit -m "feat: persist weapon and distance on lives"
```

---

## Task 2: Personality pools for the death feed

**Files:**
- Modify: `config/personality.php`
- Test: `tests/Feature/PersonalityConfigTest.php`

- [ ] **Step 1: Write the failing test**

In `tests/Feature/PersonalityConfigTest.php`, add the five new keys to the `$keys` array in the "ships a complete set" test:

```php
        'connection.connected', 'connection.disconnected', 'connection.disconnected_nodur',
        'death.pvp', 'death.pvp_noweapon', 'death.suicide', 'death.environment', 'death.misc',
```

Then add a new test asserting required tokens per pool:

```php
it('death pools carry the tokens their messages need', function () {
    $required = [
        'death.pvp' => [':killer', ':victim', ':weapon', ':distance', ':expires'],
        'death.pvp_noweapon' => [':killer', ':victim', ':expires'],
        'death.suicide' => [':victim', ':expires'],
        'death.environment' => [':victim', ':expires'],
        'death.misc' => [':victim', ':cause', ':expires'],
    ];

    foreach ($required as $key => $tokens) {
        foreach (config("personality.{$key}") as $line) {
            foreach ($tokens as $token) {
                expect(str_contains($line, $token))->toBeTrue("{$key} line missing {$token}: {$line}");
            }
        }
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PersonalityConfigTest.php`
Expected: FAIL — `death.*` pools are not arrays / not ≥10 lines.

- [ ] **Step 3: Add the pools**

In `config/personality.php`, add a top-level `'death' => [ ... ]` block (sibling of `'connection'`, before the closing `];`). Every line must include the tokens listed in the test above:

```php
    'death' => [

        'pvp' => [
            '💀 :killer dropped :victim with a :weapon at :distancem. One life, well spent — back :expires.',
            '💀 :killer reached out and touched :victim — :weapon, :distancem. See you :expires.',
            '💀 :victim caught a :weapon from :killer at :distancem. That\'s the whole life gone, back :expires.',
            '💀 :killer sent :victim to the lobby with a :weapon from :distancem out. Respawn unlocks :expires.',
            '💀 :distancem was close enough for :killer\'s :weapon. RIP :victim — back :expires.',
            '💀 :killer folded :victim at :distancem with a :weapon. Benched until :expires.',
            '💀 :victim ate a :weapon round from :killer (:distancem). One and done — back :expires.',
            '💀 Clean work by :killer: :victim down at :distancem with a :weapon. Out until :expires.',
            '💀 :killer\'s :weapon found :victim across :distancem. That\'s all she wrote — back :expires.',
            '💀 :victim got beamed by :killer at :distancem (:weapon). Gone til :expires.',
        ],

        'pvp_noweapon' => [
            '💀 :killer put :victim in the dirt. That\'s the one life — back :expires.',
            '💀 :victim got sent to respawn by :killer. Out until :expires.',
            '💀 :killer ended :victim\'s run. See you :expires.',
            '💀 :victim caught hands from :killer and lost. Benched til :expires.',
            '💀 :killer dropped :victim. One life, gone — back :expires.',
            '💀 :victim\'s story ends here, courtesy of :killer. Respawn unlocks :expires.',
            '💀 :killer collected :victim. That\'s a wrap — back :expires.',
            '💀 :victim got got by :killer. Out until :expires.',
            '💀 :killer sent :victim packing. Gone til :expires.',
            '💀 RIP :victim — :killer said no. Back :expires.',
        ],

        'suicide' => [
            '💀 :victim rage-quit life itself. One life, self-served — back :expires.',
            '💀 :victim took the express route to respawn. Out until :expires.',
            '💀 :victim decided the lobby looked nicer. Benched til :expires.',
            '💀 :victim pressed the big red button on their own run. Back :expires.',
            '💀 :victim called it on their own terms. See you :expires.',
            '💀 :victim speedran the death screen, solo. Respawn unlocks :expires.',
            '💀 :victim opted out the hard way. Gone til :expires.',
            '💀 No killer needed — :victim handled it. Out until :expires.',
            '💀 :victim showed themselves the door. Back :expires.',
            '💀 :victim ended their own run. One life, used — back :expires.',
        ],

        'environment' => [
            '💀 The map itself claimed :victim. One life, gone — back :expires.',
            '💀 :victim lost a fight with the great outdoors. Out until :expires.',
            '💀 Mother Nature 1, :victim 0. Benched til :expires.',
            '💀 :victim got got by the world, no players required. Back :expires.',
            '💀 The environment filed :victim under "deceased." Respawn unlocks :expires.',
            '💀 :victim found out the hard way that the map fights back. See you :expires.',
            '💀 :victim was undone by Chernarus itself. Gone til :expires.',
            '💀 Something out there ended :victim. Out until :expires.',
            '💀 :victim met an unfriendly piece of scenery. Back :expires.',
            '💀 No killcam for :victim — the world did it. Benched until :expires.',
        ],

        'misc' => [
            '💀 :victim :cause and lost their one life. Back :expires.',
            '💀 :victim :cause — that\'s the run. Out until :expires.',
            '💀 :victim :cause. One life, spent — benched til :expires.',
            '💀 Cause of death for :victim: :cause. Respawn unlocks :expires.',
            '💀 :victim :cause and that was that. See you :expires.',
            '💀 :victim :cause. Gone til :expires.',
            '💀 Turns out :victim :cause. Back :expires.',
            '💀 :victim :cause — no take-backs. Out until :expires.',
            '💀 :victim :cause. The one life giveth, the one life taketh. Back :expires.',
            '💀 :victim :cause. Benched until :expires.',
        ],
    ],
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PersonalityConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/personality.php tests/Feature/PersonalityConfigTest.php
git commit -m "feat: death-feed personality pools"
```

---

## Task 3: DeathFeedComposer (pure pool selection + rendering)

**Files:**
- Create: `app/Services/DeathFeed/DeathFeedComposer.php`
- Test: `tests/Unit/DeathFeedComposerTest.php`

The composer takes a `Life` and the ban `expires_at`, picks the pool key from the death
cause, builds tokens (mentions via `PlayerMention`, distance rounded to whole metres,
`:cause` humanized, `:expires` as a Discord relative timestamp), and returns the rendered
string from the injected `MessagePicker`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/DeathFeedComposerTest.php`:

```php
<?php

use App\Models\Life;
use App\Models\Player;
use App\Services\DeathFeed\DeathFeedComposer;
use App\Services\Personality\MessagePicker;
use Carbon\CarbonImmutable;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

// Deterministic picker: always the first line of the pool.
function fixedPicker(): MessagePicker {
    MessagePicker::reset();
    return new MessagePicker(fn (array $pool, ?int $avoid) => 0);
}

function lifeFor(array $attrs): Life {
    $p = Player::create(['gamertag' => $attrs['tag'] ?? 'Victim', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    return Life::create(array_merge([
        'player_id' => $p->id,
        'started_at' => now()->subHour(),
        'ended_at' => now(),
        'death_cause' => 'pvp',
    ], $attrs['life'] ?? []));
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-06-13T12:00:00Z'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('selects the pvp pool and renders weapon, distance, and a relative expiry', function () {
    $life = lifeFor(['tag' => 'Victim', 'life' => [
        'death_cause' => 'pvp', 'death_by_gamertag' => 'Killer',
        'death_weapon' => 'SVD', 'death_distance' => 243.4,
    ]]);
    $expires = CarbonImmutable::now()->addHours(12);

    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life, $expires);

    // First line of death.pvp with tokens filled.
    expect($msg)->toContain('SVD');
    expect($msg)->toContain('243m');                 // distance rounded, no decimals
    expect($msg)->toContain("<t:{$expires->timestamp}:R>");
    expect($msg)->toContain('`Victim`');             // unlinked victim → backticked
    expect($msg)->toContain('`Killer`');             // unlinked killer → backticked
});

it('uses the pvp_noweapon pool when a killer is known but no weapon', function () {
    $life = lifeFor(['tag' => 'Victim', 'life' => [
        'death_cause' => 'pvp', 'death_by_gamertag' => 'Killer',
        'death_weapon' => null, 'death_distance' => null,
    ]]);

    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life, CarbonImmutable::now());

    // First line of death.pvp_noweapon.
    expect($msg)->toContain('`Killer`');
    expect($msg)->toContain('put `Victim` in the dirt');
});

it('uses the suicide pool', function () {
    $life = lifeFor(['life' => ['death_cause' => 'suicide', 'death_by_gamertag' => null]]);
    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life, CarbonImmutable::now());
    expect($msg)->toContain('rage-quit life itself');
});

it('uses the environment pool', function () {
    $life = lifeFor(['life' => ['death_cause' => 'environment', 'death_by_gamertag' => null]]);
    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life, CarbonImmutable::now());
    expect($msg)->toContain('The map itself claimed');
});

it('uses the misc pool with a humanized cause', function () {
    $life = lifeFor(['life' => ['death_cause' => 'bled_out', 'death_by_gamertag' => null]]);
    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life, CarbonImmutable::now());
    expect($msg)->toContain('bled out');             // humanized from 'bled_out'
});

it('mentions a linked victim instead of backticking', function () {
    $life = lifeFor(['tag' => 'Linked', 'life' => ['death_cause' => 'drowned']]);
    $life->player->update(['discord_user_id' => '999']);

    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life->fresh(), CarbonImmutable::now());
    expect($msg)->toContain('<@999>');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/DeathFeedComposerTest.php`
Expected: FAIL — class `App\Services\DeathFeed\DeathFeedComposer` not found.

- [ ] **Step 3: Implement the composer**

Create `app/Services/DeathFeed/DeathFeedComposer.php`:

```php
<?php

namespace App\Services\DeathFeed;

use App\Models\Life;
use App\Services\Lookup\PlayerMention;
use App\Services\Personality\MessagePicker;
use Carbon\CarbonInterface;

/**
 * Pure builder for a death-feed line: picks the personality pool from the death cause,
 * renders victim/killer via PlayerMention (public channel → mentions linked players),
 * and interpolates weapon / distance / humanized cause / relative expiry. No I/O.
 */
class DeathFeedComposer
{
    private PlayerMention $mention;

    public function __construct(private MessagePicker $picker, ?PlayerMention $mention = null)
    {
        $this->mention = $mention ?? new PlayerMention();
    }

    public function compose(Life $life, CarbonInterface $expiresAt): string
    {
        $victim = $this->mention->forPlayer($life->player, $life->player?->gamertag);
        $killer = $this->mention->for($life->death_by_gamertag);
        $expires = "<t:{$expiresAt->getTimestamp()}:R>";

        $key = $this->keyFor($life);
        $tokens = [
            ':victim' => $victim,
            ':killer' => $killer,
            ':weapon' => $life->death_weapon,
            ':distance' => $life->death_distance !== null ? (string) (int) round($life->death_distance) : '',
            ':cause' => $this->humanCause($life->death_cause),
            ':expires' => $expires,
        ];

        return $this->picker->pick($key, $tokens, "💀 {$victim} died — back {$expires}.");
    }

    private function keyFor(Life $life): string
    {
        return match ($life->death_cause) {
            'pvp' => ($life->death_weapon !== null) ? 'death.pvp' : 'death.pvp_noweapon',
            'suicide' => 'death.suicide',
            'environment' => 'death.environment',
            default => 'death.misc', // bled_out / drowned / died / unknown
        };
    }

    private function humanCause(?string $cause): string
    {
        return match ($cause) {
            'bled_out' => 'bled out',
            'drowned' => 'drowned',
            'died' => 'died',
            default => 'died',
        };
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/DeathFeedComposerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DeathFeed/DeathFeedComposer.php tests/Unit/DeathFeedComposerTest.php
git commit -m "feat: DeathFeedComposer (pure death-feed message builder)"
```

---

## Task 4: DeathFeedNotifier trio (interface, Discord, Null)

**Files:**
- Create: `app/Services/DeathFeed/DeathFeedNotifier.php`
- Create: `app/Services/DeathFeed/NullDeathFeedNotifier.php`
- Create: `app/Services/DeathFeed/DiscordDeathFeedNotifier.php`

No standalone test — the contract is exercised through `DeathBanService` in Task 5, and
the Discord sender is a thin best-effort shim (the repo does not unit-test gateway shims).
Verify with `php -l` and class-load checks.

- [ ] **Step 1: Create the interface**

Create `app/Services/DeathFeed/DeathFeedNotifier.php`:

```php
<?php

namespace App\Services\DeathFeed;

use App\Models\Ban;
use App\Models\Life;

interface DeathFeedNotifier
{
    /** Announce a death (with kill detail) and the resulting ban's return time. */
    public function died(Life $life, Ban $ban): void;
}
```

- [ ] **Step 2: Create the null notifier**

Create `app/Services/DeathFeed/NullDeathFeedNotifier.php`:

```php
<?php

namespace App\Services\DeathFeed;

use App\Models\Ban;
use App\Models\Life;

class NullDeathFeedNotifier implements DeathFeedNotifier
{
    public function died(Life $life, Ban $ban): void
    {
        // no-op
    }
}
```

- [ ] **Step 3: Create the Discord notifier**

Create `app/Services/DeathFeed/DiscordDeathFeedNotifier.php`:

```php
<?php

namespace App\Services\DeathFeed;

use App\Models\Ban;
use App\Models\Life;
use App\Services\Personality\MessagePicker;
use Carbon\CarbonImmutable;
use Discord\Discord;

/**
 * Posts the merged death-feed line (kill detail + ban return time) to the bans channel.
 * This OWNS the public death announcement (DiscordBanNotifier no longer channel-posts
 * ban.death). A public post, so DeathFeedComposer mentions linked players.
 *
 * Entirely best-effort: a null client, missing channel id, or send failure all silently
 * no-op so ingestion/ban reconciliation never breaks on a Discord hiccup.
 */
class DiscordDeathFeedNotifier implements DeathFeedNotifier
{
    private DeathFeedComposer $composer;

    public function __construct(private ?Discord $discord, private ?string $channelId, ?DeathFeedComposer $composer = null)
    {
        $this->composer = $composer ?? new DeathFeedComposer(new MessagePicker());
    }

    public function died(Life $life, Ban $ban): void
    {
        $expires = $ban->expires_at ?? CarbonImmutable::now();
        $this->toChannel($this->composer->compose($life, $expires));
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

- [ ] **Step 4: Verify the files lint and load**

Run: `php -l app/Services/DeathFeed/DeathFeedNotifier.php && php -l app/Services/DeathFeed/NullDeathFeedNotifier.php && php -l app/Services/DeathFeed/DiscordDeathFeedNotifier.php`
Expected: `No syntax errors detected` for each.

Run: `php laracord tinker --execute="echo class_exists(App\Services\DeathFeed\DiscordDeathFeedNotifier::class) && (new App\Services\DeathFeed\NullDeathFeedNotifier()) instanceof App\Services\DeathFeed\DeathFeedNotifier ? 'OK' : 'NO';"`
Expected: prints `OK`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DeathFeed/DeathFeedNotifier.php app/Services/DeathFeed/NullDeathFeedNotifier.php app/Services/DeathFeed/DiscordDeathFeedNotifier.php
git commit -m "feat: DeathFeedNotifier trio"
```

---

## Task 5: Drive the feed from DeathBanService (dry-run-independent, freshness-gated)

**Files:**
- Modify: `app/Services/Ban/DeathBanService.php`
- Test: `tests/Feature/DeathBanServiceTest.php`

`DeathBanService` gains an injected `?DeathFeedNotifier` (default Null) and a freshness
window (minutes). It captures the `Ban` returned by `BanService::ban()` and posts to the
feed when the life ended within the window. The feed fires regardless of dry-run (the row,
with `expires_at`, exists even in dry-run); stale lives are still banned but not posted.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/DeathBanServiceTest.php`. First add a recording fake at the top of the file (after the `use` imports):

```php
class RecordingDeathFeed implements App\Services\DeathFeed\DeathFeedNotifier {
    public array $posts = [];
    public function died(App\Models\Life $life, App\Models\Ban $ban): void {
        $this->posts[] = ['life' => $life->id, 'cause' => $life->death_cause, 'expires' => $ban->expires_at?->toIso8601String()];
    }
}
```

Then add these tests:

```php
it('posts to the death feed for a fresh death, even in dry run', function () {
    endedLife('Fresh', '2026-06-12T11:55:00Z'); // 5 min before test-now 12:00
    $feed = new RecordingDeathFeed();
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier(), dryRun: true);
    $service = new DeathBanService($bans, $this->state, 12, $feed, 10);

    $service->run();

    expect($feed->posts)->toHaveCount(1);
    expect($feed->posts[0]['cause'])->toBe('pvp');
    // expires = now + 12h
    expect($feed->posts[0]['expires'])->toBe(CarbonImmutable::now()->addHours(12)->toIso8601String());
});

it('does not post to the feed for a stale death but still bans it', function () {
    endedLife('Stale', '2026-06-12T11:00:00Z'); // 60 min before test-now, window is 10
    $feed = new RecordingDeathFeed();
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    $service = new DeathBanService($bans, $this->state, 12, $feed, 10);

    $n = $service->run();

    expect($n)->toBe(1);                                  // still banned
    expect($feed->posts)->toBeEmpty();                    // but not posted
});

it('does not double-post across ticks', function () {
    endedLife('Once', '2026-06-12T11:55:00Z');
    $feed = new RecordingDeathFeed();
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    $service = new DeathBanService($bans, $this->state, 12, $feed, 10);

    $service->run();
    $service->run(); // second tick: ban_issued already true, nothing reselected

    expect($feed->posts)->toHaveCount(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/DeathBanServiceTest.php --filter="death feed|stale death|double-post"`
Expected: FAIL — `DeathBanService::__construct` has no 4th/5th argument (`ArgumentCountError`/`TypeError`), feed never called.

- [ ] **Step 3: Implement the wiring**

Replace `app/Services/Ban/DeathBanService.php` in full:

```php
<?php

namespace App\Services\Ban;

use App\Models\Life;
use App\Services\DeathFeed\DeathFeedNotifier;
use App\Services\DeathFeed\NullDeathFeedNotifier;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;

class DeathBanService
{
    private DeathFeedNotifier $feed;

    public function __construct(
        private BanService $bans,
        private BotState $state,
        private int $banHours = 12,
        ?DeathFeedNotifier $feed = null,
        private int $feedMaxAgeMinutes = 10,
    ) {
        $this->feed = $feed ?? new NullDeathFeedNotifier();
    }

    /** Ban players whose lives ended after go_live and aren't yet banned. Returns count banned. */
    public function run(): int
    {
        $goLive = $this->state->get('go_live_at');
        if (! $goLive) return 0; // not live yet — never retro-ban history

        $cutoff = CarbonImmutable::parse($goLive);
        $freshAfter = CarbonImmutable::now()->subMinutes($this->feedMaxAgeMinutes);

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

            $ban = $this->bans->ban($gamertag, $this->banHours, 'One life autoban', 'auto_death');
            $life->update(['ban_issued' => true]);
            $count++;

            // Death feed posts independently of BAN_DRY_RUN (the Ban row, with its
            // expiry, exists even in dry run). Skip stale deaths to avoid a post-downtime
            // backlog flood — they are still banned above, just not announced.
            if ($life->ended_at->greaterThanOrEqualTo($freshAfter)) {
                $this->feed->died($life, $ban);
            }
        }

        return $count;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/DeathBanServiceTest.php`
Expected: PASS (all existing + 3 new). Existing tests still construct `new DeathBanService($bans, $state, 12)` — the new params default, so they pass unchanged.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ban/DeathBanService.php tests/Feature/DeathBanServiceTest.php
git commit -m "feat: post death feed from DeathBanService (dry-run independent, freshness-gated)"
```

---

## Task 6: Stop DiscordBanNotifier channel-posting ban.death (keep the DM)

**Files:**
- Modify: `app/Services/Ban/DiscordBanNotifier.php`
- Test: `tests/Feature/DiscordBanNotifierDeathTest.php`

The death feed now owns the public death announcement. To avoid a duplicate when banning
goes live, `DiscordBanNotifier::banned()` must skip the channel post for the `ban.death`
key while still sending the `ban.dm.death` DM. Manual/extended unchanged.

We make this testable without a Discord gateway by extracting a pure decision method
`postsToChannel(string $key): bool` and asserting on it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DiscordBanNotifierDeathTest.php`:

```php
<?php

use App\Services\Ban\DiscordBanNotifier;

it('does not channel-post a death ban (the death feed owns it)', function () {
    expect(DiscordBanNotifier::postsToChannel('ban.death'))->toBeFalse();
});

it('still channel-posts manual and extended bans', function () {
    expect(DiscordBanNotifier::postsToChannel('ban.manual'))->toBeTrue();
    expect(DiscordBanNotifier::postsToChannel('ban.extended'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/DiscordBanNotifierDeathTest.php`
Expected: FAIL — `Call to undefined method ...::postsToChannel()`.

- [ ] **Step 3: Implement the guard**

In `app/Services/Ban/DiscordBanNotifier.php`, add the static decision method (next to `bannedKey`, after line 71):

```php
    /**
     * Whether the ban notifier posts this key to the bans channel. The death feed
     * (DiscordDeathFeedNotifier) owns the public death announcement, so ban.death is
     * channel-suppressed here; the ban.dm.death DM is still sent. Public + static so it
     * is unit-testable without a Discord gateway.
     */
    public static function postsToChannel(string $key): bool
    {
        return $key !== 'ban.death';
    }
```

Then guard the channel call in `banned()` (replace the `$this->toChannel($this->picker->pick(...))` block at lines 26-31):

```php
        $fallbackAction = $isExtension ? 'Ban updated' : 'Player banned';
        if (self::postsToChannel($key)) {
            $this->toChannel($this->picker->pick(
                $key,
                [':who' => $who, ':reason' => $ban->reason, ':expires' => $expires],
                "🔨 **{$fallbackAction}** — {$who} · {$ban->reason} · expires {$expires}"
            ));
        }
```

(The DM block below it — lines 33-39 — is unchanged, so the death DM still sends.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/DiscordBanNotifierDeathTest.php tests/Unit/BannedKeyTest.php`
Expected: PASS (new decision tests + existing `bannedKey` routing unchanged).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ban/DiscordBanNotifier.php tests/Feature/DiscordBanNotifierDeathTest.php
git commit -m "feat: suppress ban.death channel post (death feed owns it), keep DM"
```

---

## Task 7: Wire the notifier into IngestAdmService + pin the test env

**Files:**
- Modify: `app/Services/IngestAdmService.php`
- Modify: `phpunit.xml`

- [ ] **Step 1: Pin the freshness window in phpunit.xml**

In `phpunit.xml`, add after the `CONNECTIONS_MAX_AGE_MINUTES` line (line 20):

```xml
        <env name="CONNECTIONS_MAX_AGE_MINUTES" value="10"/>
        <env name="DEATH_FEED_MAX_AGE_MINUTES" value="10"/>
```

- [ ] **Step 2: Wire the death feed into the ban reconciliation**

In `app/Services/IngestAdmService.php`, replace the `DeathBanService` construction (line 52):

```php
            $deathFeed = new \App\Services\DeathFeed\DiscordDeathFeedNotifier(
                $this->discord(),
                env('BANS_CHANNEL_ID'),
            );
            $banned = (new \App\Services\Ban\DeathBanService(
                $bans,
                $state,
                (int) env('BAN_DURATION_HOURS', 12),
                $deathFeed,
                (int) env('DEATH_FEED_MAX_AGE_MINUTES', 10),
            ))->run();
```

- [ ] **Step 3: Verify lint + full suite green**

Run: `php -l app/Services/IngestAdmService.php`
Expected: `No syntax errors detected`.

Run: `./vendor/bin/pest`
Expected: PASS — entire suite green (DEPR markers are harmless; exit 0).

- [ ] **Step 4: Commit**

```bash
git add app/Services/IngestAdmService.php phpunit.xml
git commit -m "feat: wire death feed into ingest tick"
```

---

## Task 8: Update docs

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Document the death feed**

In `CLAUDE.md`, add a bullet to the Architecture section (near the Connection announcements bullet):

```markdown
- **Death feed** — `app/Services/DeathFeed/`: `DeathFeedComposer` (pure: picks a personality
  pool from the death cause, renders victim/killer via `PlayerMention`, weapon/distance/relative
  expiry) + `DeathFeedNotifier` / `DiscordDeathFeedNotifier` / `NullDeathFeedNotifier`. Driven by
  `DeathBanService` for each reconciled live death — posts ONE merged kill/death + ban line to
  `BANS_CHANNEL_ID` (reused). **Public post → @-mentions linked players** (victim and killer).
  **Not gated by `BAN_DRY_RUN`** (the Ban row with its expiry exists even in dry run), so the feed
  is live now while real Nitrado bans/DMs still wait for cutover. Freshness-gated by
  `DEATH_FEED_MAX_AGE_MINUTES` (default 10) to suppress post-downtime backlog. `DiscordBanNotifier`
  no longer channel-posts `ban.death` (the feed owns it) but still sends the `ban.dm.death` DM.
  Weapon/distance are persisted on `lives` (`death_weapon`/`death_distance`).
```

Also add `DEATH_FEED_MAX_AGE_MINUTES` to the `.env keys` line in the Common Commands section.

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: death feed in CLAUDE.md"
```

---

## Self-Review notes

- **Spec coverage:** schema+LifeTracker (Task 1) ✓; 5 personality pools (Task 2) ✓;
  DeathFeedComposer pure core + mentions (Task 3) ✓; notifier trio (Task 4) ✓; dry-run
  independence + freshness + idempotency from DeathBanService (Task 5) ✓; ban.death channel
  de-dup keeping DM (Task 6) ✓; wiring + reused BANS_CHANNEL_ID + pinned env (Task 7) ✓; docs (Task 8) ✓.
- **Type consistency:** `DeathFeedNotifier::died(Life, Ban)` used identically in the Null/Discord
  notifiers, the recording fake, and DeathBanService. `DeathFeedComposer::compose(Life, CarbonInterface)`
  used by the Discord notifier and tests. `postsToChannel(string): bool` consistent across Task 6.
- **Distance rendering:** composer emits whole metres (`(int) round(...)`); pool lines append the
  literal `m` (`:distancem`), matching the test assertion `243m`.
- **Pool key match:** `keyFor()` returns the exact keys created in Task 2 and asserted in
  PersonalityConfigTest.
```
