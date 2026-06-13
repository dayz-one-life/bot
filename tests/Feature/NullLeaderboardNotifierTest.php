<?php

use App\Services\Leaderboard\NullLeaderboardNotifier;

it('captures the published payload and never throws', function () {
    $notifier = new NullLeaderboardNotifier();
    $payload = ['title' => 't', 'description' => 'd', 'fields' => []];

    $notifier->publish($payload);

    expect($notifier->lastPayload)->toBe($payload);
});
