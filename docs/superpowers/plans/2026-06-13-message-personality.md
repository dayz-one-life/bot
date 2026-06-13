# Message Personality Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every public Discord message (bounty, ban/death, connection — channel posts and DMs) a cheeky, randomized voice drawn from per-message pools of ≥10 lines, never repeating the immediately-previous line.

**Architecture:** A single `config/personality.php` holds all line pools keyed by dot-key with `:token` placeholders. A reusable `App\Services\Personality\MessagePicker::pick(key, tokens, fallback)` selects a random line (avoiding the immediate repeat via a process-wide static), interpolates tokens, and falls back to a plain string if a pool is ever empty. The three notifiers funnel their strings through `pick()`. Randomness is an injectable closure so tests are deterministic.

**Tech Stack:** Laracord (Laravel Zero + DiscordPHP), PHP 8.2+, Pest. Config via `config/*.php`. `Carbon` already in use.

---

## File Structure

- **Create:** `config/personality.php` — all 17 line pools (≥10 lines each).
- **Create:** `app/Services/Personality/MessagePicker.php` — the picker (random + anti-repeat + interpolation + fallback).
- **Create:** `tests/Feature/PersonalityConfigTest.php` — pools complete & `bounty.ended` neutral.
- **Create:** `tests/Feature/MessagePickerTest.php` — interpolation, anti-repeat, fallback.
- **Create:** `tests/Feature/BannedKeyTest.php` — the death/manual/extended routing helper.
- **Modify:** `app/Services/Ban/DiscordBanNotifier.php` — pick() wiring + `public static bannedKey()`.
- **Modify:** `app/Services/Bounty/DiscordBountyNotifier.php` — pick() wiring.
- **Modify:** `app/Services/Connection/DiscordConnectionNotifier.php` — pick() wiring.
- **Modify:** `CLAUDE.md` — document the personality layer.

### Notes for the implementer (read once)

- Notifiers are constructed elsewhere as `new DiscordXNotifier($discord, $channelId)`. Add an
  **optional** 3rd `?MessagePicker $picker = null` param so existing call-sites are untouched.
- `config()`, `config()->set()`, and Eloquent model instantiation require the booted app, so the
  tests below live in `tests/Feature/` (they do **not** need `RefreshDatabase` — no DB rows).
- PHP 8.5 `DEPR` markers in Pest output are harmless (documented in CLAUDE.md); exit 0 = green.
- Run one file: `./vendor/bin/pest tests/Feature/MessagePickerTest.php`. Full suite: `./vendor/bin/pest`.

---

## Task 1: Personality config + completeness/neutrality tests

**Files:**
- Create: `config/personality.php`
- Create: `tests/Feature/PersonalityConfigTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PersonalityConfigTest.php`:

```php
<?php

it('ships a complete set of non-empty personality pools', function () {
    $keys = [
        'bounty.placed', 'bounty.moved', 'bounty.claimed', 'bounty.ended',
        'bounty.dm.placed', 'bounty.dm.moved', 'bounty.dm.claimed',
        'ban.death', 'ban.manual', 'ban.extended', 'ban.unbanned',
        'ban.dm.death', 'ban.dm.manual', 'ban.dm.unbanned',
        'connection.connected', 'connection.disconnected', 'connection.disconnected_nodur',
    ];

    foreach ($keys as $key) {
        $pool = config("personality.{$key}");
        expect($pool)->toBeArray();
        expect(count($pool))->toBeGreaterThanOrEqual(10);
        foreach ($pool as $line) {
            expect($line)->toBeString();
            expect(trim($line))->not->toBe('');
        }
    }
});

it('keeps bounty.ended neutral about payouts', function () {
    foreach (config('personality.bounty.ended') as $line) {
        foreach (['token', 'reward', 'paid', 'claim'] as $word) {
            expect(stripos($line, $word))->toBeFalse();
        }
    }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/PersonalityConfigTest.php`
Expected: FAIL — `config('personality.*')` is null (file does not exist yet).

- [ ] **Step 3: Create the config file**

Create `config/personality.php` with EXACTLY this content (lines transcribed from the approved spec):

