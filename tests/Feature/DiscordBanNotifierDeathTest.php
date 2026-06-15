<?php

use App\Models\Ban;
use App\Models\Player;
use App\Services\Ban\DiscordBanNotifier;
use App\Services\Llm\FlavorGenerator;
use App\Services\Llm\OpenRouterClient;
use Carbon\CarbonImmutable;

/** Test double: records the keys the notifier asks the generator to produce. */
class RecordingFlavor extends FlavorGenerator
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

it('routes a death autoban to the bans channel via the generator (no longer suppressed)', function () {
    $flavor = new RecordingFlavor();
    $notifier = new DiscordBanNotifier(null, 'chan', null, $flavor);

    $ban = new Ban([
        'source' => 'auto_death',
        'reason' => 'One life autoban',
        'expires_at' => CarbonImmutable::now()->addHours(12),
    ]);
    $player = new Player(['gamertag' => 'Doomed']); // no discord link -> no DM path

    $notifier->banned($ban, $player, false);

    expect($flavor->keys)->toContain('ban.death');
});

it('routes manual and extended bans through the generator too', function () {
    $flavor = new RecordingFlavor();
    $notifier = new DiscordBanNotifier(null, 'chan', null, $flavor);

    $notifier->banned(new Ban(['source' => 'admin', 'reason' => 'cheating', 'expires_at' => CarbonImmutable::now()->addDay()]), new Player(['gamertag' => 'A']), false);
    $notifier->banned(new Ban(['source' => 'admin', 'reason' => 'again', 'expires_at' => CarbonImmutable::now()->addDay()]), new Player(['gamertag' => 'B']), true);

    expect($flavor->keys)->toBe(['ban.manual', 'ban.extended']);
});

it('routes an unban through the generator', function () {
    $flavor = new RecordingFlavor();
    $notifier = new DiscordBanNotifier(null, 'chan', null, $flavor);

    $notifier->unbanned(new Player(['gamertag' => 'Freed']), 'Ban expired', 'One life autoban');

    expect($flavor->keys)->toBe(['ban.unbanned']);
});
