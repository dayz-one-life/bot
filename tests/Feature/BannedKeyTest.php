<?php

use App\Models\Ban;
use App\Services\Ban\DiscordBanNotifier;

it('routes a death autoban to ban.death', function () {
    $ban = new Ban(['source' => 'auto_death']);
    expect(DiscordBanNotifier::bannedKey($ban, false))->toBe('ban.death');
});

it('routes a manual ban to ban.manual', function () {
    $ban = new Ban(['source' => 'admin']);
    expect(DiscordBanNotifier::bannedKey($ban, false))->toBe('ban.manual');
});

it('routes any extension to ban.extended', function () {
    expect(DiscordBanNotifier::bannedKey(new Ban(['source' => 'auto_death']), true))->toBe('ban.extended');
    expect(DiscordBanNotifier::bannedKey(new Ban(['source' => 'admin']), true))->toBe('ban.extended');
});
