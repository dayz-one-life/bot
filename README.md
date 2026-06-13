# One Life Bot

A Discord/DayZ "one life" bot built with [Laracord](https://laracord.com) (Laravel + DiscordPHP).

It ingests a Nitrado-hosted Xbox DayZ server's `.ADM` admin logs and reconstructs each
player's **lives**, **play sessions**, and **playtime** from connect / disconnect / death /
server-reboot events.

> **Status — core + bounty + connection announcements live; deployed.** ADM ingestion +
> life/playtime tracking (Plan 1), the **banning layer** (Plan 2), the **linking + unban-token
> economy** (Plan 3), and the **read views + admin commands** (Plan 4) are implemented and verified,
> as are the **bounty / associate-detection** feature and the **connection-announcements** channel.
> The bot runs as the systemd service `one-life-bot`. The only remaining real-world step is arming
> live banning (the `BAN_DRY_RUN` ops toggle below). See `docs/superpowers/specs/` and
> `docs/superpowers/plans/` for the design and roadmap.

## Requirements

- PHP 8.2+ (developed against 8.5)
- Composer
- SQLite

## Setup

```bash
composer install
cp .env.example .env          # then fill in the values below
touch database/database.sqlite
php laracord migrate
```

Configure `.env`:

| Variable | Purpose |
| --- | --- |
| `NITRADO_TOKEN` | Nitrado API bearer token for the server |
| `NITRADO_SERVICE_ID` | Nitrado gameserver service id |
| `DISCORD_TOKEN` | Discord bot token (only needed to run the live bot) |
| `DISCORD_GUILD_ID` | Discord guild id (slash commands) |
| `ADMIN_ROLE_ID` | Discord role id permitted to run admin commands (fail-closed if unset) |
| `BANS_CHANNEL_ID` | Channel for ban/unban notifications |
| `BAN_DURATION_HOURS` | Death-ban length, default `12` |
| `BAN_DRY_RUN` | When `true`, record intended bans in the DB but make **no** Nitrado/Discord writes |
| `ADM_BACKFILL_BUDGET` | Max older ADM files drained per ingestion tick, default `15` |
| `CONNECTIONS_CHANNEL_ID` | Channel for player connect/disconnect announcements (unset = feature off) |
| `CONNECTIONS_MAX_AGE_MINUTES` | Suppress connect/disconnect events older than this many minutes (stale-backlog guard), default `10` |

## Verify ingestion against real data (the Plan 1 milestone)

Before trusting the life/playtime reconstruction, run a full backfill (no banning) over the
real ADM history and inspect the result:

```bash
php laracord migrate:fresh
php laracord adm:verify --ticks=500 --budget=50
```

This processes all `.ADM` files oldest-first and prints player / life / session counts,
total playtime, deaths-by-cause, and the top players by playtime. Spot-check a player with:

```bash
php laracord tinker
>>> App\Models\Player::where('gamertag', '<tag>')->first()->lives;
```

## Running the bot

```bash
php laracord
```

This boots the Discord connection and starts the periodic services: **`IngestAdmService`**
(runs `AdmIngestor::tick()` + the death-ban pass every 60s) and **`BanExpiryService`** (lifts
expired bans and reconciles the Nitrado ban list every 60s). Requires a valid `DISCORD_TOKEN`.

## Enabling banning (safe go-live cutover)

Banning only ever applies to deaths that occur **after** the bot first catches up to live
(`go_live_at`); historical deaths in the backlog are never retro-banned. To turn it on safely:

1. Set `DISCORD_TOKEN`, `BANS_CHANNEL_ID`, `BAN_DURATION_HOURS=12`, and **`BAN_DRY_RUN=true`**.
2. Run the bot (`php laracord`) and let it catch up. In dry-run, a real death creates a `bans`
   row and logs the intended ban, but makes **no** Nitrado ban and **no** Discord post — nobody
   is actually kicked. Inspect:
   ```bash
   sqlite3 database/database.sqlite \
     "select b.banned_at,b.expires_at,b.source,p.gamertag from bans b join players p on p.id=b.player_id order by b.banned_at desc limit 10;"
   ```
   Confirm only real post-go-live deaths appear.
3. When satisfied, set **`BAN_DRY_RUN=false`** and restart. Now a death adds the gamertag to
   Nitrado's `settings.general.bans`, posts to the bans channel, and is auto-removed after 12h
   by `BanExpiryService`. Verify the live ban list any time:
   ```bash
   php laracord tinker
   >>> (new App\Services\Nitrado\NitradoClient(env('NITRADO_TOKEN'), (int) env('NITRADO_SERVICE_ID')))->getBans();
   ```

## How it works

- `app/Services/Adm/AdmParser.php` — pure parser: connect/disconnect/death lines + UTC
  timestamp reconstruction (header date, midnight rollover, server→UTC clock offset).
- `app/Services/Nitrado/NitradoClient.php` — lists and downloads `.ADM` files via the
  Nitrado API.
- `app/Services/Life/LifeTracker.php` — the state machine: connect/disconnect/death/reboot →
  lives, sessions, and accrued playtime. A **life** runs from first connect to death; a
  **reboot** ends sessions but not lives; **playtime** is the sum of session durations.
- `app/Services/Adm/AdmIngestor.php` — drives chronological backfill across files using
  per-file cursors; flips from `backfill` to `live` mode once caught up.
- `app/Services/Ban/BanService.php` — bans/unbans a player: writes the `bans` table, applies
  to Nitrado's `general.bans`, notifies Discord; honors `BAN_DRY_RUN`.
- `app/Services/Ban/DeathBanService.php` — after each tick, bans players whose lives ended
  after `go_live_at` and aren't yet banned (idempotent via `lives.ban_issued`).
- `app/Services/BanExpiryService.php` — 60s service: lifts expired bans, reconciles Nitrado.
- `app/Services/IngestAdmService.php` — 60s service wrapping the ingestor + death-ban pass.
- `app/Services/Connection/{ConnectionNotifier,DiscordConnectionNotifier,NullConnectionNotifier,SessionDuration}.php`
  — posts live connect/disconnect lines to `CONNECTIONS_CHANNEL_ID` (no mentions; live + freshness
  gated). Wired into `IngestAdmService`; `SessionDuration` formats the session length on disconnect.
- `app/Services/Tokens/{LinkService,ReferrerService,RewardService,RedemptionService}.php` — the
  unban-token economy: link a gamertag (+1 token), set a referrer, monthly grants (+1 base
  +1/active referral), and spend a token to lift a temporary ban.
- `app/Services/MonthlyRewardService.php` — hourly service; runs the monthly grant on month
  rollover (idempotent) and DMs recipients.
- `app/SlashCommands/{Link,Referrer,Unban,Unbans}Command.php` — the player slash commands.
- `app/Console/Commands/VerifyIngestionCommand.php` — the `adm:verify` report.

## Player commands

- `/link gamertag [referrer]` — link your Discord to a gamertag (autocomplete); first link
  grants **1 unban token**. Optionally name who referred you.
- `/referrer gamertag` — set your referrer later (only if unset; locked once set).
- `/unban [player]` — spend a token to lift a **temporary** ban for yourself or another player
  (autocomplete lists currently temp-banned gamertags). Honors `BAN_DRY_RUN`.
- `/unbans` — show your unban-token balance.
- `/stats gamertag` — a player's lives, current life length, total playtime, deaths, and status.
- `/bans [player]` — ban status + history for a player (yours by default).
- `/referrals` — the players you referred and how many were active last month.

Monthly (on the 1st), every linked player receives **+1** token plus **+1** for each referred
player who connected during the previous month.

## Admin commands

Gated by `ADMIN_ROLE_ID` (a member must hold that role; fail-closed if unset):

- `/adminban gamertag [hours] [reason]` — manually ban (omit `hours` for the default 12h; `0` =
  permanent).
- `/adminunban gamertag` — manually lift a ban.
- `/adminlink user gamertag` — force-link a Discord user to a gamertag.
- `/adminunlink user` — unlink a Discord user.
- `/addunban gamertag amount` — grant (or remove, with a negative amount) unban tokens.
- `/distribute-unbans [confirm]` — preview the monthly grant; run with `confirm: true` to apply
  it (idempotent — the grant also fires automatically each month).

## Bounty

The bounty system places a live target on the player carrying the longest active playtime. Any
non-associate player who kills the bounty holder earns **+1 unban token**; associates earn nothing.

- The bounty follows the top-playtime, recently-active eligible life and moves automatically when a
  challenger leads by more than `BOUNTY_MOVE_MARGIN_MIN` minutes (default 5).
- **Associates** are detected automatically using a weighted blend of co-presence, proximity, and
  shared kill-graph signals, evaluated over a rolling `BOUNTY_ASSOC_WINDOW_DAYS` window (default 14
  days). Pairs that fight each other are never flagged as associates.
- `/bounty` — show the current bounty target and how far the runner-up trails.
- `/team action gamertag [gamertag2]` — admin command (requires `ADMIN_ROLE_ID`):
  - `link` — force two gamertags to be treated as associates (suppresses bounty payout).
  - `unlink` — force two gamertags to be treated as NOT associates.
  - `clear` — remove the override and return to the algorithm.
  - `show` — display a gamertag's current detected associates and their scores.

Key `config/bounty.php` env vars:

| Variable | Purpose | Default |
| --- | --- | --- |
| `BOUNTY_MIN_PLAYTIME_HOURS` | Minimum live playtime (hours) to be eligible | 2 |
| `BOUNTY_ACTIVITY_WINDOW_HOURS` | Must have been seen within this window (hours) | 48 |
| `BOUNTY_MOVE_MARGIN_MIN` | Lead (minutes) required to displace the current holder | 5 |
| `BOUNTY_ASSOC_WINDOW_DAYS` | Look-back window for association signals | 14 |
| `BOUNTY_ASSOC_RADIUS_M` | Proximity radius (metres) for co-location scoring | 150 |
| `BOUNTY_ASSOC_THRESHOLD` | Minimum blended score to flag a pair | 0.45 |
| `BOUNTY_ASSOC_WEIGHT_PROX` / `_COPRES` / `_KILLG` | Signal weights (sum to 1) | 0.55 / 0.35 / 0.10 |
| `BOUNTY_SYNC_WINDOW_MIN` | Connect/disconnect synchrony window (minutes) | 3 |
| `BOUNTY_CHANNEL_ID` | Announcements channel (falls back to `BANS_CHANNEL_ID`) | — |
| `BOUNTY_TOKEN_REWARD` | Tokens awarded per clean claim | 1 |
| `BOUNTY_POSITION_RETENTION_DAYS` | How long to keep position rows (days); 0 = forever | 0 |

Token awards from bounty kills are DB-only writes and fire even when `BAN_DRY_RUN=true`.

Run `php laracord adm:backfill-positions` to seed historical position data (e.g. so association detection works immediately rather than accumulating over ~14 days). `--since-days=N` limits scope; `--keep` appends instead of truncating.

## Connection announcements

When `CONNECTIONS_CHANNEL_ID` is set, the bot posts a one-line message to that channel each time a
player connects or disconnects:

```text
🟢 `Gamertag` connected
🔴 `Gamertag` disconnected · on for 1h 23m
```

These posts **never @-mention** linked Discord users — it's a high-volume channel, so gamertags stay
plain text (an intentional exception to the bot's usual mention-in-public-channels rule). The bot
needs **View Channel + Send Messages** on the channel.

Only **live** events are announced. Two guards keep it quiet:

- **No backfill replay** — historical events processed while the ingestor is catching up are never
  posted; announcing starts only once it flips to `live` mode.
- **Freshness window** — after downtime, a restart suppresses any event older than
  `CONNECTIONS_MAX_AGE_MINUTES` (default `10`), so the channel doesn't get a burst of hours-old
  lines.

Leave `CONNECTIONS_CHANNEL_ID` unset to disable the feature entirely (the notifier safely no-ops).

## Tests

```bash
./vendor/bin/pest
```

(The `DEPR` markers in test output are a harmless PHP 8.5 deprecation in a vendored MySQL
config; SQLite is unaffected and all assertions pass.)
