# Bounty Feature — Design

**Date:** 2026-06-12
**Stack:** Laracord (Laravel Zero + DiscordPHP), SQLite. Builds on the implemented Plans 1–4 core.

## Overview

A **bounty** rides on the player whose current life has accumulated the most playtime. Kill
that player and you earn an **unban token** — *unless* you're one of their associates, in which
case the kill earns nothing. The catch is detecting associates: the bot must infer which players
are *likely playing as a team* from behavioral signals, since DayZ has no formal party system.

The feature has two subsystems:

1. **`AssociateDetector`** — a pure, Feature-tested service that scores how likely any two
   players are teammates, blending proximity, co-presence/synchrony, and kill-graph signals over
   a rolling window, with an admin-maintained override list.
2. **The bounty engine** — ranks recently-active open lives by playtime, places/moves/removes the
   single active bounty, and on a qualifying PvP kill grants a token unless the killer is an
   associate. Runs as a post-tick reconciliation, mirroring `DeathBanService`.

The build order is **algorithm first**: parser+positions → `AssociateDetector` (tested in
isolation) → `BountyService` → commands & notifier.

## Design decisions (resolved during brainstorming)

- **Rank by accumulated playtime**, not wall-clock life length: `lives.playtime_seconds` plus the
  elapsed time of the current open session.
- **Eligibility = recently active**, NOT currently online: a candidate's `last_seen_at` must be
  within `BOUNTY_ACTIVITY_WINDOW_HOURS` (default 48h). The bounty stays put while the holder is
  offline, and is dropped only when the holder goes stale (>48h inactive), is overtaken, or dies.
- **Detection signals:** proximity (strong) + co-presence/login-synchrony (medium) + kill-graph
  (weak modifier), over a rolling recent window (default 14 days).
- **Error bias:** balanced score threshold **plus an admin override list** that can force a pair to
  be associates or force them not to be. The algorithm is never the final word.
- **Surfacing:** channel announcements (placed / moved / claimed / inactive), `/bounty` command,
  DM the new target, DM the token claimer (silent on associate-denied claims).

## Data model & ingestion

### Parser changes (`AdmParser`)
- Add `parsePosition(string $raw): ?array` for periodic position lines
  (`… Player "Name" (id=… pos=<x, y, z>)`), returning `gamertag`, `id`, `x`, `y` (z ignored —
  proximity is horizontal-plane).
- Also capture any `pos=<…>` present on connect/death lines.
- **RISK / first task:** confirm the exact position-line format against a real `.ADM` dump before
  building on it. If positions are absent or rare, the proximity weight `wP` effectively drops to 0
  and detection degrades gracefully to co-presence + kill-graph (a deliberate property of the
  weighted-score approach).

### New tables
- **`player_positions`** — `id, player_id, x (float), y (float), recorded_at`. Index on
  `recorded_at`. **Pruned** to the rolling window on each tick (raw positions are low-volume on a
  small server; not retained indefinitely).
- **`associate_overrides`** — `player_a_id, player_b_id, force (bool)`. `force=true` ⇒ always
  associates; `force=false` ⇒ never associates. Stored with `player_a_id < player_b_id` so lookups
  are symmetric. Unique on the pair.
- **`player_associations`** — derived cache: `player_a_id, player_b_id, score (float), computed_at`.
  Refreshed off the hot path; the live tick reads cache + overrides.
- **`bounties`** — `id, player_id, life_id, placed_at, ended_at, end_reason, claimed_by_player_id,
  token_awarded (bool)`. At most one row with `ended_at IS NULL` (the active bounty). `end_reason`
  ∈ `moved | claimed | claimed_by_associate | died | inactive`.

Rationale: store **raw positions** rather than pre-aggregating pairwise proximity at ingest, so the
detector recomputes association from raw samples over whatever window/radius config dictates — no
data migration needed to re-tune. Ingestion stays a dumb writer of raw facts (as `AdmIngestor`
already is); services derive meaning.

## The AssociateDetector algorithm

Pure service. Interface:

```
AssociateDetector:
  score(Player $a, Player $b): float        // 0.0–1.0 blended association
  areAssociates(Player $a, Player $b): bool // override-aware threshold check
  associatesOf(Player $a): Collection       // all players clearing the bar
```

