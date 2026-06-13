# Death Feed — Design

Date: 2026-06-13
Status: Approved (pending implementation plan)

## Summary

A public "death feed": every time a player dies on the one-life server, the bot posts a
single cheeky, detail-rich message to the bans channel — who died, who killed them (with
weapon and distance for PvP), the manner of a non-PvP death, and when the 12-hour ban
lifts. It **merges** the kill detail and the ban notice into one post (option C: same
channel, richer post), replacing the plain `ban.death` channel announcement.

The feed is a new, self-contained concern under `app/Services/DeathFeed/`. It is
**decoupled from `BAN_DRY_RUN`** — it posts as soon as a fresh death is reconciled after
go-live (like the bounty token awards), even before real banning is armed. Real Nitrado
bans and the player DM still wait for the dry-run cutover.

## Data flow

Deaths already flow through the system:

```
AdmParser::parseDeath()  →  LifeTracker::death()  →  DeathBanService::run() (60s tick)
   (victim, cause,            (closes the open        (reconciles lives ended after
    killer, weapon,            life, records           go_live_at; issues the 12h ban
    distance)                  death detail)            via BanService)
```

`DeathBanService` is the integration point: it is the one place that holds **both** the
life's full death detail **and** the issued ban's `expires_at`. The death feed posts from
there.

## Components

### 1. Schema + LifeTracker

The `lives` table already stores `death_cause` and `death_by_gamertag` (the killer's
gamertag). The parser also returns `weapon` and `distance`, but they are currently
discarded. Add a migration:

- `death_weapon` — string, nullable
- `death_distance` — decimal/float, nullable

`LifeTracker::death()` writes both from the parsed `$death` array alongside the existing
cause/killer fields. The `Life` model is mass-assignable (`$guarded = []`), so only a
`death_distance => 'float'` cast is added (no `$fillable` change).

### 2. DeathFeedComposer (pure, testable core)

A plain service that, given the death detail and the ban's `expires_at`, produces the
message string. Responsibilities:

- **Pool selection** — map the death to one personality pool key:
  - `death.pvp` — PvP kill **with** weapon + distance
  - `death.pvp_noweapon` — PvP kill, killer known but weapon/distance unknown
  - `death.suicide` — `cause === 'suicide'`
  - `death.environment` — `cause === 'environment'` (animal / infected / world)
  - `death.misc` — `bled_out` / `drowned` / `died` / `unknown`, with a humanized `:cause`
    token (e.g. "bled out", "drowned")
- **Token rendering** — `:victim` and `:killer` rendered via `PlayerMention` (a public
  channel post, so linked players are **@-mentioned**; unlinked fall back to backticked
  gamertag). `:weapon`, `:distance` (rounded metres), `:cause`, and `:expires` (a Discord
  relative timestamp `<t:unix:R>`).
- **Determinism** — randomness is injected (closure), as with `MessagePicker`, so tests
  are deterministic.

### 3. Notifier trio

Mirrors the Connection/Ban notifier pattern:

- `DeathFeedNotifier` (interface) — `died(Life $life, Ban $ban): void`
- `DiscordDeathFeedNotifier` — composes via `DeathFeedComposer` + `MessagePicker`, sends
  to `BANS_CHANNEL_ID` (option C — same channel; no new env var). Best-effort: a null
  client, missing channel, or send failure all silently no-op; never throws into the
  caller.
- `NullDeathFeedNotifier` — default no-op, used in tests and when unconfigured.

### 4. Personality pools

Five new pools in `config/personality.php`, each ≥10 lines in the established cheeky
house style, each line ending with the ban return time `:expires`:

- `death.pvp` — `:killer` `:victim` `:weapon` `:distance` `:expires`
  - e.g. *"💀 :killer dropped :victim with a :weapon at :distancem — back :expires."*
- `death.pvp_noweapon` — `:killer` `:victim` `:expires`
- `death.suicide` — `:victim` `:expires`
- `death.environment` — `:victim` `:expires`
- `death.misc` — `:victim` `:cause` `:expires`

No content constraint applies (unlike `bounty.ended`); these are free to be as cheeky as
the rest. Pools never need a non-mention rule — mentions here are intended.

### 5. Wiring & gating (DeathBanService)

`DeathBanService` gains:

- an injected `?DeathFeedNotifier $feed = null` (defaults to `NullDeathFeedNotifier`)
- a freshness window `int $feedMaxAgeMinutes` (from `DEATH_FEED_MAX_AGE_MINUTES`,
  default 10)

In `run()`, for each life it bans, it captures the returned `Ban` (`BanService::ban()`
already returns it, and the row — with `expires_at` — is written even in dry-run) and
calls `feed->died($life, $ban)` when the life is **fresh** (`ended_at` within the window).

Gating rules:

- **Dry-run independent** — the feed fires regardless of `BAN_DRY_RUN`. (The real Nitrado
  ban and the player DM remain dry-run-gated inside `BanService`.)
- **Freshness** — stale lives (older than the window, e.g. reconciled after bot downtime)
  still get `ban_issued = true` and the ban, but **no feed post**, suppressing a backlog
  flood. This mirrors `CONNECTIONS_MAX_AGE_MINUTES`.
- **Idempotent** — the existing `ban_issued` flag guarantees each life is reconciled (and
  thus posted) at most once; restarts never repost.

### 6. Ban notifier de-duplication

`DiscordBanNotifier::banned()` currently posts the `ban.death` line to the channel **and**
DMs the player. Once the death feed owns the public death announcement, the channel post
would duplicate it at cutover. Change:

- For the `ban.death` key, **skip the channel post** (the feed owns it) but **keep the
  `ban.dm.death` DM** to the banned player.
- `ban.manual` and `ban.extended` are unchanged (still post to channel + DM).

The `ban.death` channel personality pool is retained in config but is no longer used for
channel posts (the DM uses the separate `ban.dm.death` pool).

## Configuration

- `DEATH_FEED_MAX_AGE_MINUTES` — freshness window, default 10. Pinned in `phpunit.xml`
  `<env>` so the suite doesn't depend on the operator `.env`.
- Reuses `BANS_CHANNEL_ID` — no new channel env var.
- `IngestAdmService` constructs `DiscordDeathFeedNotifier($this->discord(),
  env('BANS_CHANNEL_ID'))` and passes it (plus the window) into `DeathBanService`.

## Testing (TDD)

- **LifeTracker** — a PvP death persists `death_weapon` + `death_distance` on the life;
  a non-PvP death leaves them null (Feature).
- **DeathFeedComposer** — correct pool key per cause; `:weapon`/`:distance` rendered;
  victim & killer mentioned when linked, backticked when not; deterministic with injected
  randomness (Unit/Feature).
- **Personality** — all five pools exist, each ≥10 lines, required tokens present in
  every line (Unit).
- **DeathBanService** — calls `feed->died()` with the right life/ban; fires in dry-run;
  skips stale lives (still bans them); never double-fires across ticks (Feature, with a
  recording fake notifier).
- **DiscordBanNotifier** — `ban.death` no longer posts to the channel; the death DM is
  still sent; manual/extended unchanged (Feature/Unit).

## Out of scope (YAGNI)

- A separate death-feed channel (reuse the bans channel).
- Embeds / images — plain text lines, consistent with the rest of the bot.
- Backfill of historical deaths into the feed (only live, fresh deaths post).
- Per-weapon or per-distance leaderboards/stats (the feed is announcement-only).
