# LLM-Generated Bounty & Ban Channel Messages — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate the public bounty (placed/moved/claimed/ended) and ban (death/manual/extended/unbanned) **channel** announcements via OpenRouter, with the existing canned personality pools as a byte-for-byte fallback; un-suppress death-bans so they post to `BANS_CHANNEL_ID`.

**Architecture:** A single new `App\Services\Llm\FlavorGenerator` wraps the existing `OpenRouterClient`, emits one cheeky line containing `{{PLACEHOLDER}}` tokens, substitutes them with the caller's channel values (mentions, counts, timestamps), and on **any** failure delegates to `MessagePicker::pick()` for the same dot-key. `DiscordBountyNotifier` and `DiscordBanNotifier` gain an optional `?FlavorGenerator $flavor = null` constructor param (lazy-defaulting to `FlavorGenerator::fromConfig()`) and route their **channel** posts through it; DMs stay canned. The `ban.death` channel-suppression guard (`postsToChannel()`) is removed.

**Tech Stack:** PHP 8.2+ / Laracord (Laravel Zero), Pest, `Illuminate\Support\Facades\Http` (faked in tests), OpenRouter via the existing `config/llm.php` block.

**Plan refinement vs spec:** The spec sketched `line(key, facts, tokens, fallback)`. The `facts` param is dropped — for these events every safe, non-identifying detail is already carried as a `{{PLACEHOLDER}}` token (mention, reason, expiry, token count), so a `facts` payload would always be empty. Final signature: `line(string $key, array $tokens, string $fallback)`. Everything else matches the spec.

---

## File Structure

- **Create:** `app/Services/Llm/FlavorGenerator.php` — LLM line generator + canned fallback (one responsibility: turn a key + tokens into one channel line).
- **Create:** `tests/Feature/FlavorGeneratorTest.php` — generator unit/feature tests.
- **Modify:** `app/Services/Ban/DiscordBanNotifier.php` — inject generator; route channel posts through it; remove `postsToChannel()`.
- **Modify:** `app/Services/Bounty/DiscordBountyNotifier.php` — inject generator; route channel posts through it.
- **Modify:** `config/personality.php` — update the stale `ban.death` "do not re-wire" comment (pools unchanged).
- **Rewrite:** `tests/Feature/DiscordBanNotifierDeathTest.php` — assert death bans now post through the generator (suppression removed).
- **Create:** `tests/Feature/DiscordBountyNotifierTest.php` — assert bounty channel posts route through the generator.
- **Modify:** `CLAUDE.md` — document the new generator + death-ban-posts-to-bans-channel behavior.

No new env keys, no config additions, no migrations. The 6 existing notifier call sites (`IngestAdmService`, `BanExpiryService`, `AdminBanCommand`, `UnbanCommand`, `AdminUnbanCommand`, `BountyTickService`) need **no changes** — the new constructor param defaults handle them.

---

## Task 1: `FlavorGenerator` (LLM line + canned fallback)

**Files:**
- Create: `app/Services/Llm/FlavorGenerator.php`
- Test: `tests/Feature/FlavorGeneratorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FlavorGeneratorTest.php`:

