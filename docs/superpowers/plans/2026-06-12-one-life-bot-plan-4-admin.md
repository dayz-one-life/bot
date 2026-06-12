# One Life Bot — Plan 4: Read Views & Admin Commands

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the player-facing read views (`/bans`, `/referrals`, `/players`) and the role-gated admin command set (`/adminban`, `/adminunban`, `/adminlink`, `/adminunlink`, `/addunban`, `/distribute-unbans`) on top of the verified Plans 1–3.

**Architecture:** Read/query logic and admin operations live in **testable plain services** (`PlayerStatsService`, `ReferralQueryService`, `AdminService`, plus a `previewGrant` on the existing `RewardService`). Slash commands are thin wrappers that call a service and format a reply. Admin commands are gated by a small, testable `AdminGuard` predicate against a configured `ADMIN_ROLE_ID` (and Discord-native `default_member_permissions` as a second layer).

**Tech Stack:** PHP 8.2+, Laracord v2.3.0 (Laravel Zero), Eloquent, SQLite, Pest. Slash commands via `Laracord\Commands\SlashCommand` in `app/SlashCommands/`.

**Spec:** `docs/superpowers/specs/2026-06-11-one-life-bot-design.md` — "Commands" (Section 6). Builds on Plans 1–3 (merged to `main`; tags `plan1-verified`, `plan2-complete`, `plan3-complete`).

**`.env` additions:** `ADMIN_ROLE_ID` (Discord role id permitted to run admin commands).

**No migration needed** — uses existing columns only.

