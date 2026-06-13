<?php

namespace App\SlashCommands;

use App\Services\Bounty\AssociateDetector;
use App\Services\Bounty\OverrideService;
use App\Services\Lookup\GamertagLookup;
use App\Models\Player;
use App\SlashCommands\Concerns\GuardsAdmin;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class TeamCommand extends SlashCommand
{
    use GuardsAdmin;

    protected $name = 'team';
    protected $description = 'Admin: manage bounty associate overrides.';

    protected $options = [
        ['name' => 'action', 'description' => 'link | unlink | clear | show', 'type' => 3, 'required' => true,
            'choices' => [
                ['name' => 'link (force associates)', 'value' => 'link'],
                ['name' => 'unlink (force NOT associates)', 'value' => 'unlink'],
                ['name' => 'clear (back to algorithm)', 'value' => 'clear'],
                ['name' => 'show', 'value' => 'show'],
            ]],
        ['name' => 'gamertag', 'description' => 'First gamertag', 'type' => 3, 'required' => true, 'autocomplete' => true],
        ['name' => 'gamertag2', 'description' => 'Second gamertag (for link/unlink/clear)', 'type' => 3, 'required' => false, 'autocomplete' => true],
    ];

    public function handle($interaction): void
    {
        if ($this->denyIfNotAdmin($interaction)) return;

        $action = (string) $this->value('action');
        $a = (string) $this->value('gamertag');
        $b = $this->value('gamertag2') !== null ? (string) $this->value('gamertag2') : null;

        if ($action === 'show') {
            $this->message($this->renderShow($a))->reply($interaction, ephemeral: true);
            return;
        }

        if ($b === null) {
            $this->message('⚠️ This action needs a second gamertag.')->reply($interaction, ephemeral: true);
            return;
        }

        if (strcasecmp($a, $b) === 0) {
            $this->message('⚠️ Pick two different gamertags.')->reply($interaction, ephemeral: true);
            return;
        }

        $svc = new OverrideService();
        $result = match ($action) {
            'link' => $svc->set($a, $b, true),
            'unlink' => $svc->set($a, $b, false),
            'clear' => $svc->clear($a, $b),
            default => 'not_found',
        };

        $msg = $result === 'ok'
            ? "✅ `{$a}` / `{$b}` override updated ({$action})."
            : '⚠️ One of those gamertags was not found.';
        $this->message($msg)->reply($interaction, ephemeral: true);
    }

    private function renderShow(string $tag): string
    {
        $player = Player::where('gamertag', $tag)->first();
        if (! $player) return '⚠️ No player found with that gamertag.';

        $detector = new AssociateDetector();
        $associates = $detector->associatesOf($player);
        if ($associates->isEmpty()) {
            return "🔍 `{$tag}` has no detected associates.";
        }

        $lines = $associates->map(function (Player $p) use ($player, $detector) {
            $score = round($detector->score($player, $p), 2);
            return "• `{$p->gamertag}` (score {$score})";
        })->implode("\n");

        return "🔍 Associates of `{$tag}`:\n{$lines}";
    }

    public function autocomplete(): array
    {
        return [
            'gamertag' => fn (Interaction $i, $value) => (new GamertagLookup())->players($value),
            'gamertag2' => fn (Interaction $i, $value) => (new GamertagLookup())->players($value),
        ];
    }
}