```php
<?php

use App\Services\Llm\FlavorGenerator;
use App\Services\Llm\OpenRouterClient;
use App\Services\Personality\MessagePicker;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => MessagePicker::reset());

// Deterministic chooser (index 0) so canned-fallback assertions are stable.
function flavorGen(?string $key = 'sk'): FlavorGenerator {
    return new FlavorGenerator(
        new OpenRouterClient($key, 'm', 'https://x/api/v1', 20, 900, 1.0),
        new MessagePicker(fn (array $pool, ?int $avoid) => 0),
    );
}

it('substitutes placeholder tokens into the LLM line', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '🎯 **Bounty** is now on {{TARGET}} — go get them!']]],
    ])]);

    $line = flavorGen()->line('bounty.placed', ['target' => '<@123>'], 'FB');

    expect($line)->toContain('<@123>')->and($line)->not->toContain('{{TARGET}}');
});

it('falls back to the canned pool when the client throws', function () {
    Http::fake(['*/chat/completions' => Http::response([], 500)]);

    $line = flavorGen()->line('bounty.placed', ['target' => '<@123>'], 'FB');

    $expected = strtr(array_values(config('personality.bounty.placed'))[0], [':target' => '<@123>']);
    expect($line)->toBe($expected);
});

it('falls back when the LLM leaves an unprovided placeholder in the output', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '{{TARGET}} fell to {{KILLER}}']]],
    ])]);

    // Only 'target' is provided, so {{KILLER}} survives substitution -> treated as a failure.
    $line = flavorGen()->line('bounty.placed', ['target' => '<@1>'], 'FB');

    $expected = strtr(array_values(config('personality.bounty.placed'))[0], [':target' => '<@1>']);
    expect($line)->toBe($expected)->and($line)->not->toContain('{{KILLER}}');
});

it('falls back to canned copy with no api key and makes no HTTP call', function () {
    Http::fake();

    $line = flavorGen(key: null)->line('ban.unbanned', ['who' => '<@9>', 'reason' => 'expired'], 'FB');

    $expected = strtr(array_values(config('personality.ban.unbanned'))[0], [':who' => '<@9>', ':reason' => 'expired']);
    expect($line)->toBe($expected);
    Http::assertNothingSent();
});

it('instructs the model to stay neutral for bounty.ended', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '🏳️ The bounty on {{TARGET}} is over.']]],
    ])]);

    flavorGen()->line('bounty.ended', ['target' => '<@1>'], 'FB');

    Http::assertSent(function ($r) {
        $user = $r['messages'][1]['content'];
        return str_contains($user, 'neutral')
            && str_contains($user, 'do NOT')
            && str_contains($user, '{{TARGET}}');
    });
});

it('routes ban.death through the generator and substitutes its tokens', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '⚰️ {{WHO}} is benched until {{EXPIRES}} ({{REASON}}).']]],
    ])]);

    $line = flavorGen()->line('ban.death', ['who' => '<@7>', 'reason' => 'One life autoban', 'expires' => '<t:123:f>'], 'FB');

    expect($line)->toContain('<@7>')->and($line)->toContain('<t:123:f>')->and($line)->not->toContain('{{');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/FlavorGeneratorTest.php`
Expected: FAIL — `Class "App\Services\Llm\FlavorGenerator" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Services/Llm/FlavorGenerator.php`:

