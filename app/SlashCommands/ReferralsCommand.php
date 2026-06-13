<?php

namespace App\SlashCommands;

use App\Services\Lookup\GamertagLookup;
use App\Services\Stats\ReferralQueryService;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class ReferralsCommand extends SlashCommand
{
    /**
     * The command name.
     *
     * @var string
     */
    protected $name = 'referrals';

    /**
     * The command description.
     *
     * @var string|null
     */
    protected $description = 'Show the players a gamertag referred and how many were active last month.';

    /**
     * The command options.
     *
     * @var array
     */
    protected $options = [
        ['name' => 'gamertag', 'description' => 'The gamertag to look up (defaults to your linked gamertag)', 'type' => 3, 'required' => false, 'autocomplete' => true],
    ];

    /**
     * Handle the slash command.
     *
     * @param  \Discord\Parts\Interactions\Interaction  $interaction
     * @return mixed
     */
    public function handle($interaction): void
    {
        $svc = new ReferralQueryService();
        $gamertag = $this->value('gamertag') !== null ? (string) $this->value('gamertag') : null;

        if ($gamertag !== null && $gamertag !== '') {
            $r = $svc->forGamertag($gamertag);
            if (! ($r['found'] ?? false)) {
                $this->message('⚠️ No player found with that gamertag.')->reply($interaction, ephemeral: true);

                return;
            }
            $this->reply($interaction, $r, "**Referrals for {$gamertag}**", "**{$gamertag}** hasn't referred anyone yet.");

            return;
        }

        $discordId = (string) ($interaction->member->user->id ?? $interaction->user->id);
        $r = $svc->forDiscordUser($discordId);
        if (! $r['linked']) {
            $this->message('⚠️ Link your gamertag first with `/link`, or pass a gamertag.')->reply($interaction, ephemeral: true);

            return;
        }
        $this->reply(
            $interaction,
            $r,
            '**Your referrals**',
            "You haven't referred anyone yet. Share your gamertag so new players can set you as their referrer!"
        );
    }

    /**
     * Render the referral list (or the empty-state message) as an ephemeral reply.
     */
    private function reply($interaction, array $r, string $heading, string $emptyMessage): void
    {
        if (empty($r['referrals'])) {
            $this->message($emptyMessage)->reply($interaction, ephemeral: true);

            return;
        }
        $lines = array_map(fn ($x) => ($x['active'] ? '🟢' : '⚪️')." {$x['gamertag']}", $r['referrals']);
        $this->message(
            "{$heading} ({$r['activeCount']} active last month → +{$r['activeCount']} next grant):\n".implode("\n", $lines)
        )->reply($interaction, ephemeral: true);
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