```php
<?php

return [

    'bounty' => [

        'placed' => [
            '🎯 A bounty just dropped on :target — first to send them to the lobby pockets an unban token.',
            '🎯 :target has a price on their head now. One token says you can\'t collect it.',
            '🎯 Open season on :target! Bag \'em and grab yourself an unban token. 🪙',
            '🎯 New contract: :target. Payment: one unban token. Difficulty: their problem, not yours.',
            '🎯 Somebody put :target on the menu. Whoever serves them gets a token to go.',
            '🎯 The bounty board refreshed and :target is today\'s special. Reward: one unban token.',
            '🎯 :target just became the most popular person on the server, and not in a good way. Token\'s up for grabs.',
            '🎯 Wanted: :target. Dead. Reward: one unban token. No questions asked.',
            '🎯 There\'s a token with your name on it — all you have to do is find :target first.',
            '🎯 Fresh bounty on :target. Bring them down, leave with a token. Simple economics.',
        ],

        'moved' => [
            '🎯 Plot twist: the bounty slid over to :target for the crime of refusing to die.',
            '🎯 :target survived long enough to become the problem. Bounty\'s theirs now.',
            '🎯 The bounty got bored and wandered over to :target. Congrats on the attention.',
            '🎯 :target is now the longest-living target on the server — fancy way of saying "shoot them."',
            '🎯 New face on the wanted poster: :target. They lasted the longest, so they\'re it.',
            '🎯 The bounty has changed hands — :target outlived everyone, so now everyone wants them.',
            '🎯 Bounty relocated to :target. Outliving the competition has consequences.',
            '🎯 :target wouldn\'t die, so the universe made them the target instead. Seems fair.',
            '🎯 Congratulations :target, your reward for surviving is a target on your back.',
            '🎯 The crosshair drifts to :target — last one standing, first one wanted.',
        ],

        'claimed' => [
            '💀 :killer collected the bounty on :target and walked off with :tokens unban token(s). Nature is healing.',
            '💀 :target got folded by :killer — that\'s :tokens token(s) richer.',
            '💀 GG :target — :killer claimed your bounty for :tokens token(s). Should\'ve stayed inside.',
            '💀 :killer found :target, ended :target, and got paid :tokens token(s) for the trouble.',
            '💀 Bounty claimed! :killer sent :target to the respawn screen and cashed :tokens token(s).',
            '💀 :killer just turned :target into a payday: :tokens unban token(s). Efficient.',
            '💀 And the bounty on :target goes to… :killer! That\'ll be :tokens token(s).',
            '💀 :target\'s luck ran out the second :killer showed up. :tokens token(s), claimed.',
            '💀 :killer cashed in :target for :tokens unban token(s). Hunting season\'s good this year.',
            '💀 :killer wrote :target out of the story and pocketed :tokens token(s) for it.',
        ],

        'ended' => [
            '🏳️ The bounty on :target has wrapped up. Nothing more to see here.',
            '🏳️ Contract on :target closed. The board\'s clear for now.',
            '🏳️ :target is off the wanted list. Carry on.',
            '🏳️ That\'s a wrap on :target\'s bounty. Stand down.',
            '🏳️ :target\'s name has come off the board. All quiet.',
            '🏳️ The bounty on :target is no longer active. Move along.',
            '🏳️ :target is no longer wanted. The hunt\'s off.',
            '🏳️ Bounty on :target: concluded. Back to your regularly scheduled survival.',
            '🏳️ The contract on :target has expired. Nothing to see here.',
            '🏳️ All clear on :target. The wanted poster comes down.',
        ],

        'dm' => [

            'placed' => [
                '🎯 Heads up — there\'s a bounty on you now. People will be… friendly.',
                '🎯 Congrats, you\'re today\'s target. Watch your back out there.',
                '🎯 A bounty just landed on your head. Maybe stay off the skyline.',
                '🎯 Bad news: your name\'s on a contract now. Trust no one.',
                '🎯 You\'ve been marked. Every gunshot is about you now.',
                '🎯 There\'s a price on your head. Sleep with one eye open.',
                '🎯 You\'re wanted — like, actively hunted wanted. Good luck.',
                '🎯 Someone wants you gone badly enough to make it official. Watch yourself.',
                '🎯 The server just put a target on your back. Keep moving.',
                '🎯 You\'re the bounty now. Paranoia is a valid playstyle.',
            ],

            'moved' => [
                '🎯 The bounty\'s on you now — you survived too long. Watch your back.',
                '🎯 You\'re the new target. The longest-living one. Lucky you.',
                '🎯 Bad news: the server thinks you\'ve lived long enough. Bounty\'s yours.',
                '🎯 You outlived everyone, so now everyone wants you. Congrats?',
                '🎯 Your reward for surviving: a fresh bounty. Watch yourself.',
                '🎯 You\'re it. The bounty found you for the crime of staying alive.',
                '🎯 The crosshair\'s on you now. Surviving has a price.',
                '🎯 Heads up — you\'re the most wanted player on the server now.',
                '🎯 You lasted the longest, so the bounty\'s yours. Keep moving.',
                '🎯 The target just shifted to you. Trust nobody.',
            ],

            'claimed' => [
                '💰 You claimed the bounty on :target and banked :tokens unban token(s). Nice work.',
                '💰 :target down, :tokens token(s) up. The bounty\'s yours — well earned.',
                '💰 Bounty collected! :target paid out :tokens unban token(s) to you.',
                '💰 You hunted :target and got paid :tokens token(s). Clean.',
                '💰 :tokens unban token(s) richer — thanks to :target. Spend it wisely.',
                '💰 That\'s a bounty on :target claimed. :tokens token(s) added to your stash.',
                '💰 Nice shot. :target was worth :tokens token(s), and now they\'re yours.',
                '💰 You found :target first. :tokens token(s) is your reward.',
                '💰 Bounty on :target: claimed. Payout: :tokens unban token(s). GG.',
                '💰 You earned :tokens unban token(s) off :target. Hunting pays.',
            ],
        ],
    ],

    'ban' => [

        'death' => [
            '⚰️ :who had ONE life and yeeted it into the void. Benched until :expires.',
            '⚰️ :who discovered the "one" in one-life the hard way. Back on :expires.',
            '💀 :who died as they lived: temporarily. See you :expires.',
            '⚰️ Press F for :who. They\'ll be back :expires, sadder and wiser.',
            '💀 :who found out the server only hands out one life. Sit tight til :expires.',
            '⚰️ :who has shuffled off this mortal server. Respawn unlocks :expires.',
            '💀 One life, one mistake — :who is done until :expires.',
            '⚰️ :who speedran the death screen. Benched until :expires.',
            '💀 RIP :who. Gone but not forgotten, back :expires.',
            '⚰️ :who learned that "one life" wasn\'t a suggestion. Out until :expires.',
        ],

        'manual' => [
            '🔨 :who caught the banhammer — :reason. Out until :expires.',
            '🔨 Down goes :who — :reason. Timeout ends :expires.',
            '🔨 :who is taking an involuntary vacation (:reason). Returns :expires.',
            '🔨 The banhammer found :who. Reason: :reason · expires :expires.',
            '🔨 :who has been sent to the corner. Reason: :reason. Back :expires.',
            '🔨 :who earned themselves a timeout — :reason. Free :expires.',
            '🔨 Tough break, :who — :reason. See you :expires.',
            '🔨 :who has been escorted off the server (:reason). Returns :expires.',
            '🔨 :who pressed their luck and found the banhammer. :reason · :expires.',
            '🔨 :who is sitting this one out — :reason. Back on :expires.',
        ],

        'extended' => [
            '🔨 :who\'s ban just got a remix — :reason. Now expires :expires.',
            '🔨 :who\'s vacation has been extended (:reason). New return date: :expires.',
            '🔨 :who really wanted more time off — ban updated, :reason, expires :expires.',
            '🔨 Bad news for :who: the timer reset. :reason · back :expires.',
            '🔨 :who unlocked bonus bench time — :reason. Now out until :expires.',
            '🔨 The clock on :who just restarted. :reason · expires :expires.',
            '🔨 :who\'s timeout got a sequel. :reason. Back :expires.',
            '🔨 More of a good thing for :who — ban extended, :reason, :expires.',
            '🔨 :who\'s stay has been lengthened (:reason). Returns :expires.',
            '🔨 :who hit the snooze on freedom — :reason. New alarm: :expires.',
        ],

        'unbanned' => [
            '🕊️ :who is free! (:reason) Try to keep this one alive.',
            '✅ The gates open for :who — :reason. Welcome back, don\'t waste it.',
            '🕊️ Parole granted: :who. :reason. The void missed you.',
            '✅ :who is off the bench — :reason. Go make better decisions.',
            '🕊️ :who has served their time (:reason). Back in the fight.',
            '✅ Welcome back, :who — :reason. The map wasn\'t the same without you.',
            '🕊️ :who walks free (:reason). Second chances are a beautiful thing.',
            '✅ :who is cleared for respawn — :reason. Stay alive this time.',
            '🕊️ Freedom for :who! :reason. Try not to end up back here.',
            '✅ :who is back in business — :reason. Behave.',
        ],

        'dm' => [

            'death' => [
                '⚰️ You died — that\'s the one life, gone. You\'re benched until :expires.',
                '💀 One life, and it\'s spent. You\'re out until :expires. Walk it off.',
                '⚰️ That\'s all she wrote for this life. Back in action :expires.',
                '💀 You found the "one" in one-life. See you :expires.',
                '⚰️ Game over for this run. Respawn unlocks :expires.',
                '💀 You\'ve been sent to the bench — one life, used. Back :expires.',
                '⚰️ Rough one. Your ban lifts :expires. Use the downtime to plan.',
                '💀 You died, so you\'re out until :expires. Happens to the best of us.',
                '⚰️ This life\'s over. You\'re back :expires — make the next one count.',
                '💀 One and done. Benched until :expires.',
            ],

            'manual' => [
                '🔨 You\'ve been banned — :reason. Expires :expires.',
                '🔨 Timeout: :reason. You\'re back :expires.',
                '🔨 You caught a ban — :reason. Out until :expires.',
                '🔨 You\'ve been benched. Reason: :reason. Back :expires.',
                '🔨 Banned: :reason. Freedom returns :expires.',
                '🔨 You\'re sitting this one out — :reason. Back :expires.',
                '🔨 The banhammer found you. :reason · expires :expires.',
                '🔨 You\'ve earned a timeout: :reason. Out until :expires.',
                '🔨 No server for you for a bit — :reason. Back :expires.',
                '🔨 Take a breather — :reason. You\'re back :expires.',
            ],

            'unbanned' => [
                '🕊️ Good news — your ban\'s been lifted (:reason). Don\'t waste the second chance.',
                '✅ You\'re unbanned (:reason). Back in the fight.',
                '🕊️ You\'re free (:reason). Try to keep this life longer.',
                '✅ Ban lifted: :reason. Welcome back.',
                '🕊️ The gates are open (:reason). Go make better choices.',
                '✅ You\'re cleared (:reason). Respawn awaits.',
                '🕊️ Parole granted (:reason). Stay alive this time.',
                '✅ You\'re back in business (:reason). Behave.',
                '🕊️ Freedom! :reason. Don\'t end up back here.',
                '✅ Your ban\'s over (:reason). The map missed you.',
            ],
        ],
    ],

    'connection' => [

        'connected' => [
            '🟢 :tag rolled in. The clock\'s ticking.',
            '🟢 :tag spawned. One life, no pressure.',
            '🟢 :tag entered the map. Place your bets.',
            '🟢 :tag is in. Try not to die immediately.',
            '🟢 :tag is online — good luck out there.',
            '🟢 Look who it is: :tag just connected.',
            '🟢 :tag has joined the struggle.',
            '🟢 :tag is live. May the odds be ever in their favor.',
            '🟢 :tag clocked in for another shot at survival.',
            '🟢 :tag loaded in. Let\'s see how this goes.',
        ],

        'disconnected' => [
            '🔴 :tag logged off after :duration. Lived to alt-tab another day.',
            '🔴 :tag tapped out — :duration survived. Cowardice or wisdom?',
            '🔴 :tag called it after :duration. The bush was that comfy, huh.',
            '🔴 :tag disconnected · :duration on the clock. See you next spawn.',
            '🔴 :tag is gone after :duration. Still breathing, technically.',
            '🔴 :tag survived :duration and decided that was enough heroism for today.',
            '🔴 :tag bailed after :duration. Logging off counts as a survival strategy.',
            '🔴 :tag clocked out — :duration of staying alive. Respectable.',
            '🔴 :tag dipped after :duration. The loot will be there tomorrow.',
            '🔴 :tag went dark after :duration. Smart money quits while ahead.',
        ],

        'disconnected_nodur' => [
            '🔴 :tag slipped out the back.',
            '🔴 :tag vanished. Bold strategy.',
            '🔴 :tag logged off. Poof.',
            '🔴 :tag is gone. No forwarding address.',
            '🔴 :tag ghosted the server.',
            '🔴 :tag has left the building.',
            '🔴 :tag disappeared into the night.',
            '🔴 :tag rage-quit, took a break, who\'s to say.',
            '🔴 :tag pulled the plug.',
            '🔴 :tag noped out.',
        ],
    ],
];
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PersonalityConfigTest.php`
Expected: PASS (2 tests). If the neutrality test fails, a `bounty.ended` line contains a banned word — fix the line.