```php
<?php

namespace App\Services\Llm;

use App\Services\Personality\MessagePicker;

/**
 * Generates a single cheeky one-line PUBLIC channel announcement (bounty + ban events) via
 * OpenRouter, substituting {{PLACEHOLDER}} tokens with the caller's channel values (mentions,
 * counts, timestamps). Any failure — no key, timeout, non-2xx, empty, or an un-substituted
 * placeholder left in the output — falls back to the canned personality pool for the same key,
 * byte-for-byte the pre-LLM behavior. DMs are NOT handled here (they stay canned).
 */
class FlavorGenerator
{
    private const SYSTEM = <<<'TXT'
You are the cheeky announcer for a hardcore DayZ "one life" Discord server, where players get ONE
life and a death means a ban. Write ONE short, punchy, funny line (a single sentence, ~10-25 words)
for the public feed. Light Discord markdown (**bold**) and a fitting emoji or two are good.

Rules:
- Include EVERY placeholder token you are told to use, written EXACTLY as given (e.g. {{TARGET}}),
  and invent no others. NEVER write a real name, number, or date in a placeholder's place.
- Use ONLY the information given. Never fabricate weapons, distances, reasons, kill counts, or times.
- NEVER reveal map locations: no coordinates, grid references, or in-world place names.
- Output the single line ONLY — no surrounding quotes, no headline, no preamble, no extra lines.
TXT;

    public function __construct(
        private OpenRouterClient $client,
        private ?MessagePicker $picker = null,
    ) {}

    public static function fromConfig(): self
    {
        return new self(OpenRouterClient::fromConfig(), new MessagePicker());
    }

    /**
     * @param  string  $key      personality dot-key, e.g. 'bounty.placed' / 'ban.death'
     * @param  array<string,mixed>  $tokens  placeholder name => channel value, e.g.
     *         ['target' => '<@123>', 'tokens' => 2]; mapped to {{TARGET}} for the LLM and
     *         :target for the canned fallback.
     * @param  string  $fallback  the plain literal already passed to the pool today
     */
    public function line(string $key, array $tokens, string $fallback): string
    {
        try {
            $raw = $this->client->complete(self::SYSTEM, $this->userPrompt($key));
            $line = $this->substitute(trim($raw), $tokens);

            // A residual {{TOKEN}} means the model invented/kept a token we have no value for:
            // treat it as a failure so we never ship a raw placeholder to the channel.
            if ($line === '' || preg_match('/\{\{[A-Z_]+\}\}/', $line) === 1) {
                throw new \RuntimeException('empty or unsubstituted placeholder');
            }

            return $line;
        } catch (\Throwable) {
            $picker = $this->picker ?? new MessagePicker();

            return $picker->pick($key, $this->colonTokens($tokens), $fallback);
        }
    }

    private function userPrompt(string $key): string
    {
        return match ($key) {
            'bounty.placed' => 'Announce that a NEW bounty is now on {{TARGET}} — kill them to earn an unban token. Build the tension. Include: {{TARGET}}.',
            'bounty.moved' => 'Announce that the bounty has MOVED: {{TARGET}} is now the longest-surviving target, the one everyone should hunt. Include: {{TARGET}}.',
            'bounty.claimed' => 'Celebrate that {{KILLER}} hunted down bounty target {{TARGET}} and collected {{TOKENS}} unban token(s). Include: {{KILLER}}, {{TARGET}}, {{TOKENS}}.',
            'bounty.ended' => 'Announce ONLY that the bounty on {{TARGET}} is no longer active. CRITICAL: stay strictly neutral — do NOT say or imply whether any reward, token, payout, or claim happened, and never use the words "token", "reward", "paid", or "claim". Include: {{TARGET}}.',
            'ban.death' => 'A survivor ran out of their ONE life and is benched. Announce that {{WHO}} is banned until {{EXPIRES}} ({{REASON}}). Frame it as the CONSEQUENCE — do NOT retell how they died. Include: {{WHO}}, {{REASON}}, {{EXPIRES}}.',
            'ban.manual' => 'Announce that {{WHO}} has caught a ban ({{REASON}}) and is out until {{EXPIRES}}. Include: {{WHO}}, {{REASON}}, {{EXPIRES}}.',
            'ban.extended' => "Announce that {{WHO}}'s ban was extended/remixed ({{REASON}}); it now expires {{EXPIRES}}. Include: {{WHO}}, {{REASON}}, {{EXPIRES}}.",
            'ban.unbanned' => 'Announce that {{WHO}} is free again — their ban was lifted ({{REASON}}). Tell them to try to keep this life alive. Include: {{WHO}}, {{REASON}}.',
            default => 'Write a short announcement and include every {{PLACEHOLDER}} you were given.',
        };
    }

    /** @param array<string,mixed> $tokens */
    private function substitute(string $line, array $tokens): string
    {
        $map = [];
        foreach ($tokens as $name => $value) {
            $map['{{'.strtoupper($name).'}}'] = (string) $value;
        }

        return strtr($line, $map);
    }

    /**
     * @param  array<string,mixed>  $tokens
     * @return array<string,mixed>  ':name' => value, for the MessagePicker::pick fallback
     */
    private function colonTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $name => $value) {
            $out[':'.$name] = $value;
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/FlavorGeneratorTest.php`
Expected: PASS (6 tests green; `DEPR` deprecation lines in output are harmless).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Llm/FlavorGenerator.php tests/Feature/FlavorGeneratorTest.php
git commit -m "feat: add FlavorGenerator for LLM channel-line announcements

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Wire `DiscordBanNotifier` through the generator + un-suppress death

