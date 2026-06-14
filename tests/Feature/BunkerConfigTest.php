<?php

it('exposes bunker config defaults', function () {
    expect(config('bunker.enabled'))->toBeTrue()
        ->and(config('bunker.cooldown_minutes'))->toBe(60);
});
