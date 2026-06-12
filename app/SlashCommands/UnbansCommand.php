<?php

namespace App\SlashCommands;

use App\Models\Player;
use Laracord\Commands\SlashCommand;

class UnbansCommand extends SlashCommand
{
    /**
     * The command name.
     *
     * @var string
     */
    protected $name = 'unbans';

    /**
     * The command description.
     *
     * @var string|null
     */
    protected $description = 'Show how many unban tokens you have.';

    /**
     * Handle the slash command.
     *
     * @param  \Discord\Parts\Interactions\Interaction  $interaction
     * @return mixed
     */
    public function handle($interaction): void
    {
        $discordId = (string) ($interaction->member->user->id ?? $interaction->user->id);
        $player = Player::where('discord_user_id', $discordId)->first();
        $msg = $player
            ? "🎟️ You have **{$player->unban_tokens}** unban token(s)."
            : '⚠️ Link your gamertag first with `/link`.';
        $this->message($msg)->reply($interaction, ephemeral: true);
    }
}