**Files:**
- Modify: `app/Services/Ban/DiscordBanNotifier.php`
- Modify: `config/personality.php` (comment only)
- Rewrite: `tests/Feature/DiscordBanNotifierDeathTest.php`

- [ ] **Step 1: Write the failing test**

Replace the entire contents of `tests/Feature/DiscordBanNotifierDeathTest.php` with:

```php
<?php

use App\Models\Ban;
use App\Models\Player;
use App\Services\Ban\DiscordBanNotifier;
use App\Services\Llm\FlavorGenerator;
use App\Services\Llm\OpenRouterClient;
use Carbon\CarbonImmutable;

/** Test double: records the keys the notifier asks the generator to produce. */
class RecordingFlavor extends FlavorGenerator
{
    /** @var list<string> */
    public array $keys = [];

    public function __construct()
    {
        parent::__construct(new OpenRouterClient(null, 'm', 'https://x/api/v1'));
    }

    public function line(string $key, array $tokens, string $fallback): string
    {
        $this->keys[] = $key;

        return 'stub';
    }
}

it('routes a death autoban to the bans channel via the generator (no longer suppressed)', function () {
    $flavor = new RecordingFlavor();
    $notifier = new DiscordBanNotifier(null, 'chan', null, $flavor);

    $ban = new Ban([
        'source' => 'auto_death',
        'reason' => 'One life autoban',
        'expires_at' => CarbonImmutable::now()->addHours(12),
    ]);
    $player = new Player(['gamertag' => 'Doomed']); // no discord link -> no DM path

    $notifier->banned($ban, $player, false);

    expect($flavor->keys)->toContain('ban.death');
});

it('routes manual and extended bans through the generator too', function () {
    $flavor = new RecordingFlavor();
    $notifier = new DiscordBanNotifier(null, 'chan', null, $flavor);

    $notifier->banned(new Ban(['source' => 'admin', 'reason' => 'cheating', 'expires_at' => CarbonImmutable::now()->addDay()]), new Player(['gamertag' => 'A']), false);
    $notifier->banned(new Ban(['source' => 'admin', 'reason' => 'again', 'expires_at' => CarbonImmutable::now()->addDay()]), new Player(['gamertag' => 'B']), true);

    expect($flavor->keys)->toBe(['ban.manual', 'ban.extended']);
});

it('routes an unban through the generator', function () {
    $flavor = new RecordingFlavor();
    $notifier = new DiscordBanNotifier(null, 'chan', null, $flavor);

    $notifier->unbanned(new Player(['gamertag' => 'Freed']), 'Ban expired', 'One life autoban');

    expect($flavor->keys)->toBe(['ban.unbanned']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/DiscordBanNotifierDeathTest.php`
Expected: FAIL — `DiscordBanNotifier::__construct()` does not accept a 4th argument (and/or `ban.death` not found in `$flavor->keys` because the channel post is still suppressed).

- [ ] **Step 3a: Modify the notifier — constructor + import**

In `app/Services/Ban/DiscordBanNotifier.php`, add the import after the existing `use` block:

```php
use App\Services\Llm\FlavorGenerator;
```

Replace the property + constructor:

```php
    private MessagePicker $picker;

    public function __construct(private ?Discord $discord, private ?string $bansChannelId, ?MessagePicker $picker = null)
    {
        $this->picker = $picker ?? new MessagePicker();
    }
```

with:

```php
    private MessagePicker $picker;

    private FlavorGenerator $flavor;

    public function __construct(
        private ?Discord $discord,
        private ?string $bansChannelId,
        ?MessagePicker $picker = null,
        ?FlavorGenerator $flavor = null,
    ) {
        $this->picker = $picker ?? new MessagePicker();
        $this->flavor = $flavor ?? FlavorGenerator::fromConfig();
    }
```

