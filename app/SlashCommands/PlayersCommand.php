<?php

namespace App\SlashCommands;

use App\Models\Player;
use App\Services\Stats\PlayerStatsService;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class PlayersCommand extends SlashCommand
{
    /**
     * The command name.
     *
     * @var string
     */
    protected $name = 'players';

    /**
     * The command description.
     *
     * @var string|null
     */
    protected $description = 'Show a player\'s lives, playtime, and deaths.';

    /**
     * The command options.
     *
     * @var array
     */
    protected $options = [
        ['name' => 'gamertag', 'description' => 'The gamertag to look up', 'type' => 3, 'required' => true, 'autocomplete' => true],
    ];

    /**
     * Handle the slash command.
     *
     * @param  \Discord\Parts\Interactions\Interaction  $interaction
     * @return mixed
     */
    public function handle($interaction): void
    {
        $s = (new PlayerStatsService())->statsFor((string) $this->value('gamertag'));
        if (! ($s['found'] ?? false)) {
            $this->message('⚠️ No player found with that gamertag.')->reply($interaction, ephemeral: true);

            return;
        }
        $hours = round($s['playtime_seconds'] / 3600, 1);
        $status = $s['alive'] ? 'alive' : 'dead';
        $linked = $s['linked'] ? 'yes' : 'no';
        $this->message(
            "**{$s['gamertag']}** — {$status}\n"
            ."• Lives: {$s['lives']}  • Deaths: {$s['deaths']}\n"
            ."• Total playtime: {$hours}h  • Linked: {$linked}"
        )->reply($interaction, ephemeral: true);
    }

    /**
     * Set the autocomplete choices.
     */
    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => Player::query()
                ->when($value, fn ($q) => $q->where('gamertag', 'like', "%{$value}%"))
                ->orderByDesc('last_seen_at')->limit(25)->pluck('gamertag'),
        ];
    }
}
