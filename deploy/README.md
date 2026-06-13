# Deployment (systemd)

The bot runs as a long-lived foreground process (`php laracord`) supervised by systemd.

## Install / update the unit

```bash
sudo cp /opt/one-life-bot/deploy/one-life-bot.service /etc/systemd/system/one-life-bot.service
sudo systemctl daemon-reload
sudo systemctl enable one-life-bot      # start on boot
sudo systemctl start one-life-bot       # start now
```

## Operate

```bash
sudo systemctl status one-life-bot
sudo systemctl restart one-life-bot     # after editing .env
sudo systemctl stop one-life-bot
journalctl -u one-life-bot -f           # live logs
```

## Automated deploy

`deploy/deploy.sh` promotes the running bot to the latest `v*.*.*` release tag and restarts it.

```bash
/opt/one-life-bot/deploy/deploy.sh
```

It runs seven phases — preflight, fetch, build (`composer install --no-dev`), migrate
(`php laracord migrate --force`), restart, ready-check, cleanup — and **rolls back automatically** on
any failure (restores the previous tag *and* the SQLite file). Run it as `acab`; it uses `sudo` only
for `systemctl`.

- Requires a clean working tree and at least one strict semver tag on `origin` (produced by the
  `release-flow` skill). If already on the latest tag, it exits early with no changes.
- Readiness is confirmed by polling the journal (up to 60s) for the `Successfully booted` marker —
  Laracord's final startup line, emitted once the Discord gateway connects and the periodic
  Services boot (the bot has no HTTP health endpoint). The check matches the name-independent prefix
  and strips ANSI codes, so it survives a differing `APP_NAME` and decorated journal output; it also
  fails fast (with a journal tail) if the unit crash-loops during startup.
- Each deploy writes a `database/database.sqlite.pre-<tag>.bak` snapshot. After a successful deploy
  the **cleanup** phase finds the older snapshots (all but this run's) and, when run interactively,
  prompts before removing them — keeping the newest as a manual rollback safety net. Non-interactive
  runs (cron/CI) skip the prompt and leave every snapshot in place. To prune by hand:
  `rm database/database.sqlite.pre-*.bak`.
- The script calls `sudo systemctl` for the restart. Running it by hand, you'll be prompted for
  your password once. **To run it unattended (cron, CI), grant passwordless sudo for just those
  commands**, e.g. a `/etc/sudoers.d/one-life-bot` line:
  `acab ALL=(root) NOPASSWD: /usr/bin/systemctl restart one-life-bot, /usr/bin/systemctl stop one-life-bot`
  — otherwise the deploy will block on the password prompt mid-run.

## Notes

- Config lives in `/opt/one-life-bot/.env` (read via `WorkingDirectory`). After any `.env`
  change, `systemctl restart`.
- `BAN_DRY_RUN=true` is the safe default. Verify intended bans in the DB before flipping to
  `false` and restarting. See the root `README.md` "Enabling banning" section.
- `Restart=on-failure` + `StartLimitBurst=5` means a bad config (invalid token) stops the
  unit instead of spin-looping — check `journalctl` if it won't stay up.
