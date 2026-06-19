# Weekly Newspaper Continuity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make each weekly Tribune issue aware of the previous week — both last week's published prose and last week's events — so it continues storylines and avoids repeating itself.

**Architecture:** `NewspaperGenerator::generate()` gains an optional prior-issue argument and a conditional continuity clause in its system prompt. `NewspaperService` builds the prior-week facts (`build($now->subWeek())`), loads the prior issue from `bot_state.last_newspaper_issue`, passes both to the generator, and persists the new issue's prose after publishing. The one pre-existing issue (#1) is seeded once, operationally, by the controller.

**Tech Stack:** Laracord (Laravel Zero) · PHP 8.2+ · SQLite · Pest · OpenRouter via `Http`.

## Global Constraints

- TDD: failing test first, then implementation. Feature tests use `Http::fake` and `CarbonImmutable::setTestNow()`.
- The prior-issue store is a single `bot_state` JSON key `last_newspaper_issue` = `{week, editorial, recap, classifieds}`. No new DB table.
- Continuity is conditional: when no prior issue is supplied (null/missing/malformed JSON), behavior is unchanged and generation still succeeds.
- New facts/prose feed the newspaper prompt only; the per-section fallback/`split` logic is untouched.
- Not gated by `BAN_DRY_RUN`.
- Run the full suite with `./vendor/bin/pest`. PHP 8.5 `DEPR` markers are harmless.
- **Deadline:** merged + deployed + issue #1 seeded before **22:00 UTC 2026-06-19**.

---

### Task 1: `NewspaperGenerator` accepts a prior issue + continuity clause

**Files:**
- Modify: `app/Services/Newspaper/NewspaperGenerator.php`
- Test: `tests/Feature/NewspaperGeneratorTest.php` (add cases)

**Interfaces:**
- Produces: `NewspaperGenerator::generate(array $facts, ?array $priorIssue = null): array` (unchanged return shape `{editorial, recap, classifieds}`). When `$priorIssue` is non-null it is serialized into the user prompt under the key `last_week_issue`. The `previous_week` facts ride inside `$facts` (added by the caller).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/NewspaperGeneratorTest.php`:

```php
it('includes last week\'s issue and the continuity clause when a prior issue is provided', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' =>
        "## EDITORIAL\nx\n## RECAP\ny\n## CLASSIFIEDS\nz"
    ]]]], 200)]);

    $gen = new NewspaperGenerator(new OpenRouterClient('key', 'm', 'https://x/api/v1'));
    $gen->generate(
        ['counts' => ['lives_lost' => 3, 'playtime_human' => '9h'], 'previous_week' => ['counts' => ['lives_lost' => 7]]],
        ['week' => '2026-W24', 'editorial' => 'last ed', 'recap' => 'last recap about Mike', 'classifieds' => 'last ads'],
    );

    Http::assertSent(function ($r) {
        $user = $r['messages'][1]['content'];
        $system = $r['messages'][0]['content'];
        return str_contains($user, 'last_week_issue')
            && str_contains($user, 'last recap about Mike')
            && str_contains($user, 'previous_week')
            && str_contains($system, 'CONTINUITY');
    });
});

