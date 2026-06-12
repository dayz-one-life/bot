# One Life Bot — Plan 3: Linking & Unban-Token Economy

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let players link their Discord to a gamertag (with autocomplete), earn unban tokens (one-time on link, monthly, and per active referral), and spend tokens to lift a temporary ban for themselves or another player.

**Architecture:** All economy rules live in **testable plain services** (`LinkService`, `ReferrerService`, `RewardService`, `RedemptionService`) that operate on Eloquent models — fully unit-tested. **Slash commands** in `app/SlashCommands/` are thin wrappers that parse the interaction, call a service, and reply (with autocomplete sourced from the DB). A `MonthlyRewardService` (Laracord `Service`) triggers the monthly grant when the calendar month rolls over and DMs recipients.

**Tech Stack:** PHP 8.2+, Laracord v2.3.0 (Laravel Zero), Eloquent, SQLite, Pest. DiscordPHP slash commands via `Laracord\Commands\SlashCommand`.

**Spec:** `docs/superpowers/specs/2026-06-11-one-life-bot-design.md` — "Token economy" (Section 5) + "Commands" (Section 6). Builds on Plans 1–2 (merged to `main`, tags `plan1-verified`, `plan2-complete`).

**Scope:** Core loop only — `/link`, `/referrer`, `/unban`, `/unbans`, monthly rewards. **Deferred to Plan 4:** read views (`/bans`, `/referrals`, `/players`) and admin commands (`/adminban`, `/adminunban`, `/adminlink`, `/adminunlink`, `/addunban`, `/distribute-unbans`).

**No migration needed** — `players` already has `discord_user_id` (unique, nullable), `referrer_id` (self-FK), `unban_tokens`, `used_tokens`, `link_rewarded` from Plan 1.

**Rules (from spec, locked during brainstorming):**
- One gamertag per Discord user (1:1; `discord_user_id` is unique).
- A gamertag is linkable only if it's been seen in the logs (a `players` row exists with `discord_user_id = null`).
- Link reward: `+1` token the FIRST time a user links (guarded by `link_rewarded`); relink grants nothing.
- Referrer: set at link or later via `/referrer`, only if none set (then locked); no self-referral; referrer must be a linked player. Referrer's reward is the monthly per-active-referral bonus only (no instant token).
- Monthly: each linked player gets `+1` base, plus `+1` per referred player who was **active** (≥1 connect) in the **previous calendar month**. Idempotent per month via `bot_state.last_reward_month`.
- Redemption (`/unban`): spender must be linked with ≥1 token; target = self or another gamertag with an active **temporary** ban; permanent bans rejected; token deducted only on success (uses `BanService::unban`).

**Stack facts (from Plans 1–2):** Periodic `Service` = `Laracord\Services\Service` (override `__construct(?Laracord $bot = null)` to allow no-arg test instantiation, calling `parent::__construct($bot)` only when `$bot` is non-null; `$interval` seconds; `$this->discord()`/`$this->console()`). Slash commands extend `Laracord\Commands\SlashCommand` in `app/SlashCommands/`, with `$name`, `$description`, `$options` (or `options()`), `handle($interaction)`, and `autocomplete()` returning `['option.name' => fn (Interaction $i, $value) => ...]`. `BanService::unban(gamertag, reason)` exists. Tests: Pest Feature + `RefreshDatabase`; `DEPR` markers harmless; run `./vendor/bin/pest`.

---

## File structure

```
app/
  Services/Tokens/
    LinkService.php          # link a discord user to a gamertag (+ optional referrer + reward)
    ReferrerService.php      # set a referrer later (set-once)
    RewardService.php        # monthly grant: +1 + active referrals; idempotent per month
    RedemptionService.php    # spend a token to lift a temporary ban
  Services/MonthlyRewardService.php   # Laracord Service: month-rollover -> RewardService + DMs
  SlashCommands/
    LinkCommand.php          # /link gamertag [referrer]   (autocomplete)
    ReferrerCommand.php      # /referrer gamertag          (autocomplete)
    UnbanCommand.php         # /unban [player]             (autocomplete)
    UnbansCommand.php        # /unbans                     (balance)
tests/
  Feature/LinkServiceTest.php
  Feature/ReferrerServiceTest.php
  Feature/RewardServiceTest.php
  Feature/RedemptionServiceTest.php
```

---

## Task 1: LinkService

Links a Discord user to a seen-but-unlinked gamertag; optionally sets a referrer; grants the one-time link token. Returns a result describing what happened.

**Files:** Create `app/Services/Tokens/LinkService.php`. Test: `tests/Feature/LinkServiceTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/LinkServiceTest.php`:

