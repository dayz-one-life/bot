<?php

it('exposes leaderboard defaults', function () {
    expect(config('leaderboard.enabled'))->toBeTrue();
    expect(config('leaderboard.refresh_minutes'))->toBe(15);
    expect(config('leaderboard.top_count'))->toBe(5);
});
