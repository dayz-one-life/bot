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

        // NOTE: this 'death' pool is NO LONGER used for channel posts — the death feed
        // (death.* pools, via DiscordDeathFeedNotifier) owns the public death+ban
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

    'death' => [

        'pvp' => [
            '💀 :killer dropped :victim with a :weapon at :distancem. One life, well spent — back :expires.',
            '💀 :killer reached out and touched :victim — :weapon, :distancem. See you :expires.',
            '💀 :victim caught a :weapon from :killer at :distancem. That\'s the whole life gone, back :expires.',
            '💀 :killer sent :victim to the lobby with a :weapon from :distancem out. Respawn unlocks :expires.',
            '💀 :distancem was close enough for :killer\'s :weapon. RIP :victim — back :expires.',
            '💀 :killer folded :victim at :distancem with a :weapon. Back in action :expires.',
            '💀 :victim ate a :weapon round from :killer (:distancem). One and done — back :expires.',
            '💀 Clean work by :killer: :victim down at :distancem with a :weapon. Out for now — back :expires.',
            '💀 :killer\'s :weapon found :victim across :distancem. That\'s all she wrote — back :expires.',
            '💀 :victim got beamed by :killer at :distancem (:weapon). Back :expires.',
        ],

        'pvp_noweapon' => [
            '💀 :killer put :victim in the dirt. That\'s the one life — back :expires.',
            '💀 :victim got sent to respawn by :killer. Back in action :expires.',
            '💀 :killer ended :victim\'s run. See you :expires.',
            '💀 :victim caught hands from :killer and lost. Benched — back :expires.',
            '💀 :killer dropped :victim. One life, gone — back :expires.',
            '💀 :victim\'s story ends here, courtesy of :killer. Respawn unlocks :expires.',
            '💀 :killer collected :victim. That\'s a wrap — back :expires.',
            '💀 :victim got got by :killer. Out for now — back :expires.',
            '💀 :killer sent :victim packing. Returns :expires.',
            '💀 RIP :victim — :killer said no. Back :expires.',
        ],

        'suicide' => [
            '💀 :victim rage-quit life itself. One life, self-served — back :expires.',
            '💀 :victim took the express route to respawn. Back in action :expires.',
            '💀 :victim decided the lobby looked nicer. Benched — back :expires.',
            '💀 :victim pressed the big red button on their own run. Back :expires.',
            '💀 :victim called it on their own terms. See you :expires.',
            '💀 :victim speedran the death screen, solo. Respawn unlocks :expires.',
            '💀 :victim opted out the hard way. Returns :expires.',
            '💀 No killer needed — :victim handled it. Out for now — back :expires.',
            '💀 :victim showed themselves the door. Back :expires.',
            '💀 :victim ended their own run. One life, used — back :expires.',
        ],

        'environment' => [
            '💀 The map itself claimed :victim. One life, gone — back :expires.',
            '💀 :victim lost a fight with the great outdoors. Back in action :expires.',
            '💀 Mother Nature 1, :victim 0. Benched — back :expires.',
            '💀 :victim got got by the world, no players required. Back :expires.',
            '💀 The environment filed :victim under "deceased." Respawn unlocks :expires.',
            '💀 :victim found out the hard way that the map fights back. See you :expires.',
            '💀 :victim was undone by Chernarus itself. Returns :expires.',
            '💀 Something out there ended :victim. Out for now — back :expires.',
            '💀 :victim met an unfriendly piece of scenery. Back :expires.',
            '💀 No killcam for :victim — the world did it. Back on the menu :expires.',
        ],

        'misc' => [
            '💀 :victim :cause and lost their one life. Back :expires.',
            '💀 :victim :cause — that\'s the run. Back in action :expires.',
            '💀 :victim :cause. One life, spent — back :expires.',
            '💀 Cause of death for :victim: :cause. Respawn unlocks :expires.',
            '💀 :victim :cause and that was that. See you :expires.',
            '💀 :victim :cause. Returns :expires.',
            '💀 Turns out :victim :cause. Back :expires.',
            '💀 :victim :cause — no take-backs. Out for now — back :expires.',
            '💀 :victim :cause. The one life giveth, the one life taketh. Back :expires.',
            '💀 :victim :cause. Back on the menu :expires.',
        ],
    ],
];
