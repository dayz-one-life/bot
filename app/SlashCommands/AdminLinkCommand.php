<?php

namespace App\SlashCommands;

use App\Services\Admin\AdminService;
use App\Services\Lookup\GamertagLookup;
use App\SlashCommands\Concerns\GuardsAdmin;
use App\SlashCommands\Concerns\RenamesToGamertag;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class AdminLinkCommand extends SlashCommand
{
    use GuardsAdmin;
    use RenamesToGamertag;

    protected $name = 'adminlink';
    protected $description = 'Admin: force-link a Discord user to a gamertag.';

    protected $options = [
        ['name' => 'user', 'description' => 'Discord user to link', 'type' => 6, 'required' => true],
        ['name' => 'gamertag', 'description' => 'Target gamertag', 'type' => 3, 'required' => true, 'autocomplete' => true],
    ];

    public function handle($interaction): void
    {
        if ($this->denyIfNotAdmin($interaction)) {
            return;
        }

        // USER option value is the snowflake id string as sent by Discord.
        $userId = (string) $this->value('user');
        $gamertag = (string) $this->value('gamertag');

        $r = (new AdminService())->forceLink($userId, $gamertag);

        if ($r['status'] === 'linked') {
            $this->renameUserIdToGamertag($interaction, $userId, $gamertag);
        }

        $msg = $r['status'] === 'linked'
            ? "✅ Linked <@{$userId}> to **{$gamertag}**."
                . "\n_Set their server nickname to the gamertag (if the bot has Manage Nicknames and outranks them)._"
            : '⚠️ No player found with that gamertag.';

        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => (new GamertagLookup())->players($value),
        ];
    }
}