**Confirmed Laracord SlashCommand API (from Plan 3, reuse as-is — see `app/SlashCommands/LinkCommand.php`):**
- Extend `Laracord\Commands\SlashCommand` in `app/SlashCommands/`; auto-discovered (no manual registration). Do NOT expect them in `php laracord list` (that's artisan only) — verify with `php -l`, a class-load/subclass check, and the suite staying green.
- `protected $name`, `protected $description`, `protected $options` (array; STRING type `3`, USER type `6`, INTEGER type `4`; `'autocomplete' => true` to enable autocomplete; `'required' => bool`).
- Read an option: `$this->value('option_name')` (returns mixed; cast).
- Invoking user id: `(string) ($interaction->member->user->id ?? $interaction->user->id)`.
- Member role ids (for the admin guard): `$interaction->member->roles` is a collection/array of role ids — confirm the exact shape from DiscordPHP `Discord\Parts\WebSockets\Interaction` / `Member` and adapt the extraction; fall back to iterating and casting to string ids.
- Reply ephemerally: `$this->message($content)->reply($interaction, ephemeral: true);`
- `$this->discord()` is available inside a SlashCommand (defined on `AbstractCommand`).
- Set a command's Discord-native permission gate via the property the base class exposes for `default_member_permissions` — confirm the property/method name in `vendor/laracord/framework/src/Commands/SlashCommand.php` (it may be `protected $permissions` or a `permissions()` method). If none exists, rely on the in-handler `AdminGuard` only and note it.

**Stack facts (Plans 1–3):** Models `Player` (gamertag, discord_user_id, referrer_id, unban_tokens, used_tokens, link_rewarded, sessions()), `Ban` (player_id, banned_at, expires_at, expired, reason, source, player()), `Life` (player_id, started_at, ended_at, death_cause, playtime_seconds), `GameSession` (connected_at). Services: `BanService(NitradoClient,BanNotifier,dryRun)::ban(gamertag,hours,reason,source)/unban(gamertag,reason)`; `RewardService(BotState)::monthlyGrant(now)`; `DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID'))`; `BotState`. Tests: Pest Feature + RefreshDatabase + Http::fake; `DEPR` markers harmless; run `./vendor/bin/pest`.

---

## File structure

```
app/
  Services/Stats/PlayerStatsService.php     # lives/playtime/deaths/status for a gamertag
  Services/Stats/ReferralQueryService.php   # a user's referrals + active-last-month count
  Services/Admin/AdminService.php           # forceLink / unlink / grantTokens
  Services/Admin/AdminGuard.php             # testable role-id authorization predicate
  Services/Tokens/RewardService.php         # MODIFY: add previewGrant()
  SlashCommands/
    BansCommand.php  ReferralsCommand.php  PlayersCommand.php
    AdminBanCommand.php  AdminUnbanCommand.php  AdminLinkCommand.php
    AdminUnlinkCommand.php  AddUnbanCommand.php  DistributeUnbansCommand.php
tests/Feature/
  PlayerStatsServiceTest.php  ReferralQueryServiceTest.php
  AdminServiceTest.php  AdminGuardTest.php  RewardServicePreviewTest.php
```

---

## Task 1: PlayerStatsService

Aggregates a player's lives, total playtime, deaths, and current status for `/players`.

**Files:** Create `app/Services/Stats/PlayerStatsService.php`. Test: `tests/Feature/PlayerStatsServiceTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/PlayerStatsServiceTest.php`:

```php
<?php

use App\Models\Life;
use App\Models\Player;
use App\Services\Stats\PlayerStatsService;

beforeEach(fn () => $this->svc = new PlayerStatsService());

it('reports not found for an unknown gamertag', function () {
    expect($this->svc->statsFor('Nobody')['found'])->toBeFalse();
});

it('aggregates lives, playtime, deaths, and alive status', function () {
    $p = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    // one ended life (a death) with 1800s, one open life with 600s
    Life::create(['player_id' => $p->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour(), 'death_cause' => 'pvp', 'playtime_seconds' => 1800]);
    Life::create(['player_id' => $p->id, 'started_at' => now(), 'playtime_seconds' => 600]);

    $s = $this->svc->statsFor('Alice');
    expect($s['found'])->toBeTrue();
    expect($s['lives'])->toBe(2);
    expect($s['deaths'])->toBe(1);
    expect($s['playtime_seconds'])->toBe(2400);
    expect($s['alive'])->toBeTrue();
    expect($s['linked'])->toBeFalse();
});

it('reports linked status', function () {
    Player::create(['gamertag' => 'Bob', 'discord_user_id' => 'd-bob', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    expect($this->svc->statsFor('Bob')['linked'])->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails** — `./vendor/bin/pest tests/Feature/PlayerStatsServiceTest.php` → FAIL (class not found).

- [ ] **Step 3: Implement** — `app/Services/Stats/PlayerStatsService.php`:

```php
<?php

namespace App\Services\Stats;

use App\Models\Player;

class PlayerStatsService
{
    /**
     * @return array{found:bool, gamertag?:string, lives?:int, deaths?:int,
     *               playtime_seconds?:int, alive?:bool, linked?:bool, last_seen_at?:?string}
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

        return [
            'found' => true,
            'gamertag' => $player->gamertag,
            'lives' => (int) $player->lives_count,
            'deaths' => (int) $player->deaths_count,
            'playtime_seconds' => (int) $player->lives()->sum('playtime_seconds'),
            'alive' => $player->open_lives_count > 0,
            'linked' => $player->discord_user_id !== null,
            'last_seen_at' => $player->last_seen_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Run to verify it passes** — PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: PlayerStatsService aggregates lives/playtime/deaths for a gamertag"
```

---

## Task 2: ReferralQueryService

Lists the players a user referred and how many were active in the previous calendar month (the bonus rule), for `/referrals`.

**Files:** Create `app/Services/Stats/ReferralQueryService.php`. Test: `tests/Feature/ReferralQueryServiceTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/ReferralQueryServiceTest.php`:

```php
<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Stats\ReferralQueryService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-10T12:00:00Z'); // previous calendar month = June 2026
    $this->svc = new ReferralQueryService();
});
afterEach(fn () => CarbonImmutable::setTestNow());

function refPlayer(string $tag, string $discordId): Player {
    return Player::create(['gamertag' => $tag, 'discord_user_id' => $discordId, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}
function connectedAt(Player $p, string $iso): void {
    $life = Life::create(['player_id' => $p->id, 'started_at' => $iso]);
    GameSession::create(['player_id' => $p->id, 'life_id' => $life->id, 'connected_at' => $iso]);
}

it('reports not linked for an unknown user', function () {
    expect($this->svc->forDiscordUser('d-none')['linked'])->toBeFalse();
});

it('lists referrals and counts those active in the previous month', function () {
    $me = refPlayer('Me', 'd-me');
    $a = refPlayer('A', 'd-a'); $a->update(['referrer_id' => $me->id]);
    $b = refPlayer('B', 'd-b'); $b->update(['referrer_id' => $me->id]);
    connectedAt($a, '2026-06-20T12:00:00Z'); // active in June
    connectedAt($b, '2026-05-01T12:00:00Z'); // not active in June

    $r = $this->svc->forDiscordUser('d-me');
    expect($r['linked'])->toBeTrue();
    expect($r['referrals'])->toHaveCount(2);
    expect($r['activeCount'])->toBe(1);
    $byTag = collect($r['referrals'])->keyBy('gamertag');
    expect($byTag['A']['active'])->toBeTrue();
    expect($byTag['B']['active'])->toBeFalse();
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL (class not found).

- [ ] **Step 3: Implement** — `app/Services/Stats/ReferralQueryService.php`:

```php
<?php

namespace App\Services\Stats;

use App\Models\Player;
use Carbon\CarbonImmutable;

class ReferralQueryService
{
    /**
     * @return array{linked:bool, referrals?:array<int,array{gamertag:string,active:bool}>, activeCount?:int}
     */
    public function forDiscordUser(string $discordUserId): array
    {
        $player = Player::where('discord_user_id', $discordUserId)->first();
        if (! $player) {
            return ['linked' => false];
        }

        $now = CarbonImmutable::now();
        $prevStart = $now->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $now->startOfMonth();

        $referrals = Player::where('referrer_id', $player->id)
            ->withCount(['sessions as active_count' => fn ($q) => $q
                ->where('connected_at', '>=', $prevStart)->where('connected_at', '<', $prevEnd)])
            ->orderBy('gamertag')
            ->get()
            ->map(fn (Player $r) => ['gamertag' => $r->gamertag, 'active' => $r->active_count > 0])
            ->all();

        return [
            'linked' => true,
            'referrals' => $referrals,
            'activeCount' => collect($referrals)->where('active', true)->count(),
        ];
    }
}
```

- [ ] **Step 4: Run to verify it passes** — PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: ReferralQueryService lists referrals + previous-month active count"
```

---

## Task 3: RewardService::previewGrant

Computes what the monthly grant WOULD distribute, without writing anything — for `/distribute-unbans` preview.

**Files:** Modify `app/Services/Tokens/RewardService.php`. Test: `tests/Feature/RewardServicePreviewTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/RewardServicePreviewTest.php`:

```php
<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\State\BotState;
use App\Services\Tokens\RewardService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-01T00:30:00Z');
    $this->svc = new RewardService(new BotState());
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('previews the grant without writing tokens or the month key', function () {
    $ref = Player::create(['gamertag' => 'Ref', 'discord_user_id' => 'd-ref', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $a = Player::create(['gamertag' => 'A', 'discord_user_id' => 'd-a', 'referrer_id' => $ref->id, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $a->id, 'started_at' => '2026-06-10T00:00:00Z']);
    GameSession::create(['player_id' => $a->id, 'life_id' => $life->id, 'connected_at' => '2026-06-10T00:00:00Z']);

    $preview = $this->svc->previewGrant(CarbonImmutable::now());

    expect($preview['granted'])->toBe(3); // Ref: 1+1, A: 1
    expect(Player::where('gamertag', 'Ref')->first()->unban_tokens)->toBe(0); // no writes
    expect((new BotState())->get('last_reward_month'))->toBeNull();           // month key untouched
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL ("undefined method previewGrant").

- [ ] **Step 3: Add `previewGrant` to `RewardService`** (alongside `monthlyGrant`; factor the per-player computation so the two share it):

```php
    /**
     * Compute what monthlyGrant would distribute, WITHOUT writing anything.
     * @return array{granted:int, players:array<int,array{discord_user_id:?string,gamertag:string,amount:int}>}
     */
    public function previewGrant(CarbonImmutable $now): array
    {
        $prevStart = $now->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $now->startOfMonth();

        $breakdown = [];
        $total = 0;
        foreach (Player::whereNotNull('discord_user_id')->get() as $player) {
            $activeReferrals = Player::where('referrer_id', $player->id)
                ->whereHas('sessions', fn ($q) => $q->where('connected_at', '>=', $prevStart)->where('connected_at', '<', $prevEnd))
                ->count();
            $amount = 1 + $activeReferrals;
            $total += $amount;
            $breakdown[] = ['discord_user_id' => $player->discord_user_id, 'gamertag' => $player->gamertag, 'amount' => $amount];
        }

        return ['granted' => $total, 'players' => $breakdown];
    }
```

> Optional cleanup: `monthlyGrant`'s per-player loop computes the same amounts — if you extract a private helper `amountsFor(prevStart, prevEnd): array`, have BOTH `monthlyGrant` and `previewGrant` call it (DRY). Keep `monthlyGrant`'s behavior (writes + month key) identical; only refactor the shared computation. Ensure the existing `RewardServiceTest` stays green.

- [ ] **Step 4: Run to verify it passes** — `./vendor/bin/pest tests/Feature/RewardServicePreviewTest.php tests/Feature/RewardServiceTest.php` → PASS (both).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: RewardService::previewGrant computes monthly grant without writing"
```

---

## Task 4: AdminGuard

A tiny, testable authorization predicate: is a member (by their role ids) allowed to run admin commands?

**Files:** Create `app/Services/Admin/AdminGuard.php`. Test: `tests/Feature/AdminGuardTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/AdminGuardTest.php`:

```php
<?php

use App\Services\Admin\AdminGuard;

beforeEach(fn () => $this->guard = new AdminGuard());

it('authorizes a member holding the configured admin role', function () {
    expect($this->guard->isAuthorized(['111', '222'], '222'))->toBeTrue();
});

it('denies a member without the admin role', function () {
    expect($this->guard->isAuthorized(['111'], '222'))->toBeFalse();
});

it('denies when no admin role is configured (fail closed)', function () {
    expect($this->guard->isAuthorized(['111', '222'], null))->toBeFalse();
    expect($this->guard->isAuthorized(['111'], ''))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL (class not found).

- [ ] **Step 3: Implement** — `app/Services/Admin/AdminGuard.php`:

```php
<?php

namespace App\Services\Admin;

class AdminGuard
{
    /**
     * @param array<int,string> $memberRoleIds role ids the invoking member holds
     */
    public function isAuthorized(array $memberRoleIds, ?string $adminRoleId): bool
    {
        if ($adminRoleId === null || $adminRoleId === '') {
            return false; // no admin role configured -> deny (fail closed)
        }
        return in_array((string) $adminRoleId, array_map('strval', $memberRoleIds), true);
    }
}
```

- [ ] **Step 4: Run to verify it passes** — PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: AdminGuard role-id authorization predicate (fail closed)"
```

---

## Task 5: AdminService

Force-link / unlink / grant-tokens operations behind the admin commands.

**Files:** Create `app/Services/Admin/AdminService.php`. Test: `tests/Feature/AdminServiceTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/AdminServiceTest.php`:

```php
<?php

use App\Models\Player;
use App\Services\Admin\AdminService;

beforeEach(fn () => $this->svc = new AdminService());

function seen(string $tag): Player {
    return Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('force-links a discord user to a gamertag, clearing any prior links', function () {
    $old = seen('OldTag'); $old->update(['discord_user_id' => 'd-1']); // user d-1 currently on OldTag
    seen('NewTag');

    $r = $this->svc->forceLink('d-1', 'NewTag');

    expect($r['status'])->toBe('linked');
    expect(Player::where('gamertag', 'NewTag')->first()->discord_user_id)->toBe('d-1');
    expect(Player::where('gamertag', 'OldTag')->first()->discord_user_id)->toBeNull(); // prior link cleared
});

it('rejects force-link for an unknown gamertag', function () {
    expect($this->svc->forceLink('d-1', 'Ghost')['status'])->toBe('gamertag_not_found');
});

it('unlinks a discord user', function () {
    $p = seen('Tag'); $p->update(['discord_user_id' => 'd-1']);
    expect($this->svc->unlink('d-1')['status'])->toBe('unlinked');
    expect(Player::where('gamertag', 'Tag')->first()->discord_user_id)->toBeNull();
});

it('reports nothing to unlink for an unlinked user', function () {
    expect($this->svc->unlink('d-none')['status'])->toBe('not_linked');
});

it('grants tokens to a gamertag and clamps at zero', function () {
    $p = seen('Tag'); $p->update(['unban_tokens' => 2]);
    expect($this->svc->grantTokens('Tag', 3)['balance'])->toBe(5);
    expect($this->svc->grantTokens('Tag', -100)['balance'])->toBe(0); // clamp, no underflow
});

it('reports not found when granting to an unknown gamertag', function () {
    expect($this->svc->grantTokens('Ghost', 1)['status'])->toBe('gamertag_not_found');
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL (class not found).

- [ ] **Step 3: Implement** — `app/Services/Admin/AdminService.php`:

```php
<?php

namespace App\Services\Admin;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

class AdminService
{
    /** @return array{status:string, gamertag?:string} — linked | gamertag_not_found */
    public function forceLink(string $discordUserId, string $gamertag): array
    {
        $player = Player::where('gamertag', $gamertag)->first();
        if (! $player) {
            return ['status' => 'gamertag_not_found'];
        }

        return DB::transaction(function () use ($player, $discordUserId) {
            // Clear any other gamertag currently held by this discord user (1:1 invariant).
            Player::where('discord_user_id', $discordUserId)
                ->where('id', '!=', $player->id)
                ->update(['discord_user_id' => null]);

            $player->discord_user_id = $discordUserId;
            $player->save();

            return ['status' => 'linked', 'gamertag' => $player->gamertag];
        });
    }

    /** @return array{status:string, gamertag?:string} — unlinked | not_linked */
    public function unlink(string $discordUserId): array
    {
        $player = Player::where('discord_user_id', $discordUserId)->first();
        if (! $player) {
            return ['status' => 'not_linked'];
        }
        $player->discord_user_id = null;
        $player->save();

        return ['status' => 'unlinked', 'gamertag' => $player->gamertag];
    }

    /** @return array{status:string, balance?:int} — granted | gamertag_not_found */
    public function grantTokens(string $gamertag, int $amount): array
    {
        $player = Player::where('gamertag', $gamertag)->first();
        if (! $player) {
            return ['status' => 'gamertag_not_found'];
        }
        $player->unban_tokens = max(0, $player->unban_tokens + $amount);
        $player->save();

        return ['status' => 'granted', 'balance' => $player->unban_tokens];
    }
}
```

- [ ] **Step 4: Run to verify it passes** — PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: AdminService force-link / unlink / grant-tokens"
```

---

## Task 6: Read-view slash commands — /players, /bans, /referrals

Thin wrappers over the query services. Follow `app/SlashCommands/LinkCommand.php` for the confirmed API.

**Files:** Create `app/SlashCommands/PlayersCommand.php`, `BansCommand.php`, `ReferralsCommand.php`.

- [ ] **Step 1: `PlayersCommand`** (`/players gamertag`):

```php
<?php

namespace App\SlashCommands;

use App\Models\Player;
use App\Services\Stats\PlayerStatsService;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class PlayersCommand extends SlashCommand
{
    protected $name = 'players';
    protected $description = 'Show a player\'s lives, playtime, and deaths.';

    protected $options = [
        ['name' => 'gamertag', 'description' => 'The gamertag to look up', 'type' => 3, 'required' => true, 'autocomplete' => true],
    ];

    public function handle($interaction): void
    {
        $s = (new PlayerStatsService())->statsFor((string) $this->value('gamertag'));
        if (! ($s['found'] ?? false)) {
            $this->message('⚠️ No player found with that gamertag.')->reply($interaction, ephemeral: true);
            return;
        }
        $hours = round($s['playtime_seconds'] / 3600, 1);
        $status = $s['alive'] ? 'alive' : 'dead';
        $linked = $s['linked'] ? 'yes' : 'no';
        $this->message(
            "**{$s['gamertag']}** — {$status}\n"
            ."• Lives: {$s['lives']}  • Deaths: {$s['deaths']}\n"
            ."• Total playtime: {$hours}h  • Linked: {$linked}"
        )->reply($interaction, ephemeral: true);
    }

    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => Player::query()
                ->when($value, fn ($q) => $q->where('gamertag', 'like', "%{$value}%"))
                ->orderByDesc('last_seen_at')->limit(25)->pluck('gamertag'),
        ];
    }
}
```

- [ ] **Step 2: `BansCommand`** (`/bans [player]` — defaults to caller; shows active ban + recent history):

```php
<?php

namespace App\SlashCommands;

use App\Models\Ban;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class BansCommand extends SlashCommand
{
    protected $name = 'bans';
    protected $description = 'Show ban status and recent history for a player (yours by default).';

    protected $options = [
        ['name' => 'player', 'description' => 'Gamertag (defaults to you)', 'type' => 3, 'required' => false, 'autocomplete' => true],
    ];

    public function handle($interaction): void
    {
        $tag = $this->value('player');
        $discordId = (string) ($interaction->member->user->id ?? $interaction->user->id);
        $player = $tag
            ? Player::where('gamertag', (string) $tag)->first()
            : Player::where('discord_user_id', $discordId)->first();

        if (! $player) {
            $this->message($tag ? '⚠️ No player found with that gamertag.' : '⚠️ Link your gamertag first with `/link`.')
                ->reply($interaction, ephemeral: true);
            return;
        }

        $now = CarbonImmutable::now();
        $active = Ban::where('player_id', $player->id)->where('expired', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->latest('banned_at')->first();
        $total = Ban::where('player_id', $player->id)->count();

        if ($active) {
            $when = $active->expires_at ? "expires <t:{$active->expires_at->timestamp}:R>" : 'permanent';
            $msg = "🔨 **{$player->gamertag}** is currently banned ({$active->reason}, {$when}). Total bans: {$total}.";
        } else {
            $msg = "✅ **{$player->gamertag}** is not banned. Total bans on record: {$total}.";
        }
        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    public function autocomplete(): array
    {
        return [
            'player' => fn (Interaction $i, $value) => Player::query()
                ->when($value, fn ($q) => $q->where('gamertag', 'like', "%{$value}%"))
                ->orderByDesc('last_seen_at')->limit(25)->pluck('gamertag'),
        ];
    }
}
```

- [ ] **Step 3: `ReferralsCommand`** (`/referrals` — caller's referrals):

```php
<?php

namespace App\SlashCommands;

use App\Services\Stats\ReferralQueryService;
use Laracord\Commands\SlashCommand;

class ReferralsCommand extends SlashCommand
{
    protected $name = 'referrals';
    protected $description = 'Show the players you referred and how many were active last month.';

    public function handle($interaction): void
    {
        $discordId = (string) ($interaction->member->user->id ?? $interaction->user->id);
        $r = (new ReferralQueryService())->forDiscordUser($discordId);
        if (! $r['linked']) {
            $this->message('⚠️ Link your gamertag first with `/link`.')->reply($interaction, ephemeral: true);
            return;
        }
        if (empty($r['referrals'])) {
            $this->message('You haven\'t referred anyone yet. Share your gamertag so new players can set you as their referrer!')->reply($interaction, ephemeral: true);
            return;
        }
        $lines = array_map(fn ($x) => ($x['active'] ? '🟢' : '⚪️')." {$x['gamertag']}", $r['referrals']);
        $this->message(
            "**Your referrals** ({$r['activeCount']} active last month → +{$r['activeCount']} next grant):\n".implode("\n", $lines)
        )->reply($interaction, ephemeral: true);
    }
}
```

- [ ] **Step 4: Verify** — `php -l` all three; class-load/subclass check; `./vendor/bin/pest` green.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: /players, /bans, /referrals read-view slash commands"
```

---

## Task 7: Admin slash commands

Six role-gated commands. Each handler first checks `AdminGuard` against the member's role ids and `ADMIN_ROLE_ID`; if unauthorized, replies ephemerally and returns. Then calls the relevant service.

**Files:** Create `app/SlashCommands/{AdminBan,AdminUnban,AdminLink,AdminUnlink,AddUnban,DistributeUnbans}Command.php`.

- [ ] **Step 1: Confirm the member-roles shape + permission gate.** From DiscordPHP, determine how to read the invoking member's role ids inside `handle()` (e.g. `$interaction->member->roles` → a collection of `Role` parts or ids). Write a small private helper used by every admin command:
```php
    private function memberRoleIds($interaction): array
    {
        $roles = $interaction->member->roles ?? [];
        $ids = [];
        foreach ($roles as $role) {
            $ids[] = (string) (is_object($role) ? ($role->id ?? $role) : $role);
        }
        return $ids;
    }

    private function denyIfNotAdmin($interaction): bool
    {
        if ((new \App\Services\Admin\AdminGuard())->isAuthorized($this->memberRoleIds($interaction), env('ADMIN_ROLE_ID'))) {
            return false;
        }
        $this->message('⛔ You are not authorized to use this command.')->reply($interaction, ephemeral: true);
        return true;
    }
```
Put this helper on each admin command (or a shared trait `App\SlashCommands\Concerns\GuardsAdmin` — preferred, create it once and `use` it). Also set the Discord-native permission gate property if the base class supports it (confirmed in the API step) so the commands are hidden from non-admins; if not supported, the in-handler guard is sufficient.

- [ ] **Step 2: `AdminBanCommand`** (`/adminban gamertag [hours] [reason]`): build a real `BanService` (with `DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID'))`, honoring `BAN_DRY_RUN`); call `ban($gamertag, $hours ?? (int) env('BAN_DURATION_HOURS', 12), $reason ?? 'Manual ban', 'manual')`. Options: `gamertag` (STRING, required, autocomplete = all gamertags), `hours` (INTEGER type 4, optional; `0` = permanent), `reason` (STRING, optional). Reply with a confirmation incl. expiry. Guard first.

- [ ] **Step 3: `AdminUnbanCommand`** (`/adminunban gamertag`): build `BanService`; call `unban($gamertag, 'Manual unban')`. Option `gamertag` (autocomplete = currently-banned gamertags). Guard first.

- [ ] **Step 4: `AdminLinkCommand`** (`/adminlink user gamertag`): option `user` (USER type 6, required) + `gamertag` (STRING, required, autocomplete). Read the target user id from the USER option (`$this->value('user')` returns the user id; confirm), call `AdminService::forceLink($userId, $gamertag)`. Reply per status. Guard first.

- [ ] **Step 5: `AdminUnlinkCommand`** (`/adminunlink user`): option `user` (USER, required); `AdminService::unlink($userId)`. Guard first.

- [ ] **Step 6: `AddUnbanCommand`** (`/addunban gamertag amount`): options `gamertag` (STRING, required, autocomplete) + `amount` (INTEGER, required); `AdminService::grantTokens($gamertag, $amount)`; reply with new balance. Guard first.

- [ ] **Step 7: `DistributeUnbansCommand`** (`/distribute-unbans [confirm]`): option `confirm` (BOOLEAN type 5, optional, default false). If not confirm → `RewardService::previewGrant(now)` and reply with the would-be total + top recipients (no writes). If confirm → `RewardService::monthlyGrant(now)` (idempotent per month) and reply with granted total. Guard first.

Reference shape for one admin command (`AddUnbanCommand`) — adapt the rest analogously, reusing the `GuardsAdmin` helper:
```php
<?php

namespace App\SlashCommands;

use App\Models\Player;
use App\Services\Admin\AdminService;
use App\SlashCommands\Concerns\GuardsAdmin;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class AddUnbanCommand extends SlashCommand
{
    use GuardsAdmin;

    protected $name = 'addunban';
    protected $description = 'Admin: grant (or remove) unban tokens for a gamertag.';

    protected $options = [
        ['name' => 'gamertag', 'description' => 'Target gamertag', 'type' => 3, 'required' => true, 'autocomplete' => true],
        ['name' => 'amount', 'description' => 'Tokens to add (negative to remove)', 'type' => 4, 'required' => true],
    ];

    public function handle($interaction): void
    {
        if ($this->denyIfNotAdmin($interaction)) {
            return;
        }
        $r = (new AdminService())->grantTokens((string) $this->value('gamertag'), (int) $this->value('amount'));
        $msg = $r['status'] === 'granted'
            ? "✅ Updated **{$this->value('gamertag')}** — new balance: **{$r['balance']}** token(s)."
            : '⚠️ No player found with that gamertag.';
        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => Player::query()
                ->when($value, fn ($q) => $q->where('gamertag', 'like', "%{$value}%"))
                ->orderByDesc('last_seen_at')->limit(25)->pluck('gamertag'),
        ];
    }
}
```

- [ ] **Step 8: Add `ADMIN_ROLE_ID=` to `.env.example`** (and your local `.env`).

- [ ] **Step 9: Verify** — `php -l` all; class-load/subclass check for all six; `./vendor/bin/pest` green. Confirm the `GuardsAdmin` trait and `AdminGuard` deny path work via a quick tinker check of `(new App\Services\Admin\AdminGuard())->isAuthorized([...], env('ADMIN_ROLE_ID'))`.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: role-gated admin slash commands (ban/unban/link/unlink/addunban/distribute)"
```

---

## Task 8: Verification

- [ ] **Step 1: Full suite** — `./vendor/bin/pest` (all green; the new services are covered by Tasks 1–5 tests).

- [ ] **Step 2: Class-load + subclass check** for all new slash commands (Players, Bans, Referrals, AdminBan, AdminUnban, AdminLink, AdminUnlink, AddUnban, DistributeUnbans) — each `class_exists` and `is_subclass_of(..., Laracord\Commands\SlashCommand::class)`.

- [ ] **Step 3: tinker spot-checks** (no Discord needed):
  - `(new App\Services\Stats\PlayerStatsService())->statsFor('<a real gamertag>')` against the real DB → sane lives/playtime/deaths.
  - `(new App\Services\Admin\AdminService())->grantTokens('<gamertag>', 1)` then `-1` → balance round-trips; clean up.
  - `(new App\Services\Admin\AdminGuard())->isAuthorized(['x'], null)` → false (fail-closed).

- [ ] **Step 4: Commit baseline marker**

```bash
git commit --allow-empty -m "chore: Plan 4 read views + admin commands complete and tested"
```

---

## Self-review notes (coverage against spec Section 6)

- **Player read views:** `/players` (Task 1, 6), `/bans` (Task 6), `/referrals` (Task 2, 6).
- **Admin commands:** `/adminban` + `/adminunban` (Task 7, via existing `BanService`), `/adminlink` + `/adminunlink` (Tasks 5, 7), `/addunban` (Tasks 5, 7), `/distribute-unbans` (Tasks 3, 7).
- **Authorization:** `AdminGuard` (Task 4) + per-command guard (Task 7), `ADMIN_ROLE_ID`, fail-closed when unset; plus Discord-native permission gate if the framework supports it.

**Testing posture:** all business logic (stats, referrals, admin ops, preview, guard) is unit-tested; slash commands are thin wrappers verified by class-load + the service tests (Discord interactions aren't unit-tested, consistent with Plan 3). Once deployed, smoke-test each command in Discord.

**Completeness:** With Plan 4, the full focused-core command surface from the spec (Section 6) is implemented. Remaining real-world step: the live banning cutover (Plan 2 ops toggle) whenever you choose to arm it.
```
