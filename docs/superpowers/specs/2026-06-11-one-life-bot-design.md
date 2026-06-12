# One Life Bot — Design

**Date:** 2026-06-11
**Stack:** Laracord (Laravel + DiscordPHP), SQLite, single Nitrado-hosted Xbox DayZ server.

## Overview

A Discord bot for a "one life" DayZ server. It ingests the server's `.ADM` admin logs
from Nitrado, detects player deaths, and bans the dead player for 12 hours. Players link
their Discord account to a gamertag (with autocomplete) to earn and spend **unban tokens**,
which lift temporary bans for themselves or others. Linked players receive a free token
each month plus a bonus token for every referred player who was active that month. The bot
also tracks each player's **lives** and **playtime** by reconstructing connect/disconnect/
death events from the logs.

This is a fresh rebuild in Laracord. Two existing Node.js projects are **domain references
only** (do not copy code):

- `../koth-bot` (i.e. `/Users/steveharmeyer/Development/dayz-one-life/koth-bot`) — working
  Nitrado client + ADM parsing + UTC timestamp reconstruction.
- `../../dayzkoth/onelife-bot` (i.e. `/Users/steveharmeyer/Development/dayzkoth/onelife-bot`)
  — the previous one-life bot (Prisma): ban logic, link/unban commands, monthly rewards. It
  did **not** track lives or playtime.

## Scope

**In scope (focused core):**

- ADM ingestion from Nitrado.
- Death → 12-hour ban, with automatic expiry.
- Discord ↔ gamertag linking with autocomplete (one gamertag per Discord user).
- Unban tokens: `+1` on first link, `+1`/month, `+1`/month per active referral; redeemable
  for self or another player.
- Referrer: set once (at link or later), then locked.
- Life + playtime tracking (new).

**Deferred (the old bot had these; not now):** multi-server support, supporter-role bonus
tokens, Discord invite tracking, kill feeds, leaderboards, player-list channels, location/
timezone rotation.

**Explicitly excluded from core** (recommendation accepted): auto-unban a player when they
link; instant token to a referrer at referral time. Monthly per-active-referral bonus is the
only referrer reward.

## Decisions (resolved during brainstorming)

- **Ban mechanism:** Nitrado stores DayZ bans in gameserver **settings**, not a file:
  `settings.general.bans` is a newline-separated list of gamertags. `GET
  /services/{id}/gameservers/settings`, then `POST` to update `general.bans`. Add a gamertag
  on death; remove on expiry. No file upload, no restart required.
- **Death scope:** *all* deaths end a life and trigger the ban — PvP kills, bleeding out,
  drowning, suicide, starvation, falling, "died". True one-life.
- **Playtime model:** a session is a connect→disconnect pair; `playtime(life) = Σ session
  durations`. A session left open by a server reboot is closed at the reboot timestamp
  (never invents time across the gap).
- **Referral "active":** a referred player counts if they had ≥1 connect event in the past
  calendar month.
- **First run:** ingest all historical ADM for stats (lives/playtime/deaths), but set a
  `go_live_at` cutoff and only auto-ban deaths after it. No mass retro-bans.
- **Linking:** one gamertag per Discord user, 1:1.
- **Referrer:** set once, then locked; no self-referral; referrer must be a linked player.
- **Link reward:** one-time on first link only (`link_rewarded` guard); relink grants
  nothing.
- **Execution model:** all periodic work runs in-process as Laracord `Task`s in the ReactPHP
  loop (Option A). Blocking Nitrado HTTP is controlled via small downloads, a bounded
  backfill budget per tick, and short timeouts. Can be extracted to a cron CLI later without
  touching the data model.

## Architecture

Single Laracord process, single Nitrado server, SQLite. Config in `.env`:
`NITRADO_TOKEN`, `NITRADO_SERVICE_ID`, `DISCORD_TOKEN`, `DISCORD_GUILD_ID`,
`BANS_CHANNEL_ID`, `BAN_DURATION_HOURS=12`, `TZ=UTC`.

```
app/
  Services/
    Nitrado/NitradoClient.php   # HTTP: get/update settings.general.bans, listAdmFiles, downloadFile
    Adm/AdmParser.php           # pure: parse connect/disconnect/kill/death + UTC timestamp assignment
    Adm/AdmIngestor.php         # mode machine: files -> cursor -> events -> LifeTracker/BanService
    Ban/BanService.php          # ban()/unban(): DB + Nitrado general.bans + notifications
    Life/LifeTracker.php        # connect/disconnect/death/reboot -> lives, sessions, playtime
    Tokens/RewardService.php    # link reward, monthly grant, active-referral bonus
  Tasks/
    IngestAdmTask.php           # interval ~60s: one ingest tick (bounded backfill)
    BanExpiryTask.php           # interval ~60s: sweep expired bans -> unban; reconcile general.bans
    MonthlyRewardTask.php       # interval hourly: run RewardService when the month rolls over
  Commands/                     # slash commands (see below)
  Models/  Player, Ban, Life, GameSession, AdmFile, BotState
```

