<?php

use App\Services\Connection\SessionDuration;

it('humanizes session durations', function () {
    expect(SessionDuration::human(4980))->toBe('1h 23m'); // 1h 23m
    expect(SessionDuration::human(7200))->toBe('2h 0m');   // exact hours keep 0m
    expect(SessionDuration::human(780))->toBe('13m');      // under an hour
    expect(SessionDuration::human(59))->toBe('<1m');       // sub-minute
    expect(SessionDuration::human(0))->toBe('<1m');
});
