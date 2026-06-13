# CLAUDE.md

Guidance for working in this repo. Read this first; see `docs/superpowers/specs/` (design)
and `docs/superpowers/plans/` (implementation plans 1–4, the 2026-06-12 bounty feature, and the
2026-06-13 connection-announcements feature).

## What this is

A Discord/DayZ **"one life"** bot built with **Laracord** (Laravel Zero + DiscordPHP). It
polls a Nitrado-hosted Xbox DayZ server's `.ADM` admin logs, reconstructs each player's
**lives / sessions / playtime**, **bans** a player for 12h when they die, and runs an
**unban-token economy** (link a gamertag to earn/spend tokens; monthly + referral grants).

Status: Plans 1–4, the bounty / associate-detection feature, **and** connection announcements are
implemented, tested, and deployed (systemd `one-life-bot`). The bounty token economy is **live** (it
is not gated by `BAN_DRY_RUN`). Connection announcements are **live** (channel id configured;
posts on connect/disconnect). The one remaining real-world step is arming live **banning** (the
`BAN_DRY_RUN` cutover — see README).

## Stack & environment facts (non-obvious — don't relearn the hard way)

- **Laracord v2.3.0 on Laravel Zero**, PHP 8.2+ (developed on **8.5.7**), Composer, **SQLite**.
  PSR-4: `App\` → `app/`.
- **Periodic background work = `Laracord\Services\Service`** (NOT `Laracord\Tasks\Task`, which
  doesn't exist in this version). Auto-discovered from `app/Services/` by subclassing `Service`;
  declares `protected int $interval` (seconds) + `handle()`; has `$this->discord()` /
  `$this->console()`. The parent ctor requires a bot, so to allow no-arg test instantiation
  override: `public function __construct(?Laracord $bot = null) { if ($bot) parent::__construct($bot); }`
  (see `app/Services/BanExpiryService.php`). Plain pipeline classes under `app/Services/**`
  subdirs are ignored by discovery (they don't extend `Service`).
- **Console (artisan-style) commands** → `app/Console/Commands/`, extend
  `Laracord\Console\Commands\Command`, auto-registered (`adm:verify` is one). Separate from
  `app/Commands/` (Discord *message* commands).
- **Discord slash commands** → `app/SlashCommands/`, extend `Laracord\Commands\SlashCommand`,
  auto-discovered. They **do NOT appear in `php laracord list`** (that's artisan only) — verify
  with `php -l`, a class-load/subclass check, and the test suite. API: `$this->value('opt')` to
  read an option; user id `(string) ($interaction->member->user->id ?? $interaction->user->id)`;
  reply `$this->message($c)->reply($interaction, ephemeral: true)`; `$this->discord()` available;
  option types STRING=3 INT=4 BOOL=5 USER=6, `'autocomplete' => true`; `autocomplete(): array`
  returns `['opt' => fn (Interaction $i, $value) => <=25 choices]`.
- **PHP 8.5 `DEPR` markers in test output are harmless** (a vendored MySQL `PDO::MYSQL_ATTR_SSL_CA`
  deprecation). `config/logging.php` routes the `deprecations` channel to `null` so it can't crash
  `migrate`. Exit 0 / assertions passing = green.
- **`php laracord tinker` auto-aliases app classes** — use fully-qualified names inline (e.g.
  `new App\Services\Tokens\LinkService()`), not `use` statements, or you get "name already in use".

## Common commands

```bash
composer install
php laracord migrate                 # apply migrations (SQLite at database/database.sqlite)
php laracord                          # run the bot (needs DISCORD_TOKEN; starts the Services)
php laracord adm:verify --ticks=500 --budget=50   # backfill + life/playtime/death report (no bans)
php laracord adm:backfill-positions --since-days=14   # backfill position samples (no bans; --keep to append)
./vendor/bin/pest                     # run the test suite
./vendor/bin/pest tests/Feature/Foo.php   # one file
```

`.env` keys: `NITRADO_TOKEN`, `NITRADO_SERVICE_ID` (the one-life server is **18196786**),
`DISCORD_TOKEN`, `DISCORD_GUILD_ID`, `BANS_CHANNEL_ID`, `ADMIN_ROLE_ID`, `BAN_DURATION_HOURS=12`,
`BAN_DRY_RUN`, `CONNECTIONS_CHANNEL_ID`, `CONNECTIONS_MAX_AGE_MINUTES=10`,
`ADM_BACKFILL_BUDGET=15`, plus the `BOUNTY_*` block (`BOUNTY_CHANNEL_ID`,
`BOUNTY_POSITION_RETENTION_DAYS`, and the tunables mirrored in `config/bounty.php`). `.env` is
git-ignored — never commit secrets.

## Architecture

Logic lives in **testable plain services**; **slash commands and periodic Services are thin
wrappers** over them. This is the core convention — put business rules in a service with a
Feature test, and keep the command/Service a wiring shim.

- `app/Services/Adm/AdmParser.php` — PURE: parse connect/disconnect/death lines + reconstruct UTC
  timestamps (header date, midnight rollover, server→UTC clock offset). Unit-tested.
- `app/Services/Nitrado/NitradoClient.php` — list/download ADM files; read/add/remove the ban list
  (`settings.general.bans`). Tested with `Http::fake()`.
- `app/Services/Life/LifeTracker.php` — the connect/disconnect/death/reboot state machine →
  lives, sessions, playtime.
- `app/Services/Adm/AdmIngestor.php` — cursor-driven chronological backfill; flips `backfill`→`live`
  mode once caught up (sets `go_live_at`).
- `app/Services/Ban/` — `BanService` (ban/unban: DB + Nitrado + notify, `dryRun`), `DeathBanService`
  (post-tick reconciliation: ban lives ended after `go_live_at`), notifiers.
- `app/Services/Tokens/` — `LinkService`, `ReferrerService`, `RewardService` (+`previewGrant`),
  `RedemptionService`.
- `app/Services/{Stats,Admin}/` — read queries (`PlayerStatsService`, `ReferralQueryService`) and
  admin ops (`AdminService`, `AdminGuard`). `PlayerStatsService::statsFor()` also returns
  `current_life_sessions` (the open life's sessions, oldest-first, capped at the 12 most recent,
  each `{connected_at, duration_seconds, is_open}` with the open session's duration computed
  elapsed-so-far) plus `current_life_session_total` — populated **only when the player is alive**;
  `/stats` renders these as a "Sessions this life" block (durations via `SessionDuration::human`).
- Periodic `Service`s: `IngestAdmService` (60s: ingest + death-ban), `BanExpiryService` (60s:
  expire + reconcile), `MonthlyRewardService` (hourly: month-rollover grant + DMs).
- `app/SlashCommands/` — `/link /referrer /unban /unbans /stats /bans /referrals` + admin set;
  admin commands `use App\SlashCommands\Concerns\GuardsAdmin` and call `denyIfNotAdmin()` first.
- **Bounty system** — `app/Services/Bounty/`: `AssociateDetector` (3-signal blend: co-presence,
  proximity, kill-graph; override-aware), `BountyService` (rank/place/move/claim/status),
  `OverrideService` (force-link/unlink/clear pairs), plus `DiscordBountyNotifier` / `NullBountyNotifier`.
  Periodic: `BountyTickService` (60s). Slash commands: `/bounty` (show current target) and `/team`
  (admin override manager). Tunables in `config/bounty.php` (all env-overridable). **Note:**
  `BAN_DRY_RUN` does NOT gate bounty token awards — they are DB-only writes with no external
  side effects, so they fire even in dry-run mode. Position samples are harvested live during
  ingest AND can be backfilled across ADM history via `adm:backfill-positions`; retention is
  governed by `BOUNTY_POSITION_RETENTION_DAYS` (0 = keep forever), separate from the detector's
  `assoc_window_days` scoring window.
- **Gamertag rendering** — `app/Services/Lookup/PlayerMention` turns a linked gamertag into a
  Discord mention `<@id>` (else backticked text). **Only PUBLIC channel posts mention** — the ban /
  bounty channel announcements via the notifiers. **Ephemeral slash replies and DMs keep plain
  gamertag text** (a mention there would ping no one useful / be a self-mention). When adding
  Discord output, follow this split: `toChannel(...)` may mention; ephemeral `reply` and `toUser`
  DMs do not.
- **Message personality** — `app/Services/Personality/MessagePicker` + `config/personality.php`.
  Every public notifier message (bounty / ban+death / connection — channel posts AND the player
  DMs) is drawn from a pool of ≥10 cheeky, playful lines keyed by dot-key (e.g. `bounty.placed`,
  `ban.death`, `connection.disconnected`). `pick(key, tokens, fallback)` selects a random line,
  avoids the immediately-previous line for that key (process-wide static; `reset()` for tests),
  interpolates `:tokens`, and returns a plain `fallback` if a pool is ever empty. Randomness is an
  injectable closure so tests are deterministic. **Constraints baked in:** `bounty.ended` lines stay
  neutral (never hint at a payout — associate-farm guard, asserted by a test); connection lines
  never @-mention; DM pools use the plain gamertag. Add personality to any new public message by
  adding a pool + one `pick()` call. Ban routing (`death` / `manual` / `extended`) is the pure
  `DiscordBanNotifier::bannedKey()`.
- **Connection announcements** — `app/Services/Connection/`: `ConnectionNotifier` (interface) +
  `DiscordConnectionNotifier` / `NullConnectionNotifier`, plus the pure `SessionDuration` humanizer.
  `IngestAdmService` posts a one-line `🟢 connected` / `🔴 disconnected · on for 1h 23m` to
  `CONNECTIONS_CHANNEL_ID` for **live, fresh** events only — gated by the ingestor's `$isLive` flag
  plus a `CONNECTIONS_MAX_AGE_MINUTES` (default 10) freshness window that suppresses stale
  post-restart backlog. **Deliberately never @-mentions** (high-volume channel) — an intentional
  exception to the "public posts mention" rule above.
- **Nickname on link** — `/link` (invoker) and `/adminlink` (target user) set the member's server
  nickname to their gamertag, best-effort via `app/SlashCommands/Concerns/RenamesToGamertag`
  (swallows failures; needs the bot to have Manage Nicknames and a role above the target — and it
  can never rename the server owner).

## Key domain rules (easy to get wrong)

- **A life = first connect → death**, spanning any number of sessions/reboots. **Playtime = Σ
  session durations.** A reboot ends sessions but NOT the life. Stored timestamps are UTC.
- **Death de-duplication:** DayZ logs some deaths as multiple lines (a bare `(DEAD)` position line
  after the kill line; a `died.`+`committed suicide` pair). A death only ends an **open** life; if
  there's no open life, it's a duplicate → **ignore it** (never fabricate a zero-duration life).
- **`go_live_at` gating:** only deaths AFTER the bot first caught up to live are banned; historical
  backfill is never retro-banned. Death→ban is idempotent via `lives.ban_issued`.
- **`BAN_DRY_RUN=true`** records intended bans in the DB but makes no Nitrado/Discord writes — the
  safe cutover lever.
- **Monthly tokens:** each linked player gets +1, plus +1 per referred player who connected in the
  **previous calendar month** `[startOfMonth(prev), startOfMonth(now))`. Idempotent per month via
  `bot_state.last_reward_month`. Link grants +1 once (guarded by `link_rewarded`). Referrer is
  set-once. Redemption lifts only **temporary** bans; token deducted only after a successful unban.

## Conventions

- **TDD**: write the failing Feature/Unit test first, then implement. Feature tests use
  `RefreshDatabase` + in-memory SQLite; time-dependent tests use `CarbonImmutable::setTestNow()`.
- Use **`Carbon\CarbonImmutable::now()`** (respects `setTestNow`), never raw `new DateTime`.
- Multi-row mutations go in a `DB::transaction`. Discord/Nitrado side-effects are best-effort and
  must not throw into callers (notifiers swallow exceptions).
- Slash command tasks aren't unit-tested (no gateway) — keep them thin and cover the service.
- **Tests must not depend on the operator `.env`.** Config-default assertions are pinned via
  `phpunit.xml` `<env>` (e.g. the `BOUNTY_*` block) so live tuning of `.env` can't turn the suite
  red. If you add a tunable whose default you assert in a test, pin it there too.
- Reference branches/tags: `plan1-verified`, `plan2-complete`, `plan3-complete`, `plan4-complete`.

## References (do NOT copy — rebuilt in Laracord)

- Previous Node/Prisma bot: `../../dayzkoth/onelife-bot`. Nitrado/ADM domain reference (working
  Node code): `../koth-bot`.
