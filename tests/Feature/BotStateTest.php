<?php

use App\Services\State\BotState;

it('reads a default and round-trips a value', function () {
    $state = new BotState();
    expect($state->get('mode', 'backfill'))->toBe('backfill');

    $state->set('mode', 'live');
    expect($state->get('mode'))->toBe('live');
});

it('stores and reads integers', function () {
    $state = new BotState();
    $state->setInt('high_water', 1717999999000);
    expect($state->getInt('high_water'))->toBe(1717999999000);
    expect($state->getInt('missing', 7))->toBe(7);
});
