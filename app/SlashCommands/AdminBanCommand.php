<?php

namespace App\SlashCommands;

use App\Services\Ban\BanService;
use App\Services\Ban\DiscordBanNotifier;
use App\Services\Lookup\GamertagLookup;
use App\Services\Lookup\PlayerMention;
use App\Services\Nitrado\NitradoClient;
use App\SlashCommands\Concerns\GuardsAdmin;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class AdminBanCommand extends SlashCommand
{
    use GuardsAdmin;

    protected $name = 'adminban';
    protected $description = 'Admin: ban a player by gamertag.';

    protected $options = [
        ['name' => 'gamertag', 'description' => 'Gamertag to ban', 'type' => 3, 'required' => true, 'autocomplete' => true],
        ['name' => 'hours', 'description' => 'Duration in hours (0 = permanent; omit = default)', 'type' => 4, 'required' => false],
        ['name' => 'reason', 'description' => 'Reason for ban', 'type' => 3, 'required' => false],
    ];

    public function handle($interaction): void
    {
        if ($this->denyIfNotAdmin($interaction)) {
            return;
        }

        $gamertag = (string) $this->value('gamertag');
        $hoursRaw = $this->value('hours');
        $hours = $hoursRaw === null ? (int) env('BAN_DURATION_HOURS', 12) : (int) $hoursRaw;
        $reason = (string) ($this->value('reason') ?? 'Manual ban');

        $bans = new BanService(
            new NitradoClient(env('NITRADO_TOKEN'), (int) env('NITRADO_SERVICE_ID')),
            new DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID')),
            dryRun: filter_var(env('BAN_DRY_RUN', false), FILTER_VALIDATE_BOOL),
        );

        $ban = $bans->ban($gamertag, $hours, $reason, 'manual');

        $expiry = $ban->expires_at
            ? 'expires <t:' . $ban->expires_at->timestamp . ':R>'
            : 'permanent';

        $msg = "🔨 Banned ".(new PlayerMention())->for($gamertag)." ({$reason}) — {$expiry}.";

        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => (new GamertagLookup())->players($value),
        ];
    }
}