- [ ] **Step 3b: Route `banned()` through the generator and drop the suppression check**

Replace the `banned()` method body's channel block. Change:

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

to:

```php
        $fallbackAction = $isExtension ? 'Ban updated' : 'Player banned';
        $this->toChannel($this->flavor->line(
            $key,
            ['who' => $who, 'reason' => $ban->reason, 'expires' => $expires],
            "🔨 **{$fallbackAction}** — {$who} · {$ban->reason} · expires {$expires}"
        ));
```

(The DM block below it — the `if ($player->discord_user_id)` section — is unchanged.)

- [ ] **Step 3c: Route `unbanned()` through the generator**

Change the channel block in `unbanned()`. Replace:

```php
        $this->toChannel($this->picker->pick(
            'ban.unbanned',
            [':who' => $who, ':reason' => $reason],
            "✅ **Player unbanned** — {$who} · {$reason}"
        ));
```

with:

```php
        $this->toChannel($this->flavor->line(
            'ban.unbanned',
            ['who' => $who, 'reason' => $reason],
            "✅ **Player unbanned** — {$who} · {$reason}"
        ));
```

(The DM block below it is unchanged.)

- [ ] **Step 3d: Remove the now-dead `postsToChannel()` method**

Delete this entire method (and its doc-comment) from `app/Services/Ban/DiscordBanNotifier.php`:

```php
    /**
     * Whether the ban notifier posts this key to the bans channel. The lifecycle eulogy
     * feed (LifecycleAnnouncer) owns the public death announcement, so ban.death is
     * channel-suppressed here; the ban.dm.death DM is still sent. Public + static so it
     * is unit-testable without a Discord gateway.
     */
    public static function postsToChannel(string $key): bool
    {
        return $key !== 'ban.death';
    }
```

(Keep `bannedKey()` — it is still used and still covered by `BannedKeyTest`.)

- [ ] **Step 3e: Update the stale `ban.death` comment in `config/personality.php`**

Replace the comment block above the `'death' => [` entry in the `'ban' => [` section. Change:

```php
        // NOTE: this 'ban.death' pool is NO LONGER used for channel posts — the lifecycle
        // eulogy feed (LifecycleAnnouncer, via eulogy.* pools) owns the public death
        // announcement (DiscordBanNotifier::postsToChannel suppresses 'ban.death'). The
        // banned player still gets the separate 'ban.dm.death' DM below. Retained as a
        // reference/fallback; do not re-wire the ban notifier to use it.
```

with:

```php
        // 'ban.death' is the canned FALLBACK for the death-ban channel post (FlavorGenerator
        // generates the live copy via OpenRouter). The death-ban post goes to BANS_CHANNEL_ID
        // and is framed as the CONSEQUENCE (benched until X) — distinct from the eulogy, which
        // tells the death story separately in EULOGY_CHANNEL_ID. The banned player also gets the
        // 'ban.dm.death' DM below.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/DiscordBanNotifierDeathTest.php tests/Feature/BannedKeyTest.php`
Expected: PASS (death/manual/extended/unban route through the generator; `bannedKey` mapping intact).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ban/DiscordBanNotifier.php config/personality.php tests/Feature/DiscordBanNotifierDeathTest.php
git commit -m "feat: LLM-generate ban channel posts; post death-bans to bans channel

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Wire `DiscordBountyNotifier` through the generator

**Files:**
- Modify: `app/Services/Bounty/DiscordBountyNotifier.php`
- Test: `tests/Feature/DiscordBountyNotifierTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DiscordBountyNotifierTest.php`:

```php
<?php

use App\Models\Bounty;
use App\Models\Player;
use App\Services\Bounty\DiscordBountyNotifier;
use App\Services\Llm\FlavorGenerator;
use App\Services\Llm\OpenRouterClient;

/** Test double: records the keys the notifier asks the generator to produce. */
class RecordingBountyFlavor extends FlavorGenerator
{
    /** @var list<string> */
    public array $keys = [];

    public function __construct()
    {
        parent::__construct(new OpenRouterClient(null, 'm', 'https://x/api/v1'));
    }

    public function line(string $key, array $tokens, string $fallback): string
    {
        $this->keys[] = $key;

        return 'stub';
    }
}

it('routes placed / moved / claimed / ended channel posts through the generator', function () {
    $flavor = new RecordingBountyFlavor();
    $notifier = new DiscordBountyNotifier(null, 'chan', null, $flavor);

    $bounty = new Bounty();
    $target = new Player(['gamertag' => 'Target']);   // no discord link -> no DM path
    $killer = new Player(['gamertag' => 'Hunter']);

    $notifier->placed($bounty, $target);
    $notifier->moved($bounty, $target);
    $notifier->claimed($bounty, $target, $killer, 2);
    $notifier->ended($bounty, $target, 'killed');

    expect($flavor->keys)->toBe(['bounty.placed', 'bounty.moved', 'bounty.claimed', 'bounty.ended']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/DiscordBountyNotifierTest.php`
Expected: FAIL — `DiscordBountyNotifier::__construct()` does not accept a 4th argument.

- [ ] **Step 3a: Modify the constructor + import**

In `app/Services/Bounty/DiscordBountyNotifier.php`, add the import after the existing `use` block:

```php
use App\Services\Llm\FlavorGenerator;
```

Replace:

```php
    private MessagePicker $picker;

    public function __construct(private ?Discord $discord, private ?string $channelId, ?MessagePicker $picker = null)
    {
        $this->picker = $picker ?? new MessagePicker();
    }
```

with:

```php
    private MessagePicker $picker;

    private FlavorGenerator $flavor;

    public function __construct(
        private ?Discord $discord,
        private ?string $channelId,
        ?MessagePicker $picker = null,
        ?FlavorGenerator $flavor = null,
    ) {
        $this->picker = $picker ?? new MessagePicker();
        $this->flavor = $flavor ?? FlavorGenerator::fromConfig();
    }
```

- [ ] **Step 3b: Route the four channel posts through the generator**

In `placed()`, replace:

```php
        $this->toChannel($this->picker->pick(
            'bounty.placed',
            [':target' => $targetDisplay],
            "🎯 **Bounty placed** on {$targetDisplay} — kill them for an unban token!"
        ));
```

with:

```php
        $this->toChannel($this->flavor->line(
            'bounty.placed',
            ['target' => $targetDisplay],
            "🎯 **Bounty placed** on {$targetDisplay} — kill them for an unban token!"
        ));
```

In `moved()`, replace:

```php
        $this->toChannel($this->picker->pick(
            'bounty.moved',
            [':target' => $targetDisplay],
            "🎯 **Bounty moved** — {$targetDisplay} is now the longest-surviving target."
        ));
```

with:

```php
        $this->toChannel($this->flavor->line(
            'bounty.moved',
            ['target' => $targetDisplay],
            "🎯 **Bounty moved** — {$targetDisplay} is now the longest-surviving target."
        ));
```

In `claimed()`, replace:

```php
        $this->toChannel($this->picker->pick(
            'bounty.claimed',
            [':killer' => $killerDisplay, ':target' => $targetDisplay, ':tokens' => $tokens],
            "💀 **Bounty claimed!** {$killerDisplay} killed {$targetDisplay} and earned {$tokens} unban token(s)."
        ));
```

with:

```php
        $this->toChannel($this->flavor->line(
            'bounty.claimed',
            ['killer' => $killerDisplay, 'target' => $targetDisplay, 'tokens' => $tokens],
            "💀 **Bounty claimed!** {$killerDisplay} killed {$targetDisplay} and earned {$tokens} unban token(s)."
        ));
```

In `ended()`, replace:

```php
        $this->toChannel($this->picker->pick(
            'bounty.ended',
            [':target' => $targetDisplay],
            "🏳️ **Bounty ended** — the bounty on {$targetDisplay} is no longer active."
        ));
```

