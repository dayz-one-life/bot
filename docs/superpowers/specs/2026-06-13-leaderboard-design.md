# Leaderboard — Design

**Date:** 2026-06-13
**Status:** Approved (design); implementation pending.

## What this is

An auto-updating Discord **leaderboard** for the one-life bot. A single rich embed is
posted to a dedicated leaderboard channel and **edited in place** on a fixed interval. It
ranks players across five boards. Players are rendered as **plain backticked gamertags —
never @-mentioned** (a high-frequency edited message; mentions would mass-ping). This is an
intentional exception to the "public posts mention" rule, in the same spirit as the
connections channel.

## Decisions (locked during brainstorming)

- **"Longest life" metric:** playtime = Σ session durations (matches `lives.playtime_seconds`),
  not wall-clock survival. For an *open* life, playtime is computed live (sum of finalized
  `game_sessions` durations + elapsed time of the currently-open session).
- **"Longest kills" board:** longest single PvP kills ranked by `lives.death_distance`.
- **"Longest kill streak":** most kills accumulated within a single life (first connect → death).
- **Layout:** one message / one embed containing all five boards as sections.
- **Player display:** plain backticked gamertag, no mentions.
- **Refresh:** every 15 minutes (env-overridable).
- **Entries per board:** Top 5 (env-overridable).
- **Dedupe:** the all-time-life and kill-streak boards show **one entry per player** (best
  life / best streak) so a single dominant player cannot occupy all five slots. The most-kills
  board is already per-player; the longest-distance board is intentionally per-shot (not deduped).
- **Channel:** dedicated `LEADERBOARD_CHANNEL_ID`. **No fallback** — unset/empty means the
  feature is off (the notifier no-ops on a null channel). (An earlier draft fell back to
  `BANS_CHANNEL_ID`; that was removed so the leaderboard never posts to the bans channel by
  surprise.)

## The five boards

| # | Board | Ranking key | Row contents |
|---|---|---|---|
| 1 | 🫀 Longest life (alive) | open lives by live playtime, desc | gamertag · playtime |
| 2 | ⏳ Longest life (all-time) | all lives by playtime, desc; best life per player | gamertag · playtime |
| 3 | 🔫 Most kills | count of PvP kills credited to a gamertag, desc | gamertag · kill count |
| 4 | 🩸 Longest kill streak | max kills within a single life; best per player | gamertag · streak count |
| 5 | 🎯 Longest kills | single PvP kills by `death_distance`, desc | killer · weapon · distance (· victim) |

Tie-break on every board: earliest `started_at` (the longer-standing record ranks higher).

## Data model (existing — no schema changes required)

Relevant existing columns:

- `lives`: `id, player_id, started_at, ended_at, playtime_seconds, death_cause,
  death_by_gamertag, death_weapon, death_distance`.
- `game_sessions`: `id, player_id, life_id, connected_at, disconnected_at, duration_seconds`.
- `players`: `id, gamertag, discord_user_id`.
- `bot_state`: `key` / `value` singleton K/V store.

A "kill" is a **victim** `lives` row with `death_cause = 'pvp'` and a non-null
`death_by_gamertag` (the killer). Kills are therefore counted by joining/grouping on
`death_by_gamertag`; there is no separate kills table and none is added.

### Kill-streak query (the only non-obvious computation)

A kill's timestamp is the victim life's `ended_at`. The killer's gamertag is the victim
life's `death_by_gamertag`. To compute streaks:

1. For each killer gamertag, enumerate that player's own lives, each with a window
   `[started_at, ended_at ?? now)`.
2. Count victim-deaths (PvP, `death_by_gamertag = killer`, `victim ≠ killer`) whose `ended_at`
   falls inside each killer-life window.
3. The streak for a killer-life = that count. Take the **max** across the player's lives →
   one streak per player. Rank desc, top N.

Heavy-ish (lives ⋈ lives) but trivial at single-server volume on a 15-minute cadence.

### Exclusions (all kill-based boards)

Require `death_cause = 'pvp'`, non-null `death_by_gamertag`, and killer gamertag ≠ the
victim life's own player's gamertag (drops suicides / self-attribution / environment deaths).

## Architecture

Follows the repo convention: **business logic in plain, testable services; the periodic
Service and Discord notifier are thin wrappers.**

### `App\Services\Leaderboard\LeaderboardStatsService` (plain, Feature-tested)

Five public methods, each returning a structured array of rows (associative arrays), capped
at `top_count`:

- `aliveLongestLives(int $limit)` — open lives, live playtime, desc.
- `allTimeLongestLives(int $limit)` — best life per player by playtime, desc.
- `mostKills(int $limit)` — count grouped by `death_by_gamertag`, desc.
- `longestKillStreaks(int $limit)` — best streak per player, desc.
- `longestKills(int $limit)` — single kills by `death_distance`, desc.

