# Spec: cheeky/random personality for public Discord messages

**Date:** 2026-06-13
**Status:** Approved (design)

## Goal

Give every public-facing Discord message a cheeky, playful voice that varies each time. Each
message type draws from a pool of ≥10 hand-written lines, picked at random (never repeating the
immediately-previous line for that type). The mechanism is reusable so any *future* public
message can opt in by adding a pool and one `pick()` call.

Tone: **cheeky & playful** — witty, irreverent, meme-y, good-natured. **No profanity, no slurs.**

## Background — current message surfaces

Three notifiers post the public channel messages (and the private DMs to affected players):

- `app/Services/Bounty/DiscordBountyNotifier.php` — `placed`, `moved`, `claimed`, `ended`
- `app/Services/Ban/DiscordBanNotifier.php` — `banned` (new + extension), `unbanned`
- `app/Services/Connection/DiscordConnectionNotifier.php` — `connected`, `disconnected`

Each builds a single hardcoded string and sends it via a best-effort `toChannel()` / `toUser()`.

### Constraints to preserve (do not regress)

- **`bounty.ended` must stay neutral** — its wording must never reveal whether a reward was paid,
  so an associate-farm pair cannot confirm a kill "worked." All `bounty.ended` lines are tame.
- **Connections channel never @-mentions** (high-volume) — lines use the backticked gamertag only.
- **Channel posts may mention; DMs and ephemeral replies must not.** Callers pass the already-
  rendered value into the token (a `PlayerMention` for channel posts, a backticked gamertag for
  DMs), so this split is preserved by the caller, not the pool.
- **Death vs manual ban** is distinguishable by `$ban->source` (`auto_death` for the one-life
  autoban, anything else for manual). Extensions are flagged by `$isExtension`.
- All sends remain **best-effort** — a missing pool, null client, or send failure never throws
  into ingestion/tick callers.

## Architecture

### `config/personality.php`

A single config file returning nested arrays of line templates, addressed by dot-key
(`personality.bounty.placed`, `personality.ban.death`, …). Templates use `:token` placeholders
that the caller fills. This mirrors the repo's existing `config/*.php` convention and keeps all
copy in one tunable, deploy-friendly place.

### `App\Services\Personality\MessagePicker`

The reusable picker. One public method:

```php
public function pick(string $key, array $tokens = [], ?string $fallback = null): string
```

- Reads the pool via `config("personality.{$key}")`.
- **Random selection with immediate-repeat avoidance:** keeps a process-wide static map of the
  last-chosen index per `$key`; the default chooser picks a random index `!=` the last one (when
  the pool has ≥2 lines), then records it. This is what makes messages "different each time."
- **Token interpolation:** `strtr($line, $tokens)` where `$tokens` keys are the literal
  placeholders, e.g. `[':target' => $mention, ':tokens' => 3]`.
- **Resilience:** if the pool is missing or empty, returns the interpolated `$fallback` when one
  was supplied (callers always supply a plain fallback ≈ the old wording), else returns `''`.
- **Testable randomness:** the constructor accepts an optional chooser closure
  `?\Closure $chooser = null` of shape `fn (array $pool, ?int $avoidIndex): int`. Tests inject a
  deterministic chooser; production uses the default (random, avoid-repeat). `array_rand` is fine
  in production (the CLAUDE.md note about `Math.random` is JS-only and irrelevant here).

The picker is constructed directly (`new MessagePicker()`) by each notifier — no container
wiring needed. Anti-repeat state is static on the class, so it is shared process-wide regardless
of how many notifier/picker instances exist.

### Notifier wiring

Each notifier replaces its hardcoded strings with `pick()` calls. The notifiers stay thin; the
only branching logic (which ban key applies) is extracted to a pure, unit-tested helper.

**`DiscordBountyNotifier`** (`$m = new PlayerMention()`):
- `placed`: channel `pick('bounty.placed', [':target' => $m->forPlayer($target)], <fallback>)`;
  if `$target->discord_user_id`, DM `pick('bounty.dm.placed', [], <fallback>)`.
- `moved`: channel `pick('bounty.moved', [':target' => $m->forPlayer($target)], …)`; if linked,
  DM `pick('bounty.dm.moved', [], …)`.