it('omits last_week_issue from the prompt when no prior issue is provided', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' =>
        "## EDITORIAL\nx\n## RECAP\ny\n## CLASSIFIEDS\nz"
    ]]]], 200)]);

    $gen = new NewspaperGenerator(new OpenRouterClient('key', 'm', 'https://x/api/v1'));
    $out = $gen->generate(['counts' => ['lives_lost' => 1, 'playtime_human' => '2h']]);

    Http::assertSent(fn ($r) => ! str_contains($r['messages'][1]['content'], 'last_week_issue'));
    expect($out['editorial'])->toContain('x');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/NewspaperGeneratorTest.php`
Expected: FAIL — `generate()` does not accept a 2nd arg / the prompt lacks `last_week_issue` and the system prompt lacks `CONTINUITY`.

- [ ] **Step 3: Add the continuity clause to the SYSTEM prompt**

In `app/Services/Newspaper/NewspaperGenerator.php`, inside the `SYSTEM` heredoc, add this bullet to the `HARD RULES:` list, immediately BEFORE the existing `- If a section has little data ...` line:

```
- CONTINUITY: If the data includes `last_week_issue` (last week's published prose) and `previous_week`
  (last week's events), treat this as an ongoing publication: continue and evolve running
  storylines/feuds, and AVOID re-telling last week's stories or reusing its jokes and classifieds.
  This week's events are always the lead; last week is background.
```

- [ ] **Step 4: Thread `priorIssue` through `generate()` and the user prompt**

In the same file, change the `generate()` signature and its `complete()` call:

```php
    public function generate(array $facts, ?array $priorIssue = null): array
    {
        try {
            $raw = $this->client->complete(self::SYSTEM, $this->userPrompt($facts, $priorIssue));
            $parsed = $this->split($raw);
        } catch (\Throwable) {
            $parsed = ['editorial' => '', 'recap' => '', 'classifieds' => ''];
        }
```

(Leave the rest of `generate()` — the per-section fallback loop and `return` — unchanged.)

Replace `userPrompt()` with:

```php
    private function userPrompt(array $facts, ?array $priorIssue = null): string
    {
        $payload = $facts;
        if ($priorIssue !== null) {
            $payload['last_week_issue'] = $priorIssue;
        }

        return "Write this week's issue from these facts (JSON):\n".
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
```

Update the `generate()` PHPDoc `@param` block to document `?array $priorIssue` as
`{week, editorial, recap, classifieds}` or null.

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/NewspaperGeneratorTest.php`
Expected: PASS (new cases + the 3 pre-existing cases, which call `generate($facts)` and still work via the default `null`).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Newspaper/NewspaperGenerator.php tests/Feature/NewspaperGeneratorTest.php
git commit -m "feat: newspaper generator accepts prior issue + continuity clause"
```

---

### Task 2: `NewspaperService` builds prior-week facts, loads/passes the prior issue, persists the new issue

**Files:**
- Modify: `app/Services/NewspaperService.php`
- Test: `tests/Feature/NewspaperServiceTest.php` (add cases)

**Interfaces:**
- Consumes: `NewspaperGenerator::generate(array $facts, ?array $priorIssue): array` (Task 1); `WeeklyFactsBuilder::build(CarbonImmutable $now): array`; `BotState::get/set`.
- Produces: after a publish, `bot_state.last_newspaper_issue` holds `{week, editorial, recap, classifieds}` for the just-published issue; the generator is called with `$facts['previous_week']` populated and the decoded prior issue.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/NewspaperServiceTest.php`. Add this spy-generator helper near the top helpers (after `makeService`):

```php
function spyGenerator(): NewspaperGenerator
{
    return new class(new OpenRouterClient('k', 'm', 'https://x/api/v1')) extends NewspaperGenerator {
        public array $lastFacts = [];
        public ?array $lastPrior = null;
        public function generate(array $facts, ?array $priorIssue = null): array
        {
            $this->lastFacts = $facts;
            $this->lastPrior = $priorIssue;
            return ['editorial' => 'NEW ED', 'recap' => 'NEW RECAP', 'classifieds' => 'NEW ADS'];
        }
    };
}
```

Then the cases:

```php
it('passes the prior issue + previous_week facts to the generator and persists the new prose', function () {
    CarbonImmutable::setTestNow('2026-06-19 22:00:00'); // Friday 22:00 UTC → ISO 2026-W25
    $state = new BotState();
    $state->set('go_live_at', '2026-01-01T00:00:00+00:00');
    $state->set('last_newspaper_issue', json_encode([
        'week' => '2026-W24', 'editorial' => 'OLD ED', 'recap' => 'OLD RECAP', 'classifieds' => 'OLD ADS',
    ]));
    $notifier = capturingNotifier();
    $gen = spyGenerator();

    (new NewspaperService(null, $state, new WeeklyFactsBuilder(), $gen, $notifier))->run(CarbonImmutable::now());

    expect($notifier->calls)->toBe(1);
    expect($gen->lastPrior['recap'])->toBe('OLD RECAP');        // prior issue handed to generator
    expect($gen->lastFacts)->toHaveKey('previous_week');         // prior-week facts built

    $stored = json_decode($state->get('last_newspaper_issue'), true);
    expect($stored['week'])->toBe('2026-W25');                   // persisted under THIS week
    expect($stored['recap'])->toBe('NEW RECAP');
    CarbonImmutable::setTestNow();
});

it('treats a malformed stored prior issue as none', function () {
    CarbonImmutable::setTestNow('2026-06-19 22:00:00');
    $state = new BotState();
    $state->set('go_live_at', '2026-01-01T00:00:00+00:00');
    $state->set('last_newspaper_issue', 'not json{');
    $notifier = capturingNotifier();
    $gen = spyGenerator();

    (new NewspaperService(null, $state, new WeeklyFactsBuilder(), $gen, $notifier))->run(CarbonImmutable::now());

    expect($gen->lastPrior)->toBeNull();
    CarbonImmutable::setTestNow();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/NewspaperServiceTest.php`
Expected: FAIL — `previous_week` key absent, `lastPrior` is null in the first test (prior issue not loaded), and `last_newspaper_issue` is not updated to the new prose.

- [ ] **Step 3: Wire the prior context + persistence into `run()`**

In `app/Services/NewspaperService.php`, replace the body of `run()` from the `$facts = ...` line through the two `state->set/setInt` stamps with:

```php
        $facts = $this->facts->build($now);
        $facts['previous_week'] = $this->facts->build($now->subWeek());
        $priorIssue = $this->decodePriorIssue();
        $prose = $this->generator->generate($facts, $priorIssue);
        $issueNumber = $this->state->getInt('newspaper_issue_count', 0) + 1;
        $embeds = (new NewspaperComposer())->compose($facts, $prose, $issueNumber);

        $this->resolveNotifier()->publish($embeds);

        $this->state->set('last_newspaper_week', $weekKey);
        $this->state->setInt('newspaper_issue_count', $issueNumber);
        $this->state->set('last_newspaper_issue', json_encode([
            'week' => $weekKey,
            'editorial' => $prose['editorial'],
            'recap' => $prose['recap'],
            'classifieds' => $prose['classifieds'],
        ]));
```

Add this private method to the class (e.g. after `run()`):

```php
    /** Decoded prior-issue prose ({week,editorial,recap,classifieds}), or null when unset/malformed. */
    private function decodePriorIssue(): ?array
    {
        $raw = $this->state->get('last_newspaper_issue');
        if (! $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/NewspaperServiceTest.php`
Expected: PASS (new cases + all pre-existing cases — they only assert `notifier->calls`, which is unaffected).

- [ ] **Step 5: Run the full suite**

Run: `./vendor/bin/pest`
Expected: green (0 failures; `DEPR` markers harmless).

- [ ] **Step 6: Commit**

```bash
git add app/Services/NewspaperService.php tests/Feature/NewspaperServiceTest.php
git commit -m "feat: newspaper service feeds prior issue + previous-week facts, persists each issue"
```

---

## Operational (controller-performed, NOT a code task)

Before tonight's 22:00 UTC run, seed issue #1 so issue #2 has prose to build on:

1. Fetch the most recent message(s) from `NEWSPAPER_CHANNEL_ID` via Discord REST using `DISCORD_TOKEN`
   (`GET https://discord.com/api/v10/channels/{id}/messages?limit=5`, header `Authorization: Bot <token>`).
2. Extract the editorial/recap/classifieds text from the issue's embeds.
3. Write `bot_state.last_newspaper_issue` = `{"week":"2026-W24","editorial":"…","recap":"…","classifieds":"…"}`
   on the production SQLite DB.

This runs once, by hand, during deploy. It is intentionally not bot code (approach A).

---

## Self-Review

**Spec coverage:**
- Prior prose store (`bot_state.last_newspaper_issue`) → Task 2 (persist) + Operational (seed #1). ✓
- Prior-week facts via `build($now->subWeek())` → Task 2. ✓
- Generator accepts prior issue + conditional continuity clause → Task 1. ✓
- Graceful when no/ malformed prior issue → Task 2 `decodePriorIssue()` + Task 1 default `null`. ✓
- Fallback/split untouched → Task 1 leaves them intact. ✓

**Placeholder scan:** none — every code step carries full code.

**Type consistency:** `generate(array $facts, ?array $priorIssue = null): array` identical in Task 1 (producer) and Task 2 (spy override + call site). The `last_newspaper_issue` JSON shape `{week,editorial,recap,classifieds}` is identical in Task 2's persist, Task 2's seed-read, and the Operational seed. `previous_week` key name consistent across Task 1 prompt assertion and Task 2 wiring.
