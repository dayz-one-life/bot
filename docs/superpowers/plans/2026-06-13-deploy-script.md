# Deploy Script Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a one-command, tag-based production deploy script (`deploy/deploy.sh`) that promotes the bot to the latest semver release, migrates SQLite (with backup), restarts the systemd unit, verifies readiness via the journal, and auto-rolls back on failure.

**Architecture:** A single self-contained bash script adapted from an external Next.js/Python template. Six phases (preflight → fetch → build → migrate → restart → done) guarded by `set -euo pipefail` and a `trap 'rollback' ERR` that restores both the code tag and the SQLite file. No application code changes — this is operational tooling alongside the existing `deploy/` unit file.

**Tech Stack:** Bash, git tags (`v*.*.*` from the `release-flow` skill), Composer, `php laracord migrate`, systemd (`one-life-bot`), `journalctl`.

**Spec:** `docs/superpowers/specs/2026-06-13-deploy-script-design.md`

---

### Task 1: The deploy script

**Files:**
- Create: `deploy/deploy.sh`

- [ ] **Step 1: Write the complete script**

Write `deploy/deploy.sh` with exactly this content:

```bash
#!/usr/bin/env bash
set -euo pipefail

# ─── Paths ───────────────────────────────────────────────────────────────────
# This script lives in deploy/; the repo root is one level up.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
UNIT="one-life-bot"
DB_PATH="database/database.sqlite"

# ─── Output helpers ──────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BOLD_WHITE='\033[1;37m'
RESET='\033[0m'

log_phase()   { echo -e "\n${BOLD_WHITE}━━━  $1  ━━━${RESET}"; }
log_info()    { echo -e "  $1"; }
log_success() { echo -e "  ${GREEN}✓ $1${RESET}"; }
log_warn()    { echo -e "  ${YELLOW}! $1${RESET}"; }
log_error()   { echo -e "  ${RED}✗ $1${RESET}" >&2; }

# ─── State ───────────────────────────────────────────────────────────────────
SERVICES_RESTARTED=0
CURRENT_PHASE="init"
START_TIME=$(date +%s)
ROLLBACK_TAG=""
LATEST_TAG=""
DB_BACKUP=""

# ─── Rollback ────────────────────────────────────────────────────────────────
rollback() {
  trap - ERR
  set +e
  log_error ""
  log_error "Deploy failed in phase: $CURRENT_PHASE"
  log_error "Rolling back to $ROLLBACK_TAG ..."

  if [[ -z "$ROLLBACK_TAG" ]]; then
    log_error "ROLLBACK_TAG is unset — cannot roll back automatically"
    exit 1
  fi

  if [[ "$SERVICES_RESTARTED" == "1" ]]; then
    sudo systemctl stop "$UNIT" || true
  fi

  cd "$REPO_DIR"
  git checkout "$ROLLBACK_TAG"

  # Restore the SQLite file if we had reached the migrate phase (DB_BACKUP set).
  if [[ -n "$DB_BACKUP" && -f "$DB_BACKUP" ]]; then
    log_warn "Restoring database from $DB_BACKUP"
    cp "$DB_BACKUP" "$DB_PATH"
  fi

  log_info "Rebuilding $ROLLBACK_TAG ..."
  composer install --no-dev --optimize-autoloader --no-interaction

  if [[ "$SERVICES_RESTARTED" == "1" ]]; then
    sudo systemctl restart "$UNIT"
    log_warn "Service restarted on rollback tag $ROLLBACK_TAG"
  fi

  exit 1
}

trap 'rollback' ERR

# ─── Phase 1: Preflight ──────────────────────────────────────────────────────
CURRENT_PHASE="preflight"
log_phase "PREFLIGHT"

cd "$REPO_DIR"

if ! git diff --quiet || ! git diff --staged --quiet; then
  log_error "Working tree is dirty — stash or commit changes before deploying."
  exit 1
fi

ROLLBACK_TAG=$(git describe --tags --exact-match 2>/dev/null || git rev-parse HEAD)
log_info "Rollback point recorded: $ROLLBACK_TAG"

if [[ ! -f .env ]]; then
  log_error ".env not found in $REPO_DIR"
  exit 1
fi
if [[ ! -f "$DB_PATH" ]]; then
  log_error "$DB_PATH not found — has the bot been migrated on this host?"
  exit 1
fi
if ! systemctl cat "$UNIT" >/dev/null 2>&1; then
  log_error "systemd unit '$UNIT' is not installed — see deploy/README.md"
  exit 1
fi
log_success "Preflight checks passed"

# ─── Phase 2: Fetch ──────────────────────────────────────────────────────────
CURRENT_PHASE="fetch"
log_phase "FETCH"

git fetch --tags origin

# Match ONLY strict semver tags — ignore marker tags like plan4-complete.
LATEST_TAG=$(git tag -l 'v[0-9]*' | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -1 || true)

if [[ -z "$LATEST_TAG" ]]; then
  log_error "No semver release tags (vMAJOR.MINOR.PATCH) found."
  exit 1
fi

log_info "Latest release: $LATEST_TAG"
log_info "Deploying $ROLLBACK_TAG → $LATEST_TAG"

if [[ "$ROLLBACK_TAG" == "$LATEST_TAG" ]]; then
  log_success "Already on $LATEST_TAG — nothing to deploy."
  exit 0
fi

git checkout "$LATEST_TAG"
log_success "Checked out $LATEST_TAG"

# ─── Phase 3: Build ──────────────────────────────────────────────────────────
CURRENT_PHASE="build"
log_phase "BUILD"

composer install --no-dev --optimize-autoloader --no-interaction
log_success "Composer dependencies installed"

# ─── Phase 4: Migrate ────────────────────────────────────────────────────────
CURRENT_PHASE="migrate"
log_phase "MIGRATE"

# SQLite is a single file — snapshot it so rollback can restore exactly.
DB_BACKUP="${DB_PATH}.pre-${LATEST_TAG}.bak"
cp "$DB_PATH" "$DB_BACKUP"
log_success "Database backed up to $DB_BACKUP"

# --force is REQUIRED: the box runs APP_ENV=production, so a bare migrate would
# block on Laravel's production confirmation prompt and hang the script.
php laracord migrate --force
log_success "Migrations applied"

# ─── Phase 5: Restart ────────────────────────────────────────────────────────
CURRENT_PHASE="restart"
log_phase "RESTART"

# Timestamp BEFORE restart so the journal --since window only sees this boot.
RESTART_AT="$(date '+%Y-%m-%d %H:%M:%S')"
sudo systemctl restart "$UNIT"
SERVICES_RESTARTED=1
log_info "Service restarted, waiting for ready marker ..."

# The bot has no HTTP endpoint. "Successfully booted OneLifeBot" is the final
# startup line — emitted only after the Discord gateway connects AND all
# periodic Services boot.
health_check() {
  for i in $(seq 1 10); do
    log_info "Health check attempt $i/10 ..."
    if journalctl -u "$UNIT" --since="$RESTART_AT" --no-pager 2>/dev/null \
        | grep -q 'Successfully booted OneLifeBot'; then
      return 0
    fi
    sleep 2
  done
  return 1
}

health_check || { log_error "Bot did not report ready within 20s"; exit 1; }
log_success "Bot is ready"

# ─── Phase 6: Done ───────────────────────────────────────────────────────────
ELAPSED=$(( $(date +%s) - START_TIME ))
log_phase "DONE"
echo -e "\n  ${GREEN}Deployed $LATEST_TAG in ${ELAPSED}s${RESET}\n"
```