**Pure vs effectful split:** `AdmParser` (regex + timestamp math) and the interval math in
`LifeTracker` are pure and unit-tested against real ADM samples. `NitradoClient`, DB writes,
and Discord I/O are the effectful edges.

## Data model (Eloquent)

```
players       id, gamertag (unique), discord_user_id (unique, null),
              referrer_id (FK players, null, set-once), unban_tokens (int, default 0),
              used_tokens (int, default 0), link_rewarded (bool, default false),
              first_seen_at, last_seen_at, timestamps
adm_files     id, path (unique), name, log_date, is_complete (bool),
              last_processed_line (int), last_known_size (int), timestamps
bans          id, player_id (FK), banned_at, expires_at (null = permanent),
              expired (bool), reason, source (enum: auto_death|manual|token), timestamps
lives         id, player_id (FK), started_at, ended_at (null = alive),
              death_cause (null), death_by_gamertag (null),
              playtime_seconds (int, default 0), timestamps
game_sessions id, player_id (FK), life_id (FK), connected_at, disconnected_at (null = online),
              duration_seconds (int, null), close_reason (enum: clean|reboot|superseded), timestamps
bot_state     key (unique), value          # mode, go_live_at, ingest_high_water, last_reward_month
```

Referral count and "active referrals" are **computed**, not stored: count `players` where
`referrer_id = me`; active = that player has a `game_sessions.connected_at` in the target
month. No `nitrado_service` table — single-server config lives in `.env`. Permanent bans
(`expires_at = null`) cannot be cleared by tokens.

## ADM ingestion + life/playtime tracking

### Timestamp reconstruction (ported from koth-bot)

ADM event lines carry only `HH:MM:SS`. The date comes from the `AdminLog started on
YYYY-MM-DD` header, bumped a day whenever the clock runs backwards (a midnight crossing
within one boot). A server-local→UTC offset is derived from Nitrado's `modified_at` vs the
filename time, snapped to a 15-minute multiple. `AdmParser` ports this and is unit-tested
against real files.

### Two ingestion modes (tracked in `bot_state.mode`)

Life-tracking is a **stateful, chronological** machine, unlike koth-bot's order-independent
kill feed — so events must be applied in time order.

- **BACKFILL** (first run / catching up): process files **oldest→newest**, a bounded N files
  per tick, building lives/sessions/playtime. **No bans issued.** When the newest file's
  cursor reaches its end and all older files are complete → set `go_live_at = now`, flip to
  LIVE.
- **LIVE** (steady state): each ~60s tick re-reads the newest file from its line cursor and
  applies new events in order. A death with `ts > go_live_at` triggers a ban.

Idempotency is the per-file `last_processed_line` cursor (each line applied exactly once).
Open lives/sessions persist in the DB, so bot restarts resume cleanly.

### Per-player state machine

Each player has at most one *open life* (`ended_at = null`) and one *open session*
(`disconnected_at = null`).

| Event | Action |
|---|---|
| **CONNECT** | upsert player (set `first_seen_at` if null, `last_seen_at = ts`); if no open life, start a new life; close any stale open session as `superseded`; open a session (`connected_at = ts`, attach to the open life) |
| **DISCONNECT** | close the open session `clean` (`duration = ts − connected_at`, add to `life.playtime_seconds`); the life stays open |
| **DEATH** (any cause) | close the open session `clean` at `ts`; end the life (`ended_at = ts`, record `death_cause` + `death_by_gamertag`); if LIVE and `ts > go_live_at`, call `BanService` (12h, `auto_death`, idempotent) |
| **BOOT** (`AdminLog started`) | close **all** open sessions at `ts` (`reboot`, add to playtime); lives stay open — a restart doesn't kill anyone |

Therefore: **a life = first connect → death**, spanning any number of sessions and reboots.
**Playtime = Σ session durations** within the life, a session being closed by a clean
disconnect, a reboot (capped at boot time), or death. After a death the player has no open
life, so their next connect (post-ban) opens their next life. A reboot only ends sessions —
when the player reconnects they continue the **same** open life.