- `claimed`: channel `pick('bounty.claimed', [':killer' => $m->forPlayer($killer),
  ':target' => $m->forPlayer($target), ':tokens' => $tokens], …)`; if killer linked, DM
  `pick('bounty.dm.claimed', [':target' => $target->gamertag, ':tokens' => $tokens], …)`
  (plain gamertag in the DM — no mention).
- `ended`: channel `pick('bounty.ended', [':target' => $m->forPlayer($target)], …)`. No DM.

**`DiscordBanNotifier`** — add a pure private helper:

```php
private function bannedKey(Ban $ban, bool $isExtension): string
{
    if ($isExtension) return 'ban.extended';
    return $ban->source === 'auto_death' ? 'ban.death' : 'ban.manual';
}
```

- `banned`: `$key = $this->bannedKey($ban, $isExtension)`; channel
  `pick($key, [':who' => $m->forPlayer($player), ':reason' => $ban->reason,
  ':expires' => $expires], …)` (the `ban.death` lines simply don't reference `:reason`). DM (if
  linked): for `ban.death` → `pick('ban.dm.death', [':expires' => $expires], …)`; otherwise →
  `pick('ban.dm.manual', [':reason' => $ban->reason, ':expires' => $expires], …)`.
  `$expires` is the existing rendered string (`<t:…:f>` or `never (permanent)`).
- `unbanned`: channel `pick('ban.unbanned', [':who' => $m->forPlayer($player),
  ':reason' => $reason], …)`; DM (if linked) `pick('ban.dm.unbanned', [':reason' => $reason], …)`.

**`DiscordConnectionNotifier`** (no mentions; `$tag = "\`{$gamertag}\`"`):
- `connected`: `pick('connection.connected', [':tag' => $tag], …)`.
- `disconnected`: when `$sessionSeconds === null` →
  `pick('connection.disconnected_nodur', [':tag' => $tag], …)`; else →
  `pick('connection.disconnected', [':tag' => $tag,
  ':duration' => SessionDuration::human($sessionSeconds)], …)`.

## Message pools

All pools below are the initial content shipped in `config/personality.php`. Tokens in each
header are the only placeholders that pool may use.

### bounty.placed (`:target`)
1. 🎯 A bounty just dropped on :target — first to send them to the lobby pockets an unban token.
2. 🎯 :target has a price on their head now. One token says you can't collect it.
3. 🎯 Open season on :target! Bag 'em and grab yourself an unban token. 🪙
4. 🎯 New contract: :target. Payment: one unban token. Difficulty: their problem, not yours.
5. 🎯 Somebody put :target on the menu. Whoever serves them gets a token to go.
6. 🎯 The bounty board refreshed and :target is today's special. Reward: one unban token.
7. 🎯 :target just became the most popular person on the server, and not in a good way. Token's up for grabs.
8. 🎯 Wanted: :target. Dead. Reward: one unban token. No questions asked.
9. 🎯 There's a token with your name on it — all you have to do is find :target first.
10. 🎯 Fresh bounty on :target. Bring them down, leave with a token. Simple economics.

### bounty.moved (`:target`)
1. 🎯 Plot twist: the bounty slid over to :target for the crime of refusing to die.
2. 🎯 :target survived long enough to become the problem. Bounty's theirs now.
3. 🎯 The bounty got bored and wandered over to :target. Congrats on the attention.
4. 🎯 :target is now the longest-living target on the server — fancy way of saying "shoot them."
5. 🎯 New face on the wanted poster: :target. They lasted the longest, so they're it.
6. 🎯 The bounty has changed hands — :target outlived everyone, so now everyone wants them.
7. 🎯 Bounty relocated to :target. Outliving the competition has consequences.
8. 🎯 :target wouldn't die, so the universe made them the target instead. Seems fair.
9. 🎯 Congratulations :target, your reward for surviving is a target on your back.
10. 🎯 The crosshair drifts to :target — last one standing, first one wanted.