- [ ] **Step 2: Verify syntax**

Run: `bash -n deploy/deploy.sh`
Expected: no output, exit 0.

- [ ] **Step 3: Lint (if shellcheck is installed)**

Run: `command -v shellcheck >/dev/null && shellcheck deploy/deploy.sh || echo "shellcheck not installed — skipping"`
Expected: either no findings, or "shellcheck not installed — skipping". `SC2034` on a color var is acceptable; fix any error-level (red) findings.

- [ ] **Step 4: Make it executable**

Run: `chmod +x deploy/deploy.sh`

- [ ] **Step 5: Confirm the ready marker matches live output**

Run: `journalctl -u one-life-bot --no-pager 2>/dev/null | grep -c 'Successfully booted OneLifeBot'`
Expected: a number ≥ 1 (proves the grep target exists in the real journal). If it prints `0`, the bot may not have booted recently — restart it first (`sudo systemctl restart one-life-bot`) and re-check before trusting the health phase.

- [ ] **Step 6: Commit**

```bash
git add deploy/deploy.sh
git commit -m "feat: add tag-based deploy script with auto-rollback"
```

---

### Task 2: Document the script in the deploy README

**Files:**
- Modify: `deploy/README.md`

- [ ] **Step 1: Add an "Automated deploy" section**

