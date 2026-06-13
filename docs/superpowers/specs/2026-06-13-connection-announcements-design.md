# Connection Announcements — Design

**Date:** 2026-06-13
**Stack:** Laracord (Laravel Zero + DiscordPHP), SQLite. Builds on the implemented Plans 1–4 core.

## Overview

Post a one-line message to a dedicated Discord channel each time a player **connects** or
**disconnects** from the DayZ server. Disconnect lines include the session length. The channel is
expected to be high-volume, so messages are deliberately quiet: **no @-mentions** of linked Discord
users — only plain backticked gamertag text.

This is a thin presentation layer over the existing ADM ingest pipeline. The hard part is *not*
spamming the channel: connect/disconnect events flow through `LifeTracker` during both historical
**backfill** and **live** polling, and a restart after downtime replays accumulated log lines. The
design gates announcements so only genuinely-live, fresh events post.

## Design decisions (resolved during brainstorming)

- **Announce the raw log lines**, not reconstructed session state. We hook where
  `AdmParser::parseConnect` / `parseDisconnect` succeed, so "every connect/disconnect" means every
  such log line — sidestepping how `GameSession` close reasons (`clean` / `superseded` / `reboot`)
  should map to announcements. A server reboot closes many sessions but emits no per-player
  disconnect lines, so it produces **no** announcements (desired — no mass spam).
- **No @-mentions** — a deliberate exception to the repo's "public channel posts mention" rule
  (see `PlayerMention` / CLAUDE.md). Mentioning every connect/disconnect would be noisy and ping
  users constantly. Plain backticked gamertag only.
- **Disconnect shows session length** — `🔴 \`Gamertag\` disconnected · on for 1h 23m`. The tail is
  omitted when the disconnect line closed no open session.
- **Live-only, with a freshness guard.** Two failure modes to suppress:
  1. *Backfill replay* — historical lines processed before the bot first catches up. Gated by the
     ingestor's existing `$isLive` flag (mode flips to `live` only *after* the catch-up tick).
  2. *Stale restart burst* — after downtime, the newest `.ADM` file has grown and live-mode ingest
     processes hours-old lines in one tick. Gated by a freshness window: skip any event whose log
     timestamp is older than `CONNECTIONS_MAX_AGE_MINUTES` (default 10) before now.

## Architecture

Logic stays in testable plain services; the periodic `Service` is a wiring shim. Mirrors the
existing `BanNotifier` → `DiscordBanNotifier` / `NullBanNotifier` seam.

### New: `app/Services/Connection/`

- **`ConnectionNotifier`** (interface):
  ```php
  public function connected(string $gamertag, \DateTimeImmutable $ts): void;
  public function disconnected(string $gamertag, \DateTimeImmutable $ts, ?int $sessionSeconds): void;
  ```
- **`DiscordConnectionNotifier implements ConnectionNotifier`** — posts plain text (no
  `PlayerMention`) to `CONNECTIONS_CHANNEL_ID` via a best-effort `toChannel()` copied from
  `DiscordBanNotifier` (null client / missing channel / send failure all silently no-op). Message
  format:
  - `🟢 \`Gamertag\` connected`
  - `🔴 \`Gamertag\` disconnected · on for 1h 23m` (no `· on for …` tail when `sessionSeconds` is null)
- **`NullConnectionNotifier implements ConnectionNotifier`** — no-op. The default injected into
  `AdmIngestor`, so `adm:verify` and existing tests stay silent.
- **Duration humanizer** — `2h 5m` / `13m` / `<1m`. A static/testable helper (e.g. a static method
  on `DiscordConnectionNotifier` or a tiny `Connection\Duration` class) so it gets a unit test
  without a Discord gateway.

### Changed: existing code

- **`LifeTracker::disconnect()`**: return type `void` → `?GameSession`. Returns the session it
  closed (which already has `duration_seconds` set by `closeSession`), or `null` when no session was
  open. Non-breaking — existing callers ignore the return. `connect()` is unchanged (the ingestor
  already holds the gamertag + timestamp it needs to announce a connect).
- **`AdmIngestor`**:
  - Constructor gains `?ConnectionNotifier $connections = null`, defaulting to
    `new NullConnectionNotifier()` — exactly like the existing `?PositionRecorder $positions` default
    — plus `int $announceMaxAgeMinutes = 10` (the freshness window, set instance-wide so
    `processFile()` stays lean).
  - `processFile()` gains a `bool $announce` parameter. `tick()` passes the `$isLive` it already
    computes (line ~36).
  - Inside the per-line loop: when a connect line parses and `$announce` is true and the event is
    fresh, call `connected(...)`. When a disconnect line parses, capture the returned `?GameSession`
    and, if announcing + fresh, call `disconnected(..., $closed?->duration_seconds)`.
- **`IngestAdmService::handle()`**: build
  `new DiscordConnectionNotifier($this->discord(), env('CONNECTIONS_CHANNEL_ID'))` and inject it into
  the `AdmIngestor` constructor.

### Gating logic (in `processFile`, per connect/disconnect line)

1. `$announce` false (backfill / the catch-up tick) → never announce.
2. Live but `$ts` older than `CarbonImmutable::now()->subMinutes(CONNECTIONS_MAX_AGE_MINUTES)` →
   skip (stale restart backlog). Still ingested into the state machine; just not posted.
3. Live and fresh → announce.

The freshness comparison uses `Carbon\CarbonImmutable::now()` so it honors `setTestNow()`.

## Config / env

- `CONNECTIONS_CHANNEL_ID` — read directly via `env()` in `IngestAdmService` (no config file),
  matching how `BANS_CHANNEL_ID` is handled. Unset → `NullConnectionNotifier` behavior (no channel
  → no-op), so the feature is safely dark until configured.
- `CONNECTIONS_MAX_AGE_MINUTES` — default `10`. Read by `IngestAdmService` via `env()` and passed
  into the `AdmIngestor` constructor as `$announceMaxAgeMinutes` (the same threading pattern as
  `ADM_BACKFILL_BUDGET` → `tick()`). Pinned in `phpunit.xml` `<env>` per the repo rule (a test
  asserts default freshness behavior), so operator `.env` tuning can't turn the suite red.

Document both keys in CLAUDE.md's `.env` key list and the README.

## Testing (TDD)

Write failing tests first, then implement.

- **`LifeTracker::disconnect()`** returns the closed `GameSession` with correct `duration_seconds`;
  returns `null` when no session is open.
- **`AdmIngestor::processFile()`** with a fake `ConnectionNotifier`:
  - `announce=false` → zero notifier calls (backfill silent).
  - `announce=true`, fresh events → `connected()` / `disconnected()` called with the right gamertag,
    timestamp, and (for disconnect) session seconds.
  - `announce=true` but event timestamp older than the freshness window (via
    `CarbonImmutable::setTestNow()`) → not announced.
- **Duration humanizer** — unit tests for `2h 5m`, `13m`, `<1m` boundaries.
- **`DiscordConnectionNotifier`** stays thin and is not unit-tested (no gateway), per the repo
  convention that gateway-touching wrappers are covered only by `php -l` + class-load.

## Out of scope (YAGNI)

Online-count tracking, reboot/superseded announcements, batching or dedup, per-player opt-out,
edit-in-place "online now" board. None are needed for "announce every connect/disconnect."
