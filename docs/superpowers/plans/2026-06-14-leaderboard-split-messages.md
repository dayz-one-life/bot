# Leaderboard Split-Messages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single 7-field leaderboard embed with 7 independent embed messages (one per board), each showing up to 25 ranked entries with its own personality line.

**Architecture:** `LeaderboardComposer` produces an ordered list of 7 `{key,title,description}` board payloads (entries in the embed description to clear Discord's 1024-char field cap). `DiscordLeaderboardNotifier` posts/edits 7 messages in place, persisting their ids as a JSON list in `bot_state`, and atomically reposts all 7 (in order) if any goes missing. The periodic `LeaderboardService` wires stats → composer → notifier unchanged in spirit.

**Tech Stack:** Laracord (Laravel Zero + DiscordPHP), PHP 8.2+, Pest, SQLite. DiscordPHP uses ReactPHP promises.

**Spec:** `docs/superpowers/specs/2026-06-14-leaderboard-split-messages-design.md`

**Convention notes:**
- TDD: failing test first, then minimal implementation.
- `DiscordLeaderboardNotifier` is **not** unit-tested (no Discord gateway in tests) — verify with `php -l` only, per repo convention.
- Intermediate tasks run only the touched file's tests; **Task 7 runs the full suite green**. Between Tasks 3–6 the full suite is briefly red (compose→composeBoards rename) — this is expected and resolved by Task 6.
- Run a single test file with `./vendor/bin/pest tests/Path/File.php`.

---

### Task 1: Bump `top_count` default to 25

**Files:**
- Modify: `config/leaderboard.php:9`
- Modify: `phpunit.xml:25`
- Test: `tests/Feature/LeaderboardConfigTest.php:13`

- [ ] **Step 1: Update the failing assertion**

In `tests/Feature/LeaderboardConfigTest.php`, change the `top_count` expectation:

```php
    expect(config('leaderboard.top_count'))->toBe(25);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LeaderboardConfigTest.php`
Expected: FAIL — `Failed asserting that 5 is identical to 25` (the phpunit env pin is still 5).

- [ ] **Step 3: Update the phpunit pin and config default**

In `phpunit.xml`, change line 25:

```xml
        <env name="LEADERBOARD_TOP_COUNT" value="25"/>
```

In `config/leaderboard.php`, change the `top_count` line:

```php
    'top_count' => (int) env('LEADERBOARD_TOP_COUNT', 25),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LeaderboardConfigTest.php`
Expected: PASS (both tests green).

- [ ] **Step 5: Commit**

```bash
git add config/leaderboard.php phpunit.xml tests/Feature/LeaderboardConfigTest.php
git commit -m "feat: default leaderboard top_count to 25"
```

---

### Task 2: Add 7 per-board personality pools, retire `leaderboard.intro`

**Files:**
- Modify: `config/personality.php` (the `leaderboard.intro` entry)
- Test: `tests/Feature/PersonalityConfigTest.php:11`

- [ ] **Step 1: Update the pool-completeness test key list**

In `tests/Feature/PersonalityConfigTest.php`, replace the `'leaderboard.intro',` line (line 11) with the 7 board keys:

```php
        'leaderboard.alive', 'leaderboard.all_time', 'leaderboard.kills',
        'leaderboard.streak', 'leaderboard.distance', 'leaderboard.bunker_visits',
        'leaderboard.quickest_bunker',
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PersonalityConfigTest.php`
Expected: FAIL — first new key's pool is `null` (`expect($pool)->toBeArray()` fails).

- [ ] **Step 3: Replace the `leaderboard.intro` pool with the 7 board pools**

In `config/personality.php`, find the `'leaderboard.intro' => [ ... ],` entry and replace that entire entry with the following 7 entries (each ≥10 cheeky lines; no `@`-mentions, board-focused not player-focused):

```php
    'leaderboard.alive' => [
        'Still breathing, still bragging. The survivors:',
        'These legends refuse to die. The longest-living souls:',
        'One life, many hours. Currently clinging on:',
        'The clock keeps ticking for these stubborn few:',
        'Death has not collected these names yet:',
        'Longest active lives — the ones who keep showing up:',
        'Alive and accounted for, ranked by sheer stubbornness:',
        'These players have outrun the reaper so far:',
        'Marathon runners of the wasteland, still going:',
        'The current survival standings — don\'t jinx it:',
        'Long may they run. The living leaderboard:',
    ],
    'leaderboard.all_time' => [
        'The all-time greats. Lives for the history books:',
        'Gone, but their playtimes echo forever:',
        'The longest lives ever lived on this server:',
        'Hall of fame: the marathon lives of all time:',
        'These runs will be hard to beat. All-time best:',
        'Legends past and present — the longest lives recorded:',
        'The record books, dusted off. All-time survival:',
        'Eternal bragging rights belong to these:',
        'The greatest lives this server has ever seen:',
        'Time served, top of the table. All-time leaders:',
        'The benchmark. Beat these and you\'re a legend:',
    ],
    'leaderboard.kills' => [
        'The deadliest hands on the server. Most kills:',
        'These players let their guns do the talking:',
        'Body count champions, ranked:',
        'Whoever is on this list, give them a wide berth:',
        'The most prolific killers walking the map:',
        'Kill counts that speak for themselves:',
        'The leaderboard nobody wants to be on the wrong end of:',
        'Top fraggers, in order of menace:',
        'These names are why you should log off early:',
        'Most confirmed kills — the apex predators:',
        'The kill board. Aim accordingly:',
    ],
    'leaderboard.streak' => [
        'On a tear and not stopping. Longest kill streaks:',
        'These players found a rhythm — and a lot of victims:',
        'Streak kings: most kills before catching one themselves:',
        'Hot hands, cold blood. The longest streaks:',
        'Nobody could stop these runs. Top kill streaks:',
        'The unstoppable list — longest streaks recorded:',
        'Kill, repeat, repeat, repeat. Streak leaders:',
        'These streaks made the kill feed scroll:',
        'Longest runs of pure carnage:',
        'The streak board — momentum is a hell of a thing:',
        'Back to back to back. Top streaks:',
    ],
    'leaderboard.distance' => [
        'Reach out and touch someone. The longest kills:',
        'From across the map with love. Distance kills:',
        'These shots had no business connecting. Longest range:',
        'Snipers and lucky guesses, ranked by distance:',
        'The "how did that even hit" board:',
        'Long-distance relationships, leaderboard edition:',
        'Furthest confirmed kills on record:',
        'Squint and you can see the victim. Longest shots:',
        'The marksmanship board — pure range:',
        'These kills travelled. Longest-distance hits:',
        'Bullets with frequent-flyer miles. Top distances:',
    ],
    'leaderboard.bunker_visits' => [
        'Frequent fliers of the bunker. Most visits:',
        'These players can\'t stay away from the vault:',
        'Bunker regulars, ranked by stubbornness:',
        'Most trips into the dark. The bunker board:',
        'They know the way down by heart. Top visitors:',
        'The bunker punch-card champions:',
        'Most bunker entrances logged:',
        'These names haunt the restricted area:',
        'Down the hatch, again and again. Most visits:',
        'The loyal patrons of the bunker:',
        'Most bunker runs — risk addicts, every one:',
    ],
    'leaderboard.quickest_bunker' => [
        'Off the spawn and straight underground. Fastest to bunker:',
        'No time wasted. Quickest new-life bunker runs:',
        'These players sprinted to the vault. Fastest descents:',
        'Speedrunners of the restricted area:',
        'New life, immediate detour. Quickest to bunker:',
        'The "go go go" board — fastest bunker arrivals:',
        'Blink and they\'re already down there. Quickest runs:',
        'Least time alive before diving in. Fastest to bunker:',
        'Punctuality champions of the bunker:',
        'Quickest new-life-to-bunker times on record:',
        'Wheels up the moment they spawn. Fastest descents:',
    ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/PersonalityConfigTest.php`
Expected: PASS (all personality tests green; `leaderboard.intro` no longer referenced).

- [ ] **Step 5: Commit**

```bash
git add config/personality.php tests/Feature/PersonalityConfigTest.php
git commit -m "feat: per-board leaderboard personality pools, retire intro"
```

---

### Task 3: Composer returns 7 ordered board payloads (`composeBoards`)

**Files:**
- Modify: `app/Services/Leaderboard/LeaderboardComposer.php`
- Test: `tests/Unit/LeaderboardComposerTest.php`

- [ ] **Step 1: Rewrite the composer test**

Replace the entire body of `tests/Unit/LeaderboardComposerTest.php` with:

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
        'alive' => [['gamertag' => 'Alice', 'seconds' => 5000], ['gamertag' => 'Bob', 'seconds' => 45]],
        'all_time' => [['gamertag' => 'Carol', 'seconds' => 7200]],
        'kills' => [['gamertag' => 'Bob', 'kills' => 3], ['gamertag' => 'Alice', 'kills' => 1]],
        'streak' => [['gamertag' => 'Bob', 'streak' => 2]],
        'distance' => [['killer' => 'Bob', 'victim' => 'Carol', 'weapon' => 'M24', 'distance' => 412.7]],
        'bunker_visits' => [['gamertag' => 'Alice', 'bunker_visits' => 2], ['gamertag' => 'Bob', 'bunker_visits' => 1]],
        'quickest_bunker' => [['gamertag' => 'Bob', 'seconds' => 120]],
    ];
}

