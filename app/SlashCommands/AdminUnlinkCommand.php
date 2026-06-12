<?php

namespace App\SlashCommands;

use App\Services\Admin\AdminService;
use App\SlashCommands\Concerns\GuardsAdmin;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class AdminUnlinkCommand extends SlashCommand
{
    use GuardsAdmin;

    protected $name = 'adminunlink';
    protected $description = 'Admin: unlink a Discord user from their gamertag.';

    protected $options = [
        ['name' => 'user', 'description' => 'Discord user to unlink', 'type' => 6, 'required' => true],
    ];

    public function handle($interaction): void
    {
        if ($this->denyIfNotAdmin($interaction)) {
            return;
        }

        $userId = (string) $this->value('user');

        $r = (new AdminService())->unlink($userId);

        $msg = $r['status'] === 'unlinked'
            ? "✅ Unlinked <@{$userId}> (was **{$r['gamertag']}**)."
            : '⚠️ That user isn\'t linked.';

        $this->message($msg)->reply($interaction, ephemeral: true);
    }
}