All computations look only at data within the last `BOUNTY_ASSOC_WINDOW_DAYS` (default 14).

### Sub-scores (each normalized 0–1)

1. **Proximity `prox`** — bucket positions into time slices (e.g. 5-min bins matching log cadence).
   Over bins where *both* players have a sample, `prox = co_located_bins / shared_bins`, where
   co-located means within `BOUNTY_ASSOC_RADIUS_M` (default 150m). Strong signal.

2. **Co-presence / synchrony `copres`** — average of:
   - **Overlap:** Jaccard of online time = `overlap_seconds / union_online_seconds`, from
     `game_sessions` intervals.
   - **Sync:** fraction of A's connect/disconnect events with a B event within
     `BOUNTY_SYNC_WINDOW_MIN` (default 3 min).

3. **Kill-graph `killg`** — sparse confidence *modifier*, not a standalone signal:
   - **Non-aggression bump:** high `copres`/`prox` with zero mutual kills → small positive.
   - **Shared-victims bump:** distinct players both killed in the window, normalized.
   - A **confirmed mutual kill** reduces the bump (weighted, not absolute — friendly fire happens).

### Blend & threshold

```
score = wP·prox + wC·copres + wK·killg            (weights sum to 1)
defaults: wP=0.55, wC=0.35, wK=0.10
areAssociates = override === true  ? true
              : override === false ? false
              : score >= BOUNTY_ASSOC_THRESHOLD    (default 0.45)
```

**Caching:** `associatesOf` is hit every bounty tick, so the detector writes the
`player_associations` cache, refreshed on a slower cadence (hourly / every N ticks). Raw recompute
is O(pairs × samples) but runs off the hot path; the live tick reads cache + overrides.

Defaults for `R`, threshold, and weights are starting guesses tunable live via config — they
cannot be calibrated without real server data.

## The bounty engine

Pure `BountyService`; the periodic `Service` is a thin shim that runs **after** ADM ingest, exactly
like `DeathBanService`. It reads reconstructed life facts (`death_cause`, `death_by_gamertag` set by
`LifeTracker::death()`) — it never re-parses logs.

### Ranking — `currentLeader()`
- Candidate = open life (`ended_at IS NULL`) whose player is **recently active**
  (`last_seen_at` within `BOUNTY_ACTIVITY_WINDOW_HOURS`) and whose **live-playtime** ≥
  `BOUNTY_MIN_PLAYTIME_HOURS` floor (default 2h).
- **live-playtime** = `playtime_seconds` + (currently online ? `now − open_session.connected_at` : 0).
- Leader = max live-playtime; tie → earliest life start.

### Tick — `run()` (resolution BEFORE place/move)

1. **Resolve an ended bounty.** If the active bounty's life now has `ended_at`:
   - `death_cause !== 'pvp'` → close `end_reason='died'`, no token.
   - `death_cause === 'pvp'`, killer = `death_by_gamertag`:
     - killer = victim / unparseable → close, no token.
     - `areAssociates(bounty, killer)` → close `end_reason='claimed_by_associate'`, **no token**.
     - else → close `end_reason='claimed'`, `claimed_by_player_id=killer`, **+`BOUNTY_TOKEN_REWARD`
       `unban_tokens`** to killer, guarded by `token_awarded` (pays exactly once, like `ban_issued`).
2. **Place / move.** Recompute `currentLeader()`:
   - No active bounty + valid leader → **place**.
   - Holder stale (inactive >window) → close `end_reason='inactive'`, place on new leader.
   - Challenger's live-playtime exceeds holder's by ≥ `BOUNTY_MOVE_MARGIN_MIN` (default 5 min,
     anti-flap) → close `end_reason='moved'`, open new.
   - Holder still leads → no change.

**Interaction with autoban:** a bounty holder killed by a non-associate dies like any player and is
12h-autobanned by `DeathBanService` from the same death — *and* their killer earns a token. Both
firing from one death is intended.

**Safety:** multi-row work in `DB::transaction`; notifications best-effort, never throw into the
caller. Only external write is the idempotent token increment. Like `DeathBanService`, the engine
acts only after `go_live_at` — never retro-places or retro-claims on backfilled history.

## Commands & notifications