Owns all SQL. For open-life playtime it reuses the same session-sum + open-session-elapsed
logic already in `PlayerStatsService` (factor the shared computation rather than duplicate it).
Returns rows shaped for the composer, e.g.
`['gamertag' => string, 'value' => int, 'discord_user_id' => ?string, ...board-specific fields]`.

### `App\Services\Leaderboard\LeaderboardComposer` (pure, unit-tested)

Takes the five row-sets and produces formatted field strings:

- Durations via `App\Services\Connection\SessionDuration::human`.
- Distances formatted (e.g. `412m`), weapons passed through.
- Gamertags rendered as plain backticked text (never mentions).
- Each board → an embed field (name = board title, value = numbered Top-5 lines).
- Empty board → value `*No entries yet*`.
- Embed description = a rotating flavor line from a new `leaderboard.intro` personality pool
  (via `MessagePicker`), keeping the bot's voice; falls back to a plain string if the pool is
  empty.

Output is a Discord-agnostic structure (header/description + list of `{name, value}` fields)
so it is fully unit-testable without Discord types. The notifier assembles the actual embed.

### `App\Services\Leaderboard\LeaderboardNotifier` (interface) + `DiscordLeaderboardNotifier` / `NullLeaderboardNotifier`

`DiscordLeaderboardNotifier` performs **post-or-edit**:

1. Read `leaderboard_message_id` and `leaderboard_channel_id` from `bot_state`.
2. If an id exists **and** the stored channel matches the configured channel: fetch the
   message and `->edit()` it with the new embed.
3. If the message is missing/deleted, the channel changed, or no id exists: post a fresh
   message and store its id + channel in `bot_state`.

All Discord calls are best-effort and must not throw into the caller (swallow exceptions,
log to console). `NullLeaderboardNotifier` is a no-op for tests.

### `App\Services\LeaderboardService` (periodic `Laracord\Services\Service`)

Thin wiring. `protected int $interval` derived from `config('leaderboard.refresh_minutes') * 60`
(default 900s). `handle()`:

1. Return early if `config('leaderboard.enabled')` is false.
2. Call the five stats methods.
3. Compose the embed structure.
4. Hand it to the notifier (post-or-edit).
5. Wrap in try/catch → `console()->error(...)`.

Constructor uses the `?Laracord $bot = null` testability pattern. Not `BAN_DRY_RUN`-gated
(read-only; no bans).

## Config & state

New `config/leaderboard.php`, all env-overridable, defaults pinned in `phpunit.xml`:

```php
return [
    'enabled' => (bool) env('LEADERBOARD_ENABLED', true),
    'channel_id' => env('LEADERBOARD_CHANNEL_ID') ?: null, // no bans-channel fallback
    'refresh_minutes' => (int) env('LEADERBOARD_REFRESH_MINUTES', 15),
    'top_count' => (int) env('LEADERBOARD_TOP_COUNT', 5),
];
```

`bot_state` keys:
- `leaderboard_message_id` — Discord message snowflake of the live embed.
- `leaderboard_channel_id` — channel the live embed lives in (repost trigger on change).

`.env` additions documented in README / CLAUDE.md: `LEADERBOARD_CHANNEL_ID`,
`LEADERBOARD_REFRESH_MINUTES`, `LEADERBOARD_TOP_COUNT`, `LEADERBOARD_ENABLED`.

## Personality

Add a `leaderboard.intro` pool (≥10 cheeky lines) to `config/personality.php`, used as the
rotating embed description. No tokens required (or a `:count` of tracked players if convenient).
Constraint consistent with the rest of the system: it is a public channel post but **never
@-mentions** (the leaderboard channel is high-frequency).

## Testing

- **`LeaderboardStatsService` (Feature, `RefreshDatabase`, in-memory SQLite):** seed
  `players` / `lives` / `game_sessions`; assert ordering and contents of each of the five
  boards. Use `CarbonImmutable::setTestNow()` for the alive-board live-elapsed playtime and the
  kill-streak "now" window. Cover: dedupe-per-player on boards 2 & 4; exclusion of
  suicides/non-PvP/self-attribution; tie-break by `started_at`; empty boards.
- **`LeaderboardComposer` (Unit):** deterministic `MessagePicker` chooser; assert field
  formatting, duration/distance rendering, plain-gamertag (no `<@`), and the `*No entries yet*`
  empty state.
- **`LeaderboardService` (Feature):** run through `NullLeaderboardNotifier`; assert it composes
  without error and respects `enabled = false`.
- Notifier post-or-edit logic is not unit-tested (no gateway) — kept thin.
- Pin the four `LEADERBOARD_*` env defaults in `phpunit.xml`.

## Out of scope (YAGNI)

- No slash command to query the leaderboard on demand (the channel embed is the surface).
- No new database columns or kills table (computed from existing `lives`).
- No historical/seasonal reset — boards are all-time (and "alive" is inherently live).
- No pagination beyond Top N.