- [ ] **Step 5: Commit**

```bash
git add config/personality.php tests/Feature/PersonalityConfigTest.php
git commit -m "feat: add personality message pools config"
```

---

## Task 2: MessagePicker service

**Files:**
- Create: `app/Services/Personality/MessagePicker.php`
- Create: `tests/Feature/MessagePickerTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/MessagePickerTest.php`:

```php
<?php

use App\Services\Personality\MessagePicker;

it('interpolates tokens into the chosen line', function () {
    config()->set('personality.t_interp', ['hello :name, you have :n token(s)']);
    $picker = new MessagePicker(fn (array $pool, ?int $avoid) => 0);

    expect($picker->pick('t_interp', [':name' => 'Bob', ':n' => 3]))
        ->toBe('hello Bob, you have 3 token(s)');
});

it('returns a member of the pool', function () {
    config()->set('personality.t_member', ['a', 'b', 'c']);
    $picker = new MessagePicker(fn (array $pool, ?int $avoid) => 1);

    expect($picker->pick('t_member'))->toBe('b');
});

it('never repeats the immediately-previous line (default chooser, 2-line pool)', function () {
    config()->set('personality.t_norepeat', ['one', 'two']);
    $picker = new MessagePicker(); // real default chooser

    $prev = null;
    for ($i = 0; $i < 12; $i++) {
        $line = $picker->pick('t_norepeat');
        expect($line)->not->toBe($prev);
        $prev = $line;
    }
});

it('falls back to the provided string when the pool is missing', function () {
    $picker = new MessagePicker();
    expect($picker->pick('t_absent', [':x' => 'Y'], 'fallback :x here'))
        ->toBe('fallback Y here');
});

it('returns empty string when the pool is missing and no fallback given', function () {
    $picker = new MessagePicker();
    expect($picker->pick('t_absent_2'))->toBe('');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/MessagePickerTest.php`