**Acknowledged imprecision:** a duplicate CONNECT with no preceding disconnect/reboot closes
the stale session as `superseded` at the new connect time, which can over-count one idle gap.
Logged with that reason for auditability; rare here because death→ban prevents respawn
connects.

## Ban lifecycle

- `BanService.ban(gamertag, hours = 12, reason, source)`: upsert player → compute
  `expires_at` (null = permanent) → create a `bans` row, or **extend** an existing active ban
  (update `banned_at`/`expires_at`) so repeated deaths don't stack → add gamertag to Nitrado
  `general.bans` → notify (post to `BANS_CHANNEL_ID`; DM the player if linked).
- `BanService.unban(gamertag, reason)`: remove from `general.bans` → mark active bans
  `expired` → notify.
- **Death → ban:** ingestion (LIVE, `ts > go_live_at`) calls `ban(..., source: auto_death)`.
- **Expiry:** `BanExpiryTask` (~60s) sweeps `expired = false AND expires_at < now` →
  `unban(reason: "Ban expired")`.
- **Reconcile:** each sweep also re-asserts that every still-active ban is present in
  `general.bans`, healing manual edits or a failed earlier write.
- **Permanent bans** (`expires_at = null`): never swept, not token-removable; admin-only.

## Token economy

- **Link reward:** first successful link → `+1 token`, guarded by `players.link_rewarded`.
- **Monthly grant** (`MonthlyRewardTask`, hourly check; runs when
  `bot_state.last_reward_month != current month`): each linked player gets `+1` base, plus
  `+1` per referred player active in the **previous calendar month** (≥1
  `game_sessions.connected_at` in that month). DM each recipient a breakdown. Idempotent per
  month; survives downtime.
- **Redemption** (`/unban [player]`): spender must be linked with ≥1 token; target = self or
  another gamertag with an active **temporary** ban; token deducted **only on success**;
  permanent bans rejected.
- **Referrer:** set via `/link`'s `referrer` option or `/referrer` later — only if none set
  (then locked), no self-referral, referrer must be a linked player.

## Commands (slash, guild-scoped)

**Player**

- `/link gamertag [referrer]` — link (autocomplete: `gamertag` from unlinked-but-seen
  players, `referrer` from linked players). Grants the one-time link token.
- `/referrer gamertag` — set a referrer later (only if none set; locked after).
- `/unban [player]` — spend a token to lift a temp ban (self default; `player` autocomplete =
  currently temp-banned gamertags).
- `/unbans` — your token balance.
- `/bans [player]` — ban status/history.
- `/referrals` — who you referred + active count.
- `/players gamertag` — lives, total playtime, deaths.

**Admin (role-gated)**

- `/adminban gamertag [hours] [reason]`
- `/adminunban gamertag`
- `/adminlink discord gamertag`
- `/adminunlink discord`
- `/addunban player amount`
- `/distribute-unbans` — manually trigger/preview the monthly grant.

## Error handling & resilience

- **Nitrado:** short HTTP timeouts, retry-with-backoff, failures logged and retried next
  tick — never crash the loop. Ban write failures are healed by the reconcile sweep.
- **Consistency:** per-file line cursors for idempotent ingestion; DB transactions around
  token spend/grant; Discord DM failures caught and ignored.
- **Config:** validate required `.env` on boot; refuse to start if any of `NITRADO_TOKEN`,
  `NITRADO_SERVICE_ID`, `DISCORD_TOKEN`, `DISCORD_GUILD_ID` is missing.

## Testing & verification

- **Unit:** `AdmParser` against real ADM samples; `LifeTracker` state machine (synthetic +
  real sequences); `RewardService` referral/active math; `BanService` with a mocked
  `NitradoClient`.
- **Verification milestone (required before live banning):** using the user-provided Nitrado
  token, download the full ADM set, run **BACKFILL with banning disabled**, and inspect the
  resulting lives/sessions/playtime/deaths with the user for correctness. Only after this is
  validated do we enable LIVE mode (auto-banning).

## Build phases (for the implementation plan)

1. Project scaffold (Laracord, SQLite, `.env`, migrations, models).
2. `NitradoClient` + `AdmParser` (pure), unit-tested against real samples.
3. `LifeTracker` + `AdmIngestor` BACKFILL mode (no bans) + `IngestAdmTask`.
4. **Verification milestone** against the real ADM set.
5. `BanService` + LIVE mode + `BanExpiryTask` (death→ban→expiry, reconcile).
6. Linking + tokens: `/link`, `/referrer`, `RewardService` link reward, `MonthlyRewardTask`.
7. Redemption + read commands: `/unban`, `/unbans`, `/bans`, `/referrals`, `/players`.
8. Admin commands.
