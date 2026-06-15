# CLAUDE.md

Guidance for working in this repo. Read this first; see `docs/superpowers/specs/` (design)
and `docs/superpowers/plans/` (implementation plans 1–4, the 2026-06-12 bounty feature, and the
2026-06-13 connection-announcements feature).

## What this is

A Discord/DayZ **"one life"** bot built with **Laracord** (Laravel Zero + DiscordPHP). It
polls a Nitrado-hosted Xbox DayZ server's `.ADM` admin logs, reconstructs each player's
**lives / sessions / playtime**, **bans** a player for 12h when they die, and runs an
**unban-token economy** (link a gamertag to earn/spend tokens; monthly + referral grants).

Status: Plans 1–4, the bounty / associate-detection feature, the online-players roster,
bunker-visit tracking, the births/eulogies + playtime-gated-ban feature, **and** the weekly
newspaper (The One Life Tribune, with non-fatal hit/infected-attack capture) are implemented and
tested. The bounty token economy is **live** (it is not gated by `BAN_DRY_RUN`). The online roster
is **live** (channel id configured; a single message refreshed in place every few minutes). Bunker
visits are detected from the ADM `RestrictedAreaBunkerEntrance` teleport line and surfaced on two
leaderboard boards (DB-only, not gated by `BAN_DRY_RUN`). Bans now require **≥60 min playtime**
(`BAN_MIN_PLAYTIME_MINUTES`); a new **births/eulogies** subsystem (LLM via OpenRouter, with a canned
fallback) replaces the old death feed — DB-only + channel posts, not gated by `BAN_DRY_RUN`, and
falls back to canned copy until `OPENROUTER_API_KEY` and the `BIRTHS_CHANNEL_ID` / `EULOGY_CHANNEL_ID`
channels are set. The weekly **newspaper** (Fri 22:00 UTC) is DB-read + a channel post, also not
gated by `BAN_DRY_RUN`, and stays dark until `NEWSPAPER_CHANNEL_ID` is set (LLM prose falls back to
canned copy without `OPENROUTER_API_KEY`). The one remaining real-world step is arming live
**banning** (the `BAN_DRY_RUN` cutover — see README).

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
php laracord adm:backfill-bunker-visits --since-days=14   # backfill bunker visits from ADM history (idempotent; no bans)
php laracord adm:backfill-hits --since-days=14   # backfill hit events from ADM history (no bans)
php laracord news:publish --dry-run   # preview the current week's Tribune issue in the terminal (never posts)
./vendor/bin/pest                     # run the test suite
./vendor/bin/pest tests/Feature/Foo.php   # one file
```

`.env` keys: `NITRADO_TOKEN`, `NITRADO_SERVICE_ID` (the one-life server is **18196786**),
`DISCORD_TOKEN`, `DISCORD_GUILD_ID`, `BANS_CHANNEL_ID`, `ADMIN_ROLE_ID`, `BAN_DURATION_HOURS=12`,
`BAN_DRY_RUN`, `CONNECTIONS_CHANNEL_ID`, `CONNECTIONS_REFRESH_MINUTES=5`, `CONNECTIONS_ENABLED=true`,
`ADM_BACKFILL_BUDGET=15`, plus the `BOUNTY_*` block (`BOUNTY_CHANNEL_ID`,
`BOUNTY_POSITION_RETENTION_DAYS`, and the tunables mirrored in `config/bounty.php`), plus
`LEADERBOARD_CHANNEL_ID`, `LEADERBOARD_REFRESH_MINUTES`, `LEADERBOARD_TOP_COUNT`,
`LEADERBOARD_ENABLED`, plus `BUNKER_TRACKING_ENABLED=true`, `BUNKER_VISIT_COOLDOWN_MINUTES=60`,
plus the lifecycle/LLM block: `LIFECYCLE_ENABLED=true`, `LIFE_GRACE_MINUTES=5`,
`BAN_MIN_PLAYTIME_MINUTES=60`, `LIFECYCLE_MAX_AGE_MINUTES=30`, `BIRTHS_CHANNEL_ID`,
`EULOGY_CHANNEL_ID`, `OPENROUTER_API_KEY`, `OPENROUTER_MODEL=anthropic/claude-haiku-4.5`,
`OPENROUTER_BASE_URL`, `OPENROUTER_TIMEOUT_SECONDS=20` (mirrored in `config/lifecycle.php` +
`config/llm.php`), plus the weekly-newspaper block: `NEWSPAPER_ENABLED=true`, `NEWSPAPER_CHANNEL_ID`,
`NEWSPAPER_PUBLISH_DOW=5` (ISO Mon=1..Sun=7), `NEWSPAPER_PUBLISH_HOUR_UTC=22` (Fri 22:00 UTC = 6pm
UTC-4) (mirrored in `config/newspaper.php`), plus `HIT_TRACKING_ENABLED=true` (`config/hits.php`;
reuses the same `OPENROUTER_*` block as the lifecycle feature). The retired
`DEATH_FEED_MAX_AGE_MINUTES` key is no longer used.
`.env` is git-ignored — never commit secrets.

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
  `assoc_window_days` scoring window. The four public bounty channel posts
  (placed/moved/claimed/ended) are LLM-generated via `app/Services/Llm/FlavorGenerator`
  (OpenRouter, reusing the `OPENROUTER_*` / `config/llm.php` block), with the `bounty.*`
  personality pools as the canned fallback; DMs stay canned. The `bounty.ended` neutrality rule
  (no payout hints) is enforced in BOTH the generator prompt and the pool.
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
- **Births, eulogies & playtime-gated bans** — `app/Services/Lifecycle/` + `app/Services/Llm/`
  (REPLACES the old `app/Services/DeathFeed/`, now deleted). A single **grace threshold** drives
  de-dup of spawn-reroll suicides: a life "counts" once `playtime_seconds ≥ LIFE_GRACE_MINUTES`
  (default 5) — only then is a **birth** announced and (on death) a **eulogy** posted. **Banning**
  uses a higher gate, `BAN_MIN_PLAYTIME_MINUTES` (default 60): `DeathBanService` now only bans deaths
  with ≥60 min playtime and no longer posts any feed (eulogies own the death announcement). Pieces:
  `LifeFactsBuilder` (pure: `Life` → facts incl. ages, killer/weapon/distance, associates from the
  bounty detector, prior death), `DeathLogCapturer` (pure: snapshots the raw ADM death-window into
  `lives.death_log` during ingest), `OpenRouterClient` (`Http`-facade wrapper; model
  `OPENROUTER_MODEL`, default `anthropic/claude-haiku-4.5`), `AnnouncementGenerator` (newspaper-
  columnist prompt → `{headline, body}` with `{{PLAYER}}`/`{{KILLER}}` placeholders; **falls back to
  canned `birth.*`/`eulogy.*` personality pools** on any LLM failure — so no `OPENROUTER_API_KEY`
  just means canned copy), `MentionSubstitutor` (placeholders → mention/backtick), the
  `LifecycleNotifier` trio (`Discord` posts a rich newspaper **embed**; the real `<@id>` ping rides
  a plain content line ABOVE the embed because Discord doesn't notify on mentions inside an embed),
  and `LifecycleAnnouncer` (scans due births/eulogies, gated by `go_live_at` + freshness
  `LIFECYCLE_MAX_AGE_MINUTES` + grace; idempotent via `lives.birth_announced_at` / `eulogy_posted`).
  Periodic `LifecycleAnnounceService` (60s, `config/lifecycle.php`). Births → `BIRTHS_CHANNEL_ID`,
  eulogies → `EULOGY_CHANNEL_ID`. **Births are intentionally delayed ~grace** (the cost of de-duping
  rerolls). **Not gated by `BAN_DRY_RUN`** (channel posts/DB markers are independent of real Nitrado
  bans). Ban CHANNEL posts (death/manual/extended/unbanned) are LLM-generated via
  `app/Services/Llm/FlavorGenerator` with the `ban.*` pools as fallback (DMs stay canned).
  Death-bans are **no longer channel-suppressed** — the `postsToChannel()` guard was removed, so a
  death-ban posts a consequence-framed notice to `BANS_CHANNEL_ID` (in ADDITION to the separate
  eulogy in `EULOGY_CHANNEL_ID`); a death with ≥`BAN_MIN_PLAYTIME_MINUTES` playtime thus produces
  two posts. `DiscordBanNotifier` still sends the `ban.dm.death` DM. Weapon/distance persisted on
  `lives` (`death_weapon`/`death_distance`).
- **Online-players roster** — `app/Services/Online/`: `OnlineRosterQuery` (read-only snapshot of open
  `game_sessions` → rows `{gamertag, session_seconds, life_seconds}`, longest session first),
  `OnlineRosterComposer` (pure → `{title, description}` embed payload; backticked gamertags,
  `🟢 Online — N` / `Nobody's online right now.`), `OnlineRosterNotifier` interface +
  `DiscordOnlineRosterNotifier` (post-or-edit one message; id persisted in `bot_state` as
  `online_roster_message_id`/`online_roster_channel_id`) / `NullOnlineRosterNotifier`. Periodic
  `OnlinePlayersService` (default 5m, `config/online.php`) wires query → composer → notifier.
  Mirrors the leaderboard subsystem; not gated by `BAN_DRY_RUN` (read-only). **Deliberately never
  @-mentions** (high-volume, frequently-edited message) — an intentional exception to the "public
  posts mention" rule above. `SessionDuration` (still in `app/Services/Connection/`) humanizes the
  durations. Ingestion still *records* connect/disconnect into `game_sessions` via `LifeTracker`;
  the roster just reads them — there is no longer a per-event connect/disconnect channel post.
