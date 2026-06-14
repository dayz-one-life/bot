<?php

use App\Services\Online\OnlineRosterComposer;

it('composes a roster with backticked tags and durations, never @-mentioning', function () {
    $payload = (new OnlineRosterComposer())->compose([
        ['gamertag' => 'Bob', 'session_seconds' => 7200, 'life_seconds' => 7200],
        ['gamertag' => 'Alice', 'session_seconds' => 1800, 'life_seconds' => 2400],
    ]);

    expect($payload['title'])->toBe('🟢 Online — 2');
    expect($payload['description'])->toContain('`Bob` · on 2h 0m · alive 2h 0m');
    expect($payload['description'])->toContain('`Alice` · on 30m · alive 40m');
    expect($payload['description'])->not->toContain('<@'); // high-volume channel: never @-mention
});

it('shows a friendly empty state when nobody is online', function () {
    $payload = (new OnlineRosterComposer())->compose([]);

    expect($payload['title'])->toBe('🟢 Online — 0');
    expect($payload['description'])->toBe("Nobody's online right now.");
});
