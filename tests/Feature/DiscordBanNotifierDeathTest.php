<?php

use App\Services\Ban\DiscordBanNotifier;

it('does not channel-post a death ban (the death feed owns it)', function () {
    expect(DiscordBanNotifier::postsToChannel('ban.death'))->toBeFalse();
});

it('still channel-posts manual and extended bans', function () {
    expect(DiscordBanNotifier::postsToChannel('ban.manual'))->toBeTrue();
    expect(DiscordBanNotifier::postsToChannel('ban.extended'))->toBeTrue();
});
