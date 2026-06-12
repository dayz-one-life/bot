<?php

namespace App\SlashCommands;

use App\Models\Player;
use App\Services\Tokens\ReferrerService;
use Discord\Parts\Interactions\Interaction;
use Laracord\Commands\SlashCommand;

class ReferrerCommand extends SlashCommand
{
    /**
     * The command name.
     *
     * @var string
     */
    protected $name = 'referrer';

    /**
     * The command description.
     *
     * @var string|null
     */
    protected $description = 'Set the player who referred you (linked players only, one-time).';

    /**
     * The command options.
     *
     * @var array
     */
    protected $options = [
        [
            'name' => 'gamertag',
            'description' => 'The gamertag of the player who referred you',
            'type' => 3,
            'required' => true,
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
        $discordId = (string) ($interaction->member->user->id ?? $interaction->user->id);

        $r = (new ReferrerService())->setReferrer($discordId, $gamertag);

        $msg = match ($r['status']) {
            'set' => "✅ Referrer set to **{$r['referrer']}**.",
            'not_linked' => '⚠️ Link your gamertag first with `/link`.',
            'already_set' => "⚠️ Your referrer is already set and can't be changed.",
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
            'gamertag' => fn (Interaction $i, $value) => Player::whereNotNull('discord_user_id')
                ->when($value, fn ($q) => $q->where('gamertag', 'like', "%{$value}%"))
                ->orderByDesc('last_seen_at')
                ->limit(25)
                ->pluck('gamertag'),
        ];
    }
}