Expected: FAIL — `Class "App\Services\Personality\MessagePicker" not found`.

- [ ] **Step 3: Implement MessagePicker**

Create `app/Services/Personality/MessagePicker.php`:

```php
<?php

namespace App\Services\Personality;

/**
 * Picks a random line from a personality pool (config/personality.php), avoiding the
 * immediately-previous line for that key, and interpolates :tokens. Best-effort: a missing
 * or empty pool returns the caller's plain fallback (or '' if none) so a message still sends.
 *
 * Randomness is injectable for deterministic tests: the chooser is
 * fn (array $pool, ?int $avoidIndex): int.
 */
class MessagePicker
{
    /** @var array<string,int> last-chosen index per key, shared across instances (long-running bot) */
    private static array $last = [];

    private \Closure $chooser;

    public function __construct(?\Closure $chooser = null)
    {
        $this->chooser = $chooser ?? function (array $pool, ?int $avoid): int {
            if (count($pool) <= 1) {
                return 0;
            }
            do {
                $index = array_rand($pool);
            } while ($index === $avoid);

            return $index;
        };
    }

    /**
     * @param  array<string,mixed>  $tokens  e.g. [':target' => '<@123>', ':tokens' => 2]
     */
    public function pick(string $key, array $tokens = [], ?string $fallback = null): string
    {
        $pool = config("personality.{$key}");

        if (! is_array($pool) || $pool === []) {
            return $fallback === null ? '' : strtr($fallback, $this->stringTokens($tokens));
        }

        $pool = array_values($pool);
        $index = ($this->chooser)($pool, self::$last[$key] ?? null);
        self::$last[$key] = $index;

        return strtr($pool[$index], $this->stringTokens($tokens));
    }

    /**
     * @param  array<string,mixed>  $tokens
     * @return array<string,string>
     */
    private function stringTokens(array $tokens): array
    {
        return array_map(fn ($value) => (string) $value, $tokens);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/MessagePickerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Personality/MessagePicker.php tests/Feature/MessagePickerTest.php
git commit -m "feat: add MessagePicker for randomized personality lines"
```

