# Online-players roster — design

**Date:** 2026-06-13
**Status:** Approved (ready for implementation plan)

## Goal

Replace the per-event connect/disconnect **feed** in the connections channel with a single
**online-players roster** message that is refreshed every 5 minutes and edited in place. Instead
of a running log of join/leave lines, the channel shows one always-present message listing who is
currently online.

## Background

Today `IngestAdmService` wires a `DiscordConnectionNotifier` into `AdmIngestor`, which posts a
`🟢 connected` / `🔴 disconnected · on for 1h 23m` line per event (live + freshness-gated). We are
removing that event feed entirely and replacing it with a roster snapshot.

"Online" is derived from the database, not from events: a player is online iff they have an open
`game_sessions` row (`disconnected_at IS NULL`). Ingest already records these via `LifeTracker`,
so the roster is fully decoupled from ingestion — it just reads the DB on its own cadence.

## Model

Mirror the **leaderboard** subsystem exactly:

- a periodic `Laracord\Services\Service`,
- a **pure** composer that produces a Discord-agnostic embed payload,
- a notifier that **posts one message and edits it in place**, persisting the message id in
  `bot_state` (repost if the stored message is gone).

## Components — `app/Services/Online/`

### 1. `OnlineRosterQuery` (read query)

Returns a row per player with an open session, each:

```
{ gamertag: string, session_seconds: int, life_seconds: int }
```

- `session_seconds` = `now - openSession.connected_at`.
- `life_seconds` = `App\Services\Life\LivePlaytime::forLife(openLife)` (stored life playtime +
  the open session's elapsed-so-far). If a player is online but somehow has no open life, fall
  back to `session_seconds`.
- Sorted **longest current session first**.

Feature-tested with `RefreshDatabase` + `CarbonImmutable::setTestNow()`: open sessions produce
rows with correct session/life seconds and ordering; disconnected sessions are excluded.

### 2. `OnlineRosterComposer` (pure)

Takes the rows and returns a Discord-agnostic embed payload `{ title, description }` (and/or
fields, matching the leaderboard composer's shape):

- Title: `🟢 Online — N` where N = row count.
- Each row line: `` `gamertag` · on 1h 23m · alive 4h 12m`` — durations via
  `App\Services\Connection\SessionDuration::human`.
- Empty state (N = 0): description `Nobody's online right now.`
- **Plain backticked gamertags — never @-mention.** This is the same intentional exception the old
  connection feed had (high-volume channel), and an explicit deviation from the repo's
  "public channel posts mention" rule.

Unit-tested: rows → payload; empty → "nobody online"; output contains backticked tags and no
`<@` mention syntax.

### 3. `OnlineRosterNotifier` (interface) + `DiscordOnlineRosterNotifier` + `NullOnlineRosterNotifier`

Copy the leaderboard notifier shape:

- `publish(array $payload): void`.
- `DiscordOnlineRosterNotifier`: post once, then edit in place. Persist
  `online_roster_message_id` and `online_roster_channel_id` in `bot_state`. If the stored message
  fetch fails (deleted) or the channel changed, post a fresh message and re-store. Entirely
  best-effort: null client, missing channel id, or any send failure silently no-ops.
- `NullOnlineRosterNotifier`: no-op, for tests.

### 4. `OnlinePlayersService` (periodic `Service`)

- `protected int $interval = 300;` (5 min), overridden from config in the constructor like
  `LeaderboardService`.
- No-arg test constructor: `public function __construct(?Laracord $bot = null) { if ($bot) parent::__construct($bot); ... }`.
- `handle()`: return early if `online.enabled` is false; else build the payload from
  `OnlineRosterQuery` + `OnlineRosterComposer` and hand it to `DiscordOnlineRosterNotifier`,
  wrapped in try/catch with a `console()->error` on failure.
- A `compose(OnlineRosterNotifier $notifier)` seam so tests can inject the null notifier.
- Not gated by `BAN_DRY_RUN` (read-only).

## Config — `config/online.php`

| Key | Env | Default |
| --- | --- | --- |
| `channel_id` | `CONNECTIONS_CHANNEL_ID` (reused) | — |
| `refresh_minutes` | `CONNECTIONS_REFRESH_MINUTES` (new) | 5 |
| `enabled` | `CONNECTIONS_ENABLED` (new) | true |

`refresh_minutes` is clamped to a sane minimum (≥1 min → ≥60s interval) like the leaderboard.
If a default is asserted in a test, pin it in `phpunit.xml` `<env>`.

## Removals

- Delete `app/Services/Connection/ConnectionNotifier.php`,
  `DiscordConnectionNotifier.php`, `NullConnectionNotifier.php`.
- In `AdmIngestor`: remove the `$connections` notifier param + `announceMaxAgeMinutes` param and
  the `connected()` / `disconnected()` notifier calls. **`game_sessions` recording via
  `LifeTracker` is untouched.**
- In `IngestAdmService`: drop the connection-notifier wiring and the `CONNECTIONS_MAX_AGE_MINUTES`
  read.
- Update `AdmIngestorTest` to drop the connection-announcement assertions.
- **`SessionDuration` stays** in `App\Services\Connection` — still imported by `StatsCommand`,
  `LeaderboardComposer`, and the roster composer. The `Connection` namespace becomes vestigial
  (one file), accepted to avoid churn across those importers.

## Data flow

```
ingest tick (60s)  ->  game_sessions kept current (LifeTracker)
OnlinePlayersService (300s)  ->  OnlineRosterQuery (snapshot open sessions)
                              ->  OnlineRosterComposer (pure payload)
                              ->  DiscordOnlineRosterNotifier (post-or-edit one message)
```

## Error handling

Notifier is best-effort and swallows every Discord failure; the service wraps its tick in
try/catch and logs via `console()->error`, never throwing into the Laracord loop. Read-only —
no Nitrado writes, no ban side effects.

## Testing (TDD)

- `OnlineRosterQuery` — Feature test (`RefreshDatabase`, `setTestNow`): correct rows, session/life
  seconds, sort order, exclusion of disconnected sessions.
- `OnlineRosterComposer` — Unit test: populated → payload; empty → "nobody online"; backticked
  tags and no `<@` mentions.
- `AdmIngestorTest` — updated to remove connection-announcement assertions.

Slash commands / periodic services aren't unit-tested (no gateway) — `OnlinePlayersService` stays a
thin wiring shim over the tested query/composer/notifier.
