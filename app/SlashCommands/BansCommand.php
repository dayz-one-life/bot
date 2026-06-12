<?php

namespace App\SlashCommands;

use App\Models\Ban;
use App\Models\Player;
use App\Services\Lookup\GamertagLookup;
use Carbon\CarbonImmutable;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class BansCommand extends SlashCommand
{
    /**
     * The command name.
     *
     * @var string
     */
    protected $name = 'bans';

    /**
     * The command description.
     *
     * @var string|null
     */
    protected $description = 'Show ban status and recent history for a player (yours by default).';

    /**
     * The command options.
     *
     * @var array
     */
    protected $options = [
        ['name' => 'player', 'description' => 'Gamertag (defaults to you)', 'type' => 3, 'required' => false, 'autocomplete' => true],
    ];

    /**
     * Handle the slash command.
     *
     * @param  \Discord\Parts\Interactions\Interaction  $interaction
     * @return mixed
     */
    public function handle($interaction): void
    {
        $tag = $this->value('player');
        $discordId = (string) ($interaction->member->user->id ?? $interaction->user->id);
        $player = $tag
            ? Player::where('gamertag', (string) $tag)->first()
            : Player::where('discord_user_id', $discordId)->first();

        if (! $player) {
            $this->message($tag ? '⚠️ No player found with that gamertag.' : '⚠️ Link your gamertag first with `/link`.')
                ->reply($interaction, ephemeral: true);

            return;
        }

        $now = CarbonImmutable::now();
        $active = Ban::where('player_id', $player->id)->where('expired', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->latest('banned_at')->first();
        $total = Ban::where('player_id', $player->id)->count();

        if ($active) {
            $when = $active->expires_at ? "expires <t:{$active->expires_at->timestamp}:R>" : 'permanent';
            $msg = "🔨 **{$player->gamertag}** is currently banned ({$active->reason}, {$when}). Total bans: {$total}.";
        } else {
            $msg = "✅ **{$player->gamertag}** is not banned. Total bans on record: {$total}.";
        }
        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    /**
     * Set the autocomplete choices.
     */
    public function autocomplete(): array
    {
        return [
            'player' => fn (Interaction $i, $value) => (new GamertagLookup())->players($value),
        ];
    }
}
