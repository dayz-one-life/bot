<?php

namespace App\SlashCommands;

use App\Models\Ban;
use App\Services\Ban\BanService;
use App\Services\Ban\DiscordBanNotifier;
use App\Services\Nitrado\NitradoClient;
use App\SlashCommands\Concerns\GuardsAdmin;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class AdminUnbanCommand extends SlashCommand
{
    use GuardsAdmin;

    protected $name = 'adminunban';
    protected $description = 'Admin: lift a ban for a player by gamertag.';

    protected $options = [
        ['name' => 'gamertag', 'description' => 'Gamertag to unban', 'type' => 3, 'required' => true, 'autocomplete' => true],
    ];

    public function handle($interaction): void
    {
        if ($this->denyIfNotAdmin($interaction)) {
            return;
        }

        $gamertag = (string) $this->value('gamertag');

        $bans = new BanService(
            new NitradoClient(env('NITRADO_TOKEN'), (int) env('NITRADO_SERVICE_ID')),
            new DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID')),
            dryRun: filter_var(env('BAN_DRY_RUN', false), FILTER_VALIDATE_BOOL),
        );

        $bans->unban($gamertag, 'Manual unban');

        $this->message("✅ Unbanned **{$gamertag}**.")->reply($interaction, ephemeral: true);
    }

    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => Ban::where('expired', false)
                ->with('player')->get()
                ->map(fn ($b) => $b->player?->gamertag)->filter()->unique()
                ->when($value, fn ($c) => $c->filter(fn ($t) => str_contains(strtolower((string) $t), strtolower((string) $value))))
                ->take(25)->values(),
        ];
    }
}
