# Weekly newspaper continuity — make the Tribune aware of last week

**Date:** 2026-06-19
**Status:** Approved (design)

## Problem

`NewspaperService` generates each weekly issue from a single `WeeklyFactsBuilder::build($now)`
snapshot of the trailing 7 days and one `NewspaperGenerator::generate($facts)` LLM call. It has **no
awareness of the previous issue**: it cannot continue storylines or feuds, and it can re-tell the
same stories or reuse jokes/classifieds week to week. The generated prose is also **not persisted**
— only `bot_state.last_newspaper_week` and `newspaper_issue_count` are stamped — so there is no
record of what the paper actually said.

## Goal

Give the newspaper LLM continuity with the prior week, via BOTH:
1. **Last week's prose** — the previous issue's editorial/recap/classifieds.
2. **Last week's facts** — the prior 7-day window's events.

So the new issue continues ongoing threads and avoids repeating itself, while this week's events stay
primary.

## Context / constraints

- Exactly **one** issue has been published (`newspaper_issue_count = 1`, ISO week 2026-W24,
  ~Fri June 13). Today is **Fri 2026-06-19** (ISO week 2026-W25); issue **#2 publishes tonight at
  22:00 UTC**. **Hard deadline: merged + deployed + issue #1 seeded before 22:00 UTC today.**
- Not gated by `BAN_DRY_RUN` (read + channel only).
- TDD + repo conventions (`CarbonImmutable::setTestNow`, `Http::fake`, config defaults pinned in
  `phpunit.xml`).

## Approach (chosen: A — persist forward + one-time seed)

Going forward, persist each published issue's prose to `bot_state`; read it back next week. The bot
code stays synchronous (a DB read), easy to test. The one prior issue that predates this feature
(#1) is seeded **once, manually**, by fetching it from the channel now — the bot never re-reads
Discord at generation time.

(Rejected: B — bot re-reads the channel every week. Adds async Discord reads + fragile embed
re-parsing into the generation hot path and is harder to test; same continuity result.)

## Design

### 1. Prior-issue prose store — `bot_state.last_newspaper_issue`

A single JSON value (only ever the immediately-prior issue; no new table):

```json
{ "week": "2026-W24", "editorial": "...", "recap": "...", "classifieds": "..." }
```

Written by `NewspaperService::run()` AFTER a successful publish, alongside the existing
`last_newspaper_week` / `newspaper_issue_count` stamps. Read at the start of the next run.

### 2. Prior-week facts — reuse `WeeklyFactsBuilder`

`WeeklyFactsBuilder::build(CarbonImmutable $now)` already builds the trailing 7 days from its
reference time. The prior week is simply `build($now->subWeek())`. `NewspaperService` nests it into
this week's facts under `previous_week`:

```php
$facts = $this->facts->build($now);
$facts['previous_week'] = $this->facts->build($now->subWeek());
```

### 3. `NewspaperGenerator` — accept + use prior context

- Signature: `generate(array $facts, ?array $priorIssue = null): array` (unchanged return shape
  `{editorial, recap, classifieds}`).
- The `SYSTEM` prompt gains a **conditional continuity clause** (one constant, no second variant):
  > CONTINUITY: If the data includes `last_week_issue` (last week's published prose) and
  > `previous_week` (last week's events), treat this as an ongoing publication — continue and evolve
  > running storylines/feuds, and explicitly AVOID re-telling last week's stories or reusing its
  > jokes and classifieds. This week's events are always the lead; last week is background.
- The user prompt includes `last_week_issue` (from `$priorIssue`) only when non-null, and
  `previous_week` rides in the facts JSON. When both are absent (first issue ever, or a missing
  seed), the clause references nothing and behavior is unchanged.
- Fallback/`split` logic is untouched; canned per-section fallback still applies.

### 4. `NewspaperService::run()` wiring

```php
$facts = $this->facts->build($now);
$facts['previous_week'] = $this->facts->build($now->subWeek());
$priorIssue = $this->decodePriorIssue();           // json_decode last_newspaper_issue, or null
$prose = $this->generator->generate($facts, $priorIssue);
// ... compose + publish unchanged ...
$this->state->set('last_newspaper_week', $weekKey);
$this->state->setInt('newspaper_issue_count', $issueNumber);
$this->state->set('last_newspaper_issue', json_encode([
    'week' => $weekKey,
    'editorial' => $prose['editorial'],
    'recap' => $prose['recap'],
    'classifieds' => $prose['classifieds'],
]));
```

`decodePriorIssue()` returns the decoded array, or `null` if the key is missing or the JSON is
malformed/empty (graceful — treated as "no prior issue").

### 5. One-time bootstrap of issue #1 (operational, not shipped code)

Before tonight's run, fetch issue #1 from `NEWSPAPER_CHANNEL_ID` via the Discord REST API using
`DISCORD_TOKEN` (`GET /channels/{id}/messages?limit=…`), extract the editorial/recap/classifieds
text from its embeds, and write `bot_state.last_newspaper_issue` with `week = "2026-W24"`. This is a
one-time manual step performed during deploy; it is NOT part of the bot's permanent code.

## Testing (TDD)

- **`NewspaperGenerator`** (`Http::fake`, assert the serialized prompt body):
  - With a `priorIssue`: the user prompt contains `last_week_issue` and the prior recap text; the
    `previous_week` facts are present.
  - Without a `priorIssue`: no `last_week_issue` in the prompt; generation still succeeds.
  - Fallback path still yields canned per-section copy.
- **`NewspaperService`** (Feature, `setTestNow` to a due Friday, fake notifier):
  - After a publish, `bot_state.last_newspaper_issue` holds the new prose with this week's `week`.
  - A prior `last_newspaper_issue` is read and passed to the generator (spy/fake generator records
    the `priorIssue` arg).
  - `previous_week` facts are built from `now->subWeek()`.
- **`WeeklyFactsBuilder`**: existing tests cover the window; add one asserting `build($now->subWeek())`
  counts the prior 7-day range (no new production code needed — reuse).

## Files touched

- `app/Services/Newspaper/NewspaperGenerator.php` — `generate()` signature + SYSTEM continuity clause + prompt payload.
- `app/Services/NewspaperService.php` — build prior-week facts, load/pass prior issue, persist new issue.
- `tests/` — generator + service coverage above.
- (operational) one-time `bot_state.last_newspaper_issue` seed of issue #1.

## Non-goals

- No new DB table (single `bot_state` JSON key suffices for the immediately-prior issue).
- The bot does not re-read the Discord channel at generation time (approach B, rejected).
- No backfill of issues older than #1.
