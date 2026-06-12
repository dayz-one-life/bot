<?php

use App\Models\Player;
use App\Services\Adm\AdmIngestor;
use App\Services\Adm\AdmParser;
use App\Services\Life\LifeTracker;

it('applies events from a file in chronological order from the cursor', function () {
    $content = implode("\n", [
        'AdminLog started on 2026-06-11 at 09:00:00',
        '10:00:00 | Player "Alice" (id=A=) is connected',
        '10:20:00 | Player "Alice" (DEAD) (id=A=) killed by Player "Bob" (id=B=) with Knife',
        '10:25:00 | Player "Alice" (id=A=) has been disconnected',
    ]);

    $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
    $fallback = new DateTimeImmutable('2026-06-11T00:00:00Z');

    // offsetMs = 0; cursor starts at 0; process all lines
    $newCursor = $ingestor->processFile($content, 0, $fallback, 0);
    expect($newCursor)->toBe(4);

    $alice = Player::where('gamertag', 'Alice')->first();
    $life = $alice->lives()->latest('started_at')->first();
    expect($life->death_cause)->toBe('pvp');
    expect($life->playtime_seconds)->toBe(1200); // 10:00 -> 10:20 death
});

it('does not reprocess lines before the cursor', function () {
    $content = implode("\n", [
        '10:00:00 | Player "Alice" (id=A=) is connected',
        '10:20:00 | Player "Alice" (id=A=) has been disconnected',
    ]);
    $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
    $fallback = new DateTimeImmutable('2026-06-11T00:00:00Z');

    // cursor=1 -> skip the connect, only the disconnect line is "new"
    $ingestor->processFile($content, 1, $fallback, 0);
    $alice = Player::where('gamertag', 'Alice')->first();
    // no connect applied -> no open session existed -> disconnect is a no-op; no life created
    expect($alice?->lives()->count() ?? 0)->toBe(0);
});