---

## Task 3: Wire DiscordBanNotifier (+ bannedKey helper)

**Files:**
- Modify: `app/Services/Ban/DiscordBanNotifier.php`
- Create: `tests/Feature/BannedKeyTest.php`

- [ ] **Step 1: Write the failing test for the routing helper**

Create `tests/Feature/BannedKeyTest.php`:

```php
<?php

use App\Models\Ban;
use App\Services\Ban\DiscordBanNotifier;

it('routes a death autoban to ban.death', function () {
    $ban = new Ban(['source' => 'auto_death']);
    expect(DiscordBanNotifier::bannedKey($ban, false))->toBe('ban.death');
});

it('routes a manual ban to ban.manual', function () {
    $ban = new Ban(['source' => 'admin']);
    expect(DiscordBanNotifier::bannedKey($ban, false))->toBe('ban.manual');
});

it('routes any extension to ban.extended', function () {
    expect(DiscordBanNotifier::bannedKey(new Ban(['source' => 'auto_death']), true))->toBe('ban.extended');
    expect(DiscordBanNotifier::bannedKey(new Ban(['source' => 'admin']), true))->toBe('ban.extended');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BannedKeyTest.php`
Expected: FAIL — `Call to undefined method ...::bannedKey()`.

- [ ] **Step 3: Rewrite the notifier's public methods + add the helper**

