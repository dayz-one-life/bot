<?php

namespace App\SlashCommands;

use App\Services\Stats\ReferralQueryService;
use Laracord\Commands\SlashCommand;

class ReferralsCommand extends SlashCommand
{
    /**
     * The command name.
     *
     * @var string
     */
    protected $name = 'referrals';

    /**
     * The command description.
     *
     * @var string|null
     */
    protected $description = 'Show the players you referred and how many were active last month.';

    /**
     * Handle the slash command.
     *
     * @param  \Discord\Parts\Interactions\Interaction  $interaction
     * @return mixed
     */
    public function handle($interaction): void
    {
        $discordId = (string) ($interaction->member->user->id ?? $interaction->user->id);
        $r = (new ReferralQueryService())->forDiscordUser($discordId);
        if (! $r['linked']) {
            $this->message('⚠️ Link your gamertag first with `/link`.')->reply($interaction, ephemeral: true);

            return;
        }
        if (empty($r['referrals'])) {
            $this->message('You haven\'t referred anyone yet. Share your gamertag so new players can set you as their referrer!')->reply($interaction, ephemeral: true);

            return;
        }
        $lines = array_map(fn ($x) => ($x['active'] ? '🟢' : '⚪️')." {$x['gamertag']}", $r['referrals']);
        $this->message(
            "**Your referrals** ({$r['activeCount']} active last month → +{$r['activeCount']} next grant):\n".implode("\n", $lines)
        )->reply($interaction, ephemeral: true);
    }
}