### bounty.claimed (`:killer`, `:target`, `:tokens`)
1. 💀 :killer collected the bounty on :target and walked off with :tokens unban token(s). Nature is healing.
2. 💀 :target got folded by :killer — that's :tokens token(s) richer.
3. 💀 GG :target — :killer claimed your bounty for :tokens token(s). Should've stayed inside.
4. 💀 :killer found :target, ended :target, and got paid :tokens token(s) for the trouble.
5. 💀 Bounty claimed! :killer sent :target to the respawn screen and cashed :tokens token(s).
6. 💀 :killer just turned :target into a payday: :tokens unban token(s). Efficient.
7. 💀 And the bounty on :target goes to… :killer! That'll be :tokens token(s).
8. 💀 :target's luck ran out the second :killer showed up. :tokens token(s), claimed.
9. 💀 :killer cashed in :target for :tokens unban token(s). Hunting season's good this year.
10. 💀 :killer wrote :target out of the story and pocketed :tokens token(s) for it.

### bounty.ended (`:target`) — NEUTRAL: never implies a payout
1. 🏳️ The bounty on :target has wrapped up. Nothing more to see here.
2. 🏳️ Contract on :target closed. The board's clear for now.
3. 🏳️ :target is off the wanted list. Carry on.
4. 🏳️ That's a wrap on :target's bounty. Stand down.
5. 🏳️ :target's name has come off the board. All quiet.
6. 🏳️ The bounty on :target is no longer active. Move along.
7. 🏳️ :target is no longer wanted. The hunt's off.
8. 🏳️ Bounty on :target: concluded. Back to your regularly scheduled survival.
9. 🏳️ The contract on :target has expired. Nothing to see here.
10. 🏳️ All clear on :target. The wanted poster comes down.

### ban.death (`:who`, `:expires`)
1. ⚰️ :who had ONE life and yeeted it into the void. Benched until :expires.
2. ⚰️ :who discovered the "one" in one-life the hard way. Back on :expires.
3. 💀 :who died as they lived: temporarily. See you :expires.
4. ⚰️ Press F for :who. They'll be back :expires, sadder and wiser.
5. 💀 :who found out the server only hands out one life. Sit tight til :expires.
6. ⚰️ :who has shuffled off this mortal server. Respawn unlocks :expires.
7. 💀 One life, one mistake — :who is done until :expires.
8. ⚰️ :who speedran the death screen. Benched until :expires.
9. 💀 RIP :who. Gone but not forgotten, back :expires.
10. ⚰️ :who learned that "one life" wasn't a suggestion. Out until :expires.

### ban.manual (`:who`, `:reason`, `:expires`)
1. 🔨 :who caught the banhammer — :reason. Out until :expires.
2. 🔨 Down goes :who — :reason. Timeout ends :expires.
3. 🔨 :who is taking an involuntary vacation (:reason). Returns :expires.
4. 🔨 The banhammer found :who. Reason: :reason · expires :expires.
5. 🔨 :who has been sent to the corner. Reason: :reason. Back :expires.
6. 🔨 :who earned themselves a timeout — :reason. Free :expires.
7. 🔨 Tough break, :who — :reason. See you :expires.
8. 🔨 :who has been escorted off the server (:reason). Returns :expires.
9. 🔨 :who pressed their luck and found the banhammer. :reason · :expires.
10. 🔨 :who is sitting this one out — :reason. Back on :expires.

### ban.extended (`:who`, `:reason`, `:expires`)
1. 🔨 :who's ban just got a remix — :reason. Now expires :expires.
2. 🔨 :who's vacation has been extended (:reason). New return date: :expires.
3. 🔨 :who really wanted more time off — ban updated, :reason, expires :expires.
4. 🔨 Bad news for :who: the timer reset. :reason · back :expires.
5. 🔨 :who unlocked bonus bench time — :reason. Now out until :expires.
6. 🔨 The clock on :who just restarted. :reason · expires :expires.
7. 🔨 :who's timeout got a sequel. :reason. Back :expires.
8. 🔨 More of a good thing for :who — ban extended, :reason, :expires.
9. 🔨 :who's stay has been lengthened (:reason). Returns :expires.
10. 🔨 :who hit the snooze on freedom — :reason. New alarm: :expires.

