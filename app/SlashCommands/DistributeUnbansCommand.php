<?php

namespace App\SlashCommands;

use App\Services\State\BotState;
use App\Services\Tokens\RewardService;
use App\SlashCommands\Concerns\GuardsAdmin;
use Carbon\CarbonImmutable;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class DistributeUnbansCommand extends SlashCommand
{
    use GuardsAdmin;

    protected $name = 'distribute-unbans';
    protected $description = 'Admin: preview or apply the monthly unban token distribution.';

    protected $options = [
        ['name' => 'confirm', 'description' => 'Pass true to actually distribute tokens', 'type' => 5, 'required' => false],
    ];

    public function handle($interaction): void
    {
        if ($this->denyIfNotAdmin($interaction)) {
            return;
        }

        $confirm = (bool) $this->value('confirm');
        $reward = new RewardService(new BotState());
        $now = CarbonImmutable::now();

        if (! $confirm) {
            $preview = $reward->previewGrant($now);
            $count = count($preview['players']);
            $msg = "🔎 Preview: would grant **{$preview['granted']}** token(s) to **{$count}** linked player(s). Run with confirm:true to apply.";
        } else {
            $result = $reward->monthlyGrant($now);
            $msg = $result['granted'] > 0
                ? "✅ Distributed **{$result['granted']}** token(s)."
                : 'ℹ️ Already distributed for this month.';
        }

        $this->message($msg)->reply($interaction, ephemeral: true);
    }
}