In `app/Services/Ban/DiscordBanNotifier.php`:

1. Add the import (with the existing `use` lines):

```php
use App\Services\Personality\MessagePicker;
```

2. Replace the constructor to accept an optional picker:

```php
    private MessagePicker $picker;

    public function __construct(private ?Discord $discord, private ?string $bansChannelId, ?MessagePicker $picker = null)
    {
        $this->picker = $picker ?? new MessagePicker();
    }
```

3. Replace `banned()` and `unbanned()` with:

```php
    public function banned(Ban $ban, Player $player, bool $isExtension): void
    {
        $who = (new PlayerMention())->forPlayer($player);
        $expires = $ban->expires_at ? "<t:{$ban->expires_at->timestamp}:f>" : 'never (permanent)';
        $key = self::bannedKey($ban, $isExtension);

        $fallbackAction = $isExtension ? 'Ban updated' : 'Player banned';
        $this->toChannel($this->picker->pick(
            $key,
            [':who' => $who, ':reason' => $ban->reason, ':expires' => $expires],
            "🔨 **{$fallbackAction}** — {$who} · {$ban->reason} · expires {$expires}"
        ));

        if ($player->discord_user_id) {
            $dmFallback = "🔨 You have been **banned** from the server.\n• Reason: {$ban->reason}\n• Expires: {$expires}";
            $dm = $key === 'ban.death'
                ? $this->picker->pick('ban.dm.death', [':expires' => $expires], $dmFallback)
                : $this->picker->pick('ban.dm.manual', [':reason' => $ban->reason, ':expires' => $expires], $dmFallback);
            $this->toUser($player->discord_user_id, $dm);
        }
    }

    public function unbanned(Player $player, string $reason, ?string $originalReason): void
    {
        $who = (new PlayerMention())->forPlayer($player);
        $this->toChannel($this->picker->pick(
            'ban.unbanned',
            [':who' => $who, ':reason' => $reason],
            "✅ **Player unbanned** — {$who} · {$reason}"
        ));

        if ($player->discord_user_id) {
            $this->toUser($player->discord_user_id, $this->picker->pick(
                'ban.dm.unbanned',
                [':reason' => $reason],
                "🕊️ Your ban has been removed.\n• Reason: {$reason}"
            ));
        }
    }

    /**
     * Map a ban to its personality pool key.
     * Public + static so it is unit-testable without a Discord gateway.
     */
    public static function bannedKey(Ban $ban, bool $isExtension): string
    {
        if ($isExtension) {
            return 'ban.extended';
        }

        return $ban->source === 'auto_death' ? 'ban.death' : 'ban.manual';
    }
```

Leave the private `toChannel()` and `toUser()` methods unchanged.

