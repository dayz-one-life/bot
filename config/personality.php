<?php

return [

    'bounty' => [

        'placed' => [
            '🎯 A bounty just dropped on :target — first to send them to the lobby pockets an unban token.',
            '🎯 :target has a price on their head now. One token says you can\'t collect it.',
            '🎯 Open season on :target! Bag \'em and grab yourself an unban token. 🪙',
            '🎯 New contract: :target. Payment: one unban token. Difficulty: their problem, not yours.',
            '🎯 Somebody put :target on the menu. Whoever serves them gets a token to go.',
            '🎯 The bounty board refreshed and :target is today\'s special. Reward: one unban token.',
            '🎯 :target just became the most popular person on the server, and not in a good way. Token\'s up for grabs.',
            '🎯 Wanted: :target. Dead. Reward: one unban token. No questions asked.',
            '🎯 There\'s a token with your name on it — all you have to do is find :target first.',
            '🎯 Fresh bounty on :target. Bring them down, leave with a token. Simple economics.',
        ],

        'moved' => [
            '🎯 Plot twist: the bounty slid over to :target for the crime of refusing to die.',
            '🎯 :target survived long enough to become the problem. Bounty\'s theirs now.',
            '🎯 The bounty got bored and wandered over to :target. Congrats on the attention.',
            '🎯 :target is now the longest-living target on the server — fancy way of saying "shoot them."',
            '🎯 New face on the wanted poster: :target. They lasted the longest, so they\'re it.',
            '🎯 The bounty has changed hands — :target outlived everyone, so now everyone wants them.',
            '🎯 Bounty relocated to :target. Outliving the competition has consequences.',
            '🎯 :target wouldn\'t die, so the universe made them the target instead. Seems fair.',
            '🎯 Congratulations :target, your reward for surviving is a target on your back.',
            '🎯 The crosshair drifts to :target — last one standing, first one wanted.',
        ],

        'claimed' => [
            '💀 :killer collected the bounty on :target and walked off with :tokens unban token(s). Nature is healing.',
            '💀 :target got folded by :killer — that\'s :tokens token(s) richer.',
            '💀 GG :target — :killer claimed your bounty for :tokens token(s). Should\'ve stayed inside.',
            '💀 :killer found :target, ended :target, and got paid :tokens token(s) for the trouble.',
            '💀 Bounty claimed! :killer sent :target to the respawn screen and cashed :tokens token(s).',
            '💀 :killer just turned :target into a payday: :tokens unban token(s). Efficient.',
            '💀 And the bounty on :target goes to… :killer! That\'ll be :tokens token(s).',
            '💀 :target\'s luck ran out the second :killer showed up. :tokens token(s), claimed.',
            '💀 :killer cashed in :target for :tokens unban token(s). Hunting season\'s good this year.',
            '💀 :killer wrote :target out of the story and pocketed :tokens token(s) for it.',
        ],

        'ended' => [
            '🏳️ The bounty on :target has wrapped up. Nothing more to see here.',
            '🏳️ Contract on :target closed. The board\'s clear for now.',
            '🏳️ :target is off the wanted list. Carry on.',
            '🏳️ That\'s a wrap on :target\'s bounty. Stand down.',
            '🏳️ :target\'s name has come off the board. All quiet.',
            '🏳️ The bounty on :target is no longer active. Move along.',
            '🏳️ :target is no longer wanted. The hunt\'s off.',
            '🏳️ Bounty on :target: concluded. Back to your regularly scheduled survival.',
            '🏳️ The contract on :target has expired. Nothing to see here.',
            '🏳️ All clear on :target. The wanted poster comes down.',
        ],

        'dm' => [

            'placed' => [
                '🎯 Heads up — there\'s a bounty on you now. People will be… friendly.',
                '🎯 Congrats, you\'re today\'s target. Watch your back out there.',
                '🎯 A bounty just landed on your head. Maybe stay off the skyline.',
                '🎯 Bad news: your name\'s on a contract now. Trust no one.',
                '🎯 You\'ve been marked. Every gunshot is about you now.',
                '🎯 There\'s a price on your head. Sleep with one eye open.',
                '🎯 You\'re wanted — like, actively hunted wanted. Good luck.',
                '🎯 Someone wants you gone badly enough to make it official. Watch yourself.',
                '🎯 The server just put a target on your back. Keep moving.',
                '🎯 You\'re the bounty now. Paranoia is a valid playstyle.',
            ],

            'moved' => [
                '🎯 The bounty\'s on you now — you survived too long. Watch your back.',
                '🎯 You\'re the new target. The longest-living one. Lucky you.',
                '🎯 Bad news: the server thinks you\'ve lived long enough. Bounty\'s yours.',
                '🎯 You outlived everyone, so now everyone wants you. Congrats?',
                '🎯 Your reward for surviving: a fresh bounty. Watch yourself.',
                '🎯 You\'re it. The bounty found you for the crime of staying alive.',
                '🎯 The crosshair\'s on you now. Surviving has a price.',
                '🎯 Heads up — you\'re the most wanted player on the server now.',
                '🎯 You lasted the longest, so the bounty\'s yours. Keep moving.',
                '🎯 The target just shifted to you. Trust nobody.',
            ],

            'claimed' => [
                '💰 You claimed the bounty on :target and banked :tokens unban token(s). Nice work.',
                '💰 :target down, :tokens token(s) up. The bounty\'s yours — well earned.',
                '💰 Bounty collected! :target paid out :tokens unban token(s) to you.',
                '💰 You hunted :target and got paid :tokens token(s). Clean.',
                '💰 :tokens unban token(s) richer — thanks to :target. Spend it wisely.',
                '💰 That\'s a bounty on :target claimed. :tokens token(s) added to your stash.',
                '💰 Nice shot. :target was worth :tokens token(s), and now they\'re yours.',
                '💰 You found :target first. :tokens token(s) is your reward.',
                '💰 Bounty on :target: claimed. Payout: :tokens unban token(s). GG.',
                '💰 You earned :tokens unban token(s) off :target. Hunting pays.',
            ],
        ],
    ],

    'ban' => [

        // NOTE: this 'ban.death' pool is NO LONGER used for channel posts — the lifecycle
        // eulogy feed (LifecycleAnnouncer, via eulogy.* pools) owns the public death
        // announcement (DiscordBanNotifier::postsToChannel suppresses 'ban.death'). The
        // banned player still gets the separate 'ban.dm.death' DM below. Retained as a
        // reference/fallback; do not re-wire the ban notifier to use it.
        'death' => [
            '⚰️ :who had ONE life and yeeted it into the void. Benched until :expires.',
            '⚰️ :who discovered the "one" in one-life the hard way. Back on :expires.',
            '💀 :who died as they lived: temporarily. See you :expires.',
            '⚰️ Press F for :who. They\'ll be back :expires, sadder and wiser.',
            '💀 :who found out the server only hands out one life. Sit tight til :expires.',
            '⚰️ :who has shuffled off this mortal server. Respawn unlocks :expires.',
            '💀 One life, one mistake — :who is done until :expires.',
            '⚰️ :who speedran the death screen. Benched until :expires.',
            '💀 RIP :who. Gone but not forgotten, back :expires.',
            '⚰️ :who learned that "one life" wasn\'t a suggestion. Out until :expires.',
        ],

        'manual' => [
            '🔨 :who caught the banhammer — :reason. Out until :expires.',
            '🔨 Down goes :who — :reason. Timeout ends :expires.',
            '🔨 :who is taking an involuntary vacation (:reason). Returns :expires.',
            '🔨 The banhammer found :who. Reason: :reason · expires :expires.',
            '🔨 :who has been sent to the corner. Reason: :reason. Back :expires.',
            '🔨 :who earned themselves a timeout — :reason. Free :expires.',
            '🔨 Tough break, :who — :reason. See you :expires.',
            '🔨 :who has been escorted off the server (:reason). Returns :expires.',
            '🔨 :who pressed their luck and found the banhammer. :reason · :expires.',
            '🔨 :who is sitting this one out — :reason. Back on :expires.',
        ],

        'extended' => [
            '🔨 :who\'s ban just got a remix — :reason. Now expires :expires.',
            '🔨 :who\'s vacation has been extended (:reason). New return date: :expires.',
            '🔨 :who really wanted more time off — ban updated, :reason, expires :expires.',
            '🔨 Bad news for :who: the timer reset. :reason · back :expires.',
            '🔨 :who unlocked bonus bench time — :reason. Now out until :expires.',
            '🔨 The clock on :who just restarted. :reason · expires :expires.',
            '🔨 :who\'s timeout got a sequel. :reason. Back :expires.',
            '🔨 More of a good thing for :who — ban extended, :reason, :expires.',
            '🔨 :who\'s stay has been lengthened (:reason). Returns :expires.',
            '🔨 :who hit the snooze on freedom — :reason. New alarm: :expires.',
        ],

        'unbanned' => [
            '🕊️ :who is free! (:reason) Try to keep this one alive.',
            '✅ The gates open for :who — :reason. Welcome back, don\'t waste it.',
            '🕊️ Parole granted: :who. :reason. The void missed you.',
            '✅ :who is off the bench — :reason. Go make better decisions.',
            '🕊️ :who has served their time (:reason). Back in the fight.',
            '✅ Welcome back, :who — :reason. The map wasn\'t the same without you.',
            '🕊️ :who walks free (:reason). Second chances are a beautiful thing.',
            '✅ :who is cleared for respawn — :reason. Stay alive this time.',
            '🕊️ Freedom for :who! :reason. Try not to end up back here.',
            '✅ :who is back in business — :reason. Behave.',
        ],

        'dm' => [

            'death' => [
                '⚰️ You died — that\'s the one life, gone. You\'re benched until :expires.',
                '💀 One life, and it\'s spent. You\'re out until :expires. Walk it off.',
                '⚰️ That\'s all she wrote for this life. Back in action :expires.',
                '💀 You found the "one" in one-life. See you :expires.',
                '⚰️ Game over for this run. Respawn unlocks :expires.',
                '💀 You\'ve been sent to the bench — one life, used. Back :expires.',
                '⚰️ Rough one. Your ban lifts :expires. Use the downtime to plan.',
                '💀 You died, so you\'re out until :expires. Happens to the best of us.',
                '⚰️ This life\'s over. You\'re back :expires — make the next one count.',
                '💀 One and done. Benched until :expires.',
            ],

            'manual' => [
                '🔨 You\'ve been banned — :reason. Expires :expires.',
                '🔨 Timeout: :reason. You\'re back :expires.',
                '🔨 You caught a ban — :reason. Out until :expires.',
                '🔨 You\'ve been benched. Reason: :reason. Back :expires.',
                '🔨 Banned: :reason. Freedom returns :expires.',
                '🔨 You\'re sitting this one out — :reason. Back :expires.',
                '🔨 The banhammer found you. :reason · expires :expires.',
                '🔨 You\'ve earned a timeout: :reason. Out until :expires.',
                '🔨 No server for you for a bit — :reason. Back :expires.',
                '🔨 Take a breather — :reason. You\'re back :expires.',
            ],

            'unbanned' => [
                '🕊️ Good news — your ban\'s been lifted (:reason). Don\'t waste the second chance.',
                '✅ You\'re unbanned (:reason). Back in the fight.',
                '🕊️ You\'re free (:reason). Try to keep this life longer.',
                '✅ Ban lifted: :reason. Welcome back.',
                '🕊️ The gates are open (:reason). Go make better choices.',
                '✅ You\'re cleared (:reason). Respawn awaits.',
                '🕊️ Parole granted (:reason). Stay alive this time.',
                '✅ You\'re back in business (:reason). Behave.',
                '🕊️ Freedom! :reason. Don\'t end up back here.',
                '✅ Your ban\'s over (:reason). The map missed you.',
            ],
        ],
    ],

    'connection' => [

        'connected' => [
            '🟢 :tag rolled in. The clock\'s ticking.',
            '🟢 :tag spawned. One life, no pressure.',
            '🟢 :tag entered the map. Place your bets.',
            '🟢 :tag is in. Try not to die immediately.',
            '🟢 :tag is online — good luck out there.',
            '🟢 Look who it is: :tag just connected.',
            '🟢 :tag has joined the struggle.',
            '🟢 :tag is live. May the odds be ever in their favor.',
            '🟢 :tag clocked in for another shot at survival.',
            '🟢 :tag loaded in. Let\'s see how this goes.',
        ],

        'disconnected' => [
            '🔴 :tag logged off after :duration. Lived to alt-tab another day.',
            '🔴 :tag tapped out — :duration survived. Cowardice or wisdom?',
            '🔴 :tag called it after :duration. The bush was that comfy, huh.',
            '🔴 :tag disconnected · :duration on the clock. See you next spawn.',
            '🔴 :tag is gone after :duration. Still breathing, technically.',
            '🔴 :tag survived :duration and decided that was enough heroism for today.',
            '🔴 :tag bailed after :duration. Logging off counts as a survival strategy.',
            '🔴 :tag clocked out — :duration of staying alive. Respectable.',
            '🔴 :tag dipped after :duration. The loot will be there tomorrow.',
            '🔴 :tag went dark after :duration. Smart money quits while ahead.',
        ],

        'disconnected_nodur' => [
            '🔴 :tag slipped out the back.',
            '🔴 :tag vanished. Bold strategy.',
            '🔴 :tag logged off. Poof.',
            '🔴 :tag is gone. No forwarding address.',
            '🔴 :tag ghosted the server.',
            '🔴 :tag has left the building.',
            '🔴 :tag disappeared into the night.',
            '🔴 :tag rage-quit, took a break, who\'s to say.',
            '🔴 :tag pulled the plug.',
            '🔴 :tag noped out.',
        ],
    ],

    'leaderboard' => [

        'intro' => [
            '🏆 The standings, freshly tallied. Climb or cope.',
            '🏆 Who\'s winning the one life? Scroll down and find out.',
            '🏆 Updated leaderboards — bragging rights are temporary, screenshots are forever.',
            '🏆 The numbers don\'t lie, even when you wish they would.',
            '🏆 Fresh rankings off the server. Somebody\'s mad about their spot right now.',
            '🏆 Current standings. Remember: every name here is one bullet from a reshuffle.',
            '🏆 The board just refreshed. Hope you like where you landed.',
            '🏆 Leaderboards updated. Glory to the top, condolences to everyone else.',
            '🏆 Here\'s who\'s actually good and who just talks a big game.',
            '🏆 The latest tally. Survive longer, shoot straighter, climb higher.',
        ],

    ],

    'birth' => [
        'fallback' => [
            ['headline' => '👶 A NEW SOUL STUMBLES ONTO THE COAST', 'body' => "📰 *Chernarus, today* — {{PLAYER}} has clawed their way back into the land of the living. The locals are unimpressed. The bears are hungry. Welcome to the one life, again."],
            ['headline' => '🎉 IT LIVES! {{PLAYER}} RESPAWNS', 'body' => "📰 Against all odds and most of their better judgment, {{PLAYER}} draws breath once more on the coast. Bookmakers are already taking bets on the cause of death."],
            ['headline' => '🌅 FRESH MEAT REPORTS FOR DUTY', 'body' => "📰 A brand-new {{PLAYER}} blinks awake on the shoreline with nothing but a flashlight and unearned confidence. History suggests this ends poorly."],
            ['headline' => '🧍 ANOTHER OPTIMIST ENTERS THE WORLD', 'body' => "📰 {{PLAYER}} has spawned. The coast welcomes them with damp socks and the distant sound of gunfire. Good luck out there — you'll need it."],
            ['headline' => '🐣 THE CYCLE CONTINUES', 'body' => "📰 {{PLAYER}} is alive. For now. Survivors are advised to introduce themselves quickly, before the introduction becomes an obituary."],
        ],
    ],

    'eulogy' => [
        'pvp' => [
            ['headline' => '💀 {{PLAYER}} DROPPED — {{KILLER}} DECLINES COMMENT', 'body' => "📰 *Obituary* — {{PLAYER}} met their end at the hands of {{KILLER}}. A life of promise, ended with grim efficiency. They are survived by their loot, which {{KILLER}} now owns."],
            ['headline' => '⚰️ THE LATE {{PLAYER}}: A LIFE, INTERRUPTED', 'body' => "📰 {{KILLER}} has ended the storied run of {{PLAYER}}. Witnesses report it was over quickly. Funeral arrangements are pending; the body is currently being looted."],
            ['headline' => '🪦 {{PLAYER}} LOGS OFF PERMANENTLY', 'body' => "📰 In a development surprising no one, {{PLAYER}} has died — courtesy of {{KILLER}}. The coast observes a moment of silence, then resumes shooting."],
        ],
        'suicide' => [
            ['headline' => '💀 {{PLAYER}} BEATS THE QUEUE, TAKES OWN LIFE', 'body' => "📰 *Obituary* — {{PLAYER}} has died by their own hand, cutting out the middleman entirely. Efficient. Bleak. On brand for Chernarus."],
            ['headline' => '⚰️ {{PLAYER}} DECIDED THE LOBBY LOOKED NICER', 'body' => "📰 No killer required. {{PLAYER}} handled their own departure. The community is not so much mourning as quietly nodding."],
            ['headline' => '🪦 {{PLAYER}} OPTS OUT', 'body' => "📰 {{PLAYER}} has self-deleted from the living. We hardly knew ye, and apparently neither did ye."],
        ],
        'environment' => [
            ['headline' => '💀 {{PLAYER}} VS. THE MAP: THE MAP WINS', 'body' => "📰 *Obituary* — {{PLAYER}} was claimed not by a player but by Chernarus itself. No killcam. No glory. Just the quiet indignity of the great outdoors."],
            ['headline' => '🐻 NATURE 1, {{PLAYER}} 0', 'body' => "📰 {{PLAYER}} lost a disagreement with the environment. The environment was unavailable for comment, being a fall, a wolf, or simple bad luck."],
            ['headline' => '🪦 {{PLAYER}} UNDONE BY SCENERY', 'body' => "📰 In an ending devoid of witnesses, {{PLAYER}} was filed under 'deceased' by the world at large. A humble exit for a humble survivor."],
        ],
        'misc' => [
            ['headline' => '💀 {{PLAYER}} HAS DIED', 'body' => "📰 *Obituary* — the run of {{PLAYER}} has come to its end. The precise circumstances are murky, but the result is permanent. Rest easy, survivor."],
            ['headline' => '⚰️ THE BOOK CLOSES ON {{PLAYER}}', 'body' => "📰 {{PLAYER}} is no longer with us. Cause uncertain, outcome final. The coast moves on, as it always does."],
            ['headline' => '🪦 {{PLAYER}}, GONE TOO SOON (OR NOT SOON ENOUGH)', 'body' => "📰 {{PLAYER}} has shuffled off the Chernarus coil. We raise a warm, expired can of beans in their memory."],
        ],
    ],
];
