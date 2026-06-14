# Leaderboard: split into 7 messages, 25 entries each — Design

Date: 2026-06-14

## Goal

Replace the single 7-field leaderboard embed with **7 independent embed messages**
(one per board), each showing up to **25 ranked entries**, refreshed in place every
cycle. Each board carries its own cheeky personality line.

## Background

Today `LeaderboardService` builds one embed via `LeaderboardComposer::compose()`
(title + intro personality line + 7 fields) and `DiscordLeaderboardNotifier` posts it
once and edits it in place, persisting a single `leaderboard_message_id` /
`leaderboard_channel_id` in `bot_state`. `top_count` defaults to 5.

The single embed crowds 7 boards together and caps entries low. Splitting into 7
messages gives each board room for 25 entries and its own personality.

## Board order (fixed, top → bottom)

1. 🫀 Longest Life · Still Alive — `alive`
2. ⏳ Longest Life · All Time — `all_time`
3. 🔫 Most Kills — `kills`
4. 🩸 Longest Kill Streak — `streak`
5. 🎯 Longest Kills — `distance`
6. 🚪 Most Bunker Visits — `bunker_visits`
7. ⏱️ Quickest New Life → Bunker — `quickest_bunker`

This ordering is the canonical source of truth for both compose order and the
persisted message-id order.

## Design

### Why entries go in the embed *description*, not a field

A Discord embed **field value** is capped at 1024 characters. With 25 entries, the
longest-kill rows (`N. \`killer\` (weapon) — 845m → \`victim\``) can exceed that.
The embed **description** allows 4096 characters, comfortably fitting 25 rows of any
board. So each board's entries render in the description.

### Components & changes

**`LeaderboardComposer`** (pure)
- Replace `compose(array $boards): array` with
  `composeBoards(array $boards): array` returning an **ordered list of 7 payloads**,
  each `{key: string, title: string, description: string}`, in the board order above.
- `description` = the per-board personality line + blank line + the ranked rows.
- The row-rendering helpers (`durationRows`, `countRows`, `distanceRows`) are unchanged
  and feed the description.
- Empty board → rows render as `*No entries yet*` (the personality line still shows).
- Personality line drawn via the injected `MessagePicker` using the board's dot-key
  (`leaderboard.alive`, `leaderboard.kills`, …).

**`config/personality.php`**
- Add 7 pools, ≥10 lines each:
  `leaderboard.alive`, `leaderboard.all_time`, `leaderboard.kills`,
  `leaderboard.streak`, `leaderboard.distance`, `leaderboard.bunker_visits`,
  `leaderboard.quickest_bunker`.
- **Retire** the `leaderboard.intro` pool (no header/intro message any more).
- These are channel posts but the leaderboard **never @-mentions** — keep that
  invariant; lines reference the board, not players.

**`LeaderboardNotifier` interface + `NullLeaderboardNotifier`**
- `publish(array $payloads)` now takes the ordered list of 7 board payloads.
- `NullLeaderboardNotifier` captures `public ?array $lastPayloads` (was `lastPayload`).

**`DiscordLeaderboardNotifier`** (best-effort, never throws)
- Persist message ids as a JSON array `leaderboard_message_ids` (7 ids, in board order)
  plus `leaderboard_channel_id` in `bot_state`.
- Per tick:
  - If `discord` or channel id is missing → no-op.
  - If channel changed, no stored ids, or stored id count ≠ 7 → **full reflush**.
  - Else fetch all 7 messages (`React\Promise\all`):
    - On success → edit each message in place with its payload.
    - **If any fetch rejects → full reflush** (atomic: order is always correct).
- **Reflush**:
  1. Delete the legacy single message if present (old `leaderboard_message_id`),
     then clear `leaderboard_message_id` (one-time migration from the old layout).
  2. Delete the 7 currently-stored messages (best-effort).
  3. Post all 7 **sequentially** (chained promises) so Discord display order matches
     the board order, collecting the new ids.
  4. Store the new id list as JSON in `leaderboard_message_ids` and set
     `leaderboard_channel_id`.
- Each board posts/edits as its own `Embed` (title + description).

**`config/leaderboard.php`**
- `top_count` default `5 → 25` (operator can still override via `LEADERBOARD_TOP_COUNT`).
- Update `phpunit.xml` `<env>` pin and `LeaderboardConfigTest` accordingly.

**`LeaderboardService::compose()`**
- Call `composeBoards()` and pass the ordered list to `$notifier->publish()`.
- Stats calls and the seven `$stats->…($top)` lines are unchanged.

### Edge cases

- **Empty boards** (fresh DB): each embed still posts with its personality line and
  `*No entries yet*`. No special-casing needed.
- **Channel id changed in config**: reflush (channel mismatch path) — old messages in
  the prior channel are orphaned (we only delete by stored id within the known channel;
  acceptable, matches existing behavior).
- **A board message manually deleted**: next tick's fetch-all rejects → reflush →
  order restored.
- **First run after deploy** (legacy single message exists): `leaderboard_message_ids`
  is null → reflush, which deletes the legacy message and posts the 7 fresh.

## Testing

- **`LeaderboardComposerTest`** — `composeBoards()` returns 7 payloads in the fixed
  order; each has the right title and a description containing the ranked rows; empty
  board → `*No entries yet*`; personality line present (deterministic `MessagePicker`
  via injected randomness). Replaces the old single-embed assertions.
- **`PersonalityConfigTest`** — swap `leaderboard.intro` for the 7 new keys in the
  pool-completeness list (each ≥10 non-empty lines).
- **`LeaderboardConfigTest`** — `top_count` is `25`.
- **`NullLeaderboardNotifierTest`** — captures the ordered list as `lastPayloads`.
- **`LeaderboardServiceTest`** — wires stats → `composeBoards` → notifier; asserts the
  Null notifier received 7 payloads.
- The `DiscordLeaderboardNotifier` stays a thin best-effort wrapper and is **not**
  unit-tested (no Discord gateway in tests — repo convention).

## Out of scope

- No changes to `LeaderboardStatsService` queries (they already accept the count).
- No change to refresh cadence, `enabled`, or channel gating.
- No new env keys (reuse `LEADERBOARD_TOP_COUNT`, `LEADERBOARD_CHANNEL_ID`, …).
