# Bunker Visit Tracking — Design

Date: 2026-06-14
Status: Approved (pending spec review)

## Goal

Track when players visit the in-game bunker and surface two new leaderboards:

1. **Most Bunker Visits** — total counted visits per player.
2. **Quickest New-Life → Bunker** — each player's fastest time from the start of a life to
   reaching the bunker on that life.

## Background: how a visit appears in the ADM logs

The server's bunker uses a teleport mechanic: a player logs out inside a restricted area and,
on reconnect, is teleported into the bunker. This produces an explicit, self-labeling ADM line.

Real lines captured live (service `18196786`, file `..._2026-06-14_02-20-10.ADM`):

```
02:30:35 | Player "RonaldRaygun552" (id=... pos=<5154.0, 1075.1, 56.3>) was teleported from: <4767.429199, 339.441010, 10376.306641> to: <5154.072754, 56.397713, 1075.143311>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerEntrance
02:30:35 | Player "RonaldRaygun552" (id=... pos=<5154.1, 1075.1, 56.4>) is connected
...
03:01:32 | Player "RonaldRaygun552" (id=... pos=<4828.4, 10291.8, 339.9>) was teleported from: <5005.016113, 17.792059, 1086.642700> to: <4828.403809, 339.981598, 10291.797852>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerExit
```

Across 8 recent ADM files, the **only** two restricted-area reason strings are
`RestrictedAreaBunkerEntrance` and `RestrictedAreaBunkerExit`, so matching the entrance string is
unambiguous.

Key facts:

- The **entrance** teleport line is written immediately before the matching `is connected` line, at
  the same timestamp. It marks the moment a visit begins.
