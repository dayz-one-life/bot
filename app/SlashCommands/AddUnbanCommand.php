<?php

namespace App\SlashCommands;

use App\Models\Player;
use App\Services\Admin\AdminService;
use App\SlashCommands\Concerns\GuardsAdmin;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class AddUnbanCommand extends SlashCommand
{
    use GuardsAdmin;

    protected $name = 'addunban';
    protected $description = 'Admin: grant (or remove) unban tokens for a gamertag.';

    protected $options = [
        ['name' => 'gamertag', 'description' => 'Target gamertag', 'type' => 3, 'required' => true, 'autocomplete' => true],
        ['name' => 'amount', 'description' => 'Tokens to add (negative to remove)', 'type' => 4, 'required' => true],
    ];

    public function handle($interaction): void
    {
        if ($this->denyIfNotAdmin($interaction)) {
            return;
        }
        $gamertag = (string) $this->value('gamertag');
        $r = (new AdminService())->grantTokens($gamertag, (int) $this->value('amount'));
        $msg = $r['status'] === 'granted'
            ? "✅ **{$gamertag}** now has **{$r['balance']}** token(s)."
            : '⚠️ No player found with that gamertag.';
        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => Player::query()
                ->when($value, fn ($q) => $q->where('gamertag', 'like', "%{$value}%"))
                ->orderByDesc('last_seen_at')->limit(25)->pluck('gamertag'),
        ];
    }
}
