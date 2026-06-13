<?php

namespace App\SlashCommands;

use App\Services\Bounty\AssociateDetector;
use App\Services\Bounty\BountyService;
use App\Services\Bounty\NullBountyNotifier;
use App\Services\State\BotState;
use Laracord\Commands\SlashCommand;

class BountyCommand extends SlashCommand
{
    /**
     * The command name.
     *
     * @var string
     */
    protected $name = 'bounty';

    /**
     * The command description.
     *
     * @var string|null
     */
    protected $description = 'Show the current bounty target.';

    /**
     * Handle the slash command.
     *
     * @param  \Discord\Parts\Interactions\Interaction  $interaction
     * @return mixed
     */
    public function handle($interaction): void
    {
        $svc = new BountyService(new AssociateDetector(), new BotState(), new NullBountyNotifier(), (int) config('bounty.token_reward'));
        $s = $svc->status();

        if (! ($s['active'] ?? false)) {
            $this->message('🎯 No active bounty right now.')->reply($interaction, ephemeral: true);

            return;
        }

        $hours = round($s['playtime_seconds'] / 3600, 1);
        $gap = $s['runner_up_gap_seconds'] !== null
            ? round($s['runner_up_gap_seconds'] / 3600, 1).'h ahead of the runner-up'
            : 'no runner-up';

        $this->message(
            "🎯 **Bounty:** `{$s['gamertag']}`\n"
            ."• Live playtime: {$hours}h\n"
            ."• {$gap}\n"
            .'Kill them (and don\'t be on their team) to earn an unban token.'
        )->reply($interaction, ephemeral: true);
    }
}
