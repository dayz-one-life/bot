<?php

afterEach(function () {
    foreach (['BANS_CHANNEL_ID', 'LEADERBOARD_CHANNEL_ID'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

it('exposes leaderboard defaults', function () {
    expect(config('leaderboard.enabled'))->toBeTrue();
    expect(config('leaderboard.refresh_minutes'))->toBe(15);
    expect(config('leaderboard.top_count'))->toBe(5);
});

it('does not fall back to the bans channel when LEADERBOARD_CHANNEL_ID is unset', function () {
    // Bans channel configured, leaderboard channel left unset.
    foreach (['BANS_CHANNEL_ID' => 'bans-123', 'LEADERBOARD_CHANNEL_ID' => false] as $key => $value) {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    // Re-evaluate the config file fresh so env() runs against the state above.
    $config = require base_path('config/leaderboard.php');

    expect($config['channel_id'])->toBeNull(); // never the bans channel
});