- [ ] **Step 4: Lint + run the helper test + full suite**

Run: `php -l app/Services/Ban/DiscordBanNotifier.php`
Expected: `No syntax errors detected`.

Run: `./vendor/bin/pest tests/Feature/BannedKeyTest.php`
Expected: PASS (3 tests).

Run: `./vendor/bin/pest`
Expected: green, exit 0.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ban/DiscordBanNotifier.php tests/Feature/BannedKeyTest.php
git commit -m "feat: route ban notifier through personality pools"
```

---

## Task 4: Wire DiscordBountyNotifier

**Files:**
- Modify: `app/Services/Bounty/DiscordBountyNotifier.php`

(No new unit test — the notifier is a thin gateway wrapper; the pools and picker are already covered. Verify via `php -l` + suite.)

- [ ] **Step 1: Add the import and optional picker to the constructor**

In `app/Services/Bounty/DiscordBountyNotifier.php`, add with the existing `use` lines:

```php
use App\Services\Personality\MessagePicker;
```

Replace the constructor:

```php
    private MessagePicker $picker;

    public function __construct(private ?Discord $discord, private ?string $channelId, ?MessagePicker $picker = null)
    {
        $this->picker = $picker ?? new MessagePicker();
    }
```

- [ ] **Step 2: Replace the four public methods**

```php
    public function placed(Bounty $bounty, Player $target): void
    {
        $targetDisplay = (new PlayerMention())->forPlayer($target);
        $this->toChannel($this->picker->pick(
            'bounty.placed',
            [':target' => $targetDisplay],
            "🎯 **Bounty placed** on {$targetDisplay} — kill them for an unban token!"
        ));
        if ($target->discord_user_id) {
            $this->toUser($target->discord_user_id, $this->picker->pick(
                'bounty.dm.placed', [], '🎯 A bounty has been placed on you. Watch your back.'
            ));
        }
    }

    public function moved(Bounty $bounty, Player $target): void
    {
        $targetDisplay = (new PlayerMention())->forPlayer($target);
        $this->toChannel($this->picker->pick(
            'bounty.moved',
            [':target' => $targetDisplay],
            "🎯 **Bounty moved** — {$targetDisplay} is now the longest-surviving target."
        ));
        if ($target->discord_user_id) {
            $this->toUser($target->discord_user_id, $this->picker->pick(
                'bounty.dm.moved', [], '🎯 The bounty is now on you. Watch your back.'
            ));
        }
    }

    public function claimed(Bounty $bounty, Player $target, Player $killer, int $tokens): void
    {
        $mention = new PlayerMention();
        $killerDisplay = $mention->forPlayer($killer);
        $targetDisplay = $mention->forPlayer($target);
        $this->toChannel($this->picker->pick(
            'bounty.claimed',
            [':killer' => $killerDisplay, ':target' => $targetDisplay, ':tokens' => $tokens],
            "💀 **Bounty claimed!** {$killerDisplay} killed {$targetDisplay} and earned {$tokens} unban token(s)."
        ));
        if ($killer->discord_user_id) {
            // DM stays plain gamertag (no mention).
            $this->toUser($killer->discord_user_id, $this->picker->pick(
                'bounty.dm.claimed',
                [':target' => $target->gamertag, ':tokens' => $tokens],
                "💰 You claimed the bounty on `{$target->gamertag}` and earned {$tokens} unban token(s)!"
            ));
        }
    }

    public function ended(Bounty $bounty, Player $target, string $reason): void
    {
        // Neutral wording — never reveals whether a reward was paid (associate-farm guard).
        $targetDisplay = (new PlayerMention())->forPlayer($target);
        $this->toChannel($this->picker->pick(
            'bounty.ended',
            [':target' => $targetDisplay],
            "🏳️ **Bounty ended** — the bounty on {$targetDisplay} is no longer active."
        ));
    }
```

Leave the private `toChannel()` and `toUser()` methods unchanged.

- [ ] **Step 3: Lint + full suite**

Run: `php -l app/Services/Bounty/DiscordBountyNotifier.php`
Expected: `No syntax errors detected`.

Run: `./vendor/bin/pest`
Expected: green, exit 0.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Bounty/DiscordBountyNotifier.php
git commit -m "feat: route bounty notifier through personality pools"
```

---

## Task 5: Wire DiscordConnectionNotifier