```php
<?php

use App\Models\Player;
use App\Services\Tokens\LinkService;

beforeEach(fn () => $this->link = new LinkService());

function seenPlayer(string $tag): Player {
    return Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('links a seen gamertag and grants one token', function () {
    seenPlayer('Alice');
    $result = $this->link->link('discord-1', 'Alice', null);

    expect($result['status'])->toBe('linked');
    $alice = Player::where('gamertag', 'Alice')->first();
    expect($alice->discord_user_id)->toBe('discord-1');
    expect($alice->unban_tokens)->toBe(1);
    expect($alice->link_rewarded)->toBeTrue();
});

it('rejects linking a gamertag never seen in the logs', function () {
    expect($this->link->link('discord-1', 'Nobody', null)['status'])->toBe('gamertag_not_found');
    expect(Player::where('gamertag', 'Nobody')->exists())->toBeFalse();
});

it('rejects when the discord user is already linked', function () {
    seenPlayer('Alice'); seenPlayer('Alt');
    $this->link->link('discord-1', 'Alice', null);
    $result = $this->link->link('discord-1', 'Alt', null);
    expect($result['status'])->toBe('already_linked');
    expect(Player::where('gamertag', 'Alt')->first()->discord_user_id)->toBeNull();
});

it('rejects when the gamertag is already taken by someone else', function () {
    $alice = seenPlayer('Alice');
    $alice->update(['discord_user_id' => 'discord-1']);
    expect($this->link->link('discord-2', 'Alice', null)['status'])->toBe('gamertag_not_found');
});

it('sets a valid referrer at link time', function () {
    $ref = seenPlayer('Ref'); $ref->update(['discord_user_id' => 'discord-ref']);
    seenPlayer('Alice');
    $result = $this->link->link('discord-1', 'Alice', 'Ref');
    expect($result['status'])->toBe('linked');
    expect($result['referrer'])->toBe('Ref');
    expect(Player::where('gamertag', 'Alice')->first()->referrer_id)->toBe($ref->id);
});

it('rejects self-referral and unlinked referrer', function () {
    seenPlayer('Alice');
    expect($this->link->link('discord-1', 'Alice', 'Alice')['status'])->toBe('invalid_referrer');

    seenPlayer('Bob'); seenPlayer('Carol');
    // Carol exists but is NOT linked -> invalid referrer
    expect($this->link->link('discord-2', 'Bob', 'Carol')['status'])->toBe('invalid_referrer');
});

it('does not re-grant a token on a second link attempt by an already-linked user', function () {
    $alice = seenPlayer('Alice');
    $this->link->link('discord-1', 'Alice', null);
    // a re-link attempt is rejected as already_linked; tokens stay at 1
    $this->link->link('discord-1', 'Alice', null);
    expect(Player::where('gamertag', 'Alice')->first()->unban_tokens)->toBe(1);
});
```

- [ ] **Step 2: Run to verify it fails** — `./vendor/bin/pest tests/Feature/LinkServiceTest.php` → FAIL (class not found).

- [ ] **Step 3: Implement** — `app/Services/Tokens/LinkService.php`:

```php
<?php

namespace App\Services\Tokens;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

class LinkService
{
    /**
     * @return array{status:string, gamertag?:string, referrer?:?string, tokenGranted?:bool}
     * status ∈ linked | already_linked | gamertag_not_found | invalid_referrer
     */
    public function link(string $discordUserId, string $gamertag, ?string $referrerGamertag): array
    {
        if (Player::where('discord_user_id', $discordUserId)->exists()) {
            return ['status' => 'already_linked'];
        }

        $player = Player::where('gamertag', $gamertag)->whereNull('discord_user_id')->first();
        if (! $player) {
            return ['status' => 'gamertag_not_found'];
        }

        $referrer = null;
        if ($referrerGamertag !== null && $referrerGamertag !== '') {
            if (strcasecmp($referrerGamertag, $gamertag) === 0) {
                return ['status' => 'invalid_referrer'];
            }
            $referrer = Player::where('gamertag', $referrerGamertag)
                ->whereNotNull('discord_user_id')->first();
            if (! $referrer) {
                return ['status' => 'invalid_referrer'];
            }
        }

        return DB::transaction(function () use ($player, $discordUserId, $referrer) {
            $player->discord_user_id = $discordUserId;
            if ($referrer && $player->referrer_id === null) {
                $player->referrer_id = $referrer->id;
            }
            $granted = false;
            if (! $player->link_rewarded) {
                $player->unban_tokens += 1;
                $player->link_rewarded = true;
                $granted = true;
            }
            $player->save();

            return [
                'status' => 'linked',
                'gamertag' => $player->gamertag,
                'referrer' => $referrer?->gamertag,
                'tokenGranted' => $granted,
            ];
        });
    }
}
```

