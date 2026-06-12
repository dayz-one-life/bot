# One Life Bot

A Discord/DayZ "one life" bot built with [Laracord](https://laracord.com) (Laravel + DiscordPHP).

It ingests a Nitrado-hosted Xbox DayZ server's `.ADM` admin logs and reconstructs each
player's **lives**, **play sessions**, and **playtime** from connect / disconnect / death /
server-reboot events.

> **Status — Plans 1–4 done (focused core complete).** ADM ingestion + life/playtime tracking
> (Plan 1), the **banning layer** (Plan 2), the **linking + unban-token economy** (Plan 3), and
> the **read views + admin commands** (Plan 4) are all implemented and verified. The only
> remaining real-world step is arming live banning (the `BAN_DRY_RUN` ops toggle below). See
> `docs/superpowers/specs/` and `docs/superpowers/plans/` for the design and roadmap.

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

## Tests

```bash
./vendor/bin/pest
```

(The `DEPR` markers in test output are a harmless PHP 8.5 deprecation in a vendored MySQL
config; SQLite is unaffected and all assertions pass.)
