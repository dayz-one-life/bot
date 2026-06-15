# The One Life Tribune — Weekly Edition (Design)

**Date:** 2026-06-15
**Status:** Approved design, ready for implementation plan
**Scope decision:** Single all-in build — the weekly newspaper *and* the new hit/infected-attack
capture ship together.

## What we're building

A weekly auto-generated "newspaper" posted to a dedicated Discord channel. Each issue is a single
multi-embed message in the established **"The One Life Tribune"** voice (the same masthead the
births/eulogies subsystem already uses in its footer). It is fully automatic — no human draft or
approval step — built from a week of server activity, with the prose written by the LLM via the
existing OpenRouter pipeline and a canned fallback when the API is unavailable.

This is the same machine as the births/eulogies subsystem, pointed at **a week of aggregate data**
instead of a single life:

| Births/eulogies (existing) | Tribune (new, sibling) |
|---|---|
| `OpenRouterClient` | reused as-is |
| `AnnouncementGenerator` | `NewspaperGenerator` |
| `LifeFactsBuilder` | `WeeklyFactsBuilder` |
| `LifecycleAnnouncer` | `NewspaperComposer` |
| `LifecycleNotifier` + Discord/Null | `NewspaperNotifier` + Discord/Null |
| `LifecycleAnnounceService` (1m scan) | `NewspaperService` (hourly, weekly rollover) |
| `personality.birth.*` / `eulogy.*` fallback | `personality.newspaper.*` fallback |
| `config/lifecycle.php` | `config/newspaper.php` (reuses `config/llm.php`) |

## Sections of an issue

Four content sections plus a masthead, each its own embed (Discord allows ≤10 embeds/message):

1. **✒️ Editorial** — LLM op-ed themed on the week's biggest "lede" (a fallen long-lived bounty, a
   deadly night, a record, a location trend such as *"Is Cherno still safe to live?"*).
2. **📊 The Week in Numbers** — pure data box (no LLM), with deltas vs the previous week: lives lost,
   total playtime, longest life ended, deadliest player, furthest kill, bunker descents, infected
   attacks, souls still alive.
3. **🗞️ The Recap** — LLM narrative woven from the week's real events (notable kills, bounty claims,
   bunker records, environmental deaths, infected-attack trends).