- **Leaderboard** — `app/Services/Leaderboard/`: `LeaderboardStatsService` (seven read-only
  boards: longest life alive/all-time, most kills, longest kill streak, longest-distance kills,
  most bunker visits, quickest new-life→bunker — all computed from
  `lives`/`game_sessions`/`players`/`bunker_visits`, no kills table), `LeaderboardComposer`
  (pure → an ordered list of seven Discord-agnostic board payloads `{key,title,description}`, one per message; plain backticked gamertags, **never @-mentions**; each board carries a per-board personality line, entries in the embed description to clear the 1024-char field cap),
  `DiscordLeaderboardNotifier` / `NullLeaderboardNotifier` (post-or-edit seven embeds, ids persisted in `bot_state` as a JSON list `leaderboard_message_ids` + `leaderboard_channel_id`; atomic reflush — repost all seven in order — if any message is missing or the channel changed), and the
  `LivePlaytime` helper (`app/Services/Life/`) for open-life elapsed playtime. Periodic
  `LeaderboardService` (default 15m, `config/leaderboard.php`). Not gated by `BAN_DRY_RUN`
  (read-only). The all-time-life, kill-streak, and quickest-to-bunker boards dedupe to one entry
  per player; `countRows` takes singular/plural noun args (default `kill`/`kills`).
