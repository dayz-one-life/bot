# One Life Bot

A Discord/DayZ "one life" bot built with [Laracord](https://laracord.com) (Laravel + DiscordPHP).

It ingests a Nitrado-hosted Xbox DayZ server's `.ADM` admin logs and reconstructs each
player's **lives**, **play sessions**, and **playtime** from connect / disconnect / death /
server-reboot events.

> **Status — Plan 1 (foundation).** This phase delivers ADM ingestion and life/playtime
> tracking only. **Banning is not yet implemented** (death → 12h ban, unban tokens, linking,
> and the slash commands arrive in later plans). See `docs/superpowers/specs/` and
> `docs/superpowers/plans/` for the full design and roadmap.

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
| `DISCORD_GUILD_ID` | Discord guild id (later plans) |
| `BANS_CHANNEL_ID` | Channel for ban notifications (later plans) |
| `BAN_DURATION_HOURS` | Death-ban length, default `12` (later plans) |
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

This boots the Discord connection and starts the **`IngestAdmService`** (a Laracord
`Service` that runs `AdmIngestor::tick()` every 60 seconds). Requires a valid `DISCORD_TOKEN`.

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
- `app/Services/IngestAdmService.php` — the 60s periodic Laracord service wrapping the ingestor.
- `app/Console/Commands/VerifyIngestionCommand.php` — the `adm:verify` report.

## Tests

```bash
./vendor/bin/pest
```

(The `DEPR` markers in test output are a harmless PHP 8.5 deprecation in a vendored MySQL
config; SQLite is unaffected and all assertions pass.)
