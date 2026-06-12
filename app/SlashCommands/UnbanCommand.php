<?php

namespace App\SlashCommands;

use App\Services\Ban\BanService;
use App\Services\Ban\DiscordBanNotifier;
use App\Services\Lookup\GamertagLookup;
use App\Services\Nitrado\NitradoClient;
use App\Services\Tokens\RedemptionService;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class UnbanCommand extends SlashCommand
{
    /**
     * The command name.
     *
     * @var string
     */
    protected $name = 'unban';

    /**
     * The command description.
     *
     * @var string|null
     */
    protected $description = 'Spend an unban token to lift a temporary ban (yours by default).';

    /**
     * The command options.
     *
     * @var array
     */
    protected $options = [
        [
            'name' => 'player',
            'description' => 'Gamertag to unban (defaults to you)',
            'type' => 3,
            'required' => false,
            'autocomplete' => true,
        ],
    ];

    /**
     * Handle the slash command.
     *
     * @param  \Discord\Parts\Interactions\Interaction  $interaction
     * @return mixed
     */
    public function handle($interaction): void
    {
        $target = $this->value('player');
        $target = $target !== null ? (string) $target : null;
        $discordId = (string) ($interaction->member->user->id ?? $interaction->user->id);

        $bans = new BanService(
            new NitradoClient(env('NITRADO_TOKEN'), (int) env('NITRADO_SERVICE_ID')),
            new DiscordBanNotifier($this->discord(), env('BANS_CHANNEL_ID')),
            dryRun: filter_var(env('BAN_DRY_RUN', false), FILTER_VALIDATE_BOOL),
        );
        $r = (new RedemptionService($bans))->redeem($discordId, $target);

        $msg = match ($r['status']) {
            'unbanned' => "✅ Unbanned **{$r['target']}**. Tokens remaining: **{$r['remaining']}**.",
            'not_linked' => '⚠️ Link your gamertag first with `/link`.',
            'no_tokens' => '⚠️ You have no unban tokens.',
            'target_not_found' => '⚠️ No player found with that gamertag.',
            'no_active_ban' => 'ℹ️ That player has no active temporary ban.',
            'permanent_ban' => "⚠️ That player is permanently banned — tokens can't lift it.",
            default => '⚠️ Something went wrong.',
        };

        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    /**
     * Set the autocomplete choices.
     */
    public function autocomplete(): array
    {
        return [
            'player' => fn (Interaction $i, $value) => (new GamertagLookup())->bannedGamertags($value, temporaryOnly: true),
        ];
    }
}
