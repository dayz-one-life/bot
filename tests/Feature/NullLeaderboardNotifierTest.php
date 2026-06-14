<?php

use App\Services\Leaderboard\NullLeaderboardNotifier;

it('captures the published payloads and never throws', function () {
    $notifier = new NullLeaderboardNotifier();
    $payloads = [
        ['key' => 'alive', 'title' => 't1', 'description' => 'd1'],
        ['key' => 'kills', 'title' => 't2', 'description' => 'd2'],
    ];

    $notifier->publish($payloads);

    expect($notifier->lastPayloads)->toBe($payloads);
});
