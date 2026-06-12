<?php

it('exposes bounty defaults', function () {
    expect(config('bounty.activity_window_hours'))->toBe(48);
    expect(config('bounty.assoc_threshold'))->toBe(0.45);
    expect(config('bounty.weight_prox') + config('bounty.weight_copres') + config('bounty.weight_killg'))
        ->toEqual(1.0);
});

it('defaults position retention to 0 (keep forever)', function () {
    expect(config('bounty.position_retention_days'))->toBe(0);
});