**Files:**
- Modify: `app/Services/Connection/DiscordConnectionNotifier.php`

- [ ] **Step 1: Add the import and optional picker to the constructor**

In `app/Services/Connection/DiscordConnectionNotifier.php`, add with the existing `use` line:

```php
use App\Services\Personality\MessagePicker;
```

Replace the constructor:

```php
    private MessagePicker $picker;

    public function __construct(private ?Discord $discord, private ?string $channelId, ?MessagePicker $picker = null)
    {
        $this->picker = $picker ?? new MessagePicker();
    }
```

- [ ] **Step 2: Replace the two public methods**

```php
    public function connected(string $gamertag, \DateTimeImmutable $ts): void
    {
        $tag = "`{$gamertag}`";
        $this->toChannel($this->picker->pick('connection.connected', [':tag' => $tag], "🟢 {$tag} connected"));
    }

    public function disconnected(string $gamertag, \DateTimeImmutable $ts, ?int $sessionSeconds): void
    {
        $tag = "`{$gamertag}`";

        if ($sessionSeconds === null) {
            $this->toChannel($this->picker->pick('connection.disconnected_nodur', [':tag' => $tag], "🔴 {$tag} disconnected"));

            return;
        }

        $duration = SessionDuration::human($sessionSeconds);
        $this->toChannel($this->picker->pick(
            'connection.disconnected',
            [':tag' => $tag, ':duration' => $duration],
            "🔴 {$tag} disconnected · on for {$duration}"
        ));
    }
```

Leave the private `toChannel()` method unchanged. (`SessionDuration` is already imported/used in this file.)

- [ ] **Step 3: Lint + full suite**

Run: `php -l app/Services/Connection/DiscordConnectionNotifier.php`
Expected: `No syntax errors detected`.

Run: `./vendor/bin/pest`
Expected: green, exit 0.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Connection/DiscordConnectionNotifier.php
git commit -m "feat: route connection notifier through personality pools"
```

---

## Task 6: Document the personality layer in CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add a bullet under the Architecture section**

In `CLAUDE.md`, immediately after the "Gamertag rendering" bullet (the one describing
`PlayerMention`), insert:

```markdown
- **Message personality** — `app/Services/Personality/MessagePicker` + `config/personality.php`.
  Every public notifier message (bounty / ban+death / connection — channel posts AND the player
  DMs) is drawn from a pool of ≥10 cheeky, playful lines keyed by dot-key (e.g. `bounty.placed`,
  `ban.death`, `connection.disconnected`). `pick(key, tokens, fallback)` selects a random line,
  avoids the immediately-previous line for that key (process-wide static), interpolates `:tokens`,
  and returns a plain `fallback` if a pool is ever empty. Randomness is an injectable closure so
  tests are deterministic. **Constraints baked in:** `bounty.ended` lines stay neutral (never hint
  at a payout — associate-farm guard, asserted by a test); connection lines never @-mention; DM
  pools use the plain gamertag. Add personality to any new public message by adding a pool + one
  `pick()` call. Ban routing (`death` / `manual` / `extended`) is the pure
  `DiscordBanNotifier::bannedKey()`.
```

- [ ] **Step 2: Verify the suite is still green**

Run: `./vendor/bin/pest`
Expected: green, exit 0.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: document message personality layer"
```

---

## Self-Review notes

- **Spec coverage:** config pools (Task 1), picker w/ anti-repeat + interpolation + fallback +
  injectable chooser (Task 2), ban wiring + `bannedKey` routing (Task 3), bounty wiring incl.
  neutral `ended` and plain-gamertag DMs (Task 4), connection wiring incl. duration vs nodur split
  and no-mention `:tag` (Task 5), pool-completeness + `ended` neutrality tests (Task 1), docs
  (Task 6). All spec sections covered.
- **Type consistency:** `pick(string $key, array $tokens = [], ?string $fallback = null): string`
  is defined in Task 2 and called identically in Tasks 3–5. `bannedKey(Ban $ban, bool $isExtension):
  string` is defined and tested in Task 3 and used in Task 3's `banned()`. Constructor signature
  `(?Discord, ?string, ?MessagePicker = null)` is consistent across all three notifiers and keeps
  existing 2-arg call-sites valid.
- **No placeholders:** every pool line, the full picker, and every notifier method body are shown
  in full.