- The reason string is coordinate-independent, so detection does **not** need a coordinate/radius.
  (The originally-proposed proximity check around `5070/1107` r=100 is rejected — fragile, and the
  sample visit's connect logged at 89.9m would have sat right on the 100m boundary.)
- Coordinate convention note (not used for detection, recorded for posterity): in `pos=<x, y, z>`
  the map coords are `x, y` and `z` is altitude; in the teleport `to: <a, b, c>` field the order is
  `<x, altitude, y>`.

## Detection approach

A **bunker visit = an `RestrictedAreaBunkerEntrance` teleport line.** We parse the gamertag off that
line and record a visit (subject to the cooldown below). The `RestrictedAreaBunkerExit` line is not
needed for either leaderboard and is ignored (no dwell-time feature — YAGNI).

Optional guard (not implemented unless requested later): validate the teleport `to` coordinate is
near `5070/1107` before counting. Skipped — single-bunker server, and the reason string is already
specific.

## Data model

New migration adding table `bunker_visits`:

| column      | type                         | notes                                            |
| ----------- | ---------------------------- | ------------------------------------------------ |
| id          | bigint PK                    |                                                  |
| player_id   | bigint FK → players, cascade |                                                  |
| life_id     | bigint FK → lives, nullable, nullOnDelete | the open life at visit time (see edges) |
| visited_at  | timestamp                    | UTC, from the entrance line                      |
| created_at, updated_at | timestamps        |                                                  |

Indexes: `(player_id, visited_at)`, `(visited_at)`.

New Eloquent model `App\Models\BunkerVisit` (mirrors existing simple models).

## Components

All business logic in plain testable services; ingest hook and console command are thin wrappers —
matching the repo convention.

### Parser — `AdmParser::parseBunkerEntrance(string $line): ?array`

Pure. Returns `['gamertag' => ...]` when the line contains a teleport with
`Reason: ... RestrictedAreaBunkerEntrance`; otherwise `null`. Reuses the existing
`Player "<name>"` extraction style. Ignores exit lines, connect lines, death lines.

### `App\Services\Bunker\BunkerVisitService`

- `record(string $gamertag, \DateTimeImmutable $ts): ?BunkerVisit`
  - Resolve player via `Player::firstOrCreate(['gamertag' => $gamertag])`.
  - Resolve life via `$player->openLife()` (a disconnect does **not** end a life, so on a normal
    relog the open life is present). May be `null` for a never-before-seen player whose first
    observed event is a bunker spawn — record with `life_id = null` (counts toward total, excluded
    from the quickest board).
  - **Cooldown de-dup:** if a `bunker_visits` row exists for this player with
    `visited_at >= $ts - cooldown`, skip and return `null`. Default cooldown **60 minutes**
    (env-overridable). This collapses rapid relogs inside the bunker into a single visit.
  - Otherwise insert and return the row.
  - Pure DB writes; no Nitrado/Discord side effects; **not** gated by `BAN_DRY_RUN`.

### Ingest hook — `AdmIngestor::processFile`

Add one independent check alongside the existing `PositionRecorder` call (the entrance line is
distinct from connect/disconnect/death lines, so order does not matter):

```php
if (($b = $this->parser->parseBunkerEntrance($raw)) !== null) {
    $this->bunkerVisits->record($b['gamertag'], $ts);
}
```

`BunkerVisitService` is injected into `AdmIngestor` like `PositionRecorder` (constructor default so
existing tests keep working). Chronological cursor processing keeps the cooldown query correct during
backfill.

### Backfill — `adm:backfill-bunker-visits` console command

Mirrors `adm:backfill-positions`: scans ADM history for entrance teleports and records visits
(respecting the cooldown). Run once after deploy so historical visits populate the leaderboard.
Idempotent on re-run via the cooldown window (a second pass over the same history finds an existing
visit within 60 min and skips).

### Leaderboards — `LeaderboardStatsService` + `LeaderboardComposer`

Two new boards added to the existing single embed (no structural change):

- **`mostBunkerVisits(int $limit): array`** → rows `['gamertag' => ..., 'bunker_visits' => N]`.
  `COUNT(*)` grouped by player, `ORDER BY count DESC, MIN(visited_at) ASC` (earliest-to-reach wins
  ties). Rendered with the existing `countRows($rows, 'bunker_visits')`.
- **`quickestNewLifeToBunker(int $limit): array`** → rows `['gamertag' => ..., 'seconds' => S]`.
  For each life with ≥1 visit, `seconds = MIN(visited_at) - life.started_at`; take each player's
  minimum (one row per player, like the all-time-life board); `ORDER BY seconds ASC` with earliest
  life `started_at` as tie-break. Lives/visits with `life_id = null` are excluded. Rendered with the
  existing `durationRows`.

Composer adds two fields:

- `🚪 Most Bunker Visits` → `countRows($boards['bunker_visits'], 'bunker_visits')`
- `⏱️ Quickest New Life → Bunker` → `durationRows($boards['quickest_bunker'])`

`LeaderboardService` passes the two new keys when calling `compose(...)`. Both boards use plain
backticked gamertags (never @-mention), consistent with the leaderboard convention.

## Config — `config/bunker.php`

```php
return [
    'enabled'          => filter_var(env('BUNKER_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOL),
    'cooldown_minutes' => (int) env('BUNKER_VISIT_COOLDOWN_MINUTES', 60),
];
```

`.env` additions: `BUNKER_TRACKING_ENABLED=true`, `BUNKER_VISIT_COOLDOWN_MINUTES=60`. Pin the
cooldown default in `phpunit.xml` `<env>` so live `.env` tuning can't turn the suite red (per repo
convention). When `enabled` is false, `BunkerVisitService::record` is a no-op and the boards render
empty.

## Domain rules (easy to get wrong)

- A **visit** is the entrance teleport, not a proximity hit.
- **Cooldown is per player**, measured against the most recent prior `visited_at`; default 60 min.
- A disconnect does **not** end a life, so the open life is the correct life association on relog.
- **Quickest** uses the first visit *within a life* minus that life's `started_at`; only lives with a
  visit and a non-null `life_id` participate.
- Visits are counted across **all** ADM history (backfill included), consistent with how the
  leaderboard already counts all historical lives/kills. Not gated by `go_live_at` or `BAN_DRY_RUN`.

## Testing (TDD — failing test first)

- **Unit (`AdmParserTest`):** `parseBunkerEntrance` matches a real entrance line → gamertag; returns
  null for the exit line, a plain connect line, a death line, and a bare position line.
- **Feature (`BunkerVisitService`):** records a visit with correct player/life/`visited_at`;
  second entrance within cooldown is skipped; entrance after cooldown counts again; never-seen player
  → `life_id = null` row.
- **Feature (`LeaderboardStatsService`):** `mostBunkerVisits` ordering + tie-break;
  `quickestNewLifeToBunker` computes per-life delta, picks player min, orders ascending, excludes
  null-life visits. Time-dependent tests use `CarbonImmutable::setTestNow()`.

Slash/Service wrappers and the console command stay thin and are not unit-tested (no gateway), per
repo convention.

## Out of scope (YAGNI)

- Bunker dwell time / exit tracking.
- Multiple bunkers (single coordinate / single restricted area today).
- Token rewards for visits.
- A dedicated `/bunker-stats` slash command (the two leaderboards cover the ask).