### ban.unbanned (`:who`, `:reason`)
1. 🕊️ :who is free! (:reason) Try to keep this one alive.
2. ✅ The gates open for :who — :reason. Welcome back, don't waste it.
3. 🕊️ Parole granted: :who. :reason. The void missed you.
4. ✅ :who is off the bench — :reason. Go make better decisions.
5. 🕊️ :who has served their time (:reason). Back in the fight.
6. ✅ Welcome back, :who — :reason. The map wasn't the same without you.
7. 🕊️ :who walks free (:reason). Second chances are a beautiful thing.
8. ✅ :who is cleared for respawn — :reason. Stay alive this time.
9. 🕊️ Freedom for :who! :reason. Try not to end up back here.
10. ✅ :who is back in business — :reason. Behave.

### connection.connected (`:tag`)
1. 🟢 :tag rolled in. The clock's ticking.
2. 🟢 :tag spawned. One life, no pressure.
3. 🟢 :tag entered the map. Place your bets.
4. 🟢 :tag is in. Try not to die immediately.
5. 🟢 :tag is online — good luck out there.
6. 🟢 Look who it is: :tag just connected.
7. 🟢 :tag has joined the struggle.
8. 🟢 :tag is live. May the odds be ever in their favor.
9. 🟢 :tag clocked in for another shot at survival.
10. 🟢 :tag loaded in. Let's see how this goes.

### connection.disconnected (`:tag`, `:duration`)
1. 🔴 :tag logged off after :duration. Lived to alt-tab another day.
2. 🔴 :tag tapped out — :duration survived. Cowardice or wisdom?
3. 🔴 :tag called it after :duration. The bush was that comfy, huh.
4. 🔴 :tag disconnected · :duration on the clock. See you next spawn.
5. 🔴 :tag is gone after :duration. Still breathing, technically.
6. 🔴 :tag survived :duration and decided that was enough heroism for today.
7. 🔴 :tag bailed after :duration. Logging off counts as a survival strategy.
8. 🔴 :tag clocked out — :duration of staying alive. Respectable.
9. 🔴 :tag dipped after :duration. The loot will be there tomorrow.
10. 🔴 :tag went dark after :duration. Smart money quits while ahead.

### connection.disconnected_nodur (`:tag`)
1. 🔴 :tag slipped out the back.
2. 🔴 :tag vanished. Bold strategy.
3. 🔴 :tag logged off. Poof.
4. 🔴 :tag is gone. No forwarding address.
5. 🔴 :tag ghosted the server.
6. 🔴 :tag has left the building.
7. 🔴 :tag disappeared into the night.
8. 🔴 :tag rage-quit, took a break, who's to say.
9. 🔴 :tag pulled the plug.
10. 🔴 :tag noped out.

### bounty.dm.placed (no tokens)
1. 🎯 Heads up — there's a bounty on you now. People will be… friendly.
2. 🎯 Congrats, you're today's target. Watch your back out there.
3. 🎯 A bounty just landed on your head. Maybe stay off the skyline.
4. 🎯 Bad news: your name's on a contract now. Trust no one.
5. 🎯 You've been marked. Every gunshot is about you now.
6. 🎯 There's a price on your head. Sleep with one eye open.
7. 🎯 You're wanted — like, actively hunted wanted. Good luck.
8. 🎯 Someone wants you gone badly enough to make it official. Watch yourself.
9. 🎯 The server just put a target on your back. Keep moving.
10. 🎯 You're the bounty now. Paranoia is a valid playstyle.

### bounty.dm.moved (no tokens)
1. 🎯 The bounty's on you now — you survived too long. Watch your back.
2. 🎯 You're the new target. The longest-living one. Lucky you.
3. 🎯 Bad news: the server thinks you've lived long enough. Bounty's yours.
4. 🎯 You outlived everyone, so now everyone wants you. Congrats?
5. 🎯 Your reward for surviving: a fresh bounty. Watch yourself.
6. 🎯 You're it. The bounty found you for the crime of staying alive.
7. 🎯 The crosshair's on you now. Surviving has a price.
8. 🎯 Heads up — you're the most wanted player on the server now.
9. 🎯 You lasted the longest, so the bounty's yours. Keep moving.
10. 🎯 The target just shifted to you. Trust nobody.

