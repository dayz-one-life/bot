# Deploy Script Design — `deploy/deploy.sh`

**Date:** 2026-06-13
**Status:** Approved (brainstorming)

## Purpose

A one-command, tag-based production deploy for the one-life-bot, adapted from an external
template that targeted a Next.js + Python/uv/alembic + Docker-Postgres stack. This project is a
long-lived Discord bot (`php laracord`) supervised by systemd (`one-life-bot`), backed by a single
SQLite file. The script promotes the running deployment to the latest semver release tag, applies
migrations, restarts the unit, verifies real readiness, and **rolls back automatically** on any
failure.

## Context

- Deploy target dir: `/opt/one-life-bot` (the git repo + `WorkingDirectory` of the systemd unit).
- The script lives at `deploy/deploy.sh`, one level below the repo root, alongside
  `deploy/one-life-bot.service` and `deploy/README.md`.
- Release tags are produced by the `release-flow` skill as strict semver `v<major>.<minor>.<patch>`
  (latest at design time: `v0.1.1`). Marker tags like `plan4-complete` must be ignored.
- Run by user `acab`; `sudo` is used only for `systemctl`.
- The bot exposes **no HTTP endpoint** — readiness is inferred from the systemd journal.

## Phases (mapping from the source template)

| Source phase | This project |
|---|---|
| Preflight: docker postgres health | Clean working tree; `.env` + `database/database.sqlite` present; unit installed |
| Fetch: latest `v*.*.*` tag + checkout | Kept as-is |
| Build: `npm ci && npm run build` | `composer install --no-dev --optimize-autoloader` |
| Migrate: alembic upgrade | Backup SQLite file, then `php laracord migrate` |
| Seed: python seed modules | Dropped (no seed data) |
| Restart: systemctl + curl `/health` | `systemctl restart one-life-bot` + poll journalctl for ready marker |

### Path resolution

```bash
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"   # .../deploy
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"                     # /opt/one-life-bot
```
All git/composer/migrate operations run in `REPO_DIR`.

### Phase 1 — Preflight (`CURRENT_PHASE=preflight`)

- `cd "$REPO_DIR"`.
- Fail if the working tree is dirty (`git diff --quiet` + `git diff --staged --quiet`).
- Record rollback point: `ROLLBACK_TAG=$(git describe --tags --exact-match 2>/dev/null || git rev-parse HEAD)`.
- Assert `.env` exists and `database/database.sqlite` exists.
- Assert the unit is installed (`systemctl cat one-life-bot` succeeds), so the later restart is meaningful.

### Phase 2 — Fetch (`CURRENT_PHASE=fetch`)

- `git fetch --tags origin`.
- `LATEST_TAG` = highest strict-semver tag:
  `git tag -l 'v[0-9]*' | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -1`.
- Error out if none found.
- If `ROLLBACK_TAG == LATEST_TAG` → log "already on latest" and `exit 0`.
- `git checkout "$LATEST_TAG"` (detached HEAD at the tag — same model as the source).

### Phase 3 — Build (`CURRENT_PHASE=build`)

- `composer install --no-dev --optimize-autoloader` in `REPO_DIR`.
- Note: `--no-dev` omits Pest/dev tooling — correct for production.

### Phase 4 — Migrate (`CURRENT_PHASE=migrate`)

- Backup: `cp database/database.sqlite "database/database.sqlite.pre-${LATEST_TAG}.bak"`.
- Record the backup path in a variable (`DB_BACKUP`) so rollback can restore it.
- `php laracord migrate --force` — **`--force` is required**: the deploy box runs
  `APP_ENV=production`, so a bare `migrate` blocks on Laravel's production confirmation prompt,
  hanging the unattended script forever.

### Phase 5 — Restart + health (`CURRENT_PHASE=restart`)

- Capture `RESTART_AT="$(date '+%Y-%m-%d %H:%M:%S')"` immediately before restart (journal `--since`).
- `sudo systemctl restart one-life-bot`; set `SERVICES_RESTARTED=1`.
- Poll up to 10 attempts, 2s apart:
  ```bash
  journalctl -u one-life-bot --since="$RESTART_AT" --no-pager \
    | grep -q 'Successfully booted OneLifeBot'
  ```
  This line is emitted only after the Discord gateway connects AND all Services boot.
- On timeout → `log_error` and `exit 1` (triggers rollback).

### Phase 6 — Done

- Print elapsed seconds and the deployed tag.

## Rollback (ERR trap)

Triggered on any non-zero command after preflight. Steps:

1. Disarm the trap; `set +e`.
2. If `ROLLBACK_TAG` unset → error and exit 1 (cannot recover automatically).
3. If `SERVICES_RESTARTED == 1` → `sudo systemctl stop one-life-bot` (best-effort).
4. `cd "$REPO_DIR" && git checkout "$ROLLBACK_TAG"`.
5. **DB restore:** if `CURRENT_PHASE` is `migrate` or later AND `DB_BACKUP` is set and exists →
   `cp "$DB_BACKUP" database/database.sqlite` (atomic file restore — replaces the source's
   "review DB state manually" warning).
6. `composer install --no-dev --optimize-autoloader` to rebuild autoloader for the old tag.
7. If `SERVICES_RESTARTED == 1` → `sudo systemctl restart one-life-bot` and warn that the bot is
   running on the rolled-back tag.
8. `exit 1`.

## Preserved from the source template

- `set -euo pipefail`; colored `log_phase/info/success/warn/error` helpers.
- `CURRENT_PHASE` tracking and `trap 'rollback' ERR`.
- `ROLLBACK_TAG` / `LATEST_TAG` / `SERVICES_RESTARTED` / `START_TIME` state.
- "Already on latest → exit 0" short-circuit and the elapsed-time summary.

## Out of scope (YAGNI)

- No web/asset build, no seed step, no Docker.
- No backup pruning/rotation (the `.bak` files accumulate; a one-line note in `deploy/README.md`
  tells the operator they can delete old ones).
- No remote/SSH orchestration — the script runs on the deploy host itself.

## Testing / verification

This is an operational bash script, not application code, so it is not Pest-tested. Verification:
- `bash -n deploy/deploy.sh` (syntax) and `shellcheck` if available.
- A dry inspection of the ready-marker grep against current `journalctl -u one-life-bot` output
  (already confirmed: `Successfully booted OneLifeBot with 17 commands, 4 services, and 1 interaction.`).
- Real validation is the first live run; the rollback path is the safety net.

## Follow-up docs

Add a short "Automated deploy" section to `deploy/README.md` pointing at `deploy/deploy.sh` and the
`.bak` cleanup note.