it('returns seven boards in the canonical order with key, title, description', function () {
    $boards = $this->composer->composeBoards(lbBoards());

    expect($boards)->toHaveCount(7);
    expect(array_column($boards, 'key'))->toBe([
        'alive', 'all_time', 'kills', 'streak', 'distance', 'bunker_visits', 'quickest_bunker',
    ]);
    foreach ($boards as $b) {
        expect($b['title'])->toBeString()->not->toBe('');
        expect($b['description'])->toBeString()->not->toBe('');
    }
});

it('puts ranked rows in the description and never @-mentions', function () {
    $alive = $this->composer->composeBoards(lbBoards())[0];

    expect($alive['title'])->toBe('🫀 Longest Life · Still Alive');
    expect($alive['description'])->toContain('1. `Alice` — 1h 23m');
    expect($alive['description'])->toContain('2. `Bob` — <1m');
    expect($alive['description'])->not->toContain('<@');
});

it('formats kill counts (singular/plural) and distance rows', function () {
    $boards = collect($this->composer->composeBoards(lbBoards()))->keyBy('key');

    expect($boards['kills']['description'])->toContain('1. `Bob` — 3 kills');
    expect($boards['kills']['description'])->toContain('2. `Alice` — 1 kill');
    expect($boards['distance']['description'])->toContain('`Bob` (M24) — 413m → `Carol`');
});