### bounty.dm.claimed (`:target`, `:tokens`)
1. 💰 You claimed the bounty on :target and banked :tokens unban token(s). Nice work.
2. 💰 :target down, :tokens token(s) up. The bounty's yours — well earned.
3. 💰 Bounty collected! :target paid out :tokens unban token(s) to you.
4. 💰 You hunted :target and got paid :tokens token(s). Clean.
5. 💰 :tokens unban token(s) richer — thanks to :target. Spend it wisely.
6. 💰 That's a bounty on :target claimed. :tokens token(s) added to your stash.
7. 💰 Nice shot. :target was worth :tokens token(s), and now they're yours.
8. 💰 You found :target first. :tokens token(s) is your reward.
9. 💰 Bounty on :target: claimed. Payout: :tokens unban token(s). GG.
10. 💰 You earned :tokens unban token(s) off :target. Hunting pays.

### ban.dm.death (`:expires`)
1. ⚰️ You died — that's the one life, gone. You're benched until :expires.
2. 💀 One life, and it's spent. You're out until :expires. Walk it off.
3. ⚰️ That's all she wrote for this life. Back in action :expires.
4. 💀 You found the "one" in one-life. See you :expires.
5. ⚰️ Game over for this run. Respawn unlocks :expires.
6. 💀 You've been sent to the bench — one life, used. Back :expires.
7. ⚰️ Rough one. Your ban lifts :expires. Use the downtime to plan.
8. 💀 You died, so you're out until :expires. Happens to the best of us.
9. ⚰️ This life's over. You're back :expires — make the next one count.
10. 💀 One and done. Benched until :expires.

### ban.dm.manual (`:reason`, `:expires`)
1. 🔨 You've been banned — :reason. Expires :expires.
2. 🔨 Timeout: :reason. You're back :expires.
3. 🔨 You caught a ban — :reason. Out until :expires.
4. 🔨 You've been benched. Reason: :reason. Back :expires.
5. 🔨 Banned: :reason. Freedom returns :expires.
6. 🔨 You're sitting this one out — :reason. Back :expires.
7. 🔨 The banhammer found you. :reason · expires :expires.
8. 🔨 You've earned a timeout: :reason. Out until :expires.
9. 🔨 No server for you for a bit — :reason. Back :expires.
10. 🔨 Take a breather — :reason. You're back :expires.

### ban.dm.unbanned (`:reason`)
1. 🕊️ Good news — your ban's been lifted (:reason). Don't waste the second chance.
2. ✅ You're unbanned (:reason). Back in the fight.
3. 🕊️ You're free (:reason). Try to keep this life longer.
4. ✅ Ban lifted: :reason. Welcome back.
5. 🕊️ The gates are open (:reason). Go make better choices.
6. ✅ You're cleared (:reason). Respawn awaits.
7. 🕊️ Parole granted (:reason). Stay alive this time.
8. ✅ You're back in business (:reason). Behave.
9. 🕊️ Freedom! :reason. Don't end up back here.
10. ✅ Your ban's over (:reason). The map missed you.

## Testing

Unit test `MessagePicker` (no DB needed; plain Pest unit tests):
- **Interpolation:** `pick('x', [':a' => 'Z'])` with an injected single-line pool replaces `:a`.
- **Returns a pool member:** with an injected chooser, the returned line is one of the pool's lines.
- **Anti-repeat default chooser:** the default chooser never returns the avoided index when the
  pool has ≥2 entries (call it many times against a 2-line pool with a fixed `avoidIndex` and
  assert it always returns the other index).
- **Empty/missing pool:** returns the interpolated `$fallback` when supplied; `''` when not.

Feature/config test:
- **Pools complete:** every key the notifiers use exists in `config('personality')` and has **≥10**
  non-empty string lines. Assert the full key list explicitly.
- **`bounty.ended` neutrality:** none of its lines contain payout-revealing words
  (case-insensitive: `token`, `reward`, `paid`, `claim`).

Pure-helper test:
- `DiscordBanNotifier::bannedKey()` — extract to a testable pure method (or a tiny standalone
  helper) and assert: `auto_death` + not-extension → `ban.death`; other source + not-extension →
  `ban.manual`; any source + extension → `ban.extended`.

Notifiers themselves are not unit-tested (no Discord gateway), per repo convention — they stay
thin wrappers. Verification of the wiring is `php -l` + the suite staying green.

## Out of scope (YAGNI)

- Live/DB-backed editing of pools or an admin `/personality` command (possible future add-on).
- Per-guild or per-channel personality variants; weighted line selection; localization.
- Touching the ephemeral `/stats` reply or other non-notifier output.
