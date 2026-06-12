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

## Notes

- Config lives in `/opt/one-life-bot/.env` (read via `WorkingDirectory`). After any `.env`
  change, `systemctl restart`.
- `BAN_DRY_RUN=true` is the safe default. Verify intended bans in the DB before flipping to
  `false` and restarting. See the root `README.md` "Enabling banning" section.
- `Restart=on-failure` + `StartLimitBurst=5` means a bad config (invalid token) stops the
  unit instead of spin-looping — check `journalctl` if it won't stay up.