it('renders an empty board as a placeholder (personality line still present)', function () {
    $input = lbBoards();
    $input['streak'] = [];
    $boards = collect($this->composer->composeBoards($input))->keyBy('key');

    expect($boards['streak']['description'])->toContain('*No entries yet*');
    // Personality line is the first line of the leaderboard.streak pool.
    expect($boards['streak']['description'])->toContain(config('personality.leaderboard.streak')[0]);
});

it('renders the two bunker boards with correct nouns and duration', function () {
    $boards = collect($this->composer->composeBoards(lbBoards()))->keyBy('key');

    expect($boards['bunker_visits']['title'])->toBe('🚪 Most Bunker Visits');
    expect($boards['bunker_visits']['description'])->toContain('`Alice` — 2 visits');
    expect($boards['bunker_visits']['description'])->toContain('`Bob` — 1 visit'); // singular

    expect($boards['quickest_bunker']['title'])->toBe('⏱️ Quickest New Life → Bunker');
    expect($boards['quickest_bunker']['description'])->toContain('`Bob`');
    expect($boards['quickest_bunker']['description'])->toContain('2m'); // SessionDuration
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/LeaderboardComposerTest.php`
Expected: FAIL — `Call to undefined method ...LeaderboardComposer::composeBoards()`.

- [ ] **Step 3: Replace `compose()` with `composeBoards()`**

In `app/Services/Leaderboard/LeaderboardComposer.php`, replace the `compose()` method (the whole method, keeping the class, constructor, and the three private row helpers) with these two methods:

```php
    /**
     * @param  array{alive:array, all_time:array, kills:array, streak:array, distance:array, bunker_visits:array, quickest_bunker:array}  $boards
     * @return array<int, array{key:string, title:string, description:string}>  Ordered, top→bottom.
     */
    public function composeBoards(array $boards): array
    {
        return [
            $this->board('alive', '🫀 Longest Life · Still Alive', $this->durationRows($boards['alive'])),
            $this->board('all_time', '⏳ Longest Life · All Time', $this->durationRows($boards['all_time'])),
            $this->board('kills', '🔫 Most Kills', $this->countRows($boards['kills'], 'kills')),
            $this->board('streak', '🩸 Longest Kill Streak', $this->countRows($boards['streak'], 'streak')),
            $this->board('distance', '🎯 Longest Kills', $this->distanceRows($boards['distance'])),
            $this->board('bunker_visits', '🚪 Most Bunker Visits', $this->countRows($boards['bunker_visits'], 'bunker_visits', 'visit', 'visits')),
            $this->board('quickest_bunker', '⏱️ Quickest New Life → Bunker', $this->durationRows($boards['quickest_bunker'])),
        ];
    }

    /** @return array{key:string, title:string, description:string} */
    private function board(string $key, string $title, string $rows): array
    {
        $line = $this->picker->pick("leaderboard.{$key}", [], 'The standings, fresh off the server.');

        return [
            'key' => $key,
            'title' => $title,
            'description' => $line."\n\n".$rows,
        ];
    }
```

Also update the class doc-comment's first line from "Turns the five board row-sets into a Discord-agnostic embed payload" to:

```php
/**
 * Turns the seven board row-sets into an ordered list of Discord-agnostic board
 * payloads ({key,title,description}) — one per leaderboard message. Pure/testable;
 * the notifier turns each into an actual Discord Embed. Players render as plain
 * backticked gamertags; the leaderboard NEVER @-mentions (high-frequency edited
 * messages — an intentional exception to the "public posts mention" rule).
 */
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/LeaderboardComposerTest.php`
Expected: PASS (all six tests green).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Leaderboard/LeaderboardComposer.php tests/Unit/LeaderboardComposerTest.php
git commit -m "feat: composer returns 7 ordered board payloads"
```

---

### Task 4: Notifier interface + Null impl take a list of payloads

**Files:**
- Modify: `app/Services/Leaderboard/LeaderboardNotifier.php`
- Modify: `app/Services/Leaderboard/NullLeaderboardNotifier.php`
- Test: `tests/Feature/NullLeaderboardNotifierTest.php`

- [ ] **Step 1: Rewrite the Null notifier test**

Replace the entire body of `tests/Feature/NullLeaderboardNotifierTest.php` with:

```php
<?php

use App\Services\Leaderboard\NullLeaderboardNotifier;

it('captures the published payloads and never throws', function () {
    $notifier = new NullLeaderboardNotifier();
    $payloads = [
        ['key' => 'alive', 'title' => 't1', 'description' => 'd1'],
        ['key' => 'kills', 'title' => 't2', 'description' => 'd2'],
    ];

    $notifier->publish($payloads);

    expect($notifier->lastPayloads)->toBe($payloads);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/NullLeaderboardNotifierTest.php`
Expected: FAIL — `Undefined property ...$lastPayloads` (the impl still exposes `lastPayload`).

- [ ] **Step 3: Update the interface**

Replace the whole `app/Services/Leaderboard/LeaderboardNotifier.php` with:

```php
<?php

namespace App\Services\Leaderboard;

interface LeaderboardNotifier
{
    /**
     * Publish (post or edit) the leaderboard's 7 board messages.
     *
     * @param  array<int, array{key:string, title:string, description:string}>  $payloads  Ordered, top→bottom.
     */
    public function publish(array $payloads): void;
}
```

- [ ] **Step 4: Update the Null impl**

Replace the whole `app/Services/Leaderboard/NullLeaderboardNotifier.php` with:

```php
<?php

namespace App\Services\Leaderboard;

class NullLeaderboardNotifier implements LeaderboardNotifier
{
    /** @var array<int, array{key:string, title:string, description:string}>|null */
    public ?array $lastPayloads = null;

    public function publish(array $payloads): void
    {
        $this->lastPayloads = $payloads;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/NullLeaderboardNotifierTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Leaderboard/LeaderboardNotifier.php app/Services/Leaderboard/NullLeaderboardNotifier.php tests/Feature/NullLeaderboardNotifierTest.php
git commit -m "feat: leaderboard notifier publishes a list of board payloads"
```

---

### Task 5: Rewrite `DiscordLeaderboardNotifier` for 7 messages

**Files:**
- Modify: `app/Services/Leaderboard/DiscordLeaderboardNotifier.php`

No unit test (no Discord gateway in tests — repo convention). Verified with `php -l`.

- [ ] **Step 1: Replace the notifier implementation**

Replace the whole `app/Services/Leaderboard/DiscordLeaderboardNotifier.php` with:

```php
<?php

namespace App\Services\Leaderboard;

use App\Services\State\BotState;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Embed\Embed;
use function React\Promise\all;

/**
 * Posts the 7 leaderboard board embeds once (one message each) and edits them in
 * place thereafter. The ordered list of message ids is persisted as JSON in
 * bot_state ('leaderboard_message_ids') alongside 'leaderboard_channel_id'.
 *
 * If the channel changed, the id count no longer matches, or ANY stored message
 * can't be fetched, the notifier reflushes: it deletes the stored messages (plus
 * the legacy single 'leaderboard_message_id' from the old single-embed layout) and
 * reposts all 7 sequentially so Discord display order matches the board order.
 *
 * Entirely best-effort: null client, missing channel, or any failure no-ops.
 */
class DiscordLeaderboardNotifier implements LeaderboardNotifier
{
    private BotState $state;

    public function __construct(private ?Discord $discord, private ?string $channelId, ?BotState $state = null)
    {
        $this->state = $state ?? new BotState();
    }

    public function publish(array $payloads): void
    {
        if (! $this->discord || ! $this->channelId) {
            return;
        }

        try {
            $channel = $this->discord->getChannel($this->channelId);
            if (! $channel) {
                return;
            }

            $ids = $this->storedIds();
            $storedChannel = $this->state->get('leaderboard_channel_id');

            if (count($ids) !== count($payloads) || $storedChannel !== $this->channelId) {
                $this->reflush($channel, $payloads);

                return;
            }

            // Edit in place only if EVERY message still exists; otherwise reflush
            // so the 7 messages stay in their canonical order.
            $fetches = array_map(fn ($id) => $channel->messages->fetch($id), $ids);

            all($fetches)->then(
                function ($messages) use ($payloads) {
                    foreach (array_values($messages) as $i => $message) {
                        $message->edit(MessageBuilder::new()->addEmbed($this->buildEmbed($payloads[$i])));
                    }
                },
                fn () => $this->reflush($channel, $payloads)
            );
        } catch (\Throwable) {
            // best-effort: never propagate to caller
        }
    }

    /** @return array<int, string> */
    private function storedIds(): array
    {
        $raw = $this->state->get('leaderboard_message_ids');
        if (! $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * Delete the legacy single message + the stored 7 (best-effort), then repost
     * all boards sequentially so display order matches board order.
     *
     * @param  array<int, array{key:string, title:string, description:string}>  $payloads
     */
    private function reflush($channel, array $payloads): void
    {
        // One-time migration away from the old single-embed layout.
        $legacy = $this->state->get('leaderboard_message_id');
        if ($legacy) {
            $channel->messages->fetch($legacy)
                ->then(fn ($m) => $m->delete())
                ->otherwise(fn () => null);
            $this->state->delete('leaderboard_message_id');
        }

        foreach ($this->storedIds() as $id) {
            $channel->messages->fetch($id)
                ->then(fn ($m) => $m->delete())
                ->otherwise(fn () => null);
        }

        $this->postSequential($channel, $payloads, 0, []);
    }

    /**
     * Post boards one after another (chained promises) so they land in order,
     * accumulating ids, then persist the id list + channel.
     *
     * @param  array<int, array{key:string, title:string, description:string}>  $payloads
     * @param  array<int, string>  $ids
     */
    private function postSequential($channel, array $payloads, int $index, array $ids): void
    {
        if ($index >= count($payloads)) {
            $this->state->set('leaderboard_message_ids', json_encode($ids));
            $this->state->set('leaderboard_channel_id', (string) $this->channelId);

            return;
        }

        $channel->sendMessage(MessageBuilder::new()->addEmbed($this->buildEmbed($payloads[$index])))
            ->then(function ($message) use ($channel, $payloads, $index, $ids) {
                $ids[] = (string) $message->id;
                $this->postSequential($channel, $payloads, $index + 1, $ids);
            })
            ->otherwise(fn () => null);
    }

    /** @param array{key:string, title:string, description:string} $payload */
    private function buildEmbed(array $payload): Embed
    {
        $embed = new Embed($this->discord);
        $embed->setTitle($payload['title']);

        if (! empty($payload['description'])) {
            $embed->setDescription($payload['description']);
        }

        return $embed;
    }
}
```

- [ ] **Step 2: Lint the file**

Run: `php -l app/Services/Leaderboard/DiscordLeaderboardNotifier.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verify the class loads and implements the interface**

Run: `php laracord tinker --execute="var_dump((new ReflectionClass(App\Services\Leaderboard\DiscordLeaderboardNotifier::class))->implementsInterface(App\Services\Leaderboard\LeaderboardNotifier::class));"`
Expected: output contains `bool(true)`.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Leaderboard/DiscordLeaderboardNotifier.php
git commit -m "feat: post 7 leaderboard messages, atomic reflush on drift"
```

---

### Task 6: Wire `LeaderboardService` to `composeBoards` + list publish

**Files:**
- Modify: `app/Services/LeaderboardService.php:50-72` (the `compose()` method)
- Test: `tests/Feature/LeaderboardServiceTest.php`

- [ ] **Step 1: Rewrite the service test**

Replace the entire body of `tests/Feature/LeaderboardServiceTest.php` with:

```php
<?php

use App\Models\Life;
use App\Models\Player;
use App\Services\Leaderboard\NullLeaderboardNotifier;
use App\Services\LeaderboardService;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

it('composes all seven boards into the notifier payloads', function () {
    $p = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => now()->subHours(2), 'playtime_seconds' => 4000]); // open

    $notifier = new NullLeaderboardNotifier();
    (new LeaderboardService())->compose($notifier);

    expect($notifier->lastPayloads)->toHaveCount(7);
    // Board 0 = alive; its title is fixed and its rows include Alice's open life.
    expect($notifier->lastPayloads[0]['title'])->toContain('Longest Life');
    expect($notifier->lastPayloads[0]['description'])->toContain('Alice');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LeaderboardServiceTest.php`
Expected: FAIL — `Undefined property ...$lastPayloads` (service still calls `compose()`/sets `lastPayload`).

- [ ] **Step 3: Update the service `compose()` method**

In `app/Services/LeaderboardService.php`, replace the `compose()` method body. Change the `$top` default to 25, call `composeBoards()`, and store the result in `$payloads`:

```php
    /**
     * Build the 7 board payloads from the seven stat boards and hand them to the
     * notifier. Split out so tests can inject a NullLeaderboardNotifier.
     */
    public function compose(LeaderboardNotifier $notifier): void
    {
        $top = (int) config('leaderboard.top_count', 25);
        $stats = new LeaderboardStatsService();

        $payloads = (new LeaderboardComposer())->composeBoards([
            'alive' => $stats->aliveLongestLives($top),
            'all_time' => $stats->allTimeLongestLives($top),
            'kills' => $stats->mostKills($top),
            'streak' => $stats->longestKillStreaks($top),
            'distance' => $stats->longestKills($top),
            'bunker_visits' => $stats->mostBunkerVisits($top),
            'quickest_bunker' => $stats->quickestNewLifeToBunker($top),
        ]);

        $notifier->publish($payloads);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/LeaderboardServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/LeaderboardService.php tests/Feature/LeaderboardServiceTest.php
git commit -m "feat: leaderboard service publishes 7 board payloads"
```

---

### Task 7: Full-suite verification + docs

**Files:**
- Modify: `CLAUDE.md` (leaderboard subsystem description)

- [ ] **Step 1: Run the entire test suite**

Run: `./vendor/bin/pest`
Expected: PASS — all tests green (PHP 8.5 `DEPR` markers are harmless; exit 0 is success). If anything is red, fix it before continuing.

- [ ] **Step 2: Update the leaderboard description in CLAUDE.md**

In `CLAUDE.md`, in the `**Leaderboard**` bullet, replace the description of the composer/notifier output to reflect the new layout. Change the phrase describing `LeaderboardComposer` and `DiscordLeaderboardNotifier` to:

```markdown
  `LeaderboardComposer` (pure → an ordered list of seven Discord-agnostic board
  payloads `{key,title,description}`, one per message; plain backticked gamertags,
  **never @-mentions**; each board carries a per-board personality line, entries in
  the embed description to clear the 1024-char field cap),
  `DiscordLeaderboardNotifier` / `NullLeaderboardNotifier` (post-or-edit seven
  embeds, ids persisted in `bot_state` as a JSON list `leaderboard_message_ids` +
  `leaderboard_channel_id`; atomic reflush — repost all seven in order — if any
  message is missing or the channel changed),
```

Also update the `top_count` mention if present (the default is now 25, not 5) and note `LEADERBOARD_TOP_COUNT` defaults to 25.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: leaderboard split into 7 messages, 25 entries"
```

- [ ] **Step 4: Final confirmation**

Run: `./vendor/bin/pest`
Expected: PASS (exit 0). The feature is complete.

---

## Self-Review

**Spec coverage:**
- 7 separate embed messages, no header → Task 3 (`composeBoards` → 7) + Task 5 (one message each). ✓
- 25 entries → Task 1 (default 25). ✓
- Entries in description (1024 vs 4096 limit) → Task 3 (rows in `description`) + Task 5 (`setDescription`). ✓
- Per-board personality, retire `leaderboard.intro` → Task 2. ✓
- 7 ids persisted as JSON + atomic reflush + legacy cleanup → Task 5. ✓
- Sequential posting preserves order → Task 5 (`postSequential`). ✓
- Interface + Null take a list → Task 4. ✓
- Service wiring → Task 6. ✓
- Tests (composer/personality/config/null/service) → Tasks 1–6. ✓
- Notifier not unit-tested → Task 5 (lint + reflection only), matches convention. ✓

**Placeholder scan:** No TBD/TODO; every code step shows full code. ✓

**Type consistency:** `composeBoards()` (Tasks 3, 6), `publish(array $payloads)` (Tasks 4, 5, 6), `lastPayloads` (Tasks 4, 6), `leaderboard_message_ids` JSON + `leaderboard_channel_id` (Task 5), board payload shape `{key,title,description}` consistent across Tasks 3–6. ✓