with:

```php
        $this->toChannel($this->flavor->line(
            'bounty.ended',
            ['target' => $targetDisplay],
            "🏳️ **Bounty ended** — the bounty on {$targetDisplay} is no longer active."
        ));
```

(All three DM blocks — the `if ($target->discord_user_id)` / `if ($killer->discord_user_id)` sections — are unchanged.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/DiscordBountyNotifierTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bounty/DiscordBountyNotifier.php tests/Feature/DiscordBountyNotifierTest.php
git commit -m "feat: LLM-generate bounty channel posts via FlavorGenerator

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Docs + full suite

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update `CLAUDE.md`**

In the bounty-system bullet (the `**Bounty system**` paragraph), append after the existing notifier sentence:

```
The four public bounty channel posts (placed/moved/claimed/ended) are LLM-generated via
`app/Services/Llm/FlavorGenerator` (OpenRouter, reusing the `OPENROUTER_*` / `config/llm.php`
block), with the `bounty.*` personality pools as the canned fallback; DMs stay canned. The
`bounty.ended` neutrality rule (no payout hints) is enforced in the generator prompt AND the pool.
```

In the births/eulogies bullet (or the ban section near `DiscordBanNotifier`), append:

```
Ban CHANNEL posts (death/manual/extended/unbanned) are now LLM-generated via
`app/Services/Llm/FlavorGenerator` with the `ban.*` pools as fallback (DMs stay canned). Death-bans
are **no longer channel-suppressed** — they post a consequence-framed notice to `BANS_CHANNEL_ID`
(the `postsToChannel()` guard was removed), in ADDITION to the separate eulogy in `EULOGY_CHANNEL_ID`.
So a death with ≥`BAN_MIN_PLAYTIME_MINUTES` playtime produces two posts (eulogy + ban notice).
```

- [ ] **Step 2: Run the full suite**

Run: `./vendor/bin/pest`
Expected: PASS — all green. Pay attention that `PersonalityConfigTest` (required keys + `bounty.ended` neutrality) and `AnnouncementGeneratorTest` still pass. `DEPR` deprecation lines are harmless.

- [ ] **Step 3: Lint the changed PHP files**

Run: `php -l app/Services/Llm/FlavorGenerator.php && php -l app/Services/Ban/DiscordBanNotifier.php && php -l app/Services/Bounty/DiscordBountyNotifier.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: note LLM-generated bounty/ban messages + death-ban channel post

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- LLM-generate bounty placed/moved/claimed/ended channel posts → Task 3. ✅
- LLM-generate ban death/manual/extended channel posts → Task 2. ✅
- LLM-generate ban-expired (unbanned) channel post → Task 2. ✅
- Canned pools as byte-for-byte fallback → `FlavorGenerator::line()` catch path (Task 1), verified by tests. ✅
- DMs stay canned → DM blocks untouched in Tasks 2 & 3. ✅
- Death-bans post to `BANS_CHANNEL_ID`, eulogy stays separate (two posts) → `postsToChannel()` removed (Task 2), documented (Task 4). ✅
- No new env key / reuse `BANS_CHANNEL_ID` + `OPENROUTER_*` → no config/env changes. ✅
- `bounty.ended` neutrality preserved → generator prompt instruction (Task 1, asserted) + existing pool test unchanged. ✅
- Un-substituted-placeholder safety guard → `line()` regex check (Task 1, asserted). ✅

**Placeholder scan:** No TBD/TODO/"handle edge cases"; every code step shows full code.

**Type consistency:** `FlavorGenerator::line(string $key, array $tokens, string $fallback): string` is used identically in Tasks 2 & 3; token-name keys (`who`/`reason`/`expires`, `target`/`killer`/`tokens`) map to both `{{UPPER}}` placeholders (prompt) and `:lower` pool tokens (fallback) consistently; `fromConfig()` matches the `OpenRouterClient::fromConfig()` pattern. The test doubles override the exact `line()` signature.
