<?php

namespace App\SlashCommands;

use App\Services\Lookup\GamertagLookup;
use App\Services\Tokens\LinkService;
use App\SlashCommands\Concerns\RenamesToGamertag;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class LinkCommand extends SlashCommand
{
    use RenamesToGamertag;
    /**
     * The command name.
     *
     * @var string
     */
    protected $name = 'link';

    /**
     * The command description.
     *
     * @var string|null
     */
    protected $description = 'Link your Discord account to a DayZ gamertag (one per user).';

    /**
     * The command options.
     *
     * @var array
     */
    protected $options = [
        [
            'name' => 'gamertag',
            'description' => 'The gamertag to link',
            'type' => 3,
            'required' => true,
            'autocomplete' => true,
        ],
        [
            'name' => 'referrer',
            'description' => 'Optional: who referred you (a linked player)',
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
        $gamertag = (string) $this->value('gamertag');
        $referrer = $this->value('referrer') !== null ? (string) $this->value('referrer') : null;
        $discordId = (string) ($interaction->member->user->id ?? $interaction->user->id);

        $r = (new LinkService())->link($discordId, $gamertag, $referrer);

        if ($r['status'] === 'linked') {
            $this->renameMemberToGamertag($interaction->member, $r['gamertag']);
        }

        $msg = match ($r['status']) {
            'linked' => "✅ Linked to **{$r['gamertag']}**."
                . (($r['tokenGranted'] ?? false) ? ' You received **1 unban token**.' : '')
                . (($r['referrer'] ?? null) ? " Referrer set to **{$r['referrer']}**." : '')
                . "\n_Your server nickname has been set to your gamertag. If it didn't change, ask an admin to check the bot's Manage Nicknames permission and role position._",
            'already_linked' => "⚠️ You are already linked. You can't re-link or change your gamertag.",
            'gamertag_not_found' => "⚠️ That gamertag isn't available — make sure you've connected to the server at least once, and that no one else has linked it.",
            'invalid_referrer' => '⚠️ Invalid referrer — pick a different, already-linked player (not yourself).',
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
            'gamertag' => fn (Interaction $i, $value) => (new GamertagLookup())->players($value, linked: false),
            'referrer' => fn (Interaction $i, $value) => (new GamertagLookup())->players($value, linked: true),
        ];
    }
}