- [ ] **Step 4: Run to verify it passes** — `./vendor/bin/pest tests/Feature/LinkServiceTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: LinkService links a gamertag, sets referrer, grants link token"
```

---

## Task 2: ReferrerService

Sets a referrer after linking, only if none is set (set-once). Used by `/referrer`.

**Files:** Create `app/Services/Tokens/ReferrerService.php`. Test: `tests/Feature/ReferrerServiceTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/ReferrerServiceTest.php`:

```php
<?php

use App\Models\Player;
use App\Services\Tokens\ReferrerService;

beforeEach(fn () => $this->svc = new ReferrerService());

function linked(string $tag, string $discordId): Player {
    return Player::create(['gamertag' => $tag, 'discord_user_id' => $discordId, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('sets a referrer when none exists', function () {
    $me = linked('Me', 'd-me');
    $ref = linked('Ref', 'd-ref');
    expect($this->svc->setReferrer('d-me', 'Ref')['status'])->toBe('set');
    expect($me->fresh()->referrer_id)->toBe($ref->id);
});

it('rejects when caller is not linked', function () {
    linked('Ref', 'd-ref');
    expect($this->svc->setReferrer('d-unknown', 'Ref')['status'])->toBe('not_linked');
});

it('rejects when a referrer is already set (locked)', function () {
    $me = linked('Me', 'd-me');
    $r1 = linked('R1', 'd-r1'); linked('R2', 'd-r2');
    $me->update(['referrer_id' => $r1->id]);
    expect($this->svc->setReferrer('d-me', 'R2')['status'])->toBe('already_set');
    expect($me->fresh()->referrer_id)->toBe($r1->id);
});

it('rejects self-referral and an unlinked/unknown referrer', function () {
    linked('Me', 'd-me');
    expect($this->svc->setReferrer('d-me', 'Me')['status'])->toBe('invalid_referrer');
    expect($this->svc->setReferrer('d-me', 'Ghost')['status'])->toBe('invalid_referrer');
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL (class not found).

- [ ] **Step 3: Implement** — `app/Services/Tokens/ReferrerService.php`:

```php
<?php

namespace App\Services\Tokens;

use App\Models\Player;

class ReferrerService
{
    /** @return array{status:string, referrer?:string} — set | not_linked | already_set | invalid_referrer */
    public function setReferrer(string $discordUserId, string $referrerGamertag): array
    {
        $player = Player::where('discord_user_id', $discordUserId)->first();
        if (! $player) {
            return ['status' => 'not_linked'];
        }
        if ($player->referrer_id !== null) {
            return ['status' => 'already_set'];
        }
        if (strcasecmp($referrerGamertag, $player->gamertag) === 0) {
            return ['status' => 'invalid_referrer'];
        }
        $referrer = Player::where('gamertag', $referrerGamertag)->whereNotNull('discord_user_id')->first();
        if (! $referrer) {
            return ['status' => 'invalid_referrer'];
        }

        $player->referrer_id = $referrer->id;
        $player->save();

        return ['status' => 'set', 'referrer' => $referrer->gamertag];
    }
}
```

- [ ] **Step 4: Run to verify it passes** — PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: ReferrerService sets a set-once referrer"
```

---

## Task 3: RewardService (monthly grant)

Grants monthly tokens: `+1` base plus `+1` per referred player active in the previous calendar month. Idempotent per month. Returns a per-player breakdown for DMs.

**Files:** Create `app/Services/Tokens/RewardService.php`. Test: `tests/Feature/RewardServiceTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/RewardServiceTest.php`:

```php
<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\State\BotState;
use App\Services\Tokens\RewardService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-01T00:30:00Z'); // early on the 1st; "previous month" = June 2026
    $this->state = new BotState();
    $this->svc = new RewardService($this->state);
});
afterEach(fn () => CarbonImmutable::setTestNow());

function linkedPlayer(string $tag, string $discordId): Player {
    return Player::create(['gamertag' => $tag, 'discord_user_id' => $discordId, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}
function connectAt(Player $p, string $iso): void {
    $life = Life::create(['player_id' => $p->id, 'started_at' => $iso]);
    GameSession::create(['player_id' => $p->id, 'life_id' => $life->id, 'connected_at' => $iso]);
}

it('grants +1 base to each linked player', function () {
    linkedPlayer('A', 'd-a');
    linkedPlayer('B', 'd-b');
    $result = $this->svc->monthlyGrant(CarbonImmutable::now());

    expect($result['granted'])->toBe(2);
    expect(Player::where('gamertag', 'A')->first()->unban_tokens)->toBe(1);
});

it('adds +1 per referred player active in the previous month', function () {
    $referrer = linkedPlayer('Ref', 'd-ref');
    $active = linkedPlayer('Active', 'd-active'); $active->update(['referrer_id' => $referrer->id]);
    $inactive = linkedPlayer('Inactive', 'd-inactive'); $inactive->update(['referrer_id' => $referrer->id]);
    connectAt($active, '2026-06-15T12:00:00Z');     // active in June
    connectAt($inactive, '2026-05-10T12:00:00Z');   // last active in May, not June

    $this->svc->monthlyGrant(CarbonImmutable::now());

    // Ref: 1 base + 1 active referral = 2; Active: 1 base; Inactive: 1 base
    expect(Player::where('gamertag', 'Ref')->first()->unban_tokens)->toBe(2);
    expect(Player::where('gamertag', 'Active')->first()->unban_tokens)->toBe(1);
});

it('is idempotent within the same month', function () {
    linkedPlayer('A', 'd-a');
    $this->svc->monthlyGrant(CarbonImmutable::now());
    $second = $this->svc->monthlyGrant(CarbonImmutable::now());
    expect($second['granted'])->toBe(0);
    expect(Player::where('gamertag', 'A')->first()->unban_tokens)->toBe(1);
});

it('does not grant to unlinked players', function () {
    Player::create(['gamertag' => 'Unlinked', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $this->svc->monthlyGrant(CarbonImmutable::now());
    expect(Player::where('gamertag', 'Unlinked')->first()->unban_tokens)->toBe(0);
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL (class not found).

- [ ] **Step 3: Implement** — `app/Services/Tokens/RewardService.php`:

```php
<?php

namespace App\Services\Tokens;

use App\Models\GameSession;
use App\Models\Player;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RewardService
{
    public function __construct(private BotState $state) {}

    /**
     * Grant monthly tokens once per calendar month. Returns
     * ['granted' => int totalTokens, 'players' => [['discord_user_id','gamertag','amount'], ...]].
     */
    public function monthlyGrant(CarbonImmutable $now): array
    {
        $monthKey = $now->format('Y-m');
        if ($this->state->get('last_reward_month') === $monthKey) {
            return ['granted' => 0, 'players' => []];
        }

        // "Previous calendar month" window [start, end).
        $prevStart = $now->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $now->startOfMonth();

        $players = Player::whereNotNull('discord_user_id')->get();
        $breakdown = [];
        $total = 0;

        DB::transaction(function () use ($players, $prevStart, $prevEnd, &$breakdown, &$total) {
            foreach ($players as $player) {
                $activeReferrals = Player::where('referrer_id', $player->id)
                    ->whereHas('sessions', fn ($q) => $q->where('connected_at', '>=', $prevStart)->where('connected_at', '<', $prevEnd))
                    ->count();
                $amount = 1 + $activeReferrals;
                $player->increment('unban_tokens', $amount);
                $total += $amount;
                $breakdown[] = [
                    'discord_user_id' => $player->discord_user_id,
                    'gamertag' => $player->gamertag,
                    'amount' => $amount,
                ];
            }
        });

        $this->state->set('last_reward_month', $monthKey);

        return ['granted' => $total, 'players' => $breakdown];
    }
}
```

- [ ] **Step 4: Run to verify it passes** — PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: RewardService grants monthly tokens + active-referral bonus (idempotent)"
```

---

## Task 4: RedemptionService

Spends a token to lift a player's active temporary ban (self or another). Permanent bans rejected; token deducted only on success.

**Files:** Create `app/Services/Tokens/RedemptionService.php`. Test: `tests/Feature/RedemptionServiceTest.php`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/RedemptionServiceTest.php`:

```php
<?php

use App\Models\Ban;
use App\Models\Player;
use App\Services\Ban\BanService;
use App\Services\Ban\NullBanNotifier;
use App\Services\Nitrado\NitradoClient;
use App\Services\Tokens\RedemptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    Http::fake(['*/gameservers/settings' => function ($r) {
        if ($r->method() === 'POST') return Http::response(['status' => 'success', 'data' => []]);
        return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => 'Target']]]]);
    }]);
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    $this->svc = new RedemptionService($bans);
});
afterEach(fn () => CarbonImmutable::setTestNow());

