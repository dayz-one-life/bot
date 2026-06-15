<?php

use App\Models\Bounty;
use App\Models\Player;
use App\Services\Bounty\DiscordBountyNotifier;
use App\Services\Llm\FlavorGenerator;
use App\Services\Llm\OpenRouterClient;

/** Test double: records the keys the notifier asks the generator to produce. */
class RecordingBountyFlavor extends FlavorGenerator
{
    /** @var list<string> */
    public array $keys = [];

    public function __construct()
    {
        parent::__construct(new OpenRouterClient(null, 'm', 'https://x/api/v1'));
    }

    public function line(string $key, array $tokens, string $fallback): string
    {
        $this->keys[] = $key;

        return 'stub';
    }
}

it('routes placed / moved / claimed / ended channel posts through the generator', function () {
    $flavor = new RecordingBountyFlavor();
    $notifier = new DiscordBountyNotifier(null, 'chan', null, $flavor);

    $bounty = new Bounty();
    $target = new Player(['gamertag' => 'Target']);   // no discord link -> no DM path
    $killer = new Player(['gamertag' => 'Hunter']);

    $notifier->placed($bounty, $target);
    $notifier->moved($bounty, $target);
    $notifier->claimed($bounty, $target, $killer, 2);
    $notifier->ended($bounty, $target, 'killed');

    expect($flavor->keys)->toBe(['bounty.placed', 'bounty.moved', 'bounty.claimed', 'bounty.ended']);
});
