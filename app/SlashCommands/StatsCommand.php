<?php

namespace App\SlashCommands;

use App\Services\Connection\SessionDuration;
use App\Services\Lookup\GamertagLookup;
use App\Services\Stats\PlayerStatsService;
use Carbon\CarbonImmutable;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class StatsCommand extends SlashCommand
{
    /**
     * The command name.
     *
     * @var string
     */
    protected $name = 'stats';

    /**
     * The command description.
     *
     * @var string|null
     */
    protected $description = 'Show a player\'s lives, current life, playtime, and deaths.';

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
        $currentLife = $s['current_life_seconds'] !== null
            ? round($s['current_life_seconds'] / 3600, 1).'h'
            : '—';
        $status = $s['alive'] ? 'alive' : 'dead';
        $linked = $s['linked'] ? 'yes' : 'no';

        $body = "**{$s['gamertag']}** — {$status}\n"
            ."• Lives: {$s['lives']}  • Deaths: {$s['deaths']}\n"
            ."• Current life: {$currentLife}  • Total playtime: {$hours}h\n"
            ."• Linked: {$linked}";

        $sessions = $s['current_life_sessions'] ?? [];
        if ($sessions !== []) {
            $body .= "\n\n**Sessions this life:**";

            $hidden = ($s['current_life_session_total'] ?? count($sessions)) - count($sessions);
            if ($hidden > 0) {
                $body .= "\n… +{$hidden} earlier sessions";
            }

            foreach ($sessions as $session) {
                $when = CarbonImmutable::parse($session['connected_at'])->format('M j H:i').' UTC';
                $duration = SessionDuration::human($session['duration_seconds']);
                $tag = $session['is_open'] ? ' (current)' : '';
                $body .= "\n• {$when} — {$duration}{$tag}";
            }
        }

        $this->message($body)->reply($interaction, ephemeral: true);
    }

    /**
     * Set the autocomplete choices.
     */
    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => (new GamertagLookup())->players($value),
        ];
    }
}