function linkedWithTokens(string $tag, string $discordId, int $tokens): Player {
    return Player::create(['gamertag' => $tag, 'discord_user_id' => $discordId, 'unban_tokens' => $tokens, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}
function tempBan(Player $p, string $expiresAt): void {
    Ban::create(['player_id' => $p->id, 'banned_at' => now(), 'expires_at' => $expiresAt, 'expired' => false, 'reason' => 'auto', 'source' => 'auto_death']);
}

it('spends a token to unban another temp-banned player', function () {
    $spender = linkedWithTokens('Spender', 'd-spend', 2);
    $target = linkedWithTokens('Target', 'd-target', 0);
    tempBan($target, '2026-06-12T20:00:00Z');

    $result = $this->svc->redeem('d-spend', 'Target');

    expect($result['status'])->toBe('unbanned');
    expect($spender->fresh()->unban_tokens)->toBe(1);
    expect($target->fresh()->used_tokens)->toBe(1);
    expect(Ban::where('expired', false)->count())->toBe(0);
});

it('defaults the target to the spender', function () {
    $me = linkedWithTokens('Me', 'd-me', 1);
    tempBan($me, '2026-06-12T20:00:00Z');
    expect($this->svc->redeem('d-me', null)['status'])->toBe('unbanned');
    expect($me->fresh()->unban_tokens)->toBe(0);
});

it('rejects when the spender has no tokens', function () {
    linkedWithTokens('Me', 'd-me', 0);
    tempBan(Player::where('gamertag', 'Me')->first(), '2026-06-12T20:00:00Z');
    expect($this->svc->redeem('d-me', null)['status'])->toBe('no_tokens');
});

it('rejects when the spender is not linked', function () {
    expect($this->svc->redeem('d-unknown', null)['status'])->toBe('not_linked');
});

it('rejects when the target has no active temporary ban', function () {
    linkedWithTokens('Me', 'd-me', 1);
    linkedWithTokens('Target', 'd-target', 0); // no ban
    expect($this->svc->redeem('d-me', 'Target')['status'])->toBe('no_active_ban');
});

it('rejects redeeming against a permanent ban and does not spend the token', function () {
    $me = linkedWithTokens('Me', 'd-me', 1);
    $target = linkedWithTokens('Target', 'd-target', 0);
    Ban::create(['player_id' => $target->id, 'banned_at' => now(), 'expires_at' => null, 'expired' => false, 'reason' => 'perma', 'source' => 'manual']);
    expect($this->svc->redeem('d-me', 'Target')['status'])->toBe('permanent_ban');
    expect($me->fresh()->unban_tokens)->toBe(1);
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL (class not found).

- [ ] **Step 3: Implement** — `app/Services/Tokens/RedemptionService.php`:

```php
<?php

namespace App\Services\Tokens;

use App\Models\Ban;
use App\Models\Player;
use App\Services\Ban\BanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RedemptionService
{
    public function __construct(private BanService $bans) {}

    /**
     * @return array{status:string, target?:string, remaining?:int}
     * status ∈ unbanned | not_linked | no_tokens | target_not_found | no_active_ban | permanent_ban
     */
    public function redeem(string $spenderDiscordId, ?string $targetGamertag): array
    {
        $spender = Player::where('discord_user_id', $spenderDiscordId)->first();
        if (! $spender) return ['status' => 'not_linked'];
        if ($spender->unban_tokens < 1) return ['status' => 'no_tokens'];

        $target = $targetGamertag
            ? Player::where('gamertag', $targetGamertag)->first()
            : $spender;
        if (! $target) return ['status' => 'target_not_found'];

        $now = CarbonImmutable::now();

        // Permanent ban blocks token use.
        $permanent = Ban::where('player_id', $target->id)->where('expired', false)->whereNull('expires_at')->exists();
        if ($permanent) return ['status' => 'permanent_ban'];

        $activeTemp = Ban::where('player_id', $target->id)->where('expired', false)
            ->whereNotNull('expires_at')->where('expires_at', '>', $now)->exists();
        if (! $activeTemp) return ['status' => 'no_active_ban'];

        // Lift first; deduct only after a successful unban.
        $this->bans->unban($target->gamertag, "Unban token spent by {$spender->gamertag}");

        $remaining = DB::transaction(function () use ($spender, $target) {
            $spender->decrement('unban_tokens');
            $target->increment('used_tokens');
            return $spender->fresh()->unban_tokens;
        });

        return ['status' => 'unbanned', 'target' => $target->gamertag, 'remaining' => $remaining];
    }
}
```

- [ ] **Step 4: Run to verify it passes** — PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: RedemptionService spends a token to lift a temporary ban"
```

---

## Task 5: MonthlyRewardService (Laracord Service)

Hourly tick; when the month has rolled over, run `RewardService::monthlyGrant` and DM each recipient a breakdown.

**Files:** Create `app/Services/MonthlyRewardService.php`. (No Pest test — the grant logic is tested in Task 3; this is the scheduler + DM wrapper, verified by discovery.)

- [ ] **Step 1: Implement** — `app/Services/MonthlyRewardService.php`:

```php
<?php

namespace App\Services;

use App\Services\State\BotState;
use App\Services\Tokens\RewardService;
use Carbon\CarbonImmutable;
use Laracord\Laracord;
use Laracord\Services\Service;

class MonthlyRewardService extends Service
{
    protected int $interval = 3600; // hourly check; the grant itself is once-per-month

    public function __construct(?Laracord $bot = null)
    {
        if ($bot !== null) {
            parent::__construct($bot);
        }
    }

    public function handle(): void
    {
        try {
            $result = (new RewardService(new BotState()))->monthlyGrant(CarbonImmutable::now());
            if ($result['granted'] <= 0) return;

            $this->console()->info("[rewards] granted {$result['granted']} monthly token(s).");
            foreach ($result['players'] as $p) {
                if (! $p['discord_user_id'] || $p['amount'] <= 0) continue;
                $this->dm($p['discord_user_id'], "🎁 Your monthly unban tokens have arrived: **+{$p['amount']}** (gamertag {$p['gamertag']}).");
            }
        } catch (\Throwable $e) {
            $this->console()->error('[rewards] monthly grant failed: '.$e->getMessage());
        }
    }

    private function dm(string $userId, string $content): void
    {
        try {
            $this->discord()->users->fetch($userId)
                ->then(fn ($user) => $user?->sendMessage($content))
                ->otherwise(fn () => null);
        } catch (\Throwable) {
            // best-effort
        }
    }
}
```

- [ ] **Step 2: Verify** — `php -l app/Services/MonthlyRewardService.php`; `php laracord list` (clean, service discovered); `./vendor/bin/pest` (full suite green — no regressions).

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "feat: MonthlyRewardService runs the monthly grant and DMs recipients"
```

---

## Task 6: Slash commands — /link and /referrer

Thin wrappers over `LinkService`/`ReferrerService`, with autocomplete. Verified by `php laracord list` (commands register) — slash interactions aren't unit-tested; the logic is covered by the service tests.

**Files:** Create `app/SlashCommands/LinkCommand.php`, `app/SlashCommands/ReferrerCommand.php`.

- [ ] **Step 1: Read an existing command for the exact API.** Read `app/Commands/PingCommand.php` and `vendor/laracord/framework/src/Commands/SlashCommand.php` to confirm: property names (`$name`, `$description`, `$options`), the `handle($interaction)` signature, how to read string options (`$interaction->data->options` or a helper like `$this->value('gamertag')` / `$interaction->getOption()`), how to reply (`$this->message(...)->reply($interaction, ephemeral: true)` per Laracord docs), guild scoping, and the `autocomplete()` return shape (`['gamertag' => fn (Interaction $i, $value) => Collection|array of choices]`). Adapt the code below to the confirmed API.

- [ ] **Step 2: Implement `LinkCommand`** (`/link gamertag [referrer]`):

```php
<?php

namespace App\SlashCommands;

use App\Models\Player;
use App\Services\Tokens\LinkService;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class LinkCommand extends SlashCommand
{
    protected $name = 'link';
    protected $description = 'Link your Discord account to a DayZ gamertag (one per user).';

    protected $options = [
        ['name' => 'gamertag', 'description' => 'The gamertag to link', 'type' => 3, 'required' => true, 'autocomplete' => true],
        ['name' => 'referrer', 'description' => 'Optional: who referred you (a linked player)', 'type' => 3, 'required' => false, 'autocomplete' => true],
    ];

    public function handle($interaction): void
    {
        $gamertag = $this->stringOption($interaction, 'gamertag');
        $referrer = $this->stringOption($interaction, 'referrer');

        $r = (new LinkService())->link((string) $interaction->member->user->id, $gamertag, $referrer);

        $msg = match ($r['status']) {
            'linked' => "✅ Linked to **{$r['gamertag']}**."
                .($r['tokenGranted'] ? " You received **1 unban token**." : '')
                .($r['referrer'] ? " Referrer set to **{$r['referrer']}**." : ''),
            'already_linked' => '⚠️ You are already linked. You can\'t re-link or change your gamertag.',
            'gamertag_not_found' => '⚠️ That gamertag isn\'t available — make sure you\'ve connected to the server at least once, and that no one else has linked it.',
            'invalid_referrer' => '⚠️ Invalid referrer — pick a different, already-linked player (not yourself).',
            default => '⚠️ Something went wrong.',
        };

        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => Player::whereNull('discord_user_id')
                ->when($value, fn ($q) => $q->where('gamertag', 'like', "%{$value}%"))
                ->orderByDesc('last_seen_at')->limit(25)->pluck('gamertag'),
            'referrer' => fn (Interaction $i, $value) => Player::whereNotNull('discord_user_id')
                ->when($value, fn ($q) => $q->where('gamertag', 'like', "%{$value}%"))
                ->orderByDesc('last_seen_at')->limit(25)->pluck('gamertag'),
        ];
    }

    /** Read a string option by name; adapt to the confirmed Laracord/DiscordPHP accessor. */
    private function stringOption($interaction, string $name): ?string
    {
        foreach ($interaction->data->options ?? [] as $opt) {
            if ($opt->name === $name) return $opt->value !== null ? (string) $opt->value : null;
        }
        return null;
    }
}
```

> The `$options` `type => 3` is `Option::STRING`; use the `Discord\Parts\Interactions\Command\Option::STRING` constant if you prefer. Confirm `autocomplete => true` is the right key in this Laracord version (it may require the method form). `stringOption` reads from `$interaction->data->options`; if Laracord exposes a cleaner accessor (e.g. `$this->value($name)`), use it and drop the helper.

- [ ] **Step 3: Implement `ReferrerCommand`** (`/referrer gamertag`):

```php
<?php

namespace App\SlashCommands;

use App\Models\Player;
use App\Services\Tokens\ReferrerService;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class ReferrerCommand extends SlashCommand
{
    protected $name = 'referrer';
    protected $description = 'Set who referred you (only if you haven\'t already; locked once set).';

    protected $options = [
        ['name' => 'gamertag', 'description' => 'The linked player who referred you', 'type' => 3, 'required' => true, 'autocomplete' => true],
    ];

    public function handle($interaction): void
    {
        $referrer = $this->stringOption($interaction, 'gamertag');
        $r = (new ReferrerService())->setReferrer((string) $interaction->member->user->id, (string) $referrer);

        $msg = match ($r['status']) {
            'set' => "✅ Referrer set to **{$r['referrer']}**.",
            'not_linked' => '⚠️ Link your gamertag first with `/link`.',
            'already_set' => '⚠️ Your referrer is already set and can\'t be changed.',
            'invalid_referrer' => '⚠️ Invalid referrer — pick a different, already-linked player (not yourself).',
            default => '⚠️ Something went wrong.',
        };

        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => Player::whereNotNull('discord_user_id')
                ->when($value, fn ($q) => $q->where('gamertag', 'like', "%{$value}%"))
                ->orderByDesc('last_seen_at')->limit(25)->pluck('gamertag'),
        ];
    }

    private function stringOption($interaction, string $name): ?string
    {
        foreach ($interaction->data->options ?? [] as $opt) {
            if ($opt->name === $name) return $opt->value !== null ? (string) $opt->value : null;
        }
        return null;
    }
}
```

- [ ] **Step 4: Verify** — `php -l` both files; `php laracord list` shows them with no fatal error. (If commands need guild registration for autocomplete, set it per the framework's convention — `DISCORD_GUILD_ID` is available.)

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: /link and /referrer slash commands with autocomplete"
```

---

## Task 7: Slash commands — /unban and /unbans

`/unban [player]` spends a token (autocomplete from currently temp-banned gamertags); `/unbans` shows the caller's balance.

**Files:** Create `app/SlashCommands/UnbanCommand.php`, `app/SlashCommands/UnbansCommand.php`.

- [ ] **Step 1: Implement `UnbanCommand`** — wraps `RedemptionService` (build it with a real `BanService` + `DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID'))`, honoring `BAN_DRY_RUN`):

```php
<?php

namespace App\SlashCommands;

use App\Models\Ban;
use App\Services\Ban\BanService;
use App\Services\Ban\DiscordBanNotifier;
use App\Services\Nitrado\NitradoClient;
use App\Services\Tokens\RedemptionService;
use Carbon\CarbonImmutable;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class UnbanCommand extends SlashCommand
{
    protected $name = 'unban';
    protected $description = 'Spend an unban token to lift a temporary ban (yours by default).';

    protected $options = [
        ['name' => 'player', 'description' => 'Gamertag to unban (defaults to you)', 'type' => 3, 'required' => false, 'autocomplete' => true],
    ];

    public function handle($interaction): void
    {
        $target = $this->stringOption($interaction, 'player');

        $bans = new BanService(
            new NitradoClient(env('NITRADO_TOKEN'), (int) env('NITRADO_SERVICE_ID')),
            new DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID')),
            dryRun: filter_var(env('BAN_DRY_RUN', false), FILTER_VALIDATE_BOOL),
        );
        $r = (new RedemptionService($bans))->redeem((string) $interaction->member->user->id, $target);

        $msg = match ($r['status']) {
            'unbanned' => "✅ Unbanned **{$r['target']}**. Tokens remaining: **{$r['remaining']}**.",
            'not_linked' => '⚠️ Link your gamertag first with `/link`.',
            'no_tokens' => '⚠️ You have no unban tokens.',
            'target_not_found' => '⚠️ No player found with that gamertag.',
            'no_active_ban' => 'ℹ️ That player has no active temporary ban.',
            'permanent_ban' => '⚠️ That player is permanently banned — tokens can\'t lift it.',
            default => '⚠️ Something went wrong.',
        };

        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    public function autocomplete(): array
    {
        return [
            'player' => function (Interaction $i, $value) {
                $now = CarbonImmutable::now();
                return Ban::query()->where('expired', false)
                    ->whereNotNull('expires_at')->where('expires_at', '>', $now)
                    ->with('player')->get()
                    ->map(fn (Ban $b) => $b->player?->gamertag)->filter()->unique()
                    ->when($value, fn ($c) => $c->filter(fn ($t) => str_contains(strtolower($t), strtolower($value))))
                    ->take(25)->values();
            },
        ];
    }

    private function stringOption($interaction, string $name): ?string
    {
        foreach ($interaction->data->options ?? [] as $opt) {
            if ($opt->name === $name) return $opt->value !== null ? (string) $opt->value : null;
        }
        return null;
    }
}
```

- [ ] **Step 2: Implement `UnbansCommand`** (`/unbans` — balance):

```php
<?php

namespace App\SlashCommands;

use App\Models\Player;
use Laracord\Commands\SlashCommand;

class UnbansCommand extends SlashCommand
{
    protected $name = 'unbans';
    protected $description = 'Show how many unban tokens you have.';

    public function handle($interaction): void
    {
        $player = Player::where('discord_user_id', (string) $interaction->member->user->id)->first();
        $msg = $player
            ? "🎟️ You have **{$player->unban_tokens}** unban token(s)."
            : '⚠️ Link your gamertag first with `/link`.';
        $this->message($msg)->reply($interaction, ephemeral: true);
    }
}
```

- [ ] **Step 3: Verify** — `php -l` both; `php laracord list` shows `/unban` and `/unbans`; `./vendor/bin/pest` green.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: /unban (token redemption) and /unbans (balance) slash commands"
```

---

## Task 8: Verification

- [ ] **Step 1: Full suite** — `./vendor/bin/pest` (all green; service tests cover the economy rules).

- [ ] **Step 2: Command discovery** — `php laracord list` lists `link`, `referrer`, `unban`, `unbans` with no fatal error, and `php laracord` boots far enough to register commands (full gateway needs `DISCORD_TOKEN`).

- [ ] **Step 3: Logic spot-check via tinker** (no Discord needed) against a fresh DB or the real one:
  ```bash
  php laracord tinker
  >>> App\Models\Player::create(['gamertag'=>'TestTag','first_seen_at'=>now(),'last_seen_at'=>now()]);
  >>> (new App\Services\Tokens\LinkService())->link('discord-test','TestTag',null);
  >>> App\Models\Player::where('gamertag','TestTag')->first()->only(['discord_user_id','unban_tokens','link_rewarded']);
  ```
  Confirm link + token grant. Then clean up the test row.

- [ ] **Step 4: Commit a baseline marker**

```bash
git commit --allow-empty -m "chore: Plan 3 linking + token economy complete and tested"
```

---

## Self-review notes (coverage against spec Section 5)

- **Link reward (one-time, first link)** → Task 1 (`link_rewarded` guard).
- **One gamertag per user; only seen gamertags linkable** → Task 1.
- **Referrer set-once at link or later; no self/unlinked** → Tasks 1, 2.
- **Monthly grant: +1 base + active-referral bonus; idempotent per month** → Task 3, scheduled by Task 5.
- **Redemption: linked + ≥1 token; temp ban only; deduct on success** → Task 4, surfaced by `/unban` (Task 7).
- **Commands /link, /referrer, /unban, /unbans with autocomplete** → Tasks 6, 7.

**Deferred to Plan 4:** `/bans`, `/referrals`, `/players` (read views) and the admin command set (`/adminban`, `/adminunban`, `/adminlink`, `/adminunlink`, `/addunban`, `/distribute-unbans`). Ban DMs to linked players now work (Plan 2's `DiscordBanNotifier` targets `discord_user_id`, populated once a player links).
```
