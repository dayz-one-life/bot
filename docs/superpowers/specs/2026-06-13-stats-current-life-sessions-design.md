# Spec: current-life session breakdown in `/stats`

**Date:** 2026-06-13
**Status:** Approved (design)

## Goal

Extend the `/stats` slash command so that, for a player who is currently **alive**, it
also lists the individual play sessions that make up their current (open) life. Each
listed session shows when it started and how long it lasted.

## Background

A **life** = first connect → death, and spans any number of **sessions** (a session ends
on clean disconnect or a server reboot, but the life continues). The data already exists:

- `Life` `hasMany` `GameSession`; the open life is the one with `ended_at IS NULL`.
- `GameSession` has `connected_at`, `disconnected_at`, `duration_seconds`, `close_reason`.
  The currently-open session has `disconnected_at IS NULL` and `duration_seconds IS NULL`.

Today `/stats` (`app/SlashCommands/StatsCommand.php`) renders a single summary block built
from `PlayerStatsService::statsFor()`. Per the repo convention, business logic lives in the
testable service and the slash command is a thin renderer.

## Decisions (locked during brainstorming)

- **Only when alive.** The session breakdown is shown only when the player has an open life.
  A dead player (no open life) shows the existing summary unchanged and **no** sessions block.
- **Per-session content:** connected time + duration only. No end-reason, no disconnect time.
- **Order:** oldest-first (reads as the life's progression).
- **Timezone:** UTC (stored timestamps are UTC).
- **Open session:** marked `(current)`; its duration is elapsed-so-far (`now() − connected_at`)
  since `duration_seconds` is null until the session closes.
- **Cap:** show at most the **12 most recent** sessions. If the life has more, prefix the block
  with `… +N earlier sessions`. Within the shown window, order remains oldest-first.

## Design

### Service — `App\Services\Stats\PlayerStatsService`

Add a `current_life_sessions` key to the `statsFor()` return value.

- Type: `array<int, array{connected_at:string, duration_seconds:int, is_open:bool}>`
  - `connected_at` — ISO-8601 UTC string.
  - `duration_seconds` — `GameSession::duration_seconds` for closed sessions; for the open
    session, computed as `CarbonImmutable::now()->diffInSeconds(connected_at)` (≥ 0).
  - `is_open` — true for the session whose `disconnected_at` is null.
- Populated **only when `alive === true`** (an open life exists); otherwise an empty array `[]`.
- Source: the open life's `sessions()`, ordered by `connected_at` ascending.
- The 12-most-recent cap is applied here: take the last 12 by `connected_at`, still returned
  ascending. Also return the total session count so the renderer can compute the `+N` overflow.
  Add a sibling key `current_life_session_total:int` (0 when not alive).

The existing keys (`found`, `gamertag`, `lives`, `deaths`, `playtime_seconds`,
`current_life_seconds`, `alive`, `linked`, `last_seen_at`) are unchanged.

### Command — `App\SlashCommands\StatsCommand`

After the existing summary block, when `current_life_sessions` is non-empty, append:

```
Sessions this life:
• Jun 13 14:02 UTC — 1h 23m
• Jun 13 16:40 UTC — 18m (current)
```

- If `current_life_session_total > count(current_life_sessions)`, prefix the list with a line:
  `… +{N} earlier sessions` where `N = total − shown`.
- Time format: `M j H:i \U\T\C` → e.g. `Jun 13 14:02 UTC`, formatted from the ISO string.
- Duration via `App\Services\Connection\SessionDuration::human($duration_seconds)` (reused).
- Reply stays **ephemeral**, plain text (no mentions) — consistent with existing behavior.

### Edge cases

- Dead player → `current_life_sessions === []` → no block appended.
- Open life with a single (open) session → one line, marked `(current)`.
- Open session shorter than 60s → `SessionDuration::human` yields `<1m`.

## Testing

Feature test on `PlayerStatsService` (`RefreshDatabase`, in-memory SQLite,
`CarbonImmutable::setTestNow()`):

1. **Alive, multiple sessions incl. open:** returns sessions oldest-first; closed sessions use
   stored `duration_seconds`; the open session has `is_open === true` and computed elapsed
   duration; `current_life_session_total` equals the number of sessions in the open life.
2. **Dead player:** `current_life_sessions === []`, `current_life_session_total === 0`, summary
   keys intact.
3. **Overflow:** a life with > 12 sessions returns exactly 12 (the most recent), still ascending,
   with `current_life_session_total` reflecting the true count.

The slash command itself is not unit-tested (no gateway), per repo convention — it stays a thin
renderer over the tested service.

## Out of scope

- End-reason / reboot annotations, disconnect timestamps, viewer-local Discord timestamp markup.
- Changes to dead-player output or any other `/stats` field.
