<?php

use App\Services\Adm\DeathLogCapturer;

it('keeps only lines mentioning the victim plus the death line, newest-last', function () {
    $buffer = [
        '10:00:00 | Player "Victim" (id=V=) is connected',
        '10:01:00 | Player "Other" (id=O=) is connected',
        '10:02:00 | Player "Victim" (id=V= pos=<1,2,3>)[HP: 50] hit by Player "Killer" (id=K=) into Torso',
        '10:02:30 | Player "Other" (id=O=) pos=<9,9,9>',
    ];
    $deathLine = '10:03:00 | Player "Victim" (DEAD) (id=V=) killed by Player "Killer" (id=K=) with M4A1 from 153.4 meters';

    $log = (new DeathLogCapturer())->capture($buffer, 'Victim', $deathLine);

    expect($log)->toContain('hit by Player "Killer"');
    expect($log)->toContain('is connected');
    expect($log)->not->toContain('Other');
    expect($log)->toEndWith($deathLine);
});

it('caps the excerpt to the most recent N matching lines', function () {
    $buffer = [];
    for ($i = 0; $i < 80; $i++) {
        $buffer[] = "10:00:{$i} | Player \"Victim\" (id=V=) pos=<{$i},0,0>";
    }
    $log = (new DeathLogCapturer())->capture($buffer, 'Victim', 'DEATH', maxLines: 40);

    // 39 most-recent buffer matches + the death line = 40 lines.
    expect(substr_count($log, "\n") + 1)->toBe(40);
    expect($log)->toContain('pos=<79,0,0>');
    expect($log)->not->toContain('pos=<39,0,0>');
});

it('returns just the death line when nothing in the buffer matches', function () {
    $log = (new DeathLogCapturer())->capture(['10:00:00 | Player "Z" (id=Z=) is connected'], 'Victim', 'DEATH');
    expect($log)->toBe('DEATH');
});