- **`/bounty`** (read-only, thin over `BountyService`): current target, live-playtime,
  time-since-life-start, runner-up gap. Auto-discovered like `/stats`.
- **`/team`** (admin, `use App\SlashCommands\Concerns\GuardsAdmin`, `denyIfNotAdmin()` first):
  - `link <gt> <gt>` → override `force=true`.
  - `unlink <gt> <gt>` → override `force=false`.
  - `clear <gt> <gt>` → remove override (back to algorithm).
  - `show <gt>` → that player's overrides + algorithm-detected associates with scores (calibration).
- **`BountyNotifier`** (best-effort, swallows exceptions):
  - **Channel** (`BOUNTY_CHANNEL_ID`, falls back to `BANS_CHANNEL_ID`): `placed`, `moved`,
    `claimed` (names killer + token), `inactive`. Associate-denied claims post a neutral
    "bounty ended" with no reward named, so a duo can't confirm a farm worked.
  - **DM new target** (if linked): "A bounty is on you."
  - **DM claimer** (if linked): "You earned an unban token." Silent on associate-denied.

## Config (`config/bounty.php`, `.env` overrides)

| Key | Default | Meaning |
|---|---|---|
| `BOUNTY_ACTIVITY_WINDOW_HOURS` | 48 | recently-active eligibility |
| `BOUNTY_MIN_PLAYTIME_HOURS` | 2 | floor to qualify as a target |
| `BOUNTY_MOVE_MARGIN_MIN` | 5 | anti-flap hysteresis on overtake |
| `BOUNTY_ASSOC_WINDOW_DAYS` | 14 | rolling detection window |
| `BOUNTY_ASSOC_RADIUS_M` | 150 | proximity radius (metres) |
| `BOUNTY_ASSOC_THRESHOLD` | 0.45 | score → associate |
| `BOUNTY_ASSOC_WEIGHT_PROX` | 0.55 | wP |
| `BOUNTY_ASSOC_WEIGHT_COPRES` | 0.35 | wC |
| `BOUNTY_ASSOC_WEIGHT_KILLG` | 0.10 | wK |
| `BOUNTY_SYNC_WINDOW_MIN` | 3 | connect/disconnect synchrony |
| `BOUNTY_CHANNEL_ID` | (falls back to bans) | announcements |
| `BOUNTY_TOKEN_REWARD` | 1 | tokens per clean claim |

## Edge cases

- Bounty player **suicides / drowns / bleeds out** → `died`, no token, re-place.
- Killer = **self** or unparseable → no token.
- Killer **unlinked** → token accrues on their player row; usable once they link.
- **Duplicate death lines** (DayZ logs some kills twice) → bounty closed on first; `token_awarded`
  blocks a second payout.
- **Backfill / pre-go-live deaths** → engine acts only after `go_live_at`; never retro-place/claim.
- **Simultaneous overtake + death** on one tick → resolution runs before place/move, so a claim
  always wins over a move.
- **No eligible candidates** → no active bounty; `/bounty` reports "none."
- **Position data absent** → `wP` contributes 0; detection runs on co-presence + kill-graph.
- **Bounty holder goes stale** while offline → dropped `end_reason='inactive'`, bounty moves on.

## Testing (TDD)

Feature tests use `RefreshDatabase` + in-memory SQLite; time-dependent tests use
`CarbonImmutable::setTestNow()`.

- `AdmParser::parsePosition` — unit tests against real ADM sample lines.
- `AssociateDetector` — per-sub-score fixtures (overlap=1.0, prox=1.0, sync fraction), blend,
  threshold, and override short-circuit (force true/false).
- `BountyService` — place / move-by-overtake / move-by-staleness / claim-clean /
  claim-by-associate / non-pvp-removal / duplicate-death idempotency / unlinked-killer /
  pre-go-live gating / simultaneous-overtake-and-death ordering.
- Notifier and slash commands stay thin and uncovered by gateway tests, per convention.

## Build order

1. Parser position extraction + migrations (`player_positions`, `associate_overrides`,
   `player_associations`, `bounties`) + ingest of position samples.
2. `AssociateDetector` — fully tested in isolation.
3. `BountyService` + the post-tick reconciliation `Service`.
4. `/bounty`, `/team`, `BountyNotifier`.
