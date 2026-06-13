---
name: release-flow
description: Use when a PR has been merged into develop and you need to promote it to main, or when a PR has been merged into main and you need to cut a release. Manual replacement for the GitHub Actions promote-to-main and release workflows.
---

# Release Flow

Manual replacement for a GitHub Actions release pipeline.
Two phases: **promote** (develop → main PR) and **release** (tag + GitHub release).

## Prerequisites

`gh` CLI must be authenticated: `gh auth status`

On the deploy server, `gh` may need to be installed:
```bash
sudo apt-get install gh && gh auth login
```

## Phase 1 — After a PR merges into `develop`: Promote to Main

Run this once per develop merge. It guards against duplicates.

```bash
# From the repo root (any branch is fine)
existing=$(gh pr list --base main --head develop --state open --json number --jq '.[0].number')
if [ -n "$existing" ]; then
  echo "PR #$existing already open — open it to review: gh pr view $existing --web"
else
  gh pr create \
    --base main \
    --head develop \
    --title "chore: promote develop to main" \
    --body "Automated promotion from develop to main."
  echo "PR created. Merge it when ready, then run Phase 2."
fi
```

## Phase 2 — After the develop→main PR merges: Create Release

Run this once main is updated. Auto-bumps the patch version.

```bash
# Sync main first
git fetch origin main
git checkout main
git pull origin main

# Compute next tag (patch bump: v0.1.0 → v0.1.1)
# Match ONLY strict semver tags (vMAJOR.MINOR.PATCH) — ignore marker tags
# like plan4-complete, which would otherwise corrupt the bump.
latest=$(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -1)
if [ -z "$latest" ]; then
  next="v0.1.0"
else
  base="${latest#v}"
  major=$(echo "$base" | cut -d. -f1)
  minor=$(echo "$base" | cut -d. -f2)
  patch=$(echo "$base" | cut -d. -f3)
  next="v${major}.${minor}.$((patch + 1))"
fi

echo "Tagging $next..."
git tag "$next"
git push origin "$next"
gh release create "$next" --title "Release $next" --generate-notes
echo "Done: $next released."
```

## Quick Reference

| Situation | Action |
|---|---|
| Merged PR → develop | Run Phase 1 |
| Merged develop → main PR | Run Phase 2 |
| Need minor/major bump | Tag manually: `git tag v1.0.0 && git push origin v1.0.0`, then run Phase 2 (next auto-bump will increment from there) |
| Phase 1 says PR exists | Review and merge the existing PR, don't create another |

## Common Mistakes

- **Tagging before pulling** — always `git pull origin main` first or you tag stale HEAD
- **Skipping the dedup check** — Phase 1 will fail silently if you run `gh pr create` without checking; the guard handles this
- **Manual version choice** — stick to auto-bump unless intentionally doing a minor/major; manually pushed tags become the new baseline automatically