Append the following Markdown to `deploy/README.md` (after the existing "Operate" section, before
"Notes"). The outer fence below is four backticks only so the nested code blocks render — copy the
inner content, not the four-backtick wrapper:

````markdown
## Automated deploy

`deploy/deploy.sh` promotes the running bot to the latest `v*.*.*` release tag and restarts it.

```bash
/opt/one-life-bot/deploy/deploy.sh
```

It runs six phases — preflight, fetch, build (`composer install --no-dev`), migrate
(`php laracord migrate --force`), restart, ready-check — and **rolls back automatically** on any
failure (restores the previous tag *and* the SQLite file). Run it as `acab`; it uses `sudo` only
for `systemctl`.

- Requires a clean working tree and at least one strict semver tag on `origin` (produced by the
  `release-flow` skill). If already on the latest tag, it exits early with no changes.
- Readiness is confirmed by polling the journal for `Successfully booted OneLifeBot` (the bot has
  no HTTP health endpoint).
- Each deploy writes a `database/database.sqlite.pre-<tag>.bak` snapshot. These accumulate — delete
  old ones once you're confident in the release: `rm database/database.sqlite.pre-*.bak`.
````

- [ ] **Step 2: Commit**

```bash
git add deploy/README.md
git commit -m "docs: document automated deploy script in deploy/README"
```

---

## Self-Review

**Spec coverage:**
- Path resolution (`SCRIPT_DIR`/`REPO_DIR`) → Task 1 Paths block. ✓
- Preflight (clean tree, `.env`, sqlite, unit installed) → Task 1 Phase 1. ✓
- Fetch (strict semver, early-exit, checkout) → Task 1 Phase 2. ✓
- Build (`composer install --no-dev --optimize-autoloader`) → Task 1 Phase 3. ✓
- Migrate (backup then `migrate --force`) → Task 1 Phase 4. ✓
- Restart + journalctl ready marker → Task 1 Phase 5. ✓
- Rollback (code + DB restore + rebuild + restart) → Task 1 rollback fn. ✓
- README follow-up + `.bak` cleanup note → Task 2. ✓
- Seed/web build dropped → not present. ✓

**Placeholder scan:** No TBD/TODO; all code is concrete and complete. ✓

**Type/name consistency:** `UNIT`, `DB_PATH`, `DB_BACKUP`, `ROLLBACK_TAG`, `LATEST_TAG`,
`SERVICES_RESTARTED`, `CURRENT_PHASE`, `RESTART_AT` are declared in the State block (or first
assignment) and used consistently. The rollback function only reads state vars that are declared up
front (`DB_BACKUP=""` etc.), so it parses and behaves correctly even if a failure happens before
the migrate phase sets `DB_BACKUP`. ✓
```