- **Bunker visits** — `app/Services/Bunker/BunkerVisitService.php` + `config/bunker.php`. The server's
  bunker teleports a player who logs out inside the restricted area; on reconnect the ADM logs an
  explicit `... was teleported ... Reason: Spawning in Player Restricted Area:
  RestrictedAreaBunkerEntrance` line. **Detection is that self-labeling reason string — NOT a
  coordinate/proximity check** (`AdmParser::parseBunkerEntrance`). `AdmIngestor` records each entrance
  via `BunkerVisitService::record`, which de-dupes rapid relogs inside the bunker with a per-player
  cooldown (`BUNKER_VISIT_COOLDOWN_MINUTES`, default 60) and associates the life whose
  `[started_at, ended_at)` window contains the visit (a logout doesn't end a life; correct for live
  ingest AND backfill — never `openLife()`). Visits with no containing life store `life_id = null`
  (counted in totals, excluded from the quickest board). Stored in `bunker_visits`. Two leaderboard
  boards read it (see above). Backfill historical visits with `adm:backfill-bunker-visits` (idempotent
  via the cooldown). DB-only; **not gated by `BAN_DRY_RUN`**. `config/bunker.php` defaults pinned in
  `phpunit.xml`. When `BUNKER_TRACKING_ENABLED=false`, `record()` is a no-op.
- **Hit capture** — `app/Services/Hit/HitEventService.php` + `config/hits.php`. `AdmParser::parseHit`
  parses ADM `... hit by ...` damage lines (player / infected / animal / environment, with the
  non-player source humanized via `DayzNameHumanizer`); `AdmIngestor` records each into the
  `hit_events` table (victim linked by gamertag when known — a hit alone never creates a player row;
  victim coords stored for aggregate region trends only). Backfill with `adm:backfill-hits`. DB-only;
  **not gated by `BAN_DRY_RUN`**; no-op when `HIT_TRACKING_ENABLED=false`. Powers the newspaper's
  infected-attack trends.
- **Weekly newspaper (The One Life Tribune)** — `app/Services/Newspaper/`: `WeeklyFactsBuilder`
  (location-SAFE 7-day aggregate — **never** a coordinate or a `(player, place)` pair; locations
  surface ONLY as anonymized `region => count` trends via `app/Services/Geo/ChernarusRegions`),
  `NewspaperGenerator` (ONE OpenRouter call → editorial/recap/classifieds split on `## …` delimiters,
  per-section `personality.newspaper.*` canned fallback; same anti-fabrication + location-policy system
  prompt as the eulogy generator), `NewspaperComposer` (pure → masthead + 4 section embeds incl. a
  pure-data "Week in Numbers" box, plain backticked gamertags, **never @-mentions**),
  `NewspaperNotifier` + `Discord`/`Null` (one multi-embed message, immutable back issues — no
  edit-in-place). Periodic `App\Services\NewspaperService` (hourly tick; publishes Fri 22:00 UTC,
  idempotent per ISO week via `bot_state.last_newspaper_week` + `newspaper_issue_count`, gated by
  `go_live_at`). `php laracord news:publish [--dry-run]` is a terminal **preview/renderer only** — a
  standalone artisan command has no live Discord gateway, so it never posts or stamps state; live
  posting is owned by the periodic service. Not gated by `BAN_DRY_RUN`. Reuses the `OPENROUTER_*` /
  `config/llm.php` block; defaults pinned in `phpunit.xml`.
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