4. **📋 Classifieds** — LLM jokes seeded from real loot/events (a slain player's loadout "for sale",
   a sniper's long shot as a "personal ad").

## Delivery

- **Channel:** a new dedicated channel (`NEWSPAPER_CHANNEL_ID`). Back issues remain as a readable
  archive — each issue is a **new immutable post** (NOT edit-in-place like the leaderboard/roster).
- **Cadence:** Friday evening, **~6pm server time (UTC-4) = 22:00 UTC**, covering the **trailing 7
  days** ending at publish time. Configurable day-of-week + UTC hour.
- **No @-mentions anywhere.** Plain backticked gamertags only — same convention as the leaderboard
  and online roster (a weekly multi-ping reads as spam). This is an intentional exception to the
  "public posts may mention" rule, matching the existing high-volume read-only feeds.
- **Not gated by `BAN_DRY_RUN`** — read-only over the DB + a channel post (plus DB-only hit capture),
  like the leaderboard and births/eulogies. Runs live regardless of the ban cutover.

## Location / privacy policy (the load-bearing rule)

This is subtler than the eulogy generator, which strips **all** place names. The Tribune *keeps*
place flavor (it makes the paper feel real and funny) while making doxxing structurally impossible.

**Invariant:** a location may only ever appear as an **aggregate, server-wide trend** — never
attached to a named individual, and never for bases or build events.

| ✅ Allowed | ❌ Never |
|---|---|
| "Infected attacks around **Cherno** are up this week — *is Cherno still safe to live?*" | "`PlayerX` died in Cherno" (individual ↔ place = doxxing) |
| "**Elektro** was the week's deadliest town: 8 deaths" | "`PlayerX` was last seen near Berezino" |
| "`PlayerX` logged **14 km** on foot" (a total distance — names no place) | Any coordinate, grid reference, or raw `pos=<…>` |
| "The longest shot travelled **412 m**" (a distance, not a place) | **Any** player base / build-event location, ever |

**Enforcement (defense in depth):**

1. **`WeeklyFactsBuilder` never emits a coordinate or a `(player, place)` pair.** Place names appear
   only inside anonymized aggregate structures — `region => count` maps with **no player names
   inside them**. Per-player facts (kills, deaths, distance total, playtime) carry **no location**.
2. **System prompt** instructs: you may name a town **only** as it literally appears in the provided
   aggregate trend data; never associate a town with a named player; never output coordinates or
   grid references; never mention or imply bases or build events.
3. **We never track base locations at all** — there is nothing to leak. Build events are not ingested.
4. Coordinates are stored internally (as today, for `player_positions` and `lives.death_log`) but the
   region label is derived at aggregation time and only aggregate counts cross the LLM boundary.

## Data model & ingestion

### New: `hit_events` table

Captures non-fatal (and fatal) damage events parsed from ADM `hit by` lines. Purely additive; no
bans, no dry-run gating.

```
hit_events
  id
  victim_player_id    (nullable FK players — unlinked victims tolerated)
  victim_gamertag     (string — denormalized, always present)
  attacker_gamertag   (nullable — null for non-player sources)
  attacker_type       (enum-ish string: 'player' | 'infected' | 'animal' | 'environment')
  attacker_label      (nullable — humanized source for non-player, e.g. "Infected", "Wolf", "Fall")
  body_part           (nullable string, e.g. "Torso", "Head")
  victim_hp           (nullable int — HP after the hit, from "[HP: n]")
  victim_x, victim_y  (nullable double — internal only; never exposed, used to derive region)
  occurred_at         (timestamp UTC)
  timestamps + indexes on (occurred_at), (victim_player_id), (attacker_gamertag)
```

### New parser: `AdmParser::parseHit(string $raw): ?array`

The `hit by` line format (confirmed against fixtures):

```
HH:MM:SS | Player "Victim" (id=V= pos=<x,y,z>)[HP: 50] hit by Player "Attacker" (id=A= pos=<…>) into Torso
HH:MM:SS | Player "Victim" (id=V= pos=<x,y,z>)[HP: 30] hit by <InfectedOrAnimalClass> into Leg
```

`parseHit` returns `{victim, victim_pos, victim_hp, attacker_gamertag|null, attacker_type,
attacker_label, body_part}`. Player attackers → `attacker_type='player'`. Non-player sources are
classified + humanized via the existing `DayzNameHumanizer` (infected class names → `infected`,
animals → `animal`, fall/explosion/etc → `environment`). The existing death/position parsers already
skip `hit by` lines, so this is purely additive — no behavior change to those.

### Ingestion wiring

A plain `App\Services\Hit\HitEventService::record(...)` (mirrors `BunkerVisitService`) is called from
`AdmIngestor` for each parsed hit line, associating `victim_player_id` by gamertag when known.
DB-only. A backfill console command (`adm:backfill-hits --since-days=N`) reconstructs historical hits
from ADM history, idempotent on `(victim_gamertag, occurred_at, attacker_gamertag, body_part)`.

### Region mapping: `App\Services\Geo\ChernarusRegions`

Pure helper: `regionFor(float $x, float $y): ?string` maps a coordinate to a named Chernarus
town/POI via a static table of labelled bounding boxes / nearest-POI (e.g. Cherno, Elektro,
Berezino, NWAF, …), returning `null` for the deep wilderness. **Output is only ever a region label,
never the coordinate.** Used exclusively inside aggregation to build `region => count` maps. Unit-tested.

### Reused existing data (no new capture needed)

- **Deaths / kills / weapon / distance** — `lives` (`death_cause`, `death_by_gamertag`,
  `death_weapon`, `death_distance`, `death_log`).
- **Aggregate death-by-region** — derived from coordinates already stored in `lives.death_log`.
- **Distance travelled** — summed consecutive-sample distance per player from `player_positions`.
- **Bunker descents / records** — `bunker_visits`.
- **Bounty claims / placements** — bounty tables.
- **Playtime / spawns / souls alive** — `lives` / `game_sessions` / `players`.

## New services

### `App\Services\Newspaper\WeeklyFactsBuilder`

Reads the trailing 7-day window into one structured facts array (same read-query style as
`LeaderboardStatsService` and `LifeFactsBuilder`). Produces:

- **Counts** (+ previous-week values for deltas): lives lost, total playtime, bunker descents,
  infected attacks, player-vs-player hits, souls still alive.
- **Superlatives** (per-player, **no location**): longest life ended, deadliest player (most kills),
  furthest kill (killer/victim/weapon/distance — a distance, not a place), longest streak,
  most-travelled survivor (total km), quickest new-life-to-bunker.
- **Aggregate location trends** (anonymized `region => count`, **no player names**): deaths by
  region, infected attacks by region, with a computed "hotspot" + week-over-week direction for the
  "Is X safe?" editorial angle.
- **Notable events** for the recap (kills, bounty claims, bunker records, environmental deaths) —
  each carries names + facts but **never a location**.
- **Candidate ledes** — a ranked shortlist the editorial can pick from.
- **`witnesses`** — recently-active gamertags the LLM may quote (reused verbatim from the births
  pattern; excludes nobody special here, plain names, not pinged).

Guarantees: no coordinate, no grid, no `(player, place)` pair ever enters the returned array.

### `App\Services\Newspaper\NewspaperGenerator`

Sibling of `AnnouncementGenerator`. **One** OpenRouter call (via the existing `OpenRouterClient`,
larger `max_tokens`) returns all three prose sections, the model required to emit explicit
delimiters:

```
## EDITORIAL
…
## RECAP
…
## CLASSIFIEDS
…
```

Parsed by splitting on the delimiters. **Per-section canned fallback**: any failure (no key,
timeout, non-2xx, empty) *or* a missing delimiter section falls back to that section's
`personality.newspaper.{editorial,recap,classifieds}` pool (Mad-Libs filled with the week's real
counts/names), so a dead API key or a quiet week degrades to a plainer-but-valid issue rather than
failing. System prompt carries the same hard rules as the eulogy generator **plus** the location
policy above. A combined call (rather than three) is chosen so the editorial can reference what's in
the recap for a coherent issue; the delimiter fallback preserves resilience.

### `App\Services\Newspaper\NewspaperComposer`

Pure: facts + generated prose → an ordered list of Discord-agnostic embed payloads (masthead +
4 sections). The **Week in Numbers** box is built here from pure data (no LLM). Plain backticked
gamertags; never `<@id>`. Handles a quiet week gracefully (sections collapse to short "slow news
week" copy).

### `App\Services\Newspaper\NewspaperNotifier` (+ `Discord` / `Null`)

Posts the single multi-embed message to `NEWSPAPER_CHANNEL_ID`. No message-id persistence (issues
are immutable, append-only). `Null` impl no-ops when the channel is unset.

### `App\Services\NewspaperService` (periodic `Service`)

Hourly tick. Publishes when **(a)** it is at/after the configured publish moment for the current ISO
week **and** **(b)** that ISO week has not yet been published. Idempotent via
`bot_state.last_newspaper_week` (ISO `o-W` string), exactly like `MonthlyRewardService`'s
`last_reward_month`. Gated by `go_live_at` (never publishes during backfill). On publish: build facts
→ generate prose → compose → notify → stamp `last_newspaper_week`.

### `App\Console\Commands\NewsPublishCommand` (`news:publish`)

On-demand build/publish for previewing + catch-up. `--dry-run` prints the composed issue to the
console instead of posting; `--force` ignores the weekly-rollover guard. Mirrors `adm:verify`.

## Config — `config/newspaper.php` + env

```
NEWSPAPER_ENABLED=true
NEWSPAPER_CHANNEL_ID=            # null => Null notifier no-ops
NEWSPAPER_PUBLISH_DOW=5          # ISO day-of-week, Fri
NEWSPAPER_PUBLISH_HOUR_UTC=22    # 6pm UTC-4
HIT_TRACKING_ENABLED=true        # gate hit capture (record() no-ops when false)
```

Reuses the existing `OPENROUTER_*` / `config/llm.php` block. All asserted defaults pinned in
`phpunit.xml` `<env>` so live `.env` tuning can't redden the suite.

## Testing (TDD, existing conventions)

- **Unit:** `AdmParser::parseHit` (player / infected / animal / environment / malformed);
  `ChernarusRegions::regionFor` (known towns + wilderness null); `NewspaperComposer` embed shape +
  quiet-week; `NewspaperGenerator` delimiter split + per-section fallback.
- **Feature:** `WeeklyFactsBuilder` aggregates over seeded lives/hits/visits/bounties/positions —
  including the **privacy assertions** (no coordinate, no `(player, place)` pair, region maps carry
  no player names); `HitEventService::record` (linked/unlinked victim, dedupe);
  `NewspaperGenerator` against `Http::fake()` (success + failure → fallback); `NewspaperService`
  rollover idempotency across the Friday boundary via `CarbonImmutable::setTestNow`, go_live gating,
  quiet-week.
- Notifier + Service stay thin wrappers (no gateway in tests).

## Non-goals

- No human review/approval step (fully automatic, per decision A).
- No edit-in-place / back-issue editing (issues are immutable posts).
- No base or build-event tracking — explicitly out of scope and never to be added to this feature.
- No @-mentions.
```
