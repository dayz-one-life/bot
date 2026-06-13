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
SERVICES_RESTARTED=1
sudo systemctl restart "$UNIT"
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
